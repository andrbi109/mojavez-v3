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
        return $this->render('print', ['applicant' => $applicant, 'statusLog' => $statusLog], 'none');
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
     * Convenience accessor for the Connection (for the postal_code_changes
     * direct insert above — a small one-off that doesn't warrant its own
     * repository).
     */
    private function connection(): \App\Shared\Persistence\Connection
    {
        return $this->container->make(\App\Shared\Persistence\Connection::class);
    }
}
