<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Services\AvatarService;
use App\Modules\Core\Support\ProfileUpdateValidator;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\LicenseFileRepositoryInterface;
use App\Shared\Contracts\PaymentRepositoryInterface;
use App\Shared\Contracts\ServiceTypeRepositoryInterface;
use App\Shared\Contracts\StatusLogRepositoryInterface;
use App\Shared\Contracts\UserAccountRepositoryInterface;
use App\Shared\Contracts\UserNotificationRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;

/**
 * UserPanelController — the applicant storefront panel (`/panel/*`).
 *
 * Phase 9: closes the v2→v3 gap reported by the user (404 after login).
 * v2's UserPanelController had: index/profile/requests/payments/licenses/
 * notifications/tracking. v3 had the auth→/panel redirect in place since
 * Phase 6 but no controller and no routes — so login always landed on 404.
 *
 * Auth model: every action calls requireUserLogin(), which preserves
 * redirect_after_login and redirects to /login if the user session is
 * missing. The current account id is read from `user_id` (set by
 * AuthController::login() line 122).
 *
 * Data sources (all already in v3, no new schema):
 *   - UserAccountRepositoryInterface::getApplications(accountId)
 *   - UserAccountRepositoryInterface::getPayments(accountId)
 *   - UserNotificationRepositoryInterface::findByUser(accountId, 5)
 *   - LicenseFileRepositoryInterface::findByApplicantId(applicantId)
 *   - ServiceTypeRepositoryInterface::findById(id)  (for service titles)
 *   - StatusLogRepositoryInterface::findByApplicantId(id) (for the timeline)
 *
 * The controller deliberately keeps a thin read-only surface for Phase 9 —
 * POST handlers (updateProfile, uploadAvatar, markAllRead) are wired but
 * only markAllRead is implemented here; the others are acknowledged in
 * DEBT_REVIEW D-9-1 for v1.3 (they need an AvatarService + validation work
 * that is out of scope for closing the 404).
 *
 * Phase 12 (v1.3.0) — D-9-1 paid: updateProfile() and uploadAvatar() are
 * now live (no longer 501 stubs). updateProfile() delegates to
 * ProfileUpdateValidator (static) + UserAccountRepositoryInterface::update()
 * (new in Phase 12). uploadAvatar() delegates to AvatarService (new in
 * Phase 12, bound in Core/Module.php). profile() GET now renders an
 * editable form (POST /panel/profile + multipart POST /panel/avatar),
 * with AJAX submit + inline feedback.
 */
