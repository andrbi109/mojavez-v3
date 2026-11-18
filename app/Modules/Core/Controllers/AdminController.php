<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Modules\Core\Services\AutoCancelService;
use App\Modules\Core\Services\FileStorage;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\DocumentRepositoryInterface;
use App\Shared\Contracts\JobAndPropertyRepositoryInterface;
use App\Shared\Contracts\ServiceTypeRepositoryInterface;
use App\Shared\Contracts\StatusLogRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Config;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\PersianDate;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;

/**
 * AdminController — admin panel (dashboard, applications, status changes,
 * manual registration, edit, documents, payment requests, license state,
 * postal-code change requests, role-filtered task list).
 *
 * Inherited from v2's AdminController. Phase 2 shipped the core admin
 * operations (dashboard, applications list, view, change-status). Phase 17
 * (v1.8.0) ports the remaining 12 methods from v2 — adapted to v3's
 * modular architecture (DI Container, repository interfaces, real PDO
 * prepared statements, no global Database::getInstance()).
 *
 * Auth: most actions require role='admin' (super-admin). Document add/delete
 * also allow 'expert' and 'document_uploader' per workflow.php. Postal-code
 * review allows 'admin' or 'expert'.
 *
 * Routes (16 total in Core/Module.php):
 *   GET  /admin/dashboard                       dashboard
 *   GET  /admin/applications                     applications (paginated)
 *   GET  /admin/view/{id}                        viewApplication
 *   POST /admin/change-status                    changeStatus
 *   GET  /admin/register                         registerForm
 *   POST /admin/register                         registerProcess
 *   GET  /admin/edit/{id}                        editApplication
 *   POST /admin/update                           updateApplication
 *   POST /admin/add-document                     addDocument
 *   POST /admin/delete-document                  deleteDocument
 *   POST /admin/request-payment                  requestPayment
 *   POST /admin/license-state                    updateLicenseState
 *   GET  /admin/postal-requests                  postalRequests
 *   POST /admin/postal-requests/approve          approvePostalRequest
 *   POST /admin/postal-requests/reject           rejectPostalRequest
 *   GET  /admin/my-tasks                         myTasks
 */
