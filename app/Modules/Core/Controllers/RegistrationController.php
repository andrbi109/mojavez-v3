<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Domain\Applicant;
use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Modules\Core\Services\FileStorage;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\DocumentRepositoryInterface;
use App\Shared\Contracts\JobAndPropertyRepositoryInterface;
use App\Shared\Contracts\LicenseFileRepositoryInterface;
use App\Shared\Contracts\LicenseTitleRepositoryInterface;
use App\Shared\Contracts\ServiceTypeRepositoryInterface;
use App\Shared\Contracts\StatusLogRepositoryInterface;
use App\Shared\Contracts\UserAccountRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\Normalizer;
use App\Shared\Support\PersianDate;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;

/**
 * RegistrationController — the 4-step wizard registration flow.
 *
 * Phase 44a (v2.1): the RegistrationController lost 9 methods during a git
 * merge conflict resolution. This file recreates all 13 methods required by
 * the routes registered in Core/Module.php.
 *
 * Routes (registered by the Core module):
 *   GET  /register                       → index           (select service type)
 *   GET  /register/service/{id}          → selectService    (create applicant, redirect to step 1)
 *   GET  /register/step/{n}              → stepByPosition   (delegate to step{n})
 *   POST /register/step/{n}              → processByPosition(delegate to processStep{n})
 *   GET  /register/step1..4              → step1..4         (legacy aliases)
 *   POST /register/step1..4              → processStep1..4  (legacy aliases)
 *   GET  /register/success/{trackingCode} → success         (success page)
 *
 * Wizard flow:
 *   1. GET /register → select_service view with active service_types.
 *   2. GET /register/service/{id} → create applicants row with
 *      current_status='پیش‌نویس', service_type_id={id},
 *      user_account_id=session user_id, mobile=session user_mobile,
 *      tracking_code=HG-XXXXXXXX (via Hash::generateTrackingCode).
 *      Store applicant_id in session under 'wizard.applicant_id'.
 *      Redirect to /register/step/1.
 *   3. GET /register/step/1 → step1 view (identity form).
 *   4. POST /register/step/1 → save identity, redirect to step 2.
 *   5. GET /register/step/2 → step2 view (property form with provinces + townships).
 *   6. POST /register/step/2 → save property via upsertPropertyDetail(),
 *      update postal_code on applicants, redirect to step 3.
 *   7. GET /register/step/3 → step3 view (job form with license titles).
 *   8. POST /register/step/3 → save job via upsertJobDetail(), redirect to step 4.
 *   9. GET /register/step/4 → step4 view (document upload + review).
 *  10. POST /register/step/4 → upload docs, set registration_completed_at=NOW(),
 *      consent_external_share=1, changeStatus to InfoComplete (Phase 52:
 *      was InitialApproval — the wizard now leaves the applicant at
 *      "تکمیل اطلاعات" so the admin can review and advance the status
 *      manually), remove wizard.applicant_id, redirect to
 *      /register/success/{trackingCode}.
 *  11. GET /register/success/{trackingCode} → success view with tracking code.
 */
