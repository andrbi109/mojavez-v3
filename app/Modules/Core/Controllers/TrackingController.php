<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Services\AutoCancelService;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\JobAndPropertyRepositoryInterface;
use App\Shared\Contracts\SettingRepositoryInterface;
use App\Shared\Contracts\StatusLogRepositoryInterface;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;

/**
 * TrackingController — public tracking of an applicant's case by tracking code.
 *
 * Inherited from v2's TrackingController. The public user enters their
 * HG-XXXXXXXX tracking code (and mobile if TRACKING_REQUIRE_MOBILE=true)
 * to view the case status + timeline.
 *
 * Rate limits (per docs/00-MASTER.md §12.6):
 *   POST /tracking/search: 10 / 60s (session-based)
 *   GET /tracking/status/{code}: 30 / 60s (IP-based)
 *   POST /tracking/change-postal-code: 3 / 3600s
 *   GET /tracking/print/{code}: 10 / 60s (IP-based)
 *
 * AutoCancelService::cancelExpired() is called on every /tracking/status
 * view (poor man's cron — keeps the system running even without a real
 * cron job hitting /check-cancel).
 */
final class TrackingController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly JobAndPropertyRepositoryInterface $jobAndProperty,
        private readonly StatusLogRepositoryInterface $statusLog,
        private readonly AutoCancelService $autoCancel,
        // Phase 43 (v2.0.9) — used to inject site_name + owner_name into
        // the printable A4 view (print.php) instead of hardcoding the
        // fallback. Autowired by the Container (singleton).
        private readonly SettingRepositoryInterface $settings,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /tracking — search form.
     */
    public function search(Request $request, array $params = []): Response
    {
        return $this->render('tracking/search', [
            'requireMobile' => $this->environment->trackingRequireMobile,
        ]);
    }

    /**
     * POST /tracking/search — process the search.
     */
    public function find(Request $request, array $params = []): Response
    {
        $trackingCode = Validator::trackingCode($request->input('tracking_code'));
        if ($trackingCode === null) {
            return $this->jsonError(422, 'invalid_tracking_code', 'کد رهگیری نامعتبر.');
        }
        // If TRACKING_REQUIRE_MOBILE=true, also require mobile + verify ownership.
        if ($this->environment->trackingRequireMobile) {
            $mobile = Validator::mobile($request->input('mobile'));
            if ($mobile === null) {
                return $this->jsonError(422, 'invalid_mobile', 'شمارهٔ موبایل نامعتبر.');
            }
            $applicant = $this->applicants->findByTrackingCode($trackingCode);
            if ($applicant === null || $applicant->mobile !== $mobile) {
                return $this->jsonError(404, 'not_found', 'پرونده‌ای با این کد رهگیری و موبایل یافت نشد.');
            }
            return $this->redirect("/tracking/status/{$trackingCode}");
        }
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            return $this->jsonError(404, 'not_found', 'پرونده‌ای با این کد رهگیری یافت نشد.');
        }
        return $this->redirect("/tracking/status/{$trackingCode}");
    }

    /**
     * GET /tracking/status/{trackingCode} — show the case status + timeline.
     */
    public function status(Request $request, array $params = []): Response
    {
        $trackingCode = (string)($params['trackingCode'] ?? '');
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>پرونده یافت نشد.</p>', 404);
        }
        // Poor man's cron: run AutoCancelService on every status view.
        // Wrapped in try-catch so a DB hiccup doesn't break the page.
        try {
            $this->autoCancel->cancelExpired();
        } catch (\Throwable $e) {
            $this->logger->warning('AutoCancel failed on tracking status view', [
                '_module' => 'core', 'error' => $e->getMessage(),
            ]);
        }

        $statusLog = $this->statusLog->findByApplicantId($applicant->id);
        $jobDetail = $this->jobAndProperty->findJobDetailByApplicantId($applicant->id);

        return $this->render('tracking/status', [
            'applicant'  => $applicant,
            'statusLog'  => $statusLog,
            'jobDetail'  => $jobDetail,
        ]);
    }

    /**
     * GET /tracking/print/{trackingCode} — printable case view (A4).
     *
     * Phase 10 fix: render with layout='none' because print.php is a complete
     * HTML document (its own <html>/<head>/<body> + print-specific CSS).
     * Without this, the page gets wrapped in layout.php — producing invalid
     * nested HTML and pulling in the site nav/footer (which should NOT
     * appear on a printable A4 page).
     */
    public function printView(Request $request, array $params = []): Response
    {
        $trackingCode = (string)($params['trackingCode'] ?? '');
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>پرونده یافت نشد.</p>', 404);
        }
        $statusLog = $this->statusLog->findByApplicantId($applicant->id);
        // Phase 43 (v2.0.9) — pull site_name + owner_name from settings
        // so the printable A4 footer carries the actual owner's name.
        $siteName  = $this->settings->get('site_name',  'سامانهٔ مجوزهای صنایع دستی');
        $ownerName = $this->settings->get('owner_name', '');
        return $this->render('print', [
            'applicant'  => $applicant,
            'statusLog'  => $statusLog,
            'siteName'   => $siteName,
            'ownerName'  => $ownerName,
        ], 'none');
    }

    /**
     * POST /tracking/change-postal-code — request a postal code change.
     */
    public function changePostalCode(Request $request, array $params = []): Response
    {
        $trackingCode = Validator::trackingCode($request->input('tracking_code'));
        $newPostalCode = (string)$request->input('new_postal_code', '');
        if ($trackingCode === null || !preg_match('/^\d{10}$/', $newPostalCode)) {
            return $this->jsonError(422, 'invalid_input', 'کد رهگیری یا کد پستی نامعتبر.');
        }
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            return $this->jsonError(404, 'not_found');
        }
        // Insert a postal_code_changes row (status='در انتظار' default).
        $this->connection()->execute(
            'INSERT INTO postal_code_changes (applicant_id, old_postal_code, new_postal_code) VALUES (?, ?, ?)',
            [$applicant->id, $applicant->postalCode, $newPostalCode]
        );
        return $this->jsonSuccess(['requested' => true]);
    }

    /**
     * POST /tracking/payments/submit — user uploads a payment receipt.
     *
     * Phase 15 (v1.6.0): stores a `payments` row with the user-supplied
     * amount + reference code + a flag for the admin reviewer. Status
     * is 'در انتظار' until admin review.
     */
    public function submitPayment(Request $request, array $params = []): Response
    {
        $this->requireAnyLogin();
        $trackingCode = Validator::trackingCode($request->input('tracking_code'));
        $amount = (int)$request->input('amount', 0);
        $reference = Validator::sanitizeText($request->input('reference_code', ''), 50);
        $purpose = Validator::sanitizeText($request->input('purpose', 'هزینهٔ ثبت‌نام'), 64);
        if ($trackingCode === null || $amount <= 0) {
            return $this->jsonError(422, 'invalid_input', 'کد رهگیری یا مبلغ نامعتبر.');
        }
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            return $this->jsonError(404, 'not_found', 'پرونده یافت نشد.');
        }
        $userAccountId = (int)$this->session->get('user_id');
        $this->connection()->execute(
            'INSERT INTO payments
                (applicant_id, user_account_id, purpose, amount,
                 reference_code, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $applicant->id,
                $userAccountId > 0 ? $userAccountId : null,
                $purpose !== '' ? $purpose : 'هزینهٔ ثبت‌نام',
                $amount,
                $reference !== '' ? $reference : null,
                'در انتظار',
            ]
        );
        $paymentId = (int)$this->connection()->pdo()->lastInsertId();
        $this->logger->info('Payment receipt submitted', [
            '_module'      => 'core',
            'applicant_id' => $applicant->id,
            'payment_id'   => $paymentId,
            'amount'       => $amount,
        ]);
        return $this->jsonSuccess(['payment_id' => $paymentId]);
    }

    /**
     * POST /tracking/sms/submit — user submits an SMS status check
     * (for the household-business flow where the upstream sends an SMS
     * confirmation code that the user copies into the tracking page).
     *
     * Phase 15 (v1.6.0): stores a `user_sms_entries` row referencing the
     * applicant + the SMS template id (if provided) + the user's pasted
     * text. Status='در انتظار' until admin review.
     */
    public function submitSms(Request $request, array $params = []): Response
    {
        $this->requireAnyLogin();
        $trackingCode = Validator::trackingCode($request->input('tracking_code'));
        $smsCode = Validator::sanitizeText($request->input('sms_code', ''), 50);
        $smsText = Validator::sanitizeText($request->input('sms_text', ''), 1000);
        $templateId = (int)$request->input('sms_template_id', 0);
        if ($trackingCode === null || ($smsCode === '' && $smsText === '')) {
            return $this->jsonError(422, 'invalid_input', 'کد رهگیری یا متن پیامک نامعتبر.');
        }
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            return $this->jsonError(404, 'not_found', 'پرونده یافت نشد.');
        }
        // If sms_template_id is 0, fall back to the first active template.
        if ($templateId <= 0) {
            $templateId = (int)$this->connection()->scalar(
                'SELECT id FROM sms_templates WHERE is_active = 1 ORDER BY id LIMIT 1'
            );
        }
        if ($templateId <= 0) {
            return $this->jsonError(422, 'no_template', 'هیچ الگو پیامکی فعالی موجود نیست.');
        }
        $this->connection()->execute(
            'INSERT INTO user_sms_entries
                (applicant_id, sms_template_id, sms_code, sms_text, received_at, status)
             VALUES (?, ?, ?, ?, CURDATE(), ?)',
            [
                $applicant->id,
                $templateId,
                $smsCode !== '' ? $smsCode : null,
                $smsText !== '' ? $smsText : null,
                'در انتظار',
            ]
        );
        $entryId = (int)$this->connection()->pdo()->lastInsertId();
        $this->logger->info('SMS status entry submitted', [
            '_module'      => 'core',
            'applicant_id' => $applicant->id,
            'entry_id'     => $entryId,
        ]);
        return $this->jsonSuccess(['entry_id' => $entryId]);
    }

    /**
     * Convenience accessor for the Connection (for the postal_code_changes
     * direct insert above — a small one-off that doesn't warrant its own
     * repository).
     */
    private function connection(): \App\Shared\Persistence\Connection
    {
        return $this->container->make(\App\Shared\Persistence\Connection::class);
    }
}