final class AdminController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly StatusLogRepositoryInterface $statusLog,
        private readonly AutoCancelService $autoCancel,
        private readonly Connection $conn,
        private readonly ServiceTypeRepositoryInterface $serviceTypes,
        private readonly JobAndPropertyRepositoryInterface $jobAndProperty,
        private readonly DocumentRepositoryInterface $documents,
        private readonly FileStorage $storage,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /admin/dashboard — admin dashboard with summary stats.
     */
    public function dashboard(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        // Poor man's cron: AutoCancelService on every dashboard view.
        try {
            $cancelled = $this->autoCancel->cancelExpired();
            $prioritised = $this->autoCancel->updatePriorities();
            // Phase 22 (v1.9.4) — auto-progress applicants stuck in a
            // pre-issue status for more than app.auto_progress_days.
            $progressed = $this->autoCancel->autoProgress();
        } catch (\Throwable $e) {
            $cancelled = 0;
            $prioritised = 0;
            $progressed = 0;
            $this->logger->warning('AutoCancel failed on dashboard view', [
                '_module' => 'core', 'error' => $e->getMessage(),
            ]);
        }

        $totalApplicants = (int)$this->conn->scalar('SELECT COUNT(*) FROM applicants WHERE deleted_at IS NULL');
        $pendingReview = (int)$this->conn->scalar(
            "SELECT COUNT(*) FROM applicants
             WHERE deleted_at IS NULL AND current_status IN ('تأیید اولیه','بررسی کارشناسی رشته','احراز نشانی')"
        );
        $issuedThisMonth = (int)$this->conn->scalar(
            "SELECT COUNT(*) FROM applicants
             WHERE deleted_at IS NULL
               AND license_state = 'صادر شده'
               AND matched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        return $this->render('admin/dashboard', [
            'cancelled'         => $cancelled,
            'prioritised'       => $prioritised,
            'progressed'        => $progressed,
            'totalApplicants'   => $totalApplicants,
            'pendingReview'     => $pendingReview,
            'issuedThisMonth'   => $issuedThisMonth,
        ]);
    }

    /**
     * GET /admin/applications — paginated list with filters.
     */
    public function applications(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $page = max(1, (int)$request->queryParam('page', 1));
        $pageSize = 20;
        $filters = [
            'tracking_code'   => $request->queryParam('tracking_code'),
            'national_code'   => $request->queryParam('national_code'),
            'current_status'  => $request->queryParam('current_status'),
            'license_state'   => $request->queryParam('license_state'),
            'service_type_id' => $request->queryParam('service_type_id'),
            'unmatched_only'   => $request->queryParam('unmatched_only'),
        ];
        $filters = array_filter($filters, static fn ($v): bool => $v !== null && $v !== '');
        $result = $this->applicants->paginate($filters, $page, $pageSize);
        return $this->render('admin/applications', [
            'rows'       => $result['rows'],
            'total'      => $result['total'],
            'page'       => $page,
            'pageSize'   => $pageSize,
            'filters'    => $filters,
        ]);
    }

    /**
     * GET /admin/view/{id} — view a single applicant's case.
     */
    public function viewApplication(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)($params['id'] ?? 0);
        $applicant = $this->applicants->findById($id);
        if ($applicant === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>پرونده یافت نشد.</p>', 404);
        }
        $statusLog = $this->statusLog->findByApplicantId($id);

        // Build the list of all 18 statuses for the change-status dropdown.
        $allStatuses = [];
        foreach (ApplicantStatus::cases() as $s) {
            $allStatuses[] = [
                'value'      => $s->value,
                'label'      => $s->titleFa(),
                'badgeColor' => $s->badgeColor(),
                'order'      => $s->order(),
                'isTerminal' => $s->isTerminal(),
            ];
        }
        // Sort by order (ascending).
        usort($allStatuses, static fn($a, $b): int => $a['order'] <=> $b['order']);

        // Load related data for the editable form.
        $jobDetail = null;
        $propertyDetail = null;
        try {
            $conn = $this->connection();
            $jobRow = $conn->one('SELECT * FROM job_details WHERE applicant_id = ?', [$id]);
            if ($jobRow !== null) {
                $jobDetail = $jobRow;
            }
            $propRow = $conn->one('SELECT * FROM property_details WHERE applicant_id = ?', [$id]);
            if ($propRow !== null) {
                $propertyDetail = $propRow;
            }
        } catch (\Throwable) {
            // best-effort — the view handles nulls.
        }
        // Load documents as Document domain objects (so the view can use
        // isImage()/isPdf()/extension() helpers for thumbnail rendering).
        $documents = $this->documents->findByApplicantId($id);

        // Load the raw applicants row for fields not exposed by the
        // Applicant VO (father_name, birth_date, marital_status,
        // children_count, physical_condition, disability_details,
        // income_decile, has_court_case, has_active_loan,
        // has_overdue_installments). Used by the v2-parity detail view.
        $row = $this->conn->one(
            'SELECT * FROM applicants WHERE id = ? AND deleted_at IS NULL',
            [$id]
        ) ?? [];

        // Load service type title.
        $serviceTypeTitle = '';
        if ($applicant->serviceTypeId !== null) {
            try {
                $serviceRow = $this->connection()->one(
                    'SELECT title FROM service_types WHERE id = ?',
                    [$applicant->serviceTypeId]
                );
                if ($serviceRow !== null) {
                    $serviceTypeTitle = (string)$serviceRow['title'];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        // Status meta (config/statuses.php) for color-coding the status
        // history timeline. Maps Persian status name → meta array
        // (order, color, manual, pre_issue, services).
        $statusMeta = Config::get('statuses.statuses', []);

        // PersianDate helper for Jalali date formatting in the view.
        $persianDate = $this->container->make(PersianDate::class);

        // Phase 50 (v2.1.6) — read the flash message set by
        // requestPayment() (success or error) and clear it after read
        // so it shows once. Defaults to a success-kind alert.
        $flashMessage = (string)$this->session->get('view_app_message', '');
        $flashKind    = (string)$this->session->get('view_app_message_kind', 'success');
        if ($flashMessage !== '') {
            $this->session->remove('view_app_message');
            $this->session->remove('view_app_message_kind');
        }

        // Phase 50 (v2.1.6) — suggested payment amount for the modal
        // pre-fill. Read from settings (registration_fee_amount →
        // fee_default_amount → 500000 sensible default in Toman).
        // Pull both keys in one query (more efficient than 2 round-trips).
        $suggestedAmount = 0;
        try {
            $settings = $this->conn->all(
                "SELECT setting_key, setting_value FROM settings
                 WHERE setting_key IN ('registration_fee_amount', 'fee_default_amount')"
            );
            foreach ($settings as $s) {
                $v = (int)preg_replace('/[^0-9]/', '', (string)$s['setting_value']);
                if ($s['setting_key'] === 'registration_fee_amount' && $v > 0) {
                    $suggestedAmount = $v;
                    break;
                }
                if ($s['setting_key'] === 'fee_default_amount' && $v > 0 && $suggestedAmount === 0) {
                    $suggestedAmount = $v;
                }
            }
        } catch (\Throwable) {
            // best-effort — fall back to the hard-coded default below.
        }
        if ($suggestedAmount <= 0) {
            $suggestedAmount = 500000;  // sensible fallback (Toman)
        }

        return $this->render('admin/view_application', [
            'applicant'        => $applicant,
            'statusLog'        => $statusLog,
            'allStatuses'      => $allStatuses,
            'jobDetail'        => $jobDetail,
            'propertyDetail'   => $propertyDetail,
            'documents'        => $documents,
            'serviceTypeTitle' => $serviceTypeTitle,
            'row'              => $row,
            'statusMeta'       => $statusMeta,
            'persianDate'      => $persianDate,
            'flashMessage'     => $flashMessage,
            'flashKind'        => $flashKind,
            'suggestedAmount'  => $suggestedAmount,
        ]);
    }

    /**
     * POST /admin/change-status — change an applicant's status.
     *
     * Phase 45 (v2.1.1) — after the status change, inserts a
     * user_notifications row so the applicant sees the change in their
     * user panel (notifications surface). The notification is created
     * even when description is empty (we use the new status's Persian
     * title). Skips silently if the applicant has no user_account_id
     * (e.g. admin-created cases without a linked user).
     *
     * Phase 53 (v2.1.9) — three behavioural changes:
     *
     *   1. Closed cases block further changes. When the applicant's
     *      current_status is terminal (رد درخواست / لغو / تحویل مجوز /
     *      تأیید نهایی دریافت کد رهگیری — ApplicantStatus::isTerminal()),
     *      the method refuses the change with "این پرونده بسته شده است."
     *      — even for admin. The terminal check is BEFORE the
     *      workflow-rule check and applies to all roles. Re-opening a
     *      closed case requires a fresh registration, not a status
     *      transition.
     *
     *   2. closed_at is stamped when the new status is terminal. A
     *      separate UPDATE sets closed_at = NOW() on the applicants
     *      row (column added by migration 20261119_001). Best-effort:
     *      the status change is committed BEFORE the closed_at stamp,
     *      so a closed_at write failure logs a warning but does NOT
     *      roll back the status change (the case is still closed by
     *      its current_status — closed_at is just the timestamp).
     *
     *   3. changeStatus redirects instead of returning JSON. The
     *      previous flow returned {"success":true,...} which the admin
     *      browser rendered as raw text. The new flow:
     *        - AJAX (X-Requested-With: XMLHttpRequest) — still JSON
     *          (preserves the contract for any JS clients).
     *        - Form POST — flash message + 302 redirect to
     *          /admin/view/{id}. Success: "وضعیت پرونده با موفقیت
     *          تغییر یافت" (kind=success). Error: same flash-message
     *          channel with kind=danger.
     */
    public function changeStatus(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)$request->input('applicant_id', 0);
        $newStatusSlug = (string)$request->input('new_status', '');
        $description = (string)$request->input('description', '');

        // Phase 53 (v2.1.9) — JSON only when the request explicitly
        // identifies itself as AJAX via the X-Requested-With header
        // (the same header jQuery's $.ajax and the Fetch API set
        // when calling fetch(..., { headers: { 'X-Requested-With':
        // 'XMLHttpRequest' } })). Form POSTs without the header get
        // the new redirect flow.
        $isAjax = strtolower((string)$request->header('X-Requested-With', '')) === 'xmlhttprequest';

        // Phase 53 (v2.1.9) — helper that surfaces an error either as
        // JSON (AJAX) or as a flash + redirect (form POST). Redirect
        // target is /admin/view/{id} when we have a non-zero id,
        // /admin/applications otherwise (matches the requestPayment()
        // error handling pattern for a missing applicant).
        $respondError = function (int $status, string $code, string $message) use ($isAjax, $id): never {
            if ($isAjax) {
                $this->jsonError($status, $code, $message);
            }
            $this->session->set('view_app_message', $message);
            $this->session->set('view_app_message_kind', 'danger');
            if ($id > 0) {
                $this->redirect('/admin/view/' . $id);
            }
            $this->redirect('/admin/applications');
        };

        $applicant = $this->applicants->findById($id);
        if ($applicant === null) {
            $respondError(404, 'not_found', 'پرونده یافت نشد.');
        }
        $newStatus = ApplicantStatus::fromSlug($newStatusSlug);
        if ($newStatus === null) {
            $respondError(422, 'invalid_status', 'وضعیت نامعتبر.');
        }

        // Phase 53 (v2.1.9) — block any status change once the case
        // is in a terminal state. The check is BEFORE the workflow
        // rules (validateStatusTransition) and applies to ALL roles
        // (including admin). Uses ApplicantStatus::isTerminal() so
        // the rule tracks the 18-status cycle's single source of
        // truth. Re-opening a closed case requires a fresh
        // registration, not a status transition.
        if ($applicant->currentStatus->isTerminal()) {
            $respondError(422, 'case_closed', 'این پرونده بسته شده است.');
        }

        $role = (string)$this->session->get('admin_role', 'admin');
        // Phase 53 (v2.1.9) — use the new non-exiting statusTransitionError()
        // helper so we can branch on AJAX-ness before responding.
        // (validateStatusTransition() in the base Controller still
        // exits with a JSON 422 — wrong for a form POST.)
        $transitionErr = $this->statusTransitionError($applicant->currentStatus, $newStatus, $role);
        if ($transitionErr !== null) {
            $respondError(422, 'invalid_status_transition', $transitionErr);
        }

        $triggeredBy = $role === 'admin' ? 'admin' : $role;
        $this->applicants->changeStatus($id, $newStatus, $triggeredBy, $description);
        $this->logger->info('Admin changed applicant status', [
            '_module'        => 'core',
            'applicant_id'   => $id,
            'from'           => $applicant->currentStatus->value,
            'to'             => $newStatus->value,
            'by'             => $this->session->get('admin_id'),
        ]);

        // Phase 53 (v2.1.9) — when the new status is terminal (رد
        // درخواست / لغو / تحویل مجوز / تأیید نهایی دریافت کد رهگیری),
        // stamp closed_at = NOW() on the applicant row. The column
        // was added by migration 20261119_001. Best-effort — the
        // status change is already committed; if the closed_at
        // stamp can't be written (e.g. the migration hasn't been
        // applied yet), we log a warning but keep going. The case is
        // still effectively closed by its current_status.
        if ($newStatus->isTerminal()) {
            try {
                $this->conn->execute(
                    'UPDATE applicants SET closed_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [$id]
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to stamp closed_at on terminal status', [
                    '_module'        => 'core',
                    'applicant_id'   => $id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        // Phase 45 (v2.1.1) — notify the user (applicant) that their
        // application status has changed. Inserted into user_notifications
        // so it surfaces in the user panel (/panel/notifications). Skips
        // silently if the applicant has no user_account_id (e.g.
        // admin-created cases without a linked user_account).
        try {
            $userAccountId = $applicant->userAccountId;
            if ($userAccountId !== null && $userAccountId > 0) {
                $notifBody = $description !== ''
                    ? sprintf('وضعیت پروندهٔ شما به «%s» تغییر یافت. %s', $newStatus->titleFa(), $description)
                    : sprintf('وضعیت پروندهٔ شما به «%s» تغییر یافت.', $newStatus->titleFa());
                $this->conn->execute(
                    'INSERT INTO user_notifications (user_account_id, applicant_id, type, title, body, is_read, created_at) '
                    . 'VALUES (?, ?, ?, ?, ?, 0, NOW())',
                    [
                        $userAccountId,
                        $id,
                        'status_change',
                        'تغییر وضعیت پرونده',
                        $notifBody,
                    ]
                );
            }
        } catch (\Throwable $e) {
            // Best-effort — don't fail the status change if the
            // notification can't be persisted (e.g. DB error).
            $this->logger->warning('Failed to insert status-change notification', [
                '_module'        => 'core',
                'applicant_id'   => $id,
                'error'          => $e->getMessage(),
            ]);
        }

        // Phase 53 (v2.1.9) — AJAX requests keep the JSON contract.
        // Form POSTs get a flash + redirect to /admin/view/{id}.
        if ($isAjax) {
            return $this->jsonSuccess(['changed' => true, 'new_status' => $newStatus->value]);
        }

        $this->session->set('view_app_message', 'وضعیت پرونده با موفقیت تغییر یافت.');
        $this->session->set('view_app_message_kind', 'success');
        return $this->redirect('/admin/view/' . $id);
    }

    /**
     * GET /admin/register — manual registration form for an admin to
     * create a new applicant case file.
     *
     * Phase 17 (v1.8.0): ported from v2's AdminController::registerForm,
     * adapted to v3's modular architecture (Container DI, repository
     * interfaces, no global Database::getInstance()). The admin-created
     * case starts at "تأیید اولیه" (first-approval), not "پیش‌نویس" —
     * because the admin is creating a complete record, not a draft.
     *
     * Auth: role='admin' only. Experts/operators cannot create cases.
     */
    public function registerForm(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $serviceTypes = $this->serviceTypes->getActive();
        return $this->render('admin/register', [
            'title'        => 'ثبت‌نام دستی متقاضی',
            'serviceTypes' => $serviceTypes,
            'errors'       => [],
            'form'         => [],
        ]);
    }

    /**
     * POST /admin/register — process manual registration.
     *
     * Phase 35 (v2.0.1): ported the full v2 41-field form. Validates the
     * required fields (first_name, last_name, national_code, mobile,
     * service_type_id, postal_code), builds a birth_date from the three
     * Jalali year/month/day selects, normalizes the custom_license_title
     * fallback, and creates:
     *   - applicants row with all identity + income_decile +
     *     has_court_case/has_active_loan/has_overdue_installments +
     *     consent_external_share + the 5 tracking fields
     *     (tracking_code HG-XXXXXXXX, current_status = 'تأیید اولیه',
     *     registration_started_at, registration_completed_at, version = 1).
     *   - property_details row via jobAndProperty->upsertPropertyDetail()
     *     with the 13 property fields.
     *   - job_details row via jobAndProperty->upsertJobDetail() with the
     *     3 job fields (license_title, experience_months, training_method).
     *   - documents rows for the uploaded files via handleDocumentUploads()
     *     (v2-style field names: national_card_front, national_card_back,
     *      birth_cert, ownership_doc, vocational_cert, education_degree,
     *      product_photos[], optional_1..3).
     *
     * On success: redirect to /admin/view/{id}.
     * On validation error: re-render the form with errors + submitted values.
     *
     * @phpstan-return Response
     */
    public function registerProcess(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();

        // ---------- Identity (11 fields) ----------
        $firstName    = Validator::nonEmptyString($body['first_name'] ?? '', 50);
        $lastName     = Validator::nonEmptyString($body['last_name'] ?? '', 50);
        $nationalCode = Validator::nationalCode($body['national_code'] ?? '');
        $mobile       = Validator::mobile($body['mobile'] ?? '');
        $fatherName   = Validator::sanitizeText($body['father_name'] ?? '', 50);
        $applicantType = Validator::inEnum($body['applicant_type'] ?? '', ['حقیقی', 'حقوقی']) ?? 'حقیقی';
        $maritalStatus = Validator::inEnum($body['marital_status'] ?? '', ['مجرد', 'متأهل']) ?? 'مجرد';
        $childrenCount = Validator::intInRange($body['children_count'] ?? 0, 0, 50) ?? 0;
        $physicalCondition = Validator::inEnum($body['physical_condition'] ?? '', ['سالم', 'معلولیت']) ?? 'سالم';
        $disabilityDetails = Validator::sanitizeText($body['disability_details'] ?? '', 255);

        // Birth date — combine the three Jalali selects to a Gregorian date.
        $birthDate = $this->birthDateFromInputs(
            (int)($body['birth_year'] ?? 0),
            (int)($body['birth_month'] ?? 0),
            (int)($body['birth_day'] ?? 0)
        );

        // ---------- Service + Job (5 fields) ----------
        $serviceTypeId = Validator::positiveIntOrNull($body['service_type_id'] ?? '');
        $licenseTitle  = Validator::sanitizeText($body['license_title'] ?? '', 100);
        // If "سایر" selected, fall back to the custom title.
        if (str_starts_with($licenseTitle, 'سایر')) {
            $customTitle = Validator::nonEmptyString($body['custom_license_title'] ?? '', 100);
            if ($customTitle !== null) {
                $licenseTitle = $customTitle;
            } else {
                $licenseTitle = '';
            }
        }
        $experienceMonths = Validator::intInRange($body['experience_months'] ?? '', 0, 1200);
        $trainingMethod   = Validator::inEnum(
            $body['training_method'] ?? '',
            ['در خانه', 'استاد شاگردی', 'کارگاهی']
        );

        // ---------- Property (12 fields — postal_code lives on applicants) ----------
        $postalCode       = self::validPostalCode($body['postal_code'] ?? '');
        $ownershipStatus  = Validator::inEnum($body['ownership_status'] ?? '', ['شخص', 'مستأجر', 'سرپرست']) ?? 'شخص';
        $province         = Validator::sanitizeText($body['province'] ?? '', 50);
        $county           = Validator::sanitizeText($body['county'] ?? '', 50);
        $city             = Validator::sanitizeText($body['city'] ?? '', 50);
        $district         = Validator::sanitizeText($body['district'] ?? '', 50);
        $ruralDistrict    = Validator::sanitizeText($body['rural_district'] ?? '', 50);
        $village          = Validator::sanitizeText($body['village'] ?? '', 50);
        $electricityBillId = Validator::sanitizeText($body['electricity_bill_id'] ?? '', 20);
        $addressStreet    = Validator::sanitizeText($body['address_street'] ?? '', 100);
        $addressAlley     = Validator::sanitizeText($body['address_alley'] ?? '', 100);
        $addressPlaque    = Validator::sanitizeText($body['address_plaque'] ?? '', 20);

        // ---------- Income (1) + supplementary (3) ----------
        $incomeDecile = Validator::intInRange($body['income_decile'] ?? '', 1, 10);
        $hasCourtCase = Validator::inEnum($body['has_court_case'] ?? 'خیر', ['بله', 'خیر']) ?? 'خیر';
        $hasActiveLoan = Validator::inEnum($body['has_active_loan'] ?? 'خیر', ['بله', 'خیر']) ?? 'خیر';
        $hasOverdueInstallments = Validator::inEnum($body['has_overdue_installments'] ?? 'خیر', ['بله', 'خیر']) ?? 'خیر';

        // ---------- Consent (1) ----------
        $consentExternalShare = !empty($body['consent_external_share']) ? 1 : 0;

        // Marital-status coupling: children_count is only meaningful for متأهل.
        if ($maritalStatus !== 'متأهل') {
            $childrenCount = 0;
        }
        // Physical-condition coupling: clear disability_details if سالم.
        if ($physicalCondition !== 'معلولیت') {
            $disabilityDetails = '';
        }

        // ---------- Validation ----------
        $errors = [];
        if ($firstName === null) {
            $errors[] = 'نام الزامی است (حداکثر ۵۰ نویسه).';
        }
        if ($lastName === null) {
            $errors[] = 'نام خانوادگی الزامی است (حداکثر ۵۰ نویسه).';
        }
        if ($nationalCode === null) {
            $errors[] = 'کد ملی نامعتبر است (۱۰ رقم با کنترل parity).';
        }
        if ($mobile === null) {
            $errors[] = 'موبایل نامعتبر است (فرمت 09XXXXXXXXX).';
        }
        if ($postalCode === null) {
            $errors[] = 'کد پستی نامعتبر است (۱۰ رقم).';
        }
        if ($serviceTypeId === null) {
            $errors[] = 'نوع خدمت الزامی است.';
        } elseif ($this->serviceTypes->findById($serviceTypeId) === null) {
            $errors[] = 'نوع خدمت انتخاب‌شده یافت نشد.';
        }
        if ($licenseTitle === '') {
            $errors[] = 'عنوان مجوز الزامی است.';
        }
        if ($experienceMonths === null) {
            $errors[] = 'سابقه کار (ماه) نامعتبر است.';
        }
        if ($trainingMethod === null) {
            $errors[] = 'نحوه آموزش الزامی است (در خانه / استاد شاگردی / کارگاهی).';
        }
        if ($province === '') {
            $errors[] = 'استان الزامی است.';
        }
        if ($county === '') {
            $errors[] = 'شهرستان الزامی است.';
        }

        if ($errors !== []) {
            return $this->render('admin/register', [
                'title'        => 'ثبت‌نام دستی متقاضی',
                'serviceTypes' => $this->serviceTypes->getActive(),
                'errors'       => $errors,
                'form'         => $body,
            ]);
        }

        // Generate tracking code (CSPRNG, HG-XXXXXXXX). Retry on the
        // rare chance of a collision against the UNIQUE constraint.
        $trackingCode = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $this->hash->generateTrackingCode();
            $exists = $this->conn->scalar('SELECT id FROM applicants WHERE tracking_code = ?', [$candidate]);
            if ($exists === null) {
                $trackingCode = $candidate;
                break;
            }
        }
        if ($trackingCode === null) {
            return $this->render('admin/register', [
                'title'        => 'ثبت‌نام دستی متقاضی',
                'serviceTypes' => $this->serviceTypes->getActive(),
                'errors'       => ['خطای سیستمی: تولید کد رهگیری ناموفق بود. دوباره تلاش کنید.'],
                'form'         => $body,
            ]);
        }

        $now = date('Y-m-d H:i:s');
        $applicantId = $this->applicants->create([
            'service_type_id'          => $serviceTypeId,
            'first_name'               => $firstName,
            'last_name'                => $lastName,
            'national_code'            => $nationalCode,
            'postal_code'              => $postalCode,
            'mobile'                   => $mobile,
            'tracking_code'             => $trackingCode,
            'birth_date'               => $birthDate,
            'father_name'              => $fatherName !== '' ? $fatherName : null,
            'current_status'           => ApplicantStatus::InitialApproval->value, // 'تأیید اولیه'
            'applicant_type'           => $applicantType,
            'marital_status'           => $maritalStatus,
            'children_count'           => $childrenCount,
            'physical_condition'       => $physicalCondition,
            'disability_details'       => $disabilityDetails !== '' ? $disabilityDetails : null,
            'income_decile'            => $incomeDecile,
            'has_court_case'           => $hasCourtCase,
            'has_active_loan'          => $hasActiveLoan,
            'has_overdue_installments' => $hasOverdueInstallments,
            'consent_external_share'   => $consentExternalShare,
            'registration_started_at'   => $now,
            'registration_completed_at' => $now,
        ]);

        // Job details (3 fields) — upsert into job_details (1:1 with applicants).
        $this->jobAndProperty->upsertJobDetail($applicantId, [
            'license_title'     => $licenseTitle,
            'experience_months' => $experienceMonths,
            'training_method'   => $trainingMethod,
        ]);

        // Property details (12 fields) — upsert into property_details.
        $this->jobAndProperty->upsertPropertyDetail($applicantId, [
            'ownership_status'   => $ownershipStatus,
            'province'           => $province,
            'county'             => $county,
            'city'               => $city,
            'district'           => $district !== '' ? $district : null,
            'rural_district'     => $ruralDistrict !== '' ? $ruralDistrict : null,
            'village'            => $village !== '' ? $village : null,
            'electricity_bill_id' => $electricityBillId !== '' ? $electricityBillId : '',
            'address_street'     => $addressStreet !== '' ? $addressStreet : null,
            'address_alley'      => $addressAlley !== '' ? $addressAlley : null,
            'address_plaque'     => $addressPlaque !== '' ? $addressPlaque : null,
        ]);

        // Documents (v2-style named file uploads) — best-effort: each
        // upload failure is logged but does not abort the whole registration.
        $this->handleDocumentUploads($request, $applicantId, $trackingCode);

        // Audit-log the admin creation.
        $this->statusLog->create([
            'applicant_id'  => $applicantId,
            'status_name'    => ApplicantStatus::InitialApproval->value,
            'description'    => 'ثبت‌نام دستی توسط مدیر — کد رهگیری ' . $trackingCode,
            'triggered_by'   => 'admin',
        ]);

        $this->logger->info('Admin manually registered applicant', [
            '_module'         => 'core',
            'applicant_id'    => $applicantId,
            'tracking_code'   => $trackingCode,
            'admin_id'        => (int)$this->session->get('admin_id', 0),
        ]);

        return $this->redirect('/admin/view/' . $applicantId);
    }

    /**
     * Convert three Jalali year/month/day inputs into a Gregorian 'YYYY-MM-DD'
     * string suitable for the applicants.birth_date DATE column. Returns null
     * when any of the three inputs is missing or out of range.
     */
    private function birthDateFromInputs(int $jy, int $jm, int $jd): ?string
    {
        if ($jy <= 0 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
            return null;
        }
        try {
            $persian = $this->container->make(\App\Shared\Support\PersianDate::class);
            [$gy, $gm, $gd] = $persian->jalaliToGregorian($jy, $jm, $jd);
            return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Best-effort document upload handler. Iterates over the v2-style
     * file fields (national_card_front, national_card_back, birth_cert,
     * ownership_doc, vocational_cert, education_degree, optional_1..3,
     * plus the product_photos[] array), stores each via FileStorage's
     * 7-layer pipeline, and inserts a documents row. Failures are logged
     * but never abort the registration.
     *
     * Phase 51 (v2.1.7) — widened from the 5 legacy v3 field names
     * (national_id / personal_photo / ownership_deed / skill_certificate /
     * optional_document) to the v2-style named fields so the admin
     * register form and the public wizard step4 store the same doc_type
     * keys on disk and in the documents table.
     *
     * @return array<string,string>  map of field => error (empty on success)
     */
    private function handleDocumentUploads(Request $request, int $applicantId, string $trackingCode): array
    {
        // Single-file uploads — each maps 1:1 to a documents.doc_type row.
        $singleFields = [
            'national_card_front' => 'national_card_front',
            'national_card_back'  => 'national_card_back',
            'birth_cert'          => 'birth_cert',
            'ownership_doc'       => 'ownership_doc',
            'vocational_cert'     => 'vocational_cert',
            'education_degree'    => 'education_degree',
            'optional_1'          => 'optional_1',
            'optional_2'          => 'optional_2',
            'optional_3'          => 'optional_3',
        ];
        $errors = [];
        foreach ($singleFields as $fieldKey => $docType) {
            $file = $request->file($fieldKey);
            if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;  // optional — admin may skip any subset of files
            }
            $result = $this->storage->storeUpload($file, $applicantId, $trackingCode, $docType);
            if (!$result['success']) {
                $errors[$fieldKey] = (string)($result['error'] ?? 'خطای ناشناخته');
                $this->logger->warning('Manual-register document upload failed', [
                    '_module'      => 'core',
                    'applicant_id' => $applicantId,
                    'field'        => $fieldKey,
                    'error'        => $errors[$fieldKey],
                ]);
                continue;
            }
            $this->documents->create([
                'applicant_id'   => $applicantId,
                'doc_type'       => $docType,
                'file_name'      => $result['file_name'],
                'original_name'  => $result['original_name'],
                'file_path'      => $result['file_path'],
            ]);
        }

        // product_photos[] — multi-file array upload (1..15 photos).
        // PHP flattens this to $_FILES['product_photos']['name'][i], etc.
        $productPhotos = $request->file('product_photos');
        if (
            $productPhotos !== null
            && isset($productPhotos['error'])
            && is_array($productPhotos['error'])
        ) {
            $count = count($productPhotos['error']);
            for ($i = 0; $i < $count; $i++) {
                if (($productPhotos['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $oneFile = [
                    'name'     => $productPhotos['name'][$i] ?? '',
                    'type'     => $productPhotos['type'][$i] ?? '',
                    'tmp_name' => $productPhotos['tmp_name'][$i] ?? '',
                    'error'    => $productPhotos['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $productPhotos['size'][$i] ?? 0,
                ];
                $result = $this->storage->storeUpload($oneFile, $applicantId, $trackingCode, 'product_photos');
                if (!$result['success']) {
                    $errors['product_photos_' . $i] = (string)($result['error'] ?? 'خطای ناشناخته');
                    $this->logger->warning('Manual-register product photo upload failed', [
                        '_module'      => 'core',
                        'applicant_id' => $applicantId,
                        'index'        => $i,
                        'error'        => $errors['product_photos_' . $i],
                    ]);
                    continue;
                }
                $this->documents->create([
                    'applicant_id'   => $applicantId,
                    'doc_type'       => 'product_photos',
                    'file_name'      => $result['file_name'],
                    'original_name'  => $result['original_name'],
                    'file_path'      => $result['file_path'],
                ]);
            }
        }
        return $errors;
    }

    /**
     * GET /admin/edit/{id} — render the edit form for an existing
     * applicant's case file. Loads applicant + job_details + property_details
     * + documents.
     *
     * Auth: role='admin' or 'expert'.
     */
    public function editApplication(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'expert']);
        $id = (int)($params['id'] ?? 0);
        $applicant = $this->applicants->findById($id);
        if ($applicant === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>پرونده یافت نشد.</p>', 404);
        }
        $jobDetail = $this->jobAndProperty->findJobDetailByApplicantId($id);
        $propertyDetail = $this->jobAndProperty->findPropertyDetailByApplicantId($id);
        $documents = $this->documents->findByApplicantId($id);
        $serviceTypes = $this->serviceTypes->getActive();

        // Fetch the raw applicant row (the Applicant VO doesn't expose
        // all the editable fields like father_name, marital_status, etc.).
        $row = $this->conn->one('SELECT * FROM applicants WHERE id = ? AND deleted_at IS NULL', [$id]) ?? [];

        // Build the same 18-status dropdown as view_application so the
        // edit form can change the status inline (reuses the existing
        // POST /admin/change-status handler).
        $allStatuses = [];
        foreach (ApplicantStatus::cases() as $s) {
            $allStatuses[] = [
                'value'      => $s->value,
                'label'      => $s->titleFa(),
                'badgeColor' => $s->badgeColor(),
                'order'      => $s->order(),
                'isTerminal' => $s->isTerminal(),
            ];
        }
        usort($allStatuses, static fn($a, $b): int => $a['order'] <=> $b['order']);

        // PersianDate for converting the stored Gregorian birth_date
        // back to Jalali Y/M/D in the 3-select dropdown.
        $persianDate = $this->container->make(PersianDate::class);

        return $this->render('admin/edit_application', [
            'title'         => 'ویرایش پروندهٔ متقاضی',
            'applicant'     => $applicant,
            'row'           => $row,
            'jobDetail'     => $jobDetail,
            'propertyDetail' => $propertyDetail,
            'documents'     => $documents,
            'serviceTypes'  => $serviceTypes,
            'errors'        => [],
            'allStatuses'   => $allStatuses,
            'persianDate'   => $persianDate,
        ]);
    }

    /**
     * POST /admin/update — update an existing applicant's row +
     * (optionally) upsert job_details + property_details.
     *
     * national_code and tracking_code are NOT editable (identity anchors).
     *
     * Auth: role='admin' or 'expert'.
     */
    public function updateApplication(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'expert']);
        $id = (int)$request->input('applicant_id', 0);
        $applicant = $this->applicants->findById($id);
        if ($applicant === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>پرونده یافت نشد.</p>', 404);
        }
        $body = $request->body();

        $firstName  = Validator::nonEmptyString($body['first_name'] ?? '', 50);
        $lastName   = Validator::nonEmptyString($body['last_name'] ?? '', 50);
        $mobile     = Validator::mobile($body['mobile'] ?? '');
        $postalCode = self::validPostalCode($body['postal_code'] ?? '');
        $fatherName = Validator::sanitizeText($body['father_name'] ?? '', 50);
        $address    = Validator::sanitizeText($body['address'] ?? '', 255);
        $serviceTypeId = Validator::positiveIntOrNull($body['service_type_id'] ?? '');

        // Phase 36 (v2.0.2) — v2-parity identity fields. All optional on
        // edit (the case may have been registered before these fields
        // existed, or by a v2 import that left them NULL).
        $maritalStatus = Validator::inEnum($body['marital_status'] ?? '', ['مجرد', 'متأهل']);
        $childrenCount = Validator::intOrNull($body['children_count'] ?? '');
        $physicalCondition = Validator::inEnum($body['physical_condition'] ?? '', ['سالم', 'معلولیت']);
        $disabilityDetails = Validator::sanitizeText($body['disability_details'] ?? '', 255);
        $incomeDecile = Validator::intInRange($body['income_decile'] ?? '', 1, 10);
        $hasCourtCase = Validator::inEnum($body['has_court_case'] ?? '', ['بله', 'خیر']);
        $hasActiveLoan = Validator::inEnum($body['has_active_loan'] ?? '', ['بله', 'خیر']);
        $hasOverdueInstallments = Validator::inEnum($body['has_overdue_installments'] ?? '', ['بله', 'خیر']);
        $birthDateGreg = $this->birthDateFromInputs(
            (int)($body['birth_year'] ?? 0),
            (int)($body['birth_month'] ?? 0),
            (int)($body['birth_day'] ?? 0),
        );

        // Job-detail fields (optional on edit — may be empty for a
        // manually-registered case that has no job info yet).
        $licenseTitle     = Validator::sanitizeText($body['license_title'] ?? '', 100);
        $experienceMonths = Validator::intOrNull($body['experience_months'] ?? '');
        $trainingMethod    = Validator::inEnum($body['training_method'] ?? '', ['در خانه', 'استاد شاگردی', 'کارگاهی']);

        // Property-detail fields (optional).
        $ownershipStatus = Validator::inEnum($body['ownership_status'] ?? '', ['شخص', 'مستأجر', 'سرپرست']);
        $province = Validator::sanitizeText($body['province'] ?? '', 50);
        $county   = Validator::sanitizeText($body['county'] ?? '', 50);
        $city     = Validator::sanitizeText($body['city'] ?? '', 50);
        $district = Validator::sanitizeText($body['district'] ?? '', 50);
        $ruralDistrict = Validator::sanitizeText($body['rural_district'] ?? '', 50);
        $village  = Validator::sanitizeText($body['village'] ?? '', 50);
        $electricityBillId = Validator::sanitizeText($body['electricity_bill_id'] ?? '', 20);
        $addressStreet = Validator::sanitizeText($body['address_street'] ?? '', 100);
        $addressAlley  = Validator::sanitizeText($body['address_alley'] ?? '', 100);
        $addressPlaque = Validator::sanitizeText($body['address_plaque'] ?? '', 20);

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
            $errors[] = 'کد پستی نامعتبر است.';
        }
        if ($serviceTypeId !== null && $this->serviceTypes->findById($serviceTypeId) === null) {
            $errors[] = 'نوع خدمت انتخاب‌شده یافت نشد.';
        }

        if ($errors !== []) {
            $row = $this->conn->one('SELECT * FROM applicants WHERE id = ?', [$id]) ?? [];
            $allStatuses = [];
            foreach (ApplicantStatus::cases() as $s) {
                $allStatuses[] = [
                    'value'      => $s->value,
                    'label'      => $s->titleFa(),
                    'badgeColor' => $s->badgeColor(),
                    'order'      => $s->order(),
                    'isTerminal' => $s->isTerminal(),
                ];
            }
            usort($allStatuses, static fn($a, $b): int => $a['order'] <=> $b['order']);
            return $this->render('admin/edit_application', [
                'title'         => 'ویرایش پروندهٔ متقاضی',
                'applicant'     => $applicant,
                'row'           => array_merge($row, $body),
                'jobDetail'     => $this->jobAndProperty->findJobDetailByApplicantId($id),
                'propertyDetail' => $this->jobAndProperty->findPropertyDetailByApplicantId($id),
                'documents'     => $this->documents->findByApplicantId($id),
                'serviceTypes'  => $this->serviceTypes->getActive(),
                'errors'        => $errors,
                'allStatuses'   => $allStatuses,
                'persianDate'   => $this->container->make(PersianDate::class),
            ]);
        }

        $update = array_filter([
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'mobile'     => $mobile,
            'postal_code' => $postalCode,
            'father_name' => $fatherName !== '' ? $fatherName : null,
            'service_type_id' => $serviceTypeId,
            'birth_date' => $birthDateGreg,
            'marital_status' => $maritalStatus,
            'children_count' => $childrenCount,
            'physical_condition' => $physicalCondition,
            'disability_details' => $disabilityDetails !== '' ? $disabilityDetails : null,
            'income_decile' => $incomeDecile,
            'has_court_case' => $hasCourtCase,
            'has_active_loan' => $hasActiveLoan,
            'has_overdue_installments' => $hasOverdueInstallments,
        ], static fn ($v): bool => $v !== null);
        $this->applicants->update($id, $update);

        // Job detail upsert only if any of the three required fields
        // have a non-empty value (so we don't create an empty row with
        // a NOT NULL constraint violation on license_title).
        if ($licenseTitle !== '' && $experienceMonths !== null && $trainingMethod !== null) {
            $this->jobAndProperty->upsertJobDetail($id, [
                'license_title'    => $licenseTitle,
                'experience_months' => $experienceMonths,
                'training_method'   => $trainingMethod,
            ]);
        }

        // Property detail upsert if any of the address fields are present.
        if ($ownershipStatus !== null || $province !== '' || $city !== '' || $addressStreet !== '' || $address !== ''
            || $district !== '' || $ruralDistrict !== '' || $village !== '') {
            $this->jobAndProperty->upsertPropertyDetail($id, [
                'ownership_status'   => $ownershipStatus ?? 'شخص',
                'province'            => $province,
                'county'              => $county,
                'city'                => $city,
                'district'            => $district !== '' ? $district : null,
                'rural_district'      => $ruralDistrict !== '' ? $ruralDistrict : null,
                'village'             => $village !== '' ? $village : null,
                'electricity_bill_id' => $electricityBillId,
                'address_street'      => $addressStreet !== '' ? $addressStreet : ($address !== '' ? $address : null),
                'address_alley'       => $addressAlley !== '' ? $addressAlley : null,
                'address_plaque'      => $addressPlaque !== '' ? $addressPlaque : null,
            ]);
        }

        $this->statusLog->create([
            'applicant_id' => $id,
            'status_name'   => $applicant->currentStatus->value,
            'description'   => 'ویرایش اطلاعات توسط مدیر — ' . (string)$this->session->get('admin_display_name', 'مدیر'),
            'triggered_by'  => 'admin',
        ]);

        $this->logger->info('Admin updated applicant', [
            '_module'      => 'core',
            'applicant_id' => $id,
            'by'           => (int)$this->session->get('admin_id', 0),
        ]);

        return $this->redirect('/admin/view/' . $id);
    }

    /**
     * POST /admin/add-document — upload a new document file to an
     * applicant's case. Saves to {upload_dir}/{tracking_code}/ via
     * FileStorage (7-layer secure upload), inserts a documents row.
     *
     * Auth: role='admin', 'expert', or 'document_uploader' (per
     * config/workflow.php — document_uploader role's `from` list
     * includes 'ثبت درخواست صدور' and 'بارگذاری مدارک به سازمان').
     */
    public function addDocument(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'expert', 'document_uploader']);
        $applicantId = (int)$request->input('applicant_id', 0);
        $docType = Validator::sanitizeText($request->input('doc_type', ''), 50);
        if ($docType === '') {
            $docType = 'optional';
        }

        $applicant = $this->applicants->findById($applicantId);
        if ($applicant === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>متقاضی یافت نشد.</p>', 404);
        }

        $file = $request->file('document_file');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۰</h1><p>فایلی برای بارگذاری ارسال نشده است.</p>',
                400
            );
        }

        $result = $this->storage->storeUpload($file, $applicantId, $applicant->trackingCode, $docType);
        if (!$result['success']) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۰</h1><p>'
                . htmlspecialchars((string)$result['error'], ENT_QUOTES, 'UTF-8') . '</p>',
                400
            );
        }

        $this->documents->create([
            'applicant_id'   => $applicantId,
            'doc_type'       => $docType,
            'file_name'      => $result['file_name'],
            'original_name'  => $result['original_name'],
            'file_path'      => $result['file_path'],
        ]);

        $this->statusLog->create([
            'applicant_id' => $applicantId,
            'status_name'   => $applicant->currentStatus->value,
            'description'   => 'افزودن مدرک «' . $docType . '» توسط '
                . (string)$this->session->get('admin_display_name', 'مدیر'),
            'triggered_by'  => 'admin',
        ]);

        $this->logger->info('Admin uploaded document', [
            '_module'      => 'core',
            'applicant_id' => $applicantId,
            'doc_type'     => $docType,
        ]);

        return $this->redirect('/admin/view/' . $applicantId);
    }

    /**
     * POST /admin/delete-document — delete a documents row + unlink
     * the underlying file from disk.
     *
     * Auth: role='admin', 'expert', or 'document_uploader'.
     */
    public function deleteDocument(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'expert', 'document_uploader']);
        $documentId = (int)$request->input('document_id', 0);
        $applicantId = (int)$request->input('applicant_id', 0);

        $doc = $this->documents->findById($documentId);
        if ($doc === null || $doc->applicantId !== $applicantId) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>مدرک یافت نشد.</p>', 404);
        }

        // Unlink the file from disk (best-effort).
        $this->storage->deleteFile($doc->filePath);
        $this->documents->delete($documentId);

        $this->statusLog->create([
            'applicant_id' => $applicantId,
            'status_name'   => 'حذف مدرک',
            'description'   => 'حذف مدرک «' . $doc->docType . '» (' . $doc->originalName . ') توسط '
                . (string)$this->session->get('admin_display_name', 'مدیر'),
            'triggered_by'  => 'admin',
        ]);

        $this->logger->info('Admin deleted document', [
            '_module'     => 'core',
            'document_id' => $documentId,
            'applicant_id' => $applicantId,
        ]);

        return $this->redirect('/admin/edit/' . $applicantId);
    }

    /**
     * POST /admin/request-payment — mark an applicant as needing payment
     * (set payment_required=1 + payment_requested_at=NOW()), insert a row
     * into the `payments` table so the request shows up on the user's
     * payments list AND on the admin payments list, add an audit-log
     * entry "درخواست پرداخت", and notify the user in their panel.
     *
     * Phase 50 (v2.1.6) — rewritten:
     *   - Now accepts `amount` (positive integer in Toman) and
     *     `description` (free-form Persian note) from the POST body.
     *     A Bootstrap modal in `admin/view_application.php` collects
     *     these (with the suggested amount pre-filled from settings).
     *   - Inserts a `payments` row with `status='در انتظار'` (a new
     *     status meaning "payment requested, awaiting the user to pay"
     *     — distinct from `'در انتظار رسید'` which means "user has
     *     uploaded a receipt, awaiting admin review"). The schema
     *     default is `'در انتظار رسید'` so we set the column explicitly.
     *     `purpose='هزینهٔ ثبت‌نام'` (matches the schema default and
     *     PaymentController's purpose whitelist).
     *     `description` is stored in the new `description` column added
     *     by migration `20261118_001_payments_description_column.sql`.
     *   - Returns a redirect to `/admin/view/{id}` (NOT JSON) so the
     *     admin stays on the case page. A flash message
     *     (`view_app_message`) is shown via the admin/view_application
     *     view's flash banner.
     *
     * Auth: role='admin'.
     */
    public function requestPayment(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $applicantId = (int)$request->input('applicant_id', 0);
        $applicant = $this->applicants->findById($applicantId);
        if ($applicant === null) {
            $this->session->set('view_app_message', 'پرونده یافت نشد.');
            $this->session->set('view_app_message_kind', 'danger');
            return $this->redirect('/admin/applications');
        }

        // Amount: positive integer in Toman. The modal pre-fills the
        // suggested amount from settings (registration_fee_amount →
        // fee_default_amount → 500000 fallback). The admin can edit
        // the value before submitting. Reject anything ≤ 0 or non-numeric.
        $amountRaw = (string)$request->input('amount', '');
        $amount = (int)preg_replace('/[^0-9]/', '', $amountRaw);
        if ($amount <= 0) {
            $this->session->set('view_app_message', 'مبلغ پرداخت باید یک عدد مثبت باشد.');
            $this->session->set('view_app_message_kind', 'danger');
            return $this->redirect('/admin/view/' . $applicantId);
        }
        // Description: free-form Persian note, max 500 chars (the
        // migration's column is VARCHAR(500)). Trim + cap.
        $description = mb_substr(trim((string)$request->input('description', '')), 0, 500);

        // 1) Insert a payments row so the request shows up on:
        //    - the user's /panel/payments (PdoUserAccountRepository
        //      ::getPayments filters by user_account_id)
        //    - the admin's /admin/payments (PaymentController::index
        //      lists all payments, joined with applicants).
        //    status='در انتظار' (new — distinct from 'در انتظار رسید').
        //    user_account_id is set if the applicant is linked to a
        //    user account (so the user sees the row in their panel).
        $userAccountId = $applicant->userAccountId;
        try {
            $this->conn->insert(
                'INSERT INTO payments (applicant_id, user_account_id, purpose, amount, description, status, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [
                    $applicantId,
                    ($userAccountId !== null && $userAccountId > 0) ? $userAccountId : null,
                    'هزینهٔ ثبت‌نام',
                    $amount,
                    $description !== '' ? $description : null,
                    'در انتظار',
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to insert payment row on requestPayment', [
                '_module'        => 'core',
                'applicant_id'   => $applicantId,
                'error'          => $e->getMessage(),
            ]);
            $this->session->set('view_app_message', 'خطا در ثبت درخواست پرداخت.');
            $this->session->set('view_app_message_kind', 'danger');
            return $this->redirect('/admin/view/' . $applicantId);
        }

        // 2) Mark the applicant as needing payment (legacy flag, still
        //    used by the user panel to show the "payment required" badge).
        $this->conn->execute(
            'UPDATE applicants SET payment_required = 1, payment_requested_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$applicantId]
        );
        // 3) Status-log entry (audit trail).
        $this->statusLog->create([
            'applicant_id' => $applicantId,
            'status_name'   => 'درخواست پرداخت',
            'description'   => 'درخواست پرداخت هزینه (' . number_format($amount) . ' تومان) توسط '
                . (string)$this->session->get('admin_display_name', 'مدیر')
                . ($description !== '' ? ' — ' . $description : ''),
            'triggered_by'  => 'admin',
        ]);

        // 4) Best-effort user notification (so they see it in their
        //    panel even before checking the payments page). Skips
        //    silently if the applicant has no user_account_id.
        try {
            if ($userAccountId !== null && $userAccountId > 0) {
                $adminName = (string)$this->session->get('admin_display_name', 'مدیر');
                $this->conn->execute(
                    'INSERT INTO user_notifications (user_account_id, applicant_id, type, title, body, is_read, created_at) '
                    . 'VALUES (?, ?, ?, ?, ?, 0, NOW())',
                    [
                        $userAccountId,
                        $applicantId,
                        'payment_request',
                        'درخواست پرداخت هزینه',
                        sprintf(
                            'برای پروندهٔ شما (کد پیگیری: %s) درخواست پرداخت %s تومان ثبت شد — توسط %s.',
                            $applicant->trackingCode,
                            number_format($amount),
                            $adminName
                        ),
                    ]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to insert payment-request notification', [
                '_module'        => 'core',
                'applicant_id'   => $applicantId,
                'error'          => $e->getMessage(),
            ]);
        }

        $this->logger->info('Admin requested payment', [
            '_module'      => 'core',
            'applicant_id' => $applicantId,
            'amount'       => $amount,
        ]);

        $this->session->set(
            'view_app_message',
            sprintf('درخواست پرداخت %s تومان با موفقیت ثبت شد.', number_format($amount))
        );
        $this->session->set('view_app_message_kind', 'success');
        return $this->redirect('/admin/view/' . $applicantId);
    }

    /**
     * POST /admin/license-state — change an applicant's license_state.
     *
     * Body: applicant_id, new_state ∈ ['جاری', 'صادر شده', 'باطل'].
     * (The full v3 LicenseState enum also has 'منقضی', but the task spec
     * restricts admin manual setting to these three.)
     *
     * Auth: role='admin'.
     */
    public function updateLicenseState(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $applicantId = (int)$request->input('applicant_id', 0);
        $newState = (string)$request->input('new_state', '');
        if (!in_array($newState, ['جاری', 'صادر شده', 'باطل'], true)) {
            return $this->jsonError(422, 'invalid_state', 'وضعیت مجوز نامعتبر.');
        }
        $applicant = $this->applicants->findById($applicantId);
        if ($applicant === null) {
            return $this->jsonError(404, 'not_found', 'پرونده یافت نشد.');
        }

        $this->conn->execute(
            'UPDATE applicants SET license_state = ?, updated_at = NOW() WHERE id = ?',
            [$newState, $applicantId]
        );
        $this->statusLog->create([
            'applicant_id' => $applicantId,
            'status_name'   => 'به‌روزرسانی وضعیت مجوز',
            'description'   => 'وضعیت مجوز به «' . $newState . '» تغییر یافت — توسط '
                . (string)$this->session->get('admin_display_name', 'مدیر'),
            'triggered_by'  => 'admin',
        ]);
        $this->logger->info('Admin updated license state', [
            '_module'      => 'core',
            'applicant_id' => $applicantId,
            'new_state'    => $newState,
        ]);
        return $this->jsonSuccess([
            'applicant_id'  => $applicantId,
            'license_state' => $newState,
        ]);
    }

    /**
     * GET /admin/postal-requests — list postal-code change requests.
     *
     * Renders admin/postal_requests.php with rows from postal_code_changes
     * joined with applicants, filtered by status='در انتظار' (the default
     * view; other statuses can be filtered via ?status= query param).
     *
     * Auth: role='admin' or 'expert'.
     */
    public function postalRequests(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'expert']);
        $statusFilter = (string)$request->queryParam('status', 'در انتظار');
        if (!in_array($statusFilter, ['در انتظار', 'تأیید', 'رد', ''], true)) {
            $statusFilter = 'در انتظار';
        }
        $sql = "SELECT pc.id, pc.applicant_id, pc.old_postal_code, pc.new_postal_code,
                       pc.status, pc.reject_reason, pc.requested_at, pc.reviewed_at,
                       a.tracking_code, a.first_name, a.last_name, a.national_code
                  FROM postal_code_changes pc
                  JOIN applicants a ON a.id = pc.applicant_id";
        $args = [];
        if ($statusFilter !== '') {
            $sql .= ' WHERE pc.status = ?';
            $args[] = $statusFilter;
        }
        $sql .= ' ORDER BY pc.requested_at DESC';
        $rows = $this->conn->all($sql, $args);
        $pendingCount = (int)$this->conn->scalar(
            "SELECT COUNT(*) FROM postal_code_changes WHERE status = 'در انتظار'"
        );
        return $this->render('admin/postal_requests', [
            'title'        => 'درخواست‌های تغییر کد پستی',
            'rows'         => $rows,
            'statusFilter' => $statusFilter,
            'pendingCount' => $pendingCount,
        ]);
    }

    /**
     * POST /admin/postal-requests/approve — approve a postal-code change:
     * apply the new postal_code to the applicants row, mark the request
     * 'تأیید', log to status_log.
     *
     * Auth: role='admin' or 'expert'.
     */
    public function approvePostalRequest(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'expert']);
        $requestId = (int)$request->input('request_id', 0);
        $row = $this->conn->one('SELECT * FROM postal_code_changes WHERE id = ?', [$requestId]);
        if ($row === null || $row['status'] !== 'در انتظار') {
            return $this->jsonError(404, 'not_found', 'درخواست یافت نشد یا پیش‌تر بررسی شده.');
        }

        $adminId = (int)$this->session->get('admin_id', 0);
        $this->conn->transactional(function () use ($row, $adminId): void {
            $this->conn->execute(
                'UPDATE applicants SET postal_code = ?, updated_at = NOW() WHERE id = ?',
                [$row['new_postal_code'], (int)$row['applicant_id']]
            );
            $this->conn->execute(
                'UPDATE postal_code_changes SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
                ['تأیید', $adminId, (int)$row['id']]
            );
            $this->statusLog->create([
                'applicant_id'  => (int)$row['applicant_id'],
                'status_name'   => 'تأیید تغییر کد پستی',
                'description'   => 'کد پستی از ' . $row['old_postal_code'] . ' به ' . $row['new_postal_code']
                    . ' تغییر یافت — تأیید توسط ' . (string)$this->session->get('admin_display_name', 'مدیر'),
                'triggered_by'  => 'admin',
            ]);
        });

        $this->logger->info('Postal change approved', [
            '_module'     => 'core',
            'request_id'  => $requestId,
            'applicant_id' => (int)$row['applicant_id'],
        ]);
        return $this->jsonSuccess(['request_id' => $requestId, 'status' => 'تأیید']);
    }

    /**
     * POST /admin/postal-requests/reject — reject a postal-code change.
     *
     * Auth: role='admin' or 'expert'.
     */
    public function rejectPostalRequest(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'expert']);
        $requestId = (int)$request->input('request_id', 0);
        $reason = Validator::sanitizeText($request->input('reason', ''), 255);
        $row = $this->conn->one('SELECT * FROM postal_code_changes WHERE id = ?', [$requestId]);
        if ($row === null || $row['status'] !== 'در انتظار') {
            return $this->jsonError(404, 'not_found', 'درخواست یافت نشد یا پیش‌تر بررسی شده.');
        }

        $adminId = (int)$this->session->get('admin_id', 0);
        $this->conn->execute(
            'UPDATE postal_code_changes SET status = ?, reject_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
            ['رد', $reason !== '' ? $reason : null, $adminId, (int)$row['id']]
        );
        $this->statusLog->create([
            'applicant_id' => (int)$row['applicant_id'],
            'status_name'   => 'رد درخواست تغییر کد پستی',
            'description'   => 'درخواست تغییر از ' . $row['old_postal_code'] . ' به ' . $row['new_postal_code']
                . ' رد شد.' . ($reason !== '' ? ' دلیل: ' . $reason : ''),
            'triggered_by'  => 'admin',
        ]);

        $this->logger->info('Postal change rejected', [
            '_module'    => 'core',
            'request_id' => $requestId,
            'reason'     => $reason,
        ]);
        return $this->jsonSuccess(['request_id' => $requestId, 'status' => 'رد']);
    }

    /**
     * GET /admin/my-tasks — role-filtered task list.
     *
     * For role='admin': show all applications with current_status IN
     * ('تأیید اولیه', 'بررسی کارشناسی رشته', 'احراز نشانی') — the statuses
     * that need admin attention.
     * For role='expert': show applications with current_status IN
     * ('بررسی کارشناسی رشته') (per workflow.php task_lists)
     * For role='document_uploader': show applications needing documents,
     * i.e. current_status IN ('ثبت درخواست صدور', 'بارگذاری مدارک به سازمان')
     * Other roles: show empty (or fall through to a default).
     *
     * Renders the same admin/applications.php view, with a role-filtered
     * row set and a custom title.
     */
    public function myTasks(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $role = (string)$this->session->get('admin_role', 'admin');

        $workflow = (array)Config::get('workflow.task_lists', []);
        $statuses = $workflow[$role] ?? [];
        if ($role === 'admin') {
            // Admin sees the broader pending-review set.
            $statuses = ['تأیید اولیه', 'بررسی کارشناسی رشته', 'احراز نشانی'];
        }
        $rows = [];
        $total = 0;
        if ($statuses !== []) {
            $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
            $rows = $this->conn->all(
                "SELECT SQL_CALC_FOUND_ROWS * FROM applicants
                  WHERE deleted_at IS NULL AND current_status IN ({$placeholders})
                  ORDER BY priority DESC, registration_completed_at DESC, id DESC
                  LIMIT 100",
                $statuses
            );
            $total = (int)$this->conn->scalar('SELECT FOUND_ROWS()');
        }

        // Reuse admin/applications.php — its render contract is
        // $rows + $total + $page + $pageSize + $filters. We synthesize
        // the pagination metadata (single page, 100 rows max).
        return $this->render('admin/applications', [
            'title'    => 'وظایف من',
            'rows'     => $rows,
            'total'    => $total,
            'page'     => 1,
            'pageSize' => 100,
            'filters'  => ['current_status' => $statuses],
        ]);
    }

    /**
     * GET /admin/status-cycle — placeholder redirect to the public guide.
     *
     * Phase 22 (v1.9.4) — originally rendered the full 18-status workflow
     * (14 shared + 4 household-only) as Bootstrap cards.
     *
     * Phase 41 (v2.0.7) — the cycle diagram was moved to /guide#status-cycle
     * so applicants and admins share the same authoritative reference.
     *
     * Phase 51 (v2.1.7) — removed the last admin UI links pointing here
     * ("مشاهدهٔ چرخهٔ کامل" in view_application.php, "چرخهٔ ۱۸ وضعیت" on
     * the dashboard). The 18-status cycle now ONLY appears in guide.php.
     *
     * Auth: requireRole(['admin']) — kept so any old bookmark still
     * resolves to the notice page (which redirects to /guide#status-cycle).
     */
    public function statusCycle(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);

        return $this->render('admin/status_cycle', [
            'title' => 'چرخهٔ وضعیت‌ها',
        ]);
    }

    /**
     * Validate an Iranian postal code: exactly 10 digits.
     * Returns the trimmed value, or null if invalid.
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
