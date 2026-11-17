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
        } catch (\Throwable $e) {
            $cancelled = 0;
            $prioritised = 0;
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
        return $this->render('admin/view_application', [
            'applicant' => $applicant,
            'statusLog' => $statusLog,
        ]);
    }

    /**
     * POST /admin/change-status — change an applicant's status.
     */
    public function changeStatus(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)$request->input('applicant_id', 0);
        $newStatusSlug = (string)$request->input('new_status', '');
        $description = (string)$request->input('description', '');

        $applicant = $this->applicants->findById($id);
        if ($applicant === null) {
            return $this->jsonError(404, 'not_found');
        }
        $newStatus = ApplicantStatus::fromSlug($newStatusSlug);
        if ($newStatus === null) {
            return $this->jsonError(422, 'invalid_status', 'وضعیت نامعتبر.');
        }
        $role = (string)$this->session->get('admin_role', 'admin');
        $this->validateStatusTransition($applicant->currentStatus, $newStatus, $role);

        $triggeredBy = $role === 'admin' ? 'admin' : $role;
        $this->applicants->changeStatus($id, $newStatus, $triggeredBy, $description);
        $this->logger->info('Admin changed applicant status', [
            '_module'        => 'core',
            'applicant_id'   => $id,
            'from'           => $applicant->currentStatus->value,
            'to'             => $newStatus->value,
            'by'             => $this->session->get('admin_id'),
        ]);
        return $this->jsonSuccess(['changed' => true, 'new_status' => $newStatus->value]);
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
     * Validates inputs (Persian name, 10-digit national code with parity
     * check, 11-digit mobile, 10-digit postal code, valid service_type_id)
     * and creates a new applicants row with:
     *   - current_status = 'تأیید اولیه' (admin-created, not draft)
     *   - tracking_code  = HG-XXXXXXXX (CSPRNG)
     *   - registration_started_at = registration_completed_at = NOW()
     *
     * The optional `address` field is stored as property_details.address_street
     * (the applicants table has no address column — only postal_code).
     *
     * On success: redirect to /admin/view/{id}.
     * On validation error: re-render the form with errors + submitted values.
     */
    public function registerProcess(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();

        $firstName  = Validator::nonEmptyString($body['first_name'] ?? '', 50);
        $lastName   = Validator::nonEmptyString($body['last_name'] ?? '', 50);
        $nationalCode = Validator::nationalCode($body['national_code'] ?? '');
        $mobile     = Validator::mobile($body['mobile'] ?? '');
        $postalCode = self::validPostalCode($body['postal_code'] ?? '');
        $address    = Validator::sanitizeText($body['address'] ?? '', 255);
        $serviceTypeId = Validator::positiveIntOrNull($body['service_type_id'] ?? '');

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
            'service_type_id'         => $serviceTypeId,
            'first_name'              => $firstName,
            'last_name'               => $lastName,
            'national_code'           => $nationalCode,
            'postal_code'             => $postalCode,
            'mobile'                  => $mobile,
            'tracking_code'           => $trackingCode,
            'current_status'          => ApplicantStatus::InitialApproval->value, // 'تأیید اولیه'
            'registration_started_at'  => $now,
            'registration_completed_at' => $now,
        ]);

        // Optional address → property_details.address_street (the
        // applicants table has no address column; we use the 1:1
        // property_details row as the canonical address home).
        if ($address !== '') {
            $this->jobAndProperty->upsertPropertyDetail($applicantId, [
                'ownership_status' => 'شخص',
                'province'          => '',
                'county'            => '',
                'city'              => '',
                'electricity_bill_id' => '',
                'address_street'    => $address,
            ]);
        }

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

        return $this->render('admin/edit_application', [
            'title'         => 'ویرایش پروندهٔ متقاضی',
            'applicant'     => $applicant,
            'row'           => $row,
            'jobDetail'     => $jobDetail,
            'propertyDetail' => $propertyDetail,
            'documents'     => $documents,
            'serviceTypes'  => $serviceTypes,
            'errors'        => [],
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
            return $this->render('admin/edit_application', [
                'title'         => 'ویرایش پروندهٔ متقاضی',
                'applicant'     => $applicant,
                'row'           => array_merge($row, $body),
                'jobDetail'     => $this->jobAndProperty->findJobDetailByApplicantId($id),
                'propertyDetail' => $this->jobAndProperty->findPropertyDetailByApplicantId($id),
                'documents'     => $this->documents->findByApplicantId($id),
                'serviceTypes'  => $this->serviceTypes->getActive(),
                'errors'        => $errors,
            ]);
        }

        $update = array_filter([
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'mobile'     => $mobile,
            'postal_code' => $postalCode,
            'father_name' => $fatherName !== '' ? $fatherName : null,
            'service_type_id' => $serviceTypeId,
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
        if ($ownershipStatus !== null || $province !== '' || $city !== '' || $addressStreet !== '' || $address !== '') {
            $this->jobAndProperty->upsertPropertyDetail($id, [
                'ownership_status'   => $ownershipStatus ?? 'شخص',
                'province'            => $province,
                'county'              => $county,
                'city'                => $city,
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
     * (set payment_required=1 + payment_requested_at=NOW()). Adds an
     * audit-log entry "درخواست پرداخت".
     *
     * Auth: role='admin'.
     *
     * Returns JSON: {success:true, data:{applicant_id, payment_required:true}}
     */
    public function requestPayment(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $applicantId = (int)$request->input('applicant_id', 0);
        $applicant = $this->applicants->findById($applicantId);
        if ($applicant === null) {
            return $this->jsonError(404, 'not_found', 'پرونده یافت نشد.');
        }

        $this->conn->execute(
            'UPDATE applicants SET payment_required = 1, payment_requested_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$applicantId]
        );
        $this->statusLog->create([
            'applicant_id' => $applicantId,
            'status_name'   => 'درخواست پرداخت',
            'description'   => 'درخواست پرداخت هزینه توسط '
                . (string)$this->session->get('admin_display_name', 'مدیر'),
            'triggered_by'  => 'admin',
        ]);

        $this->logger->info('Admin requested payment', [
            '_module'      => 'core',
            'applicant_id' => $applicantId,
        ]);
        return $this->jsonSuccess([
            'applicant_id'      => $applicantId,
            'payment_required'  => true,
            'payment_requested_at' => date('Y-m-d H:i:s'),
        ]);
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