final class UserPanelController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly UserAccountRepositoryInterface $userAccounts,
        private readonly UserNotificationRepositoryInterface $notifications,
        private readonly PaymentRepositoryInterface $payments,
        private readonly LicenseFileRepositoryInterface $licenseFiles,
        private readonly ServiceTypeRepositoryInterface $serviceTypes,
        private readonly StatusLogRepositoryInterface $statusLog,
        private readonly AvatarService $avatars,
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly Connection $conn,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /panel — overview dashboard for the logged-in user.
     *
     * Mirrors v2 UserPanelController::index(): a stats row (total / pending /
     * issued applications), the latest application with its status timeline,
     * the last few payments, and unread notifications.
     */
    public function index(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();

        $applications = $this->userAccounts->getApplications((int)$account['id']);
        $payments    = $this->userAccounts->getPayments((int)$account['id']);
        $notifs      = $this->notifications->findByUser((int)$account['id'], 5);

        $total   = count($applications);
        $issued  = 0;
        $pending = 0;
        foreach ($applications as $a) {
            $st = (string)($a['current_status'] ?? '');
            if (in_array($st, ['صدور مجوز', 'تحویل مجوز'], true)) {
                $issued++;
            } elseif (!in_array($st, ['لغو', 'صدور مجوز', 'تحویل مجوز'], true)) {
                $pending++;
            }
        }

        $latestApp = $applications[0] ?? null;
        $timeline  = [];
        if ($latestApp) {
            try {
                $timeline = $this->statusLog->findByApplicantId((int)$latestApp['id']);
            } catch (\Throwable) {
                // timeline is a nice-to-have; never fatal.
            }
        }

        $unread = $this->notifications->unreadCount((int)$account['id']);

        return $this->render('user/overview', [
            'title'        => 'پنل کاربری',
            'activeMenu'   => 'overview',
            'account'      => $account,
            'applications' => $applications,
            'payments'     => array_slice($payments, 0, 5),
            'notifications' => $notifs,
            'latestApp'    => $latestApp,
            'timeline'     => $timeline,
            'serviceTypes' => $this->serviceTypeMap(),
            'unreadCount'  => $unread,
            'stats' => [
                'total_apps'   => $total,
                'pending_apps' => $pending,
                'issued_apps'  => $issued,
            ],
        ], 'user_layout');
    }

    /**
     * GET /panel/profile — editable profile form.
     *
     * Phase 12 (v1.3.0): the form POSTs to /panel/profile (updateProfile)
     * via fetch() and the avatar form POSTs to /panel/avatar (uploadAvatar)
     * via fetch() with FormData. The view (`user/profile.php`) renders
     * both forms + a `<div id="profile-feedback">` for inline AJAX
     * messages + a `<script>` block that wires up the submits.
     *
     * Only `username` and `mobile` are actually persisted — the form
     * also offers `email` and `display_name` fields (validated by
     * ProfileUpdateValidator for forward compatibility, but the
     * `user_accounts` schema has no such columns — see D-12-1).
     */
    public function profile(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        return $this->render('user/profile', [
            'title'        => 'پروفایل',
            'activeMenu'   => 'profile',
            'account'      => $account,
            'avatarUrl'    => $this->avatarUrlFor($account),
            'unreadCount'  => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /**
     * POST /panel/profile — validate + persist profile edits.
     *
     * Delegates to ProfileUpdateValidator (static). On validation
     * failure: 422 with Persian `errors[]`. On success: 200 with
     * `success:true` and the persisted `clean` fields (so the client
     * can refresh the form values).
     *
     * Only `username` and `mobile` are actually persisted — `email`
     * and `display_name` are validated for forward compatibility but
     * the `user_accounts` table has no such columns (D-12-1).
     */
    public function updateProfile(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $result = ProfileUpdateValidator::validate(
            $request->body(),
            $this->userAccounts,
            (int)$account['id']
        );
        if (!$result['valid']) {
            return Response::json(
                ['success' => false, 'errors' => $result['errors']],
                422
            );
        }
        // Persist only the safe-to-update columns. ProfileUpdateValidator
        // may also return email/display_name in clean[] — they're silently
        // dropped by update()'s whitelist.
        $this->userAccounts->update((int)$account['id'], $result['clean']);
        $this->logger->info('User updated profile', [
            '_module'    => 'core',
            'account_id' => $account['id'],
            'fields'     => array_keys($result['clean']),
        ]);
        return Response::json([
            'success' => true,
            'clean'   => $result['clean'],
        ]);
    }

    /**
     * POST /panel/avatar — accept a multipart avatar upload, resize +
     * store via AvatarService. Returns JSON success/error.
     */
    public function uploadAvatar(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $file = $_FILES['avatar'] ?? null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return Response::json(
                ['success' => false, 'error' => 'no_file'],
                422
            );
        }
        try {
            $result = $this->avatars->upload((int)$account['id'], $file);
            return Response::json(['success' => true, 'data' => $result]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(
                ['success' => false, 'error' => $e->getMessage()],
                422
            );
        } catch (\Throwable $e) {
            $this->logger->error('Avatar upload failed', [
                '_module' => 'core',
                'error'   => $e->getMessage(),
            ]);
            return Response::json(
                ['success' => false, 'error' => 'server_error'],
                500
            );
        }
    }

    /**
     * GET /panel/requests — list of the user's applications.
     */
    public function requests(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $applications = $this->userAccounts->getApplications((int)$account['id']);
        return $this->render('user/requests', [
            'title'        => 'درخواست‌های من',
            'activeMenu'   => 'requests',
            'account'      => $account,
            'applications' => $applications,
            'serviceTypes' => $this->serviceTypeMap(),
            'unreadCount'  => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /**
     * GET /panel/payments — list of the user's payments.
     */
    public function payments(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $rows = $this->userAccounts->getPayments((int)$account['id']);
        return $this->render('user/payments', [
            'title'      => 'پرداخت‌های من',
            'activeMenu' => 'payments',
            'account'    => $account,
            'payments'   => $rows,
            'unreadCount' => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /**
     * GET /panel/licenses — issued licenses for the user's applications.
     */
    public function licenses(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $applications = $this->userAccounts->getApplications((int)$account['id']);

        // Attach the license file (if any) to each issued application.
        $rows = [];
        foreach ($applications as $a) {
            $license = null;
            if (in_array((string)($a['current_status'] ?? ''), ['صدور مجوز', 'تحویل مجوز'], true)) {
                try {
                    $license = $this->licenseFiles->findByApplicantId((int)$a['id']);
                } catch (\Throwable) {
                    // ignore — license file lookup is best-effort
                }
            }
            $a['license'] = $license;
            $rows[] = $a;
        }

        return $this->render('user/licenses', [
            'title'        => 'مجوزهای من',
            'activeMenu'   => 'licenses',
            'account'      => $account,
            'applications' => $rows,
            'serviceTypes' => $this->serviceTypeMap(),
            'unreadCount'  => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /**
     * GET /panel/notifications — full list of the user's notifications.
     */
    public function notifications(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $rows = $this->notifications->findByUser((int)$account['id']);
        return $this->render('user/notifications', [
            'title'         => 'اعلان‌ها',
            'activeMenu'    => 'notifications',
            'account'       => $account,
            'notifications' => $rows,
            'unreadCount'   => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /**
     * POST /panel/notifications/read — mark every unread notification as read.
     */
    public function markAllRead(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $this->notifications->markAllRead((int)$account['id']);
        return $this->redirect('/panel/notifications');
    }

    /**
     * GET /panel/tracking — quick tracking form embedded in the panel.
     */
    public function tracking(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        return $this->render('user/tracking', [
            'title'      => 'پیگیری درخواست',
            'activeMenu' => 'tracking',
            'account'    => $account,
            'unreadCount' => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /**
     * POST /panel/requests/{id}/delete — let the user delete their own
     * incomplete/unwanted application.
     *
     * Phase 46 (v2.1.2) — closes the v2→v3 gap reported by the user
     * (no way to discard an unwanted draft). Strictly scoped:
     *   - The applicant's user_account_id MUST match the current user's
     *     session user_id (no cross-account deletes).
     *   - current_status MUST be in ['پیش‌نویس', 'تکمیل اطلاعات'] (Draft
     *     or InfoComplete — i.e. pre-issue + the user hasn't pushed it
     *     forward yet). Anything further along the workflow is
     *     non-deletable by the user (the admin can hard-delete via
     *     applicant admin tools if absolutely needed).
     *   - The applicant row is SOFT-deleted (deleted_at = NOW()) — the
     *     audit trail stays intact.
     *   - Related rows in property_details, job_details, documents,
     *     status_log are HARD-deleted (cascade) — they have
     *     ON DELETE CASCADE FKs but soft-delete on applicants won't
     *     fire those, so we delete them manually here. This matches
     *     the spec wording: "soft-delete applicants, cascade the rest".
     *   - On success: flash a success message and redirect to
     *     /panel/requests. On any authorization failure: flash an
     *     error and redirect to /panel/requests (no 403 page — the
     *     user might have hit a stale "delete" button on an old
     *     page render).
     */
    public function deleteApplication(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $applicantId = (int)($params['id'] ?? 0);

        $applicant = $this->applicants->findById($applicantId);
        if ($applicant === null) {
            $this->flash('درخواست مورد نظر یافت نشد.', 'danger');
            return $this->redirect('/panel/requests');
        }
        // Authorization: the applicant's user_account_id must match the
        // current user's session user_id. findById() does NOT filter by
        // user_account_id — explicit check is required.
        if ($applicant->userAccountId === null
            || (int)$applicant->userAccountId !== (int)$account['id']
        ) {
            $this->flash('شما اجازهٔ حذف این درخواست را ندارید.', 'danger');
            return $this->redirect('/panel/requests');
        }
        // State guard: only Draft ('پیش‌نویس') and InfoComplete
        // ('تکمیل اطلاعات') are deletable. The isPreIssue() check would
        // also include AddressVerification, FieldExpertReview, etc. — too
        // permissive. Use the explicit two-value whitelist from the spec.
        $deletable = ['پیش‌نویس', 'تکمیل اطلاعات'];
        if (!in_array($applicant->currentStatus->value, $deletable, true)) {
            $this->flash(
                'حذف درخواست تنها در وضعیت «پیش‌نویس» یا «تکمیل اطلاعات» ممکن است.',
                'danger'
            );
            return $this->redirect('/panel/requests');
        }

        // Soft-delete the applicant + hard-delete the related rows
        // (property_details, job_details, documents, status_log).
        // Run inside a transaction so a partial delete never happens —
        // either the whole case is gone or none of it.
        try {
            $this->conn->transactional(function () use ($applicantId): void {
                $this->conn->execute(
                    'DELETE FROM property_details WHERE applicant_id = ?',
                    [$applicantId]
                );
                $this->conn->execute(
                    'DELETE FROM job_details WHERE applicant_id = ?',
                    [$applicantId]
                );
                $this->conn->execute(
                    'DELETE FROM documents WHERE applicant_id = ?',
                    [$applicantId]
                );
                $this->conn->execute(
                    'DELETE FROM status_log WHERE applicant_id = ?',
                    [$applicantId]
                );
                $this->applicants->softDelete($applicantId);
            });
        } catch (\Throwable $e) {
            $this->logger->error('User delete application failed', [
                '_module'      => 'core',
                'applicant_id' => $applicantId,
                'account_id'   => $account['id'],
                'error'        => $e->getMessage(),
            ]);
            $this->flash(
                'حذف درخواست با خطا روبه‌رو شد. لطفاً دوباره تلاش کنید.',
                'danger'
            );
            return $this->redirect('/panel/requests');
        }

        $this->logger->info('User deleted their own application', [
            '_module'      => 'core',
            'applicant_id' => $applicantId,
            'account_id'   => $account['id'],
            'tracking_code' => $applicant->trackingCode,
        ]);
        $this->flash('درخواست با موفقیت حذف شد.', 'success');
        return $this->redirect('/panel/requests');
    }

    /**
     * POST /panel/change-postal-code — request a postal-code change
     * for one of the user's own applications.
     *
     * Phase 46 (v2.1.2) — lets the user request a postal-code change
     * from the panel without contacting support. Strictly scoped:
     *   - The applicant is located by tracking_code (submitted by the
     *     form) and must belong to the current user (user_account_id
     *     matches the session user_id).
     *   - The new postal code must be exactly 10 digits (Iran standard).
     *   - A row is inserted into postal_code_changes with
     *     status='در انتظار' for admin review (the admin approves
     *     via /admin/postal-requests/approve, which already exists).
     *   - The old postal_code from the applicants row is captured in
     *     postal_code_changes.old_postal_code for the audit trail.
     *   - On success: flash + redirect to /panel/requests.
     *   - On any failure: flash error + redirect to /panel/requests.
     */
    public function changePostalCode(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $trackingCode = trim((string)$request->input('tracking_code', ''));
        $newPostalCode = trim((string)$request->input('new_postal_code', ''));

        if ($trackingCode === '') {
            $this->flash('کد پیگیری الزامی است.', 'danger');
            return $this->redirect('/panel/requests');
        }
        // Iran postal codes are exactly 10 digits. The form also has
        // pattern="\d{10}" on the client side; the server enforces too.
        if (!preg_match('/^\d{10}$/', $newPostalCode)) {
            $this->flash('کد پستی باید دقیقاً ۱۰ رقم باشد.', 'danger');
            return $this->redirect('/panel/requests');
        }

        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            $this->flash('درخواستی با این کد پیگیری یافت نشد.', 'danger');
            return $this->redirect('/panel/requests');
        }
        // Authorization: the applicant must belong to the current user.
        if ($applicant->userAccountId === null
            || (int)$applicant->userAccountId !== (int)$account['id']
        ) {
            $this->flash('شما اجازهٔ تغییر کد پستی این درخواست را ندارید.', 'danger');
            return $this->redirect('/panel/requests');
        }
        // No-op guard: if the new code equals the current code, refuse.
        $oldPostalCode = (string)($applicant->postalCode ?? '');
        if ($oldPostalCode === $newPostalCode) {
            $this->flash('کد پستی جدید با کد پستی فعلی یکی است.', 'warning');
            return $this->redirect('/panel/requests');
        }

        try {
            $this->conn->execute(
                'INSERT INTO postal_code_changes '
                . '(applicant_id, old_postal_code, new_postal_code, status, requested_at) '
                . 'VALUES (?, ?, ?, ?, NOW())',
                [
                    $applicant->id,
                    $oldPostalCode,
                    $newPostalCode,
                    'در انتظار',
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Postal-code change insert failed', [
                '_module'      => 'core',
                'applicant_id' => $applicant->id,
                'account_id'   => $account['id'],
                'error'        => $e->getMessage(),
            ]);
            $this->flash(
                'ثبت درخواست تغییر کد پستی با خطا روبه‌رو شد.',
                'danger'
            );
            return $this->redirect('/panel/requests');
        }

        $this->logger->info('User requested postal-code change', [
            '_module'        => 'core',
            'applicant_id'   => $applicant->id,
            'account_id'     => $account['id'],
            'tracking_code'  => $trackingCode,
            'old_postal_code' => $oldPostalCode,
            'new_postal_code' => $newPostalCode,
        ]);
        $this->flash(
            'درخواست تغییر کد پستی ثبت شد و پس از بررسی مدیر تأیید خواهد شد.',
            'success'
        );
        return $this->redirect('/panel/requests');
    }

    /* ------------------------------------------------------------------ */

    /**
     * Pull the current account array from the session, or redirect to /login.
     *
     * @return array<string,mixed>
     */
    private function currentAccount(): array
    {
        $id = (int)$this->session->get('user_id');
        $account = $this->userAccounts->findById($id);
        if ($account === null) {
            // Session said user_id but the row is gone — force re-login.
            $this->session->remove('user_logged_in');
            $this->session->remove('user_id');
            $this->redirect('/login');
        }
        return $account;
    }

    /**
     * Flash a one-shot message for the next render — stored in the
     * session under `panel_flash` as ['message' => string, 'type' => string].
     * The view (user/requests.php) consumes it once via
     * `$this->session->get('panel_flash')` + `$this->session->remove('panel_flash')`.
     *
     * Phase 46 (v2.1.2) — used by deleteApplication() and changePostalCode()
     * to surface success/error feedback after a redirect to /panel/requests.
     *
     * @param string $message Persian message body.
     * @param 'success'|'danger'|'warning'|'info' $type Bootstrap alert color.
     */
    private function flash(string $message, string $type = 'info'): void
    {
        $this->session->set('panel_flash', [
            'message' => $message,
            'type'    => $type,
        ]);
    }

    /**
     * Build a {id => service_type} lookup so views can render service titles
     * without N+1 queries.
     *
     * @return array<int,string>
     */
    private function serviceTypeMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            try {
                foreach ($this->serviceTypes->getAll() as $s) {
                    $map[(int)$s['id']] = (string)($s['title'] ?? $s['name'] ?? '—');
                }
            } catch (\Throwable) {
                // service types are a display nicety, never fatal.
            }
        }
        return $map;
    }

    /**
     * Resolve the avatar URL for an account, or null if no avatar is set.
     *
     * Phase 12 (v1.3.0): the avatar document id lives on
     * `user_accounts.avatar_document_id` (FK→documents.id, set by
     * AvatarService::upload). There is currently NO public route that
     * serves the avatar file from disk — that's tracked as a known
     * limitation (the file is served by the front controller through
     * a future `/panel/avatar/file` route, or by reading
     * `documents.file_path` directly when the admin/user views the
     * profile). For now we return null and the view falls back to the
     * initial-letter avatar circle.
     *
     * @param array<string,mixed> $account
     */
    private function avatarUrlFor(array $account): ?string
    {
        $docId = (int)($account['avatar_document_id'] ?? 0);
        if ($docId <= 0) {
            return null;
        }
        // Best-effort: if AvatarService can resolve a real on-disk path,
        // we surface a relative URL to it (the file lives under
        // storage/uploads/avatars/account-{id}.jpg — which is outside
        // the web root for security). The future `/panel/avatar/file`
        // route will serve it through the front controller; for now
        // return null and let the view fall back to the letter avatar.
        try {
            $path = $this->avatars->getPath($docId);
            if ($path !== null && is_file($path)) {
                // Return a cache-busting URL once the serving route exists.
                // For now this stays null — the view shows the letter avatar.
                return null;
            }
        } catch (\Throwable) {
            // best-effort — never break the profile page over a missing file.
        }
        return null;
    }
}
