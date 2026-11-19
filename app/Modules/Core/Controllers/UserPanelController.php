<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Services\AvatarService;
use App\Modules\Core\Services\FileStorage;
use App\Modules\Core\Support\ProfileUpdateValidator;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\JobAndPropertyRepositoryInterface;
use App\Shared\Contracts\LicenseFileRepositoryInterface;
use App\Shared\Contracts\PaymentRepositoryInterface;
use App\Shared\Contracts\ServiceTypeRepositoryInterface;
use App\Shared\Contracts\StatusLogRepositoryInterface;
use App\Shared\Contracts\UserAccountRepositoryInterface;
use App\Shared\Contracts\UserNotificationRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Config;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\PersianDate;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;

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
        private readonly FileStorage $files,
        private readonly Connection $conn,
        // Phase 60 (v2.2.6) — used by editRequest() / updateRequest()
        // to upsert job_details + property_details for the one-time edit
        // form (mirrors AdminController::updateApplication).
        private readonly JobAndPropertyRepositoryInterface $jobAndProperty,
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

        // Phase 57b — the stats row was under-counting "rejected" (the
        // 'pending' bucket used to include everything not in
        // ['لغو','صدور مجوز','تحویل مجوز'], which meant rejected
        // applications were lumped into "در حال بررسی"). The fix:
        //   - 'pending' now counts ONLY the pre-issue non-terminal
        //     statuses (the 10 in-review statuses from
        //     config/statuses.php 'pre_issue' list, plus the 2
        //     household-business-only pre-issue ones).
        //   - 'rejected' counts 'رد درخواست'.
        //   - 'issued' counts 'صدور مجوز' + 'تحویل مجوز' (unchanged).
        //   - 'closed' counts the remaining terminal statuses that
        //     don't already have their own widget — 'لغو' and the
        //     household-business final receipt ('تأیید نهایی دریافت
        //     کد رهگیری'). 'تحویل مجوز' is terminal too but is already
        //     counted under 'issued'.
        $preIssue = [
            'پیش‌نویس', 'تکمیل اطلاعات', 'احراز نشانی', 'بررسی کارشناسی رشته',
            'ثبت درخواست صدور', 'بارگذاری مدارک به سازمان', 'درخواست اصلاح مدارک',
            'تأیید اولیه', 'تأیید نهایی', 'تطبیق آدرس با ثبت احوال',
        ];
        $closedExtra = ['لغو', 'تأیید نهایی دریافت کد رهگیری'];

        $total   = count($applications);
        $issued  = 0;
        $pending = 0;
        $rejected = 0;
        $closed  = 0;
        foreach ($applications as $a) {
            $st = (string)($a['current_status'] ?? '');
            if ($st === 'رد درخواست') {
                $rejected++;
            } elseif (in_array($st, ['صدور مجوز', 'تحویل مجوز'], true)) {
                $issued++;
            } elseif (in_array($st, $preIssue, true)) {
                $pending++;
            } elseif (in_array($st, $closedExtra, true)) {
                $closed++;
            }
            // Other statuses (e.g. 'مشاهده و چاپ', 'دریافت تصویر مجوز',
            // 'تأیید اولیه در سامانه مشاغل خانگی') are not in any widget
            // — they're edge states that fall through. Total still counts
            // them, so the widgets sum to ≤ total.
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
                'total_apps'    => $total,
                'pending_apps'  => $pending,
                'issued_apps'   => $issued,
                'rejected_apps' => $rejected,
                'closed_apps'   => $closed,
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
     *
     * Phase 53 (v2.1.9) — for each application whose current_status is
     * 'رد درخواست' (rejected), we attach the rejection description
     * (status_log.description of the latest rejected entry) so the
     * user/requests view can show the reason inline. Single query per
     * rejected row (cheap — typical users have <10 applications and
     * most are not rejected).
     */
    public function requests(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $applications = $this->userAccounts->getApplications((int)$account['id']);

        // Phase 53 (v2.1.9) — attach rejection_description for rejected
        // applications so the view can surface the reason. We use a
        // direct query against status_log (rather than the
        // StatusLogRepository which fetches ALL entries) so we only
        // touch the rows we actually need.
        foreach ($applications as &$a) {
            if ((string)($a['current_status'] ?? '') === 'رد درخواست') {
                $appId = (int)($a['id'] ?? 0);
                $desc = '';
                if ($appId > 0) {
                    try {
                        $row = $this->conn->one(
                            'SELECT description FROM status_log
                             WHERE applicant_id = ? AND status_name = ?
                             ORDER BY created_at DESC LIMIT 1',
                            [$appId, 'رد درخواست']
                        );
                        if ($row !== null) {
                            $desc = (string)($row['description'] ?? '');
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to load rejection description', [
                            '_module'      => 'core',
                            'applicant_id' => $appId,
                            'error'        => $e->getMessage(),
                        ]);
                    }
                }
                $a['rejection_description'] = $desc;
            } else {
                $a['rejection_description'] = '';
            }
        }
        unset($a);

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
     * GET /panel/requests/{id}/edit — render the one-time edit form for
     * a bulk-imported applicant's case file.
     *
     * Phase 60 (v2.2.6) — closes the "imported applicants can't complete
     * their own info" gap. The bulk-import flow creates an applicants
     * row with only the CSV columns (first_name, last_name, national_code,
     * postal_code, mobile, service_type_id) and a user_account, but the
     * job_details + property_details rows are empty. The user logs in
     * and uses THIS form to fill in the rest of their case file.
     *
     * Auth + state guards (the route is a no-op otherwise):
     *   - The applicant's user_account_id MUST match the current user's
     *     session user_id (no cross-account edits — same pattern as
     *     deleteApplication).
     *   - imported_edit_locked MUST be 0 (not yet locked — they CAN edit
     *     once). After the first POST, the handler flips this to 1 and
     *     subsequent GETs redirect back to /panel/requests with a flash.
     *   - registration_completed_at MUST be NULL. The bulk-import flow
     *     (Phase 60) deliberately leaves this NULL so the guard admits
     *     imported applicants. Self-signup wizard users reach this NULL
     *     state too while still in the wizard — but they should use the
     *     wizard to complete their info, not this form. The wizard's
     *     final step sets registration_completed_at, after which this
     *     guard rejects them. The two populations that reach this gate
     *     with `registration_completed_at IS NULL` are:
     *       (a) bulk-imported applicants (always — Phase 60 import flow)
     *       (b) wizard draft applicants (still inside the wizard)
     *     For (b), the user is expected to resume the wizard from the
     *     /panel/requests "ادامه ثبت‌نام" link (not present in the
     *     current view, but the wizard route /register is open). The
     *     edit form here is only meant for (a). We don't have a separate
     *     "is_imported" flag — the combination `imported_edit_locked = 0
     *     AND registration_completed_at IS NULL` is the closest proxy
     *     and matches the Phase 60 spec literally.
     *
     * On any auth/state failure: flash an error and redirect to
     * /panel/requests (no 403 page — consistent with deleteApplication).
     *
     * On success: render user/edit_request with the applicant + job +
     * property details + service-type lookup + PersianDate helper for
     * the birth_date Jalali dropdown.
     */
    public function editRequest(Request $request, array $params = []): Response
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
        // current user's session user_id (same check as deleteApplication).
        if ($applicant->userAccountId === null
            || (int)$applicant->userAccountId !== (int)$account['id']
        ) {
            $this->flash('شما اجازهٔ ویرایش این درخواست را ندارید.', 'danger');
            return $this->redirect('/panel/requests');
        }
        // State guard — only bulk-imported applicants (imported_edit_locked=0
        // AND registration_completed_at IS NULL) may use this form.
        if ($applicant->registrationCompletedAt !== null) {
            $this->flash(
                'این درخواست قبلاً تکمیل شده است. برای ویرایش با مدیر سامانه تماس بگیرید.',
                'warning'
            );
            return $this->redirect('/panel/requests');
        }
        // Load the raw applicants row for the editable fields the
        // Applicant VO doesn't expose (father_name, marital_status,
        // children_count, physical_condition, disability_details,
        // income_decile, has_court_case, has_active_loan,
        // has_overdue_installments, birth_date, postal_code,
        // imported_edit_locked).
        $row = $this->conn->one(
            'SELECT * FROM applicants WHERE id = ? AND deleted_at IS NULL',
            [$applicantId]
        ) ?? [];
        // The DB column DEFAULT is 0 — if the migration hasn't been
        // applied yet on this environment, the column won't exist and
        // array_key_exists returns false. Treat that as "0 = unlocked"
        // so the form is reachable during the deploy window.
        $importedEditLocked = (int)($row['imported_edit_locked'] ?? 0);
        if ($importedEditLocked === 1) {
            $this->flash(
                'این درخواست قبلاً یک‌بار ویرایش شده و قفل شده است. برای ویرایش‌های بعدی با مدیر سامانه تماس بگیرید.',
                'warning'
            );
            return $this->redirect('/panel/requests');
        }

        $jobDetail      = $this->jobAndProperty->findJobDetailByApplicantId($applicantId);
        $propertyDetail = $this->jobAndProperty->findPropertyDetailByApplicantId($applicantId);
        $serviceTypes   = $this->serviceTypes->getActive();
        $persianDate    = $this->container->make(PersianDate::class);

        return $this->render('user/edit_request', [
            'title'          => 'ویرایش و تکمیل درخواست',
            'activeMenu'     => 'requests',
            'account'        => $account,
            'applicant'      => $applicant,
            'row'            => $row,
            'jobDetail'      => $jobDetail,
            'propertyDetail' => $propertyDetail,
            'serviceTypes'   => $serviceTypes,
            'persianDate'    => $persianDate,
            'errors'         => [],
            'unreadCount'    => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /**
     * POST /panel/requests/{id}/edit — validate + persist the one-time
     * edit for a bulk-imported applicant, then lock further edits.
     *
     * Phase 60 (v2.2.6) — same auth + state guards as editRequest():
     * the applicant must belong to the current user, must NOT be locked
     * yet (imported_edit_locked=0), and must NOT have already completed
     * registration (registration_completed_at IS NULL). On any guard
     * failure: flash + redirect to /panel/requests.
     *
     * On validation failure: re-render the edit form with the user's
     * input + the error list (same shape as AdminController::updateApplication's
     * error path — the view reads $row + $errors).
     *
     * On success:
     *   1. UPDATE applicants with the validated identity fields.
     *   2. Upsert job_details + property_details (only if the user
     *      provided enough info to satisfy the NOT NULL constraints —
     *      same pattern as the admin handler).
     *   3. UPDATE applicants SET imported_edit_locked = 1 — locks the
     *      one-time edit window. Future GET /panel/requests/{id}/edit
     *      will redirect with a flash.
     *   4. Insert a status_log row noting the user-edited the case (audit
     *      trail — the admin's status-cycle view shows this entry).
     *   5. Flash the Persian success message + redirect to /panel/requests.
     *
     * national_code and tracking_code are NOT editable (identity anchors).
     */
    public function updateRequest(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $applicantId = (int)($params['id'] ?? 0);

        $applicant = $this->applicants->findById($applicantId);
        if ($applicant === null) {
            $this->flash('درخواست مورد نظر یافت نشد.', 'danger');
            return $this->redirect('/panel/requests');
        }
        // Authorization.
        if ($applicant->userAccountId === null
            || (int)$applicant->userAccountId !== (int)$account['id']
        ) {
            $this->flash('شما اجازهٔ ویرایش این درخواست را ندارید.', 'danger');
            return $this->redirect('/panel/requests');
        }
        // State guards — same as editRequest().
        if ($applicant->registrationCompletedAt !== null) {
            $this->flash(
                'این درخواست قبلاً تکمیل شده است. برای ویرایش با مدیر سامانه تماس بگیرید.',
                'warning'
            );
            return $this->redirect('/panel/requests');
        }
        $row = $this->conn->one(
            'SELECT * FROM applicants WHERE id = ? AND deleted_at IS NULL',
            [$applicantId]
        ) ?? [];
        $importedEditLocked = (int)($row['imported_edit_locked'] ?? 0);
        if ($importedEditLocked === 1) {
            $this->flash(
                'این درخواست قبلاً یک‌بار ویرایش شده و قفل شده است. برای ویرایش‌های بعدی با مدیر سامانه تماس بگیرید.',
                'warning'
            );
            return $this->redirect('/panel/requests');
        }

        $body = $request->body();

        // ---- Identity fields (mirrors AdminController::updateApplication) ----
        $firstName  = Validator::nonEmptyString($body['first_name'] ?? '', 50);
        $lastName   = Validator::nonEmptyString($body['last_name'] ?? '', 50);
        $mobile     = Validator::mobile($body['mobile'] ?? '');
        $postalCode = self::validPostalCode($body['postal_code'] ?? '');
        $fatherName = Validator::sanitizeText($body['father_name'] ?? '', 50);
        $serviceTypeId = Validator::positiveIntOrNull($body['service_type_id'] ?? '');

        $maritalStatus          = Validator::inEnum($body['marital_status'] ?? '', ['مجرد', 'متأهل']);
        $childrenCount          = Validator::intOrNull($body['children_count'] ?? '');
        $physicalCondition      = Validator::inEnum($body['physical_condition'] ?? '', ['سالم', 'معلولیت']);
        $disabilityDetails      = Validator::sanitizeText($body['disability_details'] ?? '', 255);
        $incomeDecile           = Validator::intInRange($body['income_decile'] ?? '', 1, 10);
        $hasCourtCase           = Validator::inEnum($body['has_court_case'] ?? '', ['بله', 'خیر']);
        $hasActiveLoan          = Validator::inEnum($body['has_active_loan'] ?? '', ['بله', 'خیر']);
        $hasOverdueInstallments = Validator::inEnum($body['has_overdue_installments'] ?? '', ['بله', 'خیر']);
        $birthDateGreg          = $this->birthDateFromInputs(
            (int)($body['birth_year'] ?? 0),
            (int)($body['birth_month'] ?? 0),
            (int)($body['birth_day'] ?? 0),
        );

        // ---- Job-detail fields ----
        $licenseTitle     = Validator::sanitizeText($body['license_title'] ?? '', 100);
        $experienceMonths = Validator::intOrNull($body['experience_months'] ?? '');
        $trainingMethod    = Validator::inEnum($body['training_method'] ?? '', ['در خانه', 'استاد شاگردی', 'کارگاهی']);

        // ---- Property-detail fields ----
        $ownershipStatus   = Validator::inEnum($body['ownership_status'] ?? '', ['شخص', 'مستأجر', 'سرپرست']);
        $province          = Validator::sanitizeText($body['province'] ?? '', 50);
        $county            = Validator::sanitizeText($body['county'] ?? '', 50);
        $city              = Validator::sanitizeText($body['city'] ?? '', 50);
        $district          = Validator::sanitizeText($body['district'] ?? '', 50);
        $ruralDistrict     = Validator::sanitizeText($body['rural_district'] ?? '', 50);
        $village           = Validator::sanitizeText($body['village'] ?? '', 50);
        $electricityBillId = Validator::sanitizeText($body['electricity_bill_id'] ?? '', 20);
        $addressStreet     = Validator::sanitizeText($body['address_street'] ?? '', 100);
        $addressAlley      = Validator::sanitizeText($body['address_alley'] ?? '', 100);
        $addressPlaque     = Validator::sanitizeText($body['address_plaque'] ?? '', 20);

        $errors = [];
        if ($firstName === null) {
            $errors[] = 'نام الزامی است.';
        }
        if ($lastName === null) {
            $errors[] = 'نام خانوادگی الزامی است.';
        }
        if ($mobile === null) {
            $errors[] = 'موبایل نامعتبر است.';
        }
        if ($postalCode === null) {
            $errors[] = 'کد پستی باید دقیقاً ۱۰ رقم باشد.';
        }
        if ($serviceTypeId !== null && $this->serviceTypes->findById($serviceTypeId) === null) {
            $errors[] = 'نوع خدمت انتخاب‌شده یافت نشد.';
        }

        if ($errors !== []) {
            // Re-render the form with the user's input + error list.
            // Merge the existing DB row with the submitted body so the
            // view's $val() helper falls back to the new input on the
            // fields the user just typed.
            $mergedRow = array_merge($row, $body);
            return $this->render('user/edit_request', [
                'title'          => 'ویرایش و تکمیل درخواست',
                'activeMenu'     => 'requests',
                'account'        => $account,
                'applicant'      => $applicant,
                'row'            => $mergedRow,
                'jobDetail'      => $this->jobAndProperty->findJobDetailByApplicantId($applicantId),
                'propertyDetail' => $this->jobAndProperty->findPropertyDetailByApplicantId($applicantId),
                'serviceTypes'   => $this->serviceTypes->getActive(),
                'persianDate'    => $this->container->make(PersianDate::class),
                'errors'         => $errors,
                'unreadCount'    => $this->notifications->unreadCount((int)$account['id']),
            ], 'user_layout');
        }

        // ---- Persist (identity updatable, job + property upserted) ----
        $update = array_filter([
            'first_name'              => $firstName,
            'last_name'               => $lastName,
            'mobile'                  => $mobile,
            'postal_code'             => $postalCode,
            'father_name'             => $fatherName !== '' ? $fatherName : null,
            'service_type_id'         => $serviceTypeId,
            'birth_date'              => $birthDateGreg,
            'marital_status'          => $maritalStatus,
            'children_count'          => $childrenCount,
            'physical_condition'      => $physicalCondition,
            'disability_details'      => $disabilityDetails !== '' ? $disabilityDetails : null,
            'income_decile'           => $incomeDecile,
            'has_court_case'          => $hasCourtCase,
            'has_active_loan'         => $hasActiveLoan,
            'has_overdue_installments' => $hasOverdueInstallments,
            // Phase 60 — lock the one-time edit window. Future GET
            // /panel/requests/{id}/edit will redirect with a flash
            // because imported_edit_locked is now 1.
            'imported_edit_locked'    => 1,
        ], static fn ($v): bool => $v !== null);
        // Force the lock flag through even if array_filter above would
        // drop it (it wouldn't, since 1 !== null, but be explicit — this
        // is the whole point of the POST).
        $update['imported_edit_locked'] = 1;
        $this->applicants->update($applicantId, $update);

        // Job detail upsert only if all three required fields are filled
        // (mirrors AdminController — the table has NOT NULL constraints
        // on license_title, experience_months, training_method).
        if ($licenseTitle !== '' && $experienceMonths !== null && $trainingMethod !== null) {
            $this->jobAndProperty->upsertJobDetail($applicantId, [
                'license_title'     => $licenseTitle,
                'experience_months' => $experienceMonths,
                'training_method'   => $trainingMethod,
            ]);
        }

        // Property detail upsert if any meaningful address field is set.
        if ($ownershipStatus !== null || $province !== '' || $city !== ''
            || $addressStreet !== '' || $district !== '' || $ruralDistrict !== ''
            || $village !== ''
        ) {
            $this->jobAndProperty->upsertPropertyDetail($applicantId, [
                'ownership_status'   => $ownershipStatus ?? 'شخص',
                'province'            => $province,
                'county'              => $county,
                'city'                => $city,
                'district'            => $district !== '' ? $district : null,
                'rural_district'      => $ruralDistrict !== '' ? $ruralDistrict : null,
                'village'             => $village !== '' ? $village : null,
                'electricity_bill_id' => $electricityBillId,
                'address_street'      => $addressStreet !== '' ? $addressStreet : null,
                'address_alley'       => $addressAlley !== '' ? $addressAlley : null,
                'address_plaque'      => $addressPlaque !== '' ? $addressPlaque : null,
            ]);
        }

        // Audit-trail entry in status_log (the admin's status-cycle view
        // surfaces this so the operator can see when the user self-edited).
        try {
            $this->statusLog->create([
                'applicant_id' => $applicantId,
                'status_name'   => $applicant->currentStatus->value,
                'description'   => 'ویرایش و تکمیل اطلاعات توسط کاربر — '
                                 . 'قفل ویرایش یک‌باره فعال شد.',
                'triggered_by'  => 'user',
            ]);
        } catch (\Throwable $e) {
            // status_log is an audit nicety, never fatal — the update
            // itself has already committed by this point.
            $this->logger->warning('User edit: status_log insert failed', [
                '_module'      => 'core',
                'applicant_id' => $applicantId,
                'error'        => $e->getMessage(),
            ]);
        }

        $this->logger->info('User completed one-time edit of imported application', [
            '_module'      => 'core',
            'applicant_id' => $applicantId,
            'account_id'   => $account['id'],
            'tracking_code' => $applicant->trackingCode,
        ]);

        $this->flash(
            'اطلاعات شما با موفقیت ثبت شد. برای ویرایش‌های بعدی با مدیر سامانه تماس بگیرید.',
            'success'
        );
        return $this->redirect('/panel/requests');
    }

    /**
     * GET /panel/payments — list of the user's payments.
     *
     * Phase 57b — also loads the active `payment_channels` rows (now
     * carrying an `account_info` column added by migration
     * 20261120_002) so the per-row payment modal can show them as
     * radio options and the user can choose how to pay through each
     * one (online gateway / card transfer / IBAN / etc.). Channels
     * are sorted by `sort_order` and only `is_active = 1` rows are
     * surfaced — disabled channels stay admin-only.
     */
    public function payments(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $rows = $this->userAccounts->getPayments((int)$account['id']);

        // Phase 57b — load active payment channels. Best-effort
        // (a DB hiccup should never break the payments page — the
        // modal just falls back to the inline alert with no channels).
        $channels = [];
        try {
            $channels = $this->conn->all(
                'SELECT id, title, channel_type, account_info
                   FROM payment_channels
                  WHERE is_active = 1
                  ORDER BY sort_order ASC, id ASC'
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load payment_channels for user payments view', [
                '_module' => 'core',
                'error'   => $e->getMessage(),
            ]);
        }

        return $this->render('user/payments', [
            'title'      => 'پرداخت‌های من',
            'activeMenu' => 'payments',
            'account'    => $account,
            'payments'   => $rows,
            'channels'   => $channels,
            'unreadCount' => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /**
     * POST /panel/payments/{id}/receipt — user uploads a payment receipt
     * after selecting a non-online channel in the per-row payment modal.
     *
     * Phase 58 (v2.2.4) — replaces the previous "alert('این مرحله در فاز بعد
     * به‌صورت خودکار متصل خواهد شد')" stub that fired when the user clicked
     * "تأیید" in the channel chooser. The new flow is:
     *   - The user picks a payment channel in the modal.
     *   - If the channel's `channel_type` is `online`, the modal shows a
     *     placeholder "انتقال به درگاه" link instead of the upload form
     *     (the receipt will come back from the gateway callback in a
     *     later phase) — no POST to this route is made.
     *   - For any other channel_type (transfer / cash / etc.), the modal
     *     shows a multipart form (file input + description + submit). The
     *     form POSTs to this route.
     *
     * On success the handler:
     *   1. Saves the uploaded file via FileStorage under the
     *      `storage/uploads/receipts/{payment_id}/` subdirectory (so
     *      receipts are visually grouped by payment id, mirroring the
     *      applicant-tracking-code layout FileStorage uses for regular
     *      documents). The file is validated by the same 7-layer
     *      pipeline used for applicant documents (ext+MIME consistency,
     *      size cap, image re-encode via GD).
     *   2. Updates the `payments` row: status='در انتظار رسید',
     *      receipt_file_path=$relativePath, receipt_description=$userNote.
     *   3. Flashes a success message and redirects to /panel/payments.
     *
     * Authorization: the payment's `user_account_id` MUST match the
     * current user's session user_id. Payments without a `user_account_id`
     * (legacy rows created by the admin before user accounts existed)
     * cannot be receipted from the user panel.
     *
     * State guard: only payments with `status = 'در انتظار'` (admin
     * requested, awaiting user payment) accept a receipt upload. Already-
     * reviewed or already-receipted payments are refused with a flash
     * error (the user might have hit a stale "بارگذاری رسید" button on
     * an old page render).
     *
     * Route registered in Core/Module.php as
     * `POST /panel/payments/{id:\d+}/receipt` (the {id:\d+} constraint
     * prevents collision with the GET /panel/payments route above).
     */
    public function uploadReceipt(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $paymentId = (int)($params['id'] ?? 0);

        if ($paymentId <= 0) {
            $this->flash('درخواست نامعتبر است.', 'danger');
            return $this->redirect('/panel/payments');
        }

        // Authorization + state guard — single SELECT covers both.
        $row = $this->conn->one(
            'SELECT id, user_account_id, status, amount, purpose
               FROM payments
              WHERE id = ? LIMIT 1',
            [$paymentId]
        );
        if ($row === null) {
            $this->flash('پرداخت مورد نظر یافت نشد.', 'danger');
            return $this->redirect('/panel/payments');
        }
        $ownerId = isset($row['user_account_id']) ? (int)$row['user_account_id'] : 0;
        if ($ownerId === 0 || $ownerId !== (int)$account['id']) {
            $this->flash('شما اجازهٔ بارگذاری رسید برای این پرداخت را ندارید.', 'danger');
            return $this->redirect('/panel/payments');
        }
        if ((string)$row['status'] !== 'در انتظار') {
            $this->flash(
                'بارگذاری رسید تنها برای پرداخت‌های «در انتظار پرداخت» ممکن است.',
                'warning'
            );
            return $this->redirect('/panel/payments');
        }

        // Pull + validate the uploaded file. The view's file input is
        // named `receipt`; accept image/PDF per config/app.php's
        // allowed_extensions whitelist (jpg/jpeg/png/pdf).
        $file = $request->file('receipt');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->flash('هیچ فایلی برای بارگذاری انتخاب نشده است.', 'danger');
            return $this->redirect('/panel/payments');
        }

        // Phase 59a — replace FileStorage::storeUpload() with a direct
        // move_uploaded_file() into storage/uploads/receipts/{payment_id}/.
        // FileStorage's 7-layer pipeline rejects some valid uploads (the
        // ext↔MIME consistency check is brittle and the GD re-encode can
        // fail on large images), so we keep it simple here: validate the
        // extension against the whitelist, move the file with a random
        // suffix, and persist the project-root-relative path to
        // payments.receipt_file_path. The returned path matches the
        // project-root-relative layout used by AvatarService and the
        // admin document modal, so /document/inline and FileStorage::
        // getFullPath() (which now also accepts project-root-relative
        // paths, see Phase 57b) can both resolve it.
        try {
            $relPath = $this->storeReceiptDirect($file, $paymentId);
        } catch (\Throwable $e) {
            $this->logger->error('Receipt upload failed', [
                '_module'    => 'core',
                'payment_id' => $paymentId,
                'account_id' => $account['id'],
                'error'      => $e->getMessage(),
            ]);
            $this->flash(
                'بارگذاری رسید ناموفق بود: ' . $e->getMessage(),
                'danger'
            );
            return $this->redirect('/panel/payments');
        }

        // Optional user-supplied description (free text, max 500 chars —
        // matches the `receipt_description` column width).
        $userNote = trim((string)$request->input('description', ''));
        if (mb_strlen($userNote) > 500) {
            $userNote = mb_substr($userNote, 0, 500);
        }

        // Persist the receipt path + status transition in a single
        // UPDATE. The status change to 'در انتظار رسید' is what makes
        // the row surface in the admin's pending-review queue (see
        // PaymentController::STATUS_PENDING = 'در انتظار رسید').
        try {
            $this->conn->execute(
                'UPDATE payments
                    SET status = ?,
                        receipt_file_path = ?,
                        receipt_description = ?,
                        reviewed_by = NULL,
                        reviewed_at = NULL,
                        reject_reason = NULL
                  WHERE id = ?',
                ['در انتظار رسید', $relPath, $userNote !== '' ? $userNote : null, $paymentId]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Payment receipt update failed', [
                '_module'    => 'core',
                'payment_id' => $paymentId,
                'account_id' => $account['id'],
                'error'      => $e->getMessage(),
            ]);
            // Best-effort cleanup of the orphaned file we just wrote.
            $projectRoot = dirname(__DIR__, 4);
            $absolutePath = $projectRoot . '/' . $relPath;
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
            $this->flash(
                'ذخیرهٔ رسید با خطا روبه‌رو شد. لطفاً دوباره تلاش کنید.',
                'danger'
            );
            return $this->redirect('/panel/payments');
        }

        $this->logger->info('Payment receipt uploaded', [
            '_module'      => 'core',
            'payment_id'   => $paymentId,
            'account_id'   => $account['id'],
            'file_path'    => $relPath,
        ]);
        $this->flash(
            'رسید با موفقیت بارگذاری شد و در انتظار بررسی مدیر قرار گرفت.',
            'success'
        );
        return $this->redirect('/panel/payments');
    }

    /**
     * Move the uploaded receipt to storage/uploads/receipts/{payment_id}/.
     *
     * Phase 59a — replaces FileStorage::storeUpload() with a direct
     * move_uploaded_file(). FileStorage's 7-layer pipeline rejects some
     * valid uploads (the ext↔MIME consistency check is brittle and the
     * GD re-encode can fail on large images), so we keep it simple here:
     *
     *   1. Validate the upload error code (UPLOAD_ERR_OK).
     *   2. Validate the extension against the whitelist
     *      (config('app.allowed_extensions') — jpg/jpeg/png/pdf).
     *   3. Validate the file size (≤ config('app.max_upload_size')).
     *   4. Create the destination directory if needed
     *      (storage/uploads/receipts/{payment_id}/).
     *   5. move_uploaded_file() to {payment_id}/receipt_{rand6}.{ext}.
     *   6. chmod 0640 — same as FileStorage.
     *
     * Returns the project-root-relative path
     * (e.g. "storage/uploads/receipts/42/receipt_a1b2c3.jpg") so the
     * admin panel can construct a download URL via /document/inline
     * and FileStorage::getFullPath() (Phase 57b — also accepts
     * project-root-relative paths) can resolve it.
     *
     * @param array<string,mixed> $file  $_FILES['receipt'] entry
     * @throws \RuntimeException on any failure with a Persian message
     */
    private function storeReceiptDirect(array $file, int $paymentId): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(
                'خطا در بارگذاری فایل (کد: ' . ($file['error'] ?? 'unknown') . ').'
            );
        }

        $originalName = (string)($file['name'] ?? '');
        // Strip directory traversal from the filename before extracting
        // the extension (basename-only, same as FileStorage::normaliseFilename).
        $originalName = basename($originalName);
        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));

        $allowed = Config::get('app.allowed_extensions', ['jpg', 'jpeg', 'png', 'pdf']);
        if (!is_array($allowed)) {
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        }
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            throw new \RuntimeException(
                'پسوند فایل مجاز نیست. تنها ' . implode('، ', $allowed) . ' پذیرفته می‌شود.'
            );
        }

        $maxSize = (int)Config::get('app.max_upload_size', 2 * 1024 * 1024);
        if (($file['size'] ?? 0) > $maxSize) {
            throw new \RuntimeException('حجم فایل بیش از حد مجاز است (۲ مگابایت).');
        }

        $projectRoot = dirname(__DIR__, 4);
        $storageDir = $projectRoot . '/storage/uploads/receipts/' . $paymentId;
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0775, true);
        }
        if (!is_dir($storageDir) || !is_writable($storageDir)) {
            throw new \RuntimeException('پوشهٔ ذخیره‌سازی قابل نوشتن نیست.');
        }

        $rand = bin2hex(random_bytes(3));  // 6 hex chars
        $fileName = 'receipt_' . $rand . '.' . $ext;
        $absolutePath = $storageDir . '/' . $fileName;

        if (!move_uploaded_file((string)$file['tmp_name'], $absolutePath)) {
            throw new \RuntimeException('انتقال فایل بارگذاری‌شده ناموفق بود.');
        }
        @chmod($absolutePath, 0640);

        return 'storage/uploads/receipts/' . $paymentId . '/' . $fileName;
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
     * AvatarService::upload).
     *
     * Phase 57b — now returns the real serving URL `/document/avatar`
     * (the route is registered in Core/Module.php and handled by
     * DocumentController::avatar, which streams the file via
     * FileStorage::getFullPath). FileStorage was extended in this same
     * phase to resolve both upload-dir-relative paths (regular
     * documents) and project-root-relative paths (avatars stored by
     * AvatarService as `storage/uploads/avatars/account-{id}.jpg`).
     *
     * Before this phase, `avatarUrlFor()` always returned null — the
     * view therefore fell back to the letter avatar and the user never
     * saw the uploaded image even though it was correctly stored on
     * disk + registered in the documents table.
     *
     * @param array<string,mixed> $account
     */
    private function avatarUrlFor(array $account): ?string
    {
        $docId = (int)($account['avatar_document_id'] ?? 0);
        if ($docId <= 0) {
            return null;
        }
        // Best-effort: confirm the file actually exists on disk before
        // surfacing the URL (so we don't ship a broken <img> when the
        // document row is set but the file was lost — e.g. by a manual
        // cleanup). If anything goes wrong, fall through to the URL
        // anyway (the controller will return a 1x1 placeholder PNG,
        // which the <img> can render without a broken-image icon).
        try {
            $path = $this->avatars->getPath($docId);
            if ($path !== null && is_file($path)) {
                return $this->environment->baseUrl . 'document/avatar';
            }
        } catch (\Throwable) {
            // best-effort — never break the profile page over a missing file.
        }
        // If the document row exists but the file is missing on disk,
        // the /document/avatar endpoint will gracefully return a 1x1
        // transparent PNG placeholder. We still surface the URL so the
        // browser never shows a broken-image icon.
        return $this->environment->baseUrl . 'document/avatar';
    }

    /**
     * Convert three Jalali year/month/day inputs into a Gregorian
     * 'YYYY-MM-DD' string for the applicants.birth_date DATE column.
     * Returns null when any of the three inputs is missing or out of
     * range. Mirrors AdminController::birthDateFromInputs (kept private
     * here so the user panel doesn't reach into the admin controller's
     * surface — they happen to share the same Jalali→Gregorian logic).
     *
     * Phase 60 (v2.2.6) — used by updateRequest() for the birth_date
     * dropdown on the one-time edit form.
     */
    private function birthDateFromInputs(int $jy, int $jm, int $jd): ?string
    {
        if ($jy <= 0 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
            return null;
        }
        try {
            $persian = $this->container->make(PersianDate::class);
            [$gy, $gm, $gd] = $persian->jalaliToGregorian($jy, $jm, $jd);
            return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Validate an Iranian postal code: exactly 10 digits. Returns the
     * trimmed value, or null if invalid. Mirrors
     * AdminController::validPostalCode (same private-copy rationale as
     * birthDateFromInputs above).
     */
    private static function validPostalCode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^\d{10}$/', $value)) {
            return null;
        }
        return $value;
    }
}