final class RegistrationController extends Controller
{
    /**
     * The v2-style named file inputs that handleDocumentUploads() knows
     * about. Each entry maps the $_FILES key → the documents.doc_type
     * label that gets stored alongside the file.
     */
    public const DOC_FIELD_MAP = [
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

    /** Multi-file field (array of files under one $_FILES key). */
    public const DOC_FIELD_ARRAY = 'product_photos';

    /** Session key under which the in-progress applicant id is stored. */
    public const SESSION_WIZARD_KEY = 'wizard.applicant_id';

    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly FileStorage $storage,
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly JobAndPropertyRepositoryInterface $jobAndProperty,
        private readonly ServiceTypeRepositoryInterface $serviceTypes,
        private readonly LicenseTitleRepositoryInterface $licenseTitles,
        private readonly StatusLogRepositoryInterface $statusLog,
        private readonly DocumentRepositoryInterface $documents,
        private readonly LicenseFileRepositoryInterface $licenseFiles,
        private readonly UserAccountRepositoryInterface $userAccounts,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /* ============================================================
     * Wizard entry — /register
     * ============================================================ */

    /**
     * GET /register — render the select_service view with all active
     * service_types. This is the wizard's entry point: the user picks a
     * service type, which sends them to /register/service/{id} to start
     * a fresh applicant row.
     */
    public function index(Request $request, array $params = []): Response
    {
        // The wizard requires a logged-in user_account so we can attach
        // the new applicant row to the user.
        $this->requireUserLogin();

        $serviceTypes = $this->serviceTypes->getActive();
        return $this->render('registration/select_service', [
            'title'        => 'انتخاب نوع خدمت',
            'serviceTypes' => $serviceTypes,
        ]);
    }

    /**
     * GET /register/service/{id} — create a new applicant row for the
     * selected service type and redirect to step 1.
     *
     * The new row has:
     *   current_status = 'پیش‌نویس' (Draft)
     *   service_type_id = {id}
     *   user_account_id = session user_id
     *   mobile = (from user_account, falls back to session user_mobile)
     *   first_name / last_name = (from the user's most recent previous
     *                             application, if any — Phase 52 / v2.1.8)
     *   tracking_code = HG-XXXXXXXX (via Hash::generateTrackingCode, retried on collision)
     *
     * The applicant_id is stored in the session under 'wizard.applicant_id'
     * so the step handlers know which row to update.
     *
     * Phase 52 (v2.1.8) — pre-fills identity fields from the user's
     * `user_account` row (mobile) and from their most recent previous
     * application (first_name, last_name) so a returning user does not
     * have to re-type them. The previous application's property +
     * job data is also pre-filled in steps 2 and 3 (see step2() and
     * step3()).
     */
    public function selectService(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();

        $serviceId = (int)($params['id'] ?? 0);
        $service = $this->serviceTypes->findById($serviceId);
        if ($service === null || (int)($service['is_active'] ?? 0) === 0) {
            return $this->redirect('/register');
        }

        $userId = (int)$this->session->get('user_id', 0);
        $sessionMobile = (string)$this->session->get('user_mobile', '');

        // ---- Phase 52 (v2.1.8): auto-fill from user_account + previous app ----
        // 1. Load the user_account row (mobile, display_name).
        $userAccount = $userId > 0 ? $this->userAccounts->findById($userId) : null;
        $userMobile = (string)($userAccount['mobile'] ?? '');
        if ($userMobile === '') {
            $userMobile = $sessionMobile;
        }

        // 2. Load the user's most recent previous application (excluding
        //    the row we are about to insert — there isn't one yet at this
        //    point, so the simple ORDER BY ... LIMIT 1 suffices). We
        //    pre-fill first_name + last_name from it; the previous
        //    application's property + job data is loaded lazily by
        //    step2()/step3() when the current applicant has none yet.
        $prevApplicant = $this->mostRecentPreviousApplicant($userId, 0);
        $prevFirstName = $prevApplicant?->firstName ?? '';
        $prevLastName  = $prevApplicant?->lastName ?? '';

        // Generate a unique tracking code (retry on the rare UNIQUE collision).
        $trackingCode = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $this->hash->generateTrackingCode();
            $exists = $this->connection()->scalar(
                'SELECT id FROM applicants WHERE tracking_code = ?',
                [$candidate]
            );
            if ($exists === null) {
                $trackingCode = $candidate;
                break;
            }
        }
        if ($trackingCode === null) {
            return $this->redirect('/register');
        }

        $now = date('Y-m-d H:i:s');
        $applicantId = $this->applicants->create([
            'service_type_id'           => $serviceId,
            'user_account_id'            => $userId > 0 ? $userId : null,
            'first_name'                 => $prevFirstName,
            'last_name'                  => $prevLastName,
            'national_code'              => '',
            'postal_code'                => '',
            'mobile'                     => $userMobile,
            'tracking_code'              => $trackingCode,
            'current_status'             => ApplicantStatus::Draft->value,  // 'پیش‌نویس'
            'registration_started_at'     => $now,
            'consent_external_share'     => 0,
        ]);

        $this->session->set(self::SESSION_WIZARD_KEY, $applicantId);

        $this->logger->info('Wizard applicant created', [
            '_module'         => 'core',
            'applicant_id'    => $applicantId,
            'service_type_id' => $serviceId,
            'user_id'         => $userId,
            'prefilled_name'  => $prevFirstName !== '' || $prevLastName !== '',
        ]);

        return $this->redirect('/register/step/1');
    }

    /* ============================================================
     * Position-based dispatchers — /register/step/{n}
     * ============================================================ */

    /**
     * GET /register/step/{n} — dispatch to step{n} based on the position.
     */
    public function stepByPosition(Request $request, array $params = []): Response
    {
        $n = (int)($params['n'] ?? 0);
        if ($n < 1 || $n > 4) {
            return $this->redirect('/register');
        }
        $method = 'step' . $n;
        return $this->$method($request, $params);
    }

    /**
     * POST /register/step/{n} — dispatch to processStep{n} based on the position.
     */
    public function processByPosition(Request $request, array $params = []): Response
    {
        $n = (int)($params['n'] ?? 0);
        if ($n < 1 || $n > 4) {
            return $this->redirect('/register');
        }
        $method = 'processStep' . $n;
        return $this->$method($request, $params);
    }

    /* ============================================================
     * Step 1 — identity (GET + POST)
     * ============================================================ */

    /**
     * GET /register/step1 — render the identity form with the current
     * applicant's data pre-filled.
     *
     * Phase 52 (v2.1.8) — the user_account row is loaded and its
     * `display_name` is passed to the view as a hint (rendered under
     * the identity card as a small "نام نمایشی حساب" line). The
     * `mobile` and `first_name`/`last_name` pre-fills already happen
     * in `selectService()` (they are written to the applicants row at
     * creation time so `applicantValues()` surfaces them here).
     */
    public function step1(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }

        $serviceType = $this->serviceTypes->findById((int)$applicant->serviceTypeId) ?? [];
        $values = $this->applicantValues($applicant);

        // Phase 52 (v2.1.8) — load the user's display_name as a hint.
        $userAccount = $applicant->userAccountId !== null
            ? $this->userAccounts->findById((int)$applicant->userAccountId)
            : null;
        $userDisplayName = (string)($userAccount['display_name'] ?? '');

        return $this->render('registration/step1', [
            'title'           => 'مرحلهٔ ۱ — اطلاعات هویتی',
            'serviceType'     => $serviceType,
            'values'          => $values,
            'errors'          => [],
            'userDisplayName' => $userDisplayName,
        ]);
    }

    /**
     * POST /register/step1 — validate and save identity fields.
     *
     * Fields: first_name, last_name, national_code, birth_date, father_name,
     * mobile, applicant_type, marital_status, children_count,
     * physical_condition, disability_details.
     *
     * The step1 view submits the first 6 fields; the remaining 5 are
     * accepted from the body if present and otherwise default to:
     *   applicant_type      = 'حقیقی'
     *   marital_status      = 'مجرد'
     *   children_count      = 0
     *   physical_condition  = 'سالم'
     *   disability_details  = null
     *
     * On success → redirect to /register/step/2.
     * On error   → re-render step1 with errors + posted values.
     */
    public function processStep1(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }

        $body = $request->body();
        $serviceType = $this->serviceTypes->findById((int)$applicant->serviceTypeId) ?? [];

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

        // Birth date — parse Jalali text "YYYY/MM/DD" (Persian or Latin digits)
        // into 3 ints, then convert to Gregorian.
        $birthDate = $this->birthDateFromInputString((string)($body['birth_date'] ?? ''));

        // Marital-status coupling: children_count is only meaningful for متأهل.
        if ($maritalStatus !== 'متأهل') {
            $childrenCount = 0;
        }
        // Physical-condition coupling: clear disability_details if سالم.
        if ($physicalCondition !== 'معلولیت') {
            $disabilityDetails = '';
        }

        $errors = [];
        if ($firstName === null) {
            $errors['first_name'][] = 'نام الزامی است (حداکثر ۵۰ نویسه).';
        }
        if ($lastName === null) {
            $errors['last_name'][] = 'نام خانوادگی الزامی است (حداکثر ۵۰ نویسه).';
        }
        if ($nationalCode === null) {
            $errors['national_code'][] = 'کد ملی نامعتبر است (۱۰ رقم با کنترل parity).';
        }
        if ($mobile === null) {
            $errors['mobile'][] = 'موبایل نامعتبر است (فرمت 09XXXXXXXXX).';
        }
        if (($body['birth_date'] ?? '') !== '' && $birthDate === null) {
            $errors['birth_date'][] = 'تاریخ تولد معتبر نیست (فرمت YYYY/MM/DD شمسی).';
        }

        if ($errors !== []) {
            return $this->render('registration/step1', [
                'title'       => 'مرحلهٔ ۱ — اطلاعات هویتی',
                'serviceType' => $serviceType,
                'values'      => $body,
                'errors'      => $errors,
            ]);
        }

        $this->applicants->update($applicant->id, [
            'first_name'         => $firstName,
            'last_name'          => $lastName,
            'national_code'      => $nationalCode,
            'mobile'             => $mobile,
            'birth_date'         => $birthDate,
            'father_name'        => $fatherName !== '' ? $fatherName : null,
            'applicant_type'     => $applicantType,
            'marital_status'     => $maritalStatus,
            'children_count'     => $childrenCount,
            'physical_condition' => $physicalCondition,
            'disability_details' => $disabilityDetails !== '' ? $disabilityDetails : null,
        ]);

        return $this->redirect('/register/step/2');
    }

    /* ============================================================
     * Step 2 — property (GET + POST)
     * ============================================================ */

    /**
     * GET /register/step2 — render the property form with provinces +
     * townships from national_lookups.
     */
    public function step2(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }

        // national_lookups has no sort_order column; order by code (which is
        // the canonical province code '01'..'08' from the seed migration).
        $provinces = $this->connection()->all(
            "SELECT id, code, title FROM national_lookups
             WHERE kind = 'province' AND is_active = 1
             ORDER BY code"
        );
        $townships = $this->connection()->all(
            "SELECT id, code, title, parent_code AS province_id FROM national_lookups
             WHERE kind = 'township' AND is_active = 1
             ORDER BY parent_code, code"
        );

        // Pre-fill values from existing property_details if present.
        // Phase 52 (v2.1.8) — if the current applicant has no property
        // details row yet, fall back to the user's most recent previous
        // application's property_details so returning users don't have
        // to re-enter their business address.
        $values = [];
        $prop = $this->jobAndProperty->findPropertyDetailByApplicantId($applicant->id);
        $propPostalCode = $applicant->postalCode ?? '';
        if ($prop === null) {
            $prevId = $this->mostRecentPreviousApplicantId($applicant->userAccountId, $applicant->id);
            if ($prevId !== null) {
                $prop = $this->jobAndProperty->findPropertyDetailByApplicantId($prevId);
                $prevApplicant = $this->applicants->findById($prevId);
                $propPostalCode = $prevApplicant?->postalCode ?? $propPostalCode;
            }
        }
        if ($prop !== null) {
            // Look up the province + township ids by their titles.
            $values['postal_code']    = $propPostalCode;
            $values['address_street'] = $prop->addressStreet ?? '';
            $values['address_alley']   = $prop->addressAlley ?? '';
            $values['address_plaque']  = $prop->addressPlaque ?? '';

            // Resolve province_id by matching national_lookups.title == property.province.
            if ($prop->province !== '') {
                $row = $this->connection()->one(
                    "SELECT id FROM national_lookups WHERE kind = 'province' AND title = ? LIMIT 1",
                    [$prop->province]
                );
                if ($row !== null) {
                    $values['province_id'] = (string)$row['id'];
                }
            }
            // Resolve township_id by matching national_lookups.title == property.township (or county).
            $townshipTitle = $prop->township ?? $prop->county;
            if ($townshipTitle !== '') {
                $row = $this->connection()->one(
                    "SELECT id FROM national_lookups WHERE kind = 'township' AND title = ? LIMIT 1",
                    [$townshipTitle]
                );
                if ($row !== null) {
                    $values['township_id'] = (string)$row['id'];
                }
            }
        } else {
            $values['postal_code'] = $propPostalCode;
        }

        return $this->render('registration/step2', [
            'title'     => 'مرحلهٔ ۲ — محل کسب‌وکار',
            'provinces' => $provinces,
            'townships' => $townships,
            'values'    => $values,
            'errors'    => [],
        ]);
    }

    /**
     * POST /register/step2 — validate and save property details via
     * upsertPropertyDetail(), and update postal_code on the applicants row.
     *
     * The step2 view submits: province_id, township_id, address_street,
     * address_alley, address_plaque, postal_code.
     *
     * We resolve the province + township titles from national_lookups by id,
     * then upsert property_details with all NOT NULL fields populated
     * (province, county, city, township, electricity_bill_id).
     *
     * On success → redirect to /register/step/3.
     * On error   → re-render step2 with errors + posted values.
     */
    public function processStep2(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }

        $body = $request->body();

        $provinceId  = (int)($body['province_id'] ?? 0);
        $townshipId  = (int)($body['township_id'] ?? 0);
        $postalCode  = self::validPostalCode($body['postal_code'] ?? '');
        $addressStreet = Validator::sanitizeText($body['address_street'] ?? '', 100);
        $addressAlley  = Validator::sanitizeText($body['address_alley'] ?? '', 100);
        $addressPlaque = Validator::sanitizeText($body['address_plaque'] ?? '', 20);

        $errors = [];
        $provinceTitle = '';
        $townshipTitle = '';
        if ($provinceId <= 0) {
            $errors['province_id'][] = 'استان الزامی است.';
        } else {
            $row = $this->connection()->one(
                "SELECT title FROM national_lookups WHERE kind = 'province' AND id = ? LIMIT 1",
                [$provinceId]
            );
            if ($row === null) {
                $errors['province_id'][] = 'استان انتخاب‌شده یافت نشد.';
            } else {
                $provinceTitle = (string)$row['title'];
            }
        }
        if ($townshipId <= 0) {
            $errors['township_id'][] = 'شهرستان الزامی است.';
        } else {
            $row = $this->connection()->one(
                "SELECT title FROM national_lookups WHERE kind = 'township' AND id = ? LIMIT 1",
                [$townshipId]
            );
            if ($row === null) {
                $errors['township_id'][] = 'شهرستان انتخاب‌شده یافت نشد.';
            } else {
                $townshipTitle = (string)$row['title'];
            }
        }
        if ($postalCode === null) {
            $errors['postal_code'][] = 'کد پستی نامعتبر است (۱۰ رقم).';
        }

        if ($errors !== []) {
            $provinces = $this->connection()->all(
                "SELECT id, code, title FROM national_lookups
                 WHERE kind = 'province' AND is_active = 1 ORDER BY code"
            );
            $townships = $this->connection()->all(
                "SELECT id, code, title, parent_code AS province_id FROM national_lookups
                 WHERE kind = 'township' AND is_active = 1 ORDER BY parent_code, code"
            );
            return $this->render('registration/step2', [
                'title'     => 'مرحلهٔ ۲ — محل کسب‌وکار',
                'provinces' => $provinces,
                'townships' => $townships,
                'values'    => $body,
                'errors'    => $errors,
            ]);
        }

        // Update postal_code on the applicants row.
        $this->applicants->update($applicant->id, [
            'postal_code' => $postalCode,
        ]);

        // Upsert property_details. property_details.province + county + city
        // + electricity_bill_id are NOT NULL, so we populate them all.
        // The township column (Phase 24) is preferred by the matcher over
        // county — we set both to the resolved township title.
        $this->jobAndProperty->upsertPropertyDetail($applicant->id, [
            'ownership_status'    => 'شخص',
            'province'             => $provinceTitle,
            'county'               => $townshipTitle,
            'city'                 => $townshipTitle,
            'township'             => $townshipTitle,
            'electricity_bill_id'  => '',
            'address_street'       => $addressStreet !== '' ? $addressStreet : null,
            'address_alley'        => $addressAlley !== '' ? $addressAlley : null,
            'address_plaque'       => $addressPlaque !== '' ? $addressPlaque : null,
        ]);

        return $this->redirect('/register/step/3');
    }

    /* ============================================================
     * Step 3 — job / license (GET + POST)
     * ============================================================ */

    /**
     * GET /register/step3 — render the job form with license titles for
     * the applicant's service type.
     *
     * Phase 52 (v2.1.8) — if the current applicant has no job_details
     * row yet, fall back to the user's most recent previous application's
     * job_details so returning users get a sensible default for the
     * license_title field. (If the previous application's service type
     * was different and the license_title isn't valid for the current
     * service type, the rendered <select> options simply won't include
     * it and the field stays empty — no harm done.)
     */
    public function step3(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }

        $licenseTitles = $this->licenseTitles->getAll((int)$applicant->serviceTypeId);

        // Pre-fill from existing job_details if present.
        $values = [];
        $job = $this->jobAndProperty->findJobDetailByApplicantId($applicant->id);
        if ($job === null) {
            $prevId = $this->mostRecentPreviousApplicantId($applicant->userAccountId, $applicant->id);
            if ($prevId !== null) {
                $job = $this->jobAndProperty->findJobDetailByApplicantId($prevId);
            }
        }
        if ($job !== null) {
            // Look up the license_title id by title string.
            $row = $this->connection()->one(
                'SELECT id FROM license_titles WHERE title = ? LIMIT 1',
                [$job->licenseTitle]
            );
            if ($row !== null) {
                $values['license_title_id'] = (string)$row['id'];
            }
            $values['business_description'] = '';
        }

        return $this->render('registration/step3', [
            'title'         => 'مرحلهٔ ۳ — مشخصات شغلی',
            'licenseTitles' => $licenseTitles,
            'fields'        => [],   // custom wizard fields — not implemented in this phase
            'values'        => $values,
            'errors'        => [],
        ]);
    }

    /**
     * POST /register/step3 — validate and save job details via
     * upsertJobDetail() with (license_title, experience_months,
     * training_method). The step3 view submits license_title_id and
     * business_description; experience_months and training_method
     * default to 0 and 'در خانه' respectively (view doesn't collect them).
     *
     * On success → redirect to /register/step/4.
     * On error   → re-render step3 with errors + posted values.
     */
    public function processStep3(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }

        $body = $request->body();
        $licenseTitleId = (int)($body['license_title_id'] ?? 0);
        $experienceMonths = Validator::intInRange($body['experience_months'] ?? 0, 0, 1200) ?? 0;
        $trainingMethod = Validator::inEnum(
            $body['training_method'] ?? '',
            ['در خانه', 'استاد شاگردی', 'کارگاهی']
        ) ?? 'در خانه';

        $errors = [];
        $licenseTitle = '';
        if ($licenseTitleId <= 0) {
            $errors['license_title_id'][] = 'عنوان مجوز الزامی است.';
        } else {
            $row = $this->licenseTitles->findById($licenseTitleId);
            if ($row === null) {
                $errors['license_title_id'][] = 'عنوان مجوز انتخاب‌شده یافت نشد.';
            } else {
                $licenseTitle = (string)$row['title'];
            }
        }

        if ($errors !== []) {
            $licenseTitles = $this->licenseTitles->getAll((int)$applicant->serviceTypeId);
            return $this->render('registration/step3', [
                'title'         => 'مرحلهٔ ۳ — مشخصات شغلی',
                'licenseTitles' => $licenseTitles,
                'fields'        => [],
                'values'        => $body,
                'errors'        => $errors,
            ]);
        }

        $this->jobAndProperty->upsertJobDetail($applicant->id, [
            'license_title'     => $licenseTitle,
            'experience_months' => $experienceMonths,
            'training_method'   => $trainingMethod,
        ]);

        return $this->redirect('/register/step/4');
    }

    /* ============================================================
     * Step 4 — documents + review (GET + POST)
     * ============================================================ */

    /**
     * GET /register/step4 — render the document upload form with a
     * read-only summary of the data collected in steps 1..3.
     */
    public function step4(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }

        $property = $this->jobAndProperty->findPropertyDetailByApplicantId($applicant->id);
        $job = $this->jobAndProperty->findJobDetailByApplicantId($applicant->id);

        $summary = [
            'first_name'   => $applicant->firstName,
            'last_name'    => $applicant->lastName,
            'national_code' => $applicant->nationalCode,
            'father_name'  => $applicant->fatherName ?? '',
            'mobile'       => $applicant->mobile,
            'province'     => $property?->province ?? '',
            'township'     => $property?->township ?? $property?->county ?? '',
            'postal_code'  => $applicant->postalCode ?? '',
            'address'      => $property?->fullAddress() ?? '',
            'license_title' => $job?->licenseTitle ?? '',
        ];

        // sysActive: default to all-on (the optional documents show by default).
        $sysActive = [
            'national_card_back' => true,
            'vocational_cert'    => true,
            'education_degree'   => true,
            'product_photos'     => true,
            'optional_1'         => true,
            'optional_2'         => true,
            'optional_3'         => true,
        ];

        return $this->render('registration/step4', [
            'title'           => 'مرحلهٔ ۴ — مدارک و بازبینی',
            'sysActive'       => $sysActive,
            'ownershipStatus' => $property?->ownershipStatus ?? '',
            'summary'         => $summary,
            'errors'          => [],
            'skipDocuments'   => false,
            'stepPosition'    => 4,
        ]);
    }

    /**
     * POST /register/step4 — handle document uploads, mark the
     * registration as completed, change status to InfoComplete (Phase 52:
     * was InitialApproval — the wizard now leaves the applicant at
     * "تکمیل اطلاعات" so the admin reviews and advances the status
     * manually), clear the wizard session, and redirect to the success
     * page.
     */
    public function processStep4(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }

        $now = date('Y-m-d H:i:s');

        // Handle document uploads via the v2-style named file inputs.
        // handleDocumentUploads() is idempotent: missing optional files
        // are silently skipped. Each stored file is also written to the
        // documents table via DocumentRepository.
        $sysActive = [
            'national_card_back' => true,
            'vocational_cert'    => true,
            'education_degree'   => true,
            'product_photos'     => true,
            'optional_1'         => true,
            'optional_2'         => true,
            'optional_3'         => true,
        ];
        $uploadResult = $this->handleDocumentUploads(
            $applicant->id,
            $applicant->trackingCode,
            $sysActive
        );

        // Persist each stored file as a documents row.
        foreach ($uploadResult['stored'] as $stored) {
            $this->documents->create([
                'applicant_id'   => $applicant->id,
                'doc_type'       => $stored['doc_type'],
                'file_name'      => $stored['file_name'],
                'original_name'  => $stored['file_name'],
                'file_path'      => $stored['file_path'],
            ]);
        }

        // Log upload errors but do not abort the registration — the
        // admin can request additional documents later via /admin/add-document.
        foreach ($uploadResult['errors'] as $err) {
            $this->logger->warning('Wizard document upload failed', [
                '_module'      => 'core',
                'applicant_id' => $applicant->id,
                'field'        => $err['field'] ?? '',
                'error'        => $err['error'] ?? '',
            ]);
        }

        // Mark registration completed + record consent.
        $this->applicants->update($applicant->id, [
            'registration_completed_at' => $now,
            'consent_external_share'   => 1,
        ]);

        // Transition to InfoComplete (Phase 52, v2.1.8) — the wizard
        // leaves the applicant at "تکمیل اطلاعات" so the admin reviews
        // the application and advances the status manually. Was
        // InitialApproval ("تأیید اولیه") before Phase 52, which
        // skipped the admin-review step.
        $this->applicants->changeStatus(
            $applicant->id,
            ApplicantStatus::InfoComplete,
            'user',
            'تکمیل ثبت‌نام توسط کاربر'
        );

        // Clear the wizard session — the applicant is now in the
        // normal workflow queue.
        $this->session->remove(self::SESSION_WIZARD_KEY);

        $this->logger->info('Wizard registration finalised', [
            '_module'         => 'core',
            'applicant_id'    => $applicant->id,
            'tracking_code'   => $applicant->trackingCode,
            'docs_stored'     => count($uploadResult['stored']),
            'docs_failed'     => count($uploadResult['errors']),
        ]);

        return $this->redirect('/register/success/' . urlencode($applicant->trackingCode));
    }

    /* ============================================================
     * Success page — /register/success/{trackingCode}
     * ============================================================ */

    /**
     * GET /register/success/{trackingCode} — look up the applicant by
     * tracking code and render the success page with their tracking code
     * + full name. If the tracking code doesn't match any applicant,
     * redirect to /.
     */
    public function success(Request $request, array $params = []): Response
    {
        $trackingCode = (string)($params['trackingCode'] ?? '');
        if (Validator::trackingCode($trackingCode) === null) {
            return $this->redirect('/');
        }

        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            return $this->redirect('/');
        }

        return $this->render('registration/success', [
            'title'         => 'ثبت‌نام با موفقیت انجام شد',
            'trackingCode'  => $applicant->trackingCode,
            'applicantName' => $applicant->fullName(),
        ]);
    }

    /* ============================================================
     * Document upload handler (Phase 41 — kept verbatim)
     * ============================================================ */

    /**
     * Handle v2-style named document uploads from step4.
     *
     * Walks the expected $_FILES keys (national_card_front, national_card_back,
     * birth_cert, ownership_doc, vocational_cert, education_degree,
     * product_photos[], optional_1, optional_2, optional_3) and stores each
     * uploaded file via FileStorage::storeUpload(). Returns a result map:
     *
     *   [
     *     'stored'   => array<int, array{field:string, file_path:string,
     *                                    file_name:string, doc_type:string}>,
     *     'errors'   => array<int, array{field:string, error:string}>,
     *     'skipped'  => int,
     *   ]
     *
     * Missing optional fields are NOT errors — they're skipped silently.
     * Required-field enforcement is the caller's responsibility (the step4
     * POST handler validates before calling this).
     *
     * @param int    $applicantId   Applicants row id (for ownership of the
     *                              files; not used as a path component).
     * @param string $trackingCode  Applicant's tracking code (folder name).
     * @param array<string,bool> $sysActive  Toggle map (which optional
     *                                      fields are accepted). Missing
     *                                      keys default to true.
     *
     * @return array{stored:array<int,array<string,string>>,errors:array<int,array<string,string>>,skipped:int}
     */
    public function handleDocumentUploads(
        int $applicantId,
        string $trackingCode,
        array $sysActive = []
    ): array {
        $stored  = [];
        $errors  = [];
        $skipped = 0;

        $on = static fn (string $key): bool => $sysActive[$key] ?? true;

        // Single-file fields.
        foreach (self::DOC_FIELD_MAP as $field => $docType) {
            if (!$on($field)) {
                $skipped++;
                continue;
            }
            if (!isset($_FILES[$field])) {
                $skipped++;
                continue;
            }
            $file = $_FILES[$field];
            // Treat as "skipped" if no file was actually selected.
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
                || ($file['size'] ?? 0) === 0
            ) {
                $skipped++;
                continue;
            }
            $result = $this->storage->storeUpload($file, $applicantId, $trackingCode, $docType);
            if ($result['success']) {
                $stored[] = [
                    'field'     => $field,
                    'doc_type'  => $docType,
                    'file_path' => (string)$result['file_path'],
                    'file_name' => (string)$result['file_name'],
                ];
            } else {
                $errors[] = [
                    'field' => $field,
                    'error' => (string)$result['error'],
                ];
            }
        }

        // Multi-file field: product_photos[].
        if ($on(self::DOC_FIELD_ARRAY) && isset($_FILES[self::DOC_FIELD_ARRAY])) {
            $entries = $_FILES[self::DOC_FIELD_ARRAY];
            // PHP normalises <input name="product_photos[]"> into parallel
            // arrays: name[], tmp_name[], error[], size[] are all indexed
            // by the same 0..N-1 index.
            $count = is_array($entries['name'] ?? null) ? count($entries['name']) : 0;
            for ($i = 0; $i < $count; $i++) {
                if (($entries['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
                    || ($entries['size'][$i] ?? 0) === 0
                ) {
                    $skipped++;
                    continue;
                }
                $file = [
                    'name'     => $entries['name'][$i] ?? '',
                    'type'     => $entries['type'][$i] ?? '',
                    'tmp_name' => $entries['tmp_name'][$i] ?? '',
                    'error'    => $entries['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $entries['size'][$i] ?? 0,
                ];
                $result = $this->storage->storeUpload($file, $applicantId, $trackingCode, self::DOC_FIELD_ARRAY);
                if ($result['success']) {
                    $stored[] = [
                        'field'     => self::DOC_FIELD_ARRAY,
                        'doc_type'  => self::DOC_FIELD_ARRAY,
                        'file_path' => (string)$result['file_path'],
                        'file_name' => (string)$result['file_name'],
                    ];
                } else {
                    $errors[] = [
                        'field' => self::DOC_FIELD_ARRAY,
                        'error' => (string)$result['error'],
                    ];
                }
            }
        } else {
            $skipped++;
        }

        return ['stored' => $stored, 'errors' => $errors, 'skipped' => $skipped];
    }

    /* ============================================================
     * Legacy stubs — kept for backward compatibility (Phase 41).
     * The routes registered in Core/Module.php use `index` for
     * GET /register and `stepByPosition` for GET /register/step/{n},
     * not these stubs. They're preserved so existing code that
     * might still reference them (e.g. test harnesses) continues to
     * resolve.
     * ============================================================ */

    /**
     * @codeCoverageIgnore
     */
    public function start(Request $request, array $params = []): Response
    {
        return $this->index($request, $params);
    }

    /**
     * @codeCoverageIgnore
     */
    public function step(Request $request, array $params = []): Response
    {
        return $this->stepByPosition($request, $params);
    }

    /* ============================================================
     * Private helpers
     * ============================================================ */

    /**
     * Read the in-progress applicant id from the session and load the
     * Applicant. Returns null if the session key is missing or the id
     * no longer matches a row (e.g. soft-deleted).
     */
    private function currentWizardApplicant(): ?Applicant
    {
        $id = (int)$this->session->get(self::SESSION_WIZARD_KEY, 0);
        if ($id <= 0) {
            return null;
        }
        return $this->applicants->findById($id);
    }

    /**
     * Phase 52 (v2.1.8) — find the user's most recent previous applicant
     * row (excluding the given $excludeId, or 0 to exclude none). Used to
     * pre-fill the wizard's identity, property, and job fields from the
     * user's earlier submissions so returning users don't re-type them.
     *
     * Returns null if the user_account_id is missing or no previous
     * application exists.
     */
    private function mostRecentPreviousApplicant(?int $userAccountId, int $excludeId = 0): ?Applicant
    {
        if ($userAccountId === null || $userAccountId <= 0) {
            return null;
        }
        $sql = 'SELECT * FROM applicants
                  WHERE user_account_id = ? AND deleted_at IS NULL';
        $params = [$userAccountId];
        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 1';
        $row = $this->connection()->one($sql, $params);
        return $row === null ? null : Applicant::fromArray($row);
    }

    /**
     * Phase 52 (v2.1.8) — convenience wrapper around
     * {@see mostRecentPreviousApplicant()} that returns just the row id
     * (or null). Used by step2() and step3() to look up the previous
     * application's property_details / job_details.
     */
    private function mostRecentPreviousApplicantId(?int $userAccountId, int $excludeId = 0): ?int
    {
        $prev = $this->mostRecentPreviousApplicant($userAccountId, $excludeId);
        return $prev === null ? null : $prev->id;
    }

    /**
     * Render step $n view with the right data (provinces, townships,
     * license titles, form fields, document types). Used by the GET
     * handlers and the POST error paths so they share one rendering
     * code path.
     *
     * @param int                      $n        Step number (1..4).
     * @param array<string,mixed>       $errors   Per-field error messages.
     * @param array<string,mixed>      $postBody Submitted values to re-display.
     */
    private function renderStep(int $n, array $errors = [], array $postBody = []): Response
    {
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }
        $values = $postBody !== [] ? $postBody : $this->applicantValues($applicant);
        $serviceType = $this->serviceTypes->findById((int)$applicant->serviceTypeId) ?? [];

        switch ($n) {
            case 1:
                return $this->render('registration/step1', [
                    'title'       => 'مرحلهٔ ۱ — اطلاعات هویتی',
                    'serviceType' => $serviceType,
                    'values'      => $values,
                    'errors'      => $errors,
                ]);
            case 2:
                return $this->render('registration/step2', [
                    'title'     => 'مرحلهٔ ۲ — محل کسب‌وکار',
                    'provinces' => $this->connection()->all(
                        "SELECT id, code, title FROM national_lookups
                         WHERE kind = 'province' AND is_active = 1 ORDER BY code"
                    ),
                    'townships' => $this->connection()->all(
                        "SELECT id, code, title, parent_code AS province_id FROM national_lookups
                         WHERE kind = 'township' AND is_active = 1 ORDER BY parent_code, code"
                    ),
                    'values'    => $values,
                    'errors'    => $errors,
                ]);
            case 3:
                return $this->render('registration/step3', [
                    'title'         => 'مرحلهٔ ۳ — مشخصات شغلی',
                    'licenseTitles' => $this->licenseTitles->getAll((int)$applicant->serviceTypeId),
                    'fields'        => [],
                    'values'        => $values,
                    'errors'        => $errors,
                ]);
            case 4:
                $property = $this->jobAndProperty->findPropertyDetailByApplicantId($applicant->id);
                $job      = $this->jobAndProperty->findJobDetailByApplicantId($applicant->id);
                $summary = [
                    'first_name'    => $applicant->firstName,
                    'last_name'     => $applicant->lastName,
                    'national_code' => $applicant->nationalCode,
                    'father_name'   => $applicant->fatherName ?? '',
                    'mobile'        => $applicant->mobile,
                    'province'      => $property?->province ?? '',
                    'township'      => $property?->township ?? $property?->county ?? '',
                    'postal_code'   => $applicant->postalCode ?? '',
                    'address'       => $property?->fullAddress() ?? '',
                    'license_title' => $job?->licenseTitle ?? '',
                ];
                return $this->render('registration/step4', [
                    'title'           => 'مرحلهٔ ۴ — مدارک و بازبینی',
                    'sysActive'       => [
                        'national_card_back' => true,
                        'vocational_cert'    => true,
                        'education_degree'   => true,
                        'product_photos'     => true,
                        'optional_1'         => true,
                        'optional_2'         => true,
                        'optional_3'         => true,
                    ],
                    'ownershipStatus' => $property?->ownershipStatus ?? '',
                    'summary'         => $summary,
                    'errors'          => $errors,
                    'skipDocuments'   => false,
                    'stepPosition'    => 4,
                ]);
            default:
                return $this->redirect('/register');
        }
    }

    /**
     * Query document_types for all active non-avatar types.
     *
     * The `avatar` type is excluded because it is uploaded separately
     * via the /panel/avatar endpoint (Phase 12 AvatarService) and is
     * not part of the wizard's document upload step.
     *
     * @return array<int,array<string,mixed>>
     */
    private function requiredDocs(): array
    {
        return $this->connection()->all(
            "SELECT id, doc_type_key, label, category, is_active, is_required, sort_order
             FROM document_types
             WHERE is_active = 1 AND doc_type_key != 'avatar'
             ORDER BY sort_order, id"
        );
    }

    /**
     * Look up a national_lookups title by kind + code.
     *
     * @param string $kind  'province'|'township'|'main_organization'|'sub_organization'
     * @param string $code  The natural code (e.g. '01' for Tehran province).
     * @return string       The title, or '' if not found.
     */
    private function lookupTitle(string $kind, string $code): string
    {
        $row = $this->connection()->one(
            'SELECT title FROM national_lookups WHERE kind = ? AND code = ? LIMIT 1',
            [$kind, $code]
        );
        return $row === null ? '' : (string)$row['title'];
    }

    /**
     * Get the shared Connection from the container.
     */
    private function connection(): Connection
    {
        return $this->container->make(Connection::class);
    }

    /**
     * Convert 3 Jalali year/month/day inputs into a Gregorian 'YYYY-MM-DD'
     * string suitable for the applicants.birth_date DATE column. Returns
     * null when any of the three inputs is missing or out of range.
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
     * Parse a Jalali date string from a single text input ("YYYY/MM/DD"
     * with Persian or Latin digits) into 3 ints, then convert to
     * Gregorian. Returns null if the input is empty or malformed.
     */
    private function birthDateFromInputString(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        // Normalise Persian/Arabic-Indic digits to Latin.
        $input = Normalizer::digits($input);
        if (!preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $input, $m)) {
            return null;
        }
        return $this->birthDateFromInputs((int)$m[1], (int)$m[2], (int)$m[3]);
    }

    /**
     * Build a $values array (for step1 view pre-fill) from an Applicant
     * domain object.
     *
     * @return array<string,string>
     */
    private function applicantValues(Applicant $applicant): array
    {
        $values = [
            'first_name'    => $applicant->firstName,
            'last_name'     => $applicant->lastName,
            'national_code' => $applicant->nationalCode,
            'father_name'   => $applicant->fatherName ?? '',
            'mobile'        => $applicant->mobile,
            'birth_date'    => '',
        ];

        // Render the Gregorian birth_date as a Jalali string for the
        // single text input.
        if ($applicant->registrationCompletedAt !== null || $applicant->createdAt !== '') {
            // No birth_date column on Applicant (it's on the DB row but not
            // surfaced on the VO); skip pre-filling for now. The view
            // falls back to ''.
        }

        return $values;
    }

    /**
     * Validate an Iranian postal code (10 digits, no separators).
     */
    private static function validPostalCode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = Normalizer::digits(trim($value));
        if (!preg_match('/^\d{10}$/', $value)) {
            return null;
        }
        return $value;
    }
}
