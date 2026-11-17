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
use App\Shared\Contracts\UserAccountRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\Normalizer;
use App\Shared\Support\PersianDate;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;

/**
 * RegistrationController — the 4-step applicant registration wizard.
 *
 * Phase 15 (v1.6.0): the registration views existed since Phase 14 (v1.5.0)
 * but no controller served them — so the user's "شروع ثبت‌نام" button
 * linked to / (home page) instead of an actual wizard.
 *
 * Wizard data persistence: the in-progress applicant row id is stored in
 * the session under `wizard.applicant_id`. The wizard flow is:
 *   GET  /register                              → service selection page
 *   GET  /register/service/{id}                → create an applicants row
 *                                                 (status='پیش‌نویس', tracking_code=HG-XXXXXXXX)
 *                                                 and redirect to step 1.
 *   GET  /register/step/{n}                    → render step n (1..4) using
 *                                                 step{n}.php with the
 *                                                 applicant row as $values.
 *   POST /register/step/{n}                    → validate + persist step n,
 *                                                 then redirect to step n+1
 *                                                 (or to /register/success on n=4).
 *   GET  /register/success/{trackingCode}     → success page with tracking code.
 *
 * Step 1: identity (first_name, last_name, national_code, birth_date,
 *                   father_name, applicant_type, marital_status,
 *                   children_count, physical_condition, disability_details).
 *         Stored on the applicants row directly.
 * Step 2: business address / property → property_details table (1:1).
 * Step 3: job + license_title (filtered by service_type_id), saved to
 *         job_details (1:1) + applicant_field_values for any custom
 *         form_fields defined for this service_type.
 * Step 4: documents + review + submit. Sets current_status='تأیید اولیه',
 *         registration_completed_at=NOW(), and redirects to the success
 *         page with the tracking code.
 *
 * Auth: requireUserLogin() at the top of every action — only logged-in
 * users may submit a registration. The session-keyed wizard data is tied
 * to user_account_id.
 */
final class RegistrationController extends Controller
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
        private readonly ServiceTypeRepositoryInterface $serviceTypes,
        private readonly LicenseTitleRepositoryInterface $licenseTitles,
        private readonly DocumentRepositoryInterface $documents,
        private readonly LicenseFileRepositoryInterface $licenseFiles,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /register — show the service selection page (wizard step 0).
     */
    public function index(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $serviceTypes = $this->serviceTypes->getActive();
        return $this->render('registration/select_service', [
            'title'        => 'انتخاب نوع خدمت',
            'serviceTypes' => $serviceTypes,
        ]);
    }

    /**
     * GET /register/service/{id} — confirm service selection, create the
     * in-progress applicants row, store its id in the session, redirect
     * to step 1.
     */
    public function selectService(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $serviceTypeId = (int)($params['id'] ?? 0);
        $serviceType = $this->serviceTypes->findById($serviceTypeId);
        if ($serviceType === null) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>نوع خدمت یافت نشد.</p>',
                404
            );
        }
        $account = $this->currentAccount();
        $trackingCode = $this->hash->generateTrackingCode();
        $applicantId = $this->applicants->create([
            'service_type_id'        => $serviceTypeId,
            'user_account_id'        => (int)$account['id'],
            'mobile'                 => (string)$account['mobile'],
            'tracking_code'          => $trackingCode,
            'current_status'         => ApplicantStatus::Draft->value,
            'first_name'             => '',
            'last_name'              => '',
            'national_code'          => '',
            'postal_code'            => '',
            'registration_started_at' => date('Y-m-d H:i:s'),
        ]);
        $this->session->set('wizard.applicant_id', $applicantId);
        $this->logger->info('Wizard started', [
            '_module'         => 'core',
            'applicant_id'    => $applicantId,
            'service_type_id' => $serviceTypeId,
            'tracking_code'   => $trackingCode,
            'user_account_id' => (int)$account['id'],
        ]);
        return $this->redirect('/register/step/1');
    }

    /**
     * GET /register/step/{n} — render step n (1..4) of the wizard.
     */
    public function stepByPosition(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $n = (int)($params['n'] ?? 0);
        if ($n < 1 || $n > 4) {
            return $this->redirect('/register');
        }
        return $this->renderStep($n);
    }

    /**
     * POST /register/step/{n} — process step n form submit.
     */
    public function processByPosition(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $n = (int)($params['n'] ?? 0);
        if ($n < 1 || $n > 4) {
            return $this->redirect('/register');
        }
        return match ($n) {
            1       => $this->processStep1($request, []),
            2       => $this->processStep2($request, []),
            3       => $this->processStep3($request, []),
            4       => $this->processStep4($request, []),
            default => $this->redirect('/register'),
        };
    }

    /**
     * GET /register/step1 — explicit step 1 GET (alias of /register/step/1).
     */
    public function step1(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        return $this->renderStep(1);
    }

    /**
     * POST /register/step1 — process step 1 form submit (identity).
     */
    public function processStep1(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }
        $body = $request->body();
        $nationalCode = Validator::nationalCode($body['national_code'] ?? '');
        $firstName = Validator::nonEmptyString($body['first_name'] ?? '', 50);
        $lastName = Validator::nonEmptyString($body['last_name'] ?? '', 50);
        $errors = [];
        if ($firstName === null) {
            $errors[] = 'نام الزامی است.';
        }
        if ($lastName === null) {
            $errors[] = 'نام خانوادگی الزامی است.';
        }
        if ($nationalCode === null) {
            $errors[] = 'کد ملی نامعتبر است.';
        }
        if ($errors === []) {
            $this->applicants->update($applicant->id, [
                'first_name'          => $firstName,
                'last_name'           => $lastName,
                'national_code'       => $nationalCode,
                'birth_date'          => $this->normalizeJalaliToGregorian((string)($body['birth_date'] ?? '')),
                'father_name'         => Validator::sanitizeText($body['father_name'] ?? '', 50),
                'applicant_type'      => in_array($body['applicant_type'] ?? '', ['حقیقی', 'حقوقی'], true)
                                            ? $body['applicant_type'] : 'حقیقی',
                'marital_status'      => in_array($body['marital_status'] ?? '', ['مجرد', 'متأهل'], true)
                                            ? $body['marital_status'] : 'مجرد',
                'children_count'      => max(0, (int)($body['children_count'] ?? 0)),
                'physical_condition'  => in_array($body['physical_condition'] ?? '', ['سالم', 'معلولیت'], true)
                                            ? $body['physical_condition'] : 'سالم',
                'disability_details'  => Validator::sanitizeText($body['disability_details'] ?? '', 255),
            ]);
            $this->logger->info('Wizard step 1 saved', [
                '_module'      => 'core',
                'applicant_id' => $applicant->id,
            ]);
            return $this->redirect('/register/step/2');
        }
        return $this->renderStep(1, $errors, $body);
    }

    /**
     * GET /register/step2 — explicit step 2 GET (property / address).
     */
    public function step2(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        return $this->renderStep(2);
    }

    /**
     * POST /register/step2 — process step 2 form submit (property / address).
     */
    public function processStep2(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }
        $body = $request->body();
        $postalCode = preg_match('/^\d{10}$/', (string)($body['postal_code'] ?? ''))
            ? (string)$body['postal_code']
            : null;
        $errors = [];
        if ($postalCode === null) {
            $errors[] = 'کد پستی باید ۱۰ رقم باشد.';
        }
        // The form posts province_id + township_id (lookup codes); translate
        // them to titles for storage in property_details (which uses varchar).
        $provinceCode = (string)($body['province_id'] ?? '');
        $countyCode = (string)($body['township_id'] ?? '');
        $province = $this->lookupTitle('province', $provinceCode) ?: Validator::nonEmptyString($body['province'] ?? '', 50);
        $county = $this->lookupTitle('township', $countyCode) ?: Validator::nonEmptyString($body['county'] ?? '', 50);
        if ($province === null) {
            $errors[] = 'استان الزامی است.';
        }
        if ($county === null) {
            $errors[] = 'شهرستان الزامی است.';
        }
        if ($errors === []) {
            $ownership = in_array($body['ownership_status'] ?? '', ['شخص', 'مستأجر', 'سرپرست'], true)
                ? $body['ownership_status']
                : 'شخص';
            $this->jobAndProperty->upsertPropertyDetail($applicant->id, [
                'ownership_status'   => $ownership,
                'province'           => $province,
                'county'             => $county,
                'city'               => Validator::sanitizeText($body['city'] ?? '', 50) ?: $county,
                'district'           => Validator::sanitizeText($body['district'] ?? '', 50),
                'rural_district'     => Validator::sanitizeText($body['rural_district'] ?? '', 50),
                'village'             => Validator::sanitizeText($body['village'] ?? '', 50),
                'electricity_bill_id' => Validator::sanitizeText($body['electricity_bill_id'] ?? '', 20),
                'address_street'     => Validator::sanitizeText($body['address_street'] ?? '', 100),
                'address_alley'      => Validator::sanitizeText($body['address_alley'] ?? '', 100),
                'address_plaque'     => Validator::sanitizeText($body['address_plaque'] ?? '', 20),
            ]);
            $this->applicants->update($applicant->id, ['postal_code' => $postalCode]);
            $this->logger->info('Wizard step 2 saved', [
                '_module'      => 'core',
                'applicant_id' => $applicant->id,
            ]);
            return $this->redirect('/register/step/3');
        }
        return $this->renderStep(2, $errors, $body);
    }

    /**
     * GET /register/step3 — explicit step 3 GET (job / license detail).
     */
    public function step3(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        return $this->renderStep(3);
    }

    /**
     * POST /register/step3 — process step 3 form submit (job / license).
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
        $licenseTitleRow = $licenseTitleId > 0
            ? $this->licenseTitles->findById($licenseTitleId)
            : null;
        if ($licenseTitleRow === null) {
            return $this->renderStep(3, ['عنوان مجوز انتخاب نشده است.'], $body);
        }
        $licenseTitle = (string)($licenseTitleRow['title'] ?? '');
        $training = in_array($body['training_method'] ?? '', ['در خانه', 'استاد شاگردی', 'کارگاهی'], true)
            ? $body['training_method']
            : 'در خانه';
        $this->jobAndProperty->upsertJobDetail($applicant->id, [
            'license_title'     => $licenseTitle,
            'experience_months' => max(0, (int)($body['experience_months'] ?? 0)),
            'training_method'   => $training,
        ]);
        $this->persistCustomFieldValues($applicant->id, (int)$applicant->serviceTypeId, $body);
        $this->logger->info('Wizard step 3 saved', [
            '_module'        => 'core',
            'applicant_id'   => $applicant->id,
            'license_title'  => $licenseTitle,
        ]);
        return $this->redirect('/register/step/4');
    }

    /**
     * GET /register/step4 — explicit step 4 GET (documents + review + submit).
     */
    public function step4(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        return $this->renderStep(4);
    }

    /**
     * POST /register/step4 — process step 4 (upload docs + final submit).
     */
    public function processStep4(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }
        $body = $request->body();
        if (empty($body['consent'])) {
            return $this->renderStep(4, ['تأیید صحت اطلاعات الزامی است.'], $body);
        }
        $this->handleDocumentUploads($applicant, $request->files());
        $this->applicants->update($applicant->id, [
            'registration_completed_at' => date('Y-m-d H:i:s'),
            'consent_external_share'    => 1,
        ]);
        $this->applicants->changeStatus(
            $applicant->id,
            ApplicantStatus::InitialApproval,
            'applicant',
            'ثبت نهایی توسط متقاضی'
        );
        $this->session->remove('wizard.applicant_id');
        $this->logger->info('Wizard submitted', [
            '_module'       => 'core',
            'applicant_id'  => $applicant->id,
            'tracking_code' => $applicant->trackingCode,
        ]);
        return $this->redirect('/register/success/' . rawurlencode($applicant->trackingCode));
    }

    /**
     * GET /register/success/{trackingCode} — show the success page.
     */
    public function success(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $trackingCode = Validator::trackingCode($params['trackingCode'] ?? '');
        if ($trackingCode === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>کد رهگیری نامعتبر.</p>', 404);
        }
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>پرونده یافت نشد.</p>', 404);
        }
        return $this->render('registration/success', [
            'title'         => 'ثبت‌نام موفق',
            'trackingCode'  => $trackingCode,
            'applicantName' => $applicant->fullName(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Render the wizard's step $n view with the applicant row as $values.
     *
     * @param array<int,string> $errors
     * @param array<string,mixed> $postBody
     */
    private function renderStep(int $n, array $errors = [], array $postBody = []): Response
    {
        $applicant = $this->currentWizardApplicant();
        if ($applicant === null) {
            return $this->redirect('/register');
        }
        $serviceType = $applicant->serviceTypeId !== null
            ? $this->serviceTypes->findById($applicant->serviceTypeId)
            : null;
        $values = $this->stepValues($applicant, $n, $postBody);
        $data = [
            'title'       => 'مرحلهٔ ' . $n . ' ثبت‌نام',
            'serviceType' => $serviceType ?? [],
            'values'      => $values,
            'errors'      => $errors,
        ];
        if ($n === 2) {
            $data['provinces'] = $this->connection()->all(
                "SELECT code AS id, title FROM national_lookups WHERE kind = 'province' AND is_active = 1 ORDER BY title"
            );
            $data['townships'] = $this->connection()->all(
                "SELECT code AS id, title, parent_code AS province_id FROM national_lookups WHERE kind = 'township' AND is_active = 1 ORDER BY title"
            );
        }
        if ($n === 3) {
            $data['licenseTitles'] = $this->licenseTitles->getAll($applicant->serviceTypeId);
            $data['fields'] = $this->formFieldsForService((int)$applicant->serviceTypeId);
        }
        if ($n === 4) {
            $data['requiredDocs'] = $this->requiredDocs();
            $data['summary'] = $this->summaryForReview($applicant);
        }
        return $this->render('registration/step' . $n, $data);
    }

    /**
     * Build the $values map for a step view: merge the applicant row + any
     * persisted child rows + any POST body (re-display on validation error).
     *
     * @return array<string,mixed>
     */
    private function stepValues(Applicant $applicant, int $n, array $postBody): array
    {
        $base = [
            'first_name'         => $applicant->firstName,
            'last_name'          => $applicant->lastName,
            'national_code'      => $applicant->nationalCode,
            'mobile'             => $applicant->mobile,
            'father_name'        => '',
            'birth_date'         => '',
            'applicant_type'     => 'حقیقی',
            'marital_status'     => 'مجرد',
            'children_count'     => 0,
            'physical_condition' => 'سالم',
            'disability_details' => '',
        ];
        // Pull from the raw applicants row to capture fields not on the
        // Applicant value object (e.g. father_name, applicant_type).
        $raw = $this->connection()->one('SELECT * FROM applicants WHERE id = ?', [$applicant->id]);
        if ($raw !== null) {
            foreach ([
                'father_name', 'birth_date', 'applicant_type',
                'marital_status', 'children_count',
                'physical_condition', 'disability_details',
            ] as $k) {
                if (isset($raw[$k])) {
                    $base[$k] = $raw[$k];
                }
            }
        }
        if ($n === 2) {
            $prop = $this->jobAndProperty->findPropertyDetailByApplicantId($applicant->id);
            if ($prop !== null) {
                $base['ownership_status']   = $prop->ownershipStatus;
                $base['province']           = $prop->province;
                $base['county']             = $prop->county;
                $base['city']               = $prop->city;
                $base['district']           = $prop->district;
                $base['rural_district']      = $prop->ruralDistrict;
                $base['village']            = $prop->village;
                $base['electricity_bill_id'] = $prop->electricityBillId;
                $base['address_street']     = $prop->addressStreet;
                $base['address_alley']      = $prop->addressAlley;
                $base['address_plaque']     = $prop->addressPlaque;
            }
            $base['postal_code'] = $applicant->postalCode ?? '';
        }
        if ($n === 3) {
            $job = $this->jobAndProperty->findJobDetailByApplicantId($applicant->id);
            if ($job !== null) {
                $base['license_title']     = $job->licenseTitle;
                $base['experience_months'] = $job->experienceMonths;
                $base['training_method']   = $job->trainingMethod;
            }
            $base['fields'] = $this->customFieldValues($applicant->id);
        }
        // POST body overrides persisted values (re-display on validation error).
        foreach ($postBody as $k => $v) {
            if (is_string($v) || is_int($v)) {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    /**
     * Fetch the custom form_fields defined for this service_type (any step).
     *
     * @return array<int,array<string,mixed>>
     */
    private function formFieldsForService(int $serviceTypeId): array
    {
        if ($serviceTypeId <= 0) {
            return [];
        }
        return $this->connection()->all(
            'SELECT * FROM form_fields WHERE service_type_id = ? ORDER BY sort_order, id',
            [$serviceTypeId]
        );
    }

    /**
     * Read the persisted custom field values for an applicant.
     *
     * @return array<string,string>
     */
    private function customFieldValues(int $applicantId): array
    {
        $rows = $this->connection()->all(
            'SELECT ff.field_key, afv.field_value
               FROM applicant_field_values afv
               JOIN form_fields ff ON ff.id = afv.field_id
              WHERE afv.applicant_id = ?',
            [$applicantId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['field_key']] = (string)($r['field_value'] ?? '');
        }
        return $out;
    }

    /**
     * Persist custom form_field values from the POST body.
     *
     * @param array<string,mixed> $body
     */
    private function persistCustomFieldValues(int $applicantId, int $serviceTypeId, array $body): void
    {
        $fields = $this->formFieldsForService($serviceTypeId);
        foreach ($fields as $f) {
            $key = (string)($f['field_key'] ?? '');
            if ($key === '' || !array_key_exists($key, $body)) {
                continue;
            }
            $value = (string)$body[$key];
            $this->connection()->execute(
                'INSERT INTO applicant_field_values (applicant_id, field_id, field_value)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)',
                [$applicantId, (int)$f['id'], $value]
            );
        }
    }

    /**
     * The required documents for step 4 (placeholder list — admin can
     * configure via document_types in a later phase).
     *
     * @return array<int,array<string,mixed>>
     */
    private function requiredDocs(): array
    {
        return [
            ['doc_type' => 'national_id', 'label' => 'تصویر کارت ملی', 'is_required' => 1],
            ['doc_type' => 'photo',       'label' => 'عکس پرسنلی',     'is_required' => 1],
            ['doc_type' => 'business_ownership', 'label' => 'سند مالکیت محل کسب', 'is_required' => 0],
        ];
    }

    /**
     * Build the read-only summary for the step 4 review section.
     *
     * @return array<string,string>
     */
    private function summaryForReview(Applicant $applicant): array
    {
        $raw = $this->connection()->one('SELECT * FROM applicants WHERE id = ?', [$applicant->id]) ?? [];
        $summary = [
            'first_name'    => (string)($raw['first_name'] ?? $applicant->firstName),
            'last_name'     => (string)($raw['last_name'] ?? $applicant->lastName),
            'national_code' => (string)($raw['national_code'] ?? $applicant->nationalCode),
            'mobile'        => (string)($raw['mobile'] ?? $applicant->mobile),
            'father_name'   => (string)($raw['father_name'] ?? ''),
        ];
        $prop = $this->jobAndProperty->findPropertyDetailByApplicantId($applicant->id);
        if ($prop !== null) {
            $summary['province']    = $prop->province;
            $summary['county']      = $prop->county;
            $summary['postal_code'] = (string)($applicant->postalCode ?? '');
            $summary['address']     = trim(
                $prop->addressStreet . ' ' . $prop->addressAlley . ' پلاک ' . $prop->addressPlaque
            );
        }
        $job = $this->jobAndProperty->findJobDetailByApplicantId($applicant->id);
        if ($job !== null) {
            $summary['license_title'] = $job->licenseTitle;
        }
        return $summary;
    }

    /**
     * Handle file uploads for the documents array ($_FILES['docs']).
     *
     * @param array<string,mixed> $files
     */
    private function handleDocumentUploads(Applicant $applicant, array $files): void
    {
        $docs = $files['docs'] ?? null;
        if (!is_array($docs) || empty($docs)) {
            return;
        }
        $rows = $this->normalizeFilesArray($docs);
        $fileStorage = $this->fileStorage();
        foreach ($rows as $docType => $file) {
            $stored = $fileStorage->storeUpload($file, $applicant->id, $applicant->trackingCode, $docType);
            if (!$stored['success'] || $stored['file_path'] === null) {
                $this->logger->warning('Wizard document upload failed', [
                    '_module'      => 'core',
                    'applicant_id' => $applicant->id,
                    'doc_type'     => $docType,
                    'error'        => $stored['error'],
                ]);
                continue;
            }
            $this->documents->create([
                'applicant_id'  => $applicant->id,
                'doc_type'      => $docType,
                'file_name'     => (string)$stored['file_name'],
                'original_name' => (string)$stored['original_name'],
                'file_path'     => (string)$stored['file_path'],
            ]);
        }
    }

    /**
     * Normalize a $_FILES['docs'] entry into a flat list of
     * {doc_type => file-array} entries (one per uploaded file).
     *
     * @param array<string,mixed> $docs
     * @return array<string,array<string,mixed>>
     */
    private function normalizeFilesArray(array $docs): array
    {
        $out = [];
        // Standard nested form: name[doc_type] => filename, tmp_name[doc_type] => ...
        if (isset($docs['name']) && is_array($docs['name'])) {
            foreach ($docs['name'] as $docType => $name) {
                if (($docs['error'][$docType] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $out[$docType] = [
                    'name'     => $name,
                    'type'     => $docs['type'][$docType] ?? '',
                    'tmp_name' => $docs['tmp_name'][$docType] ?? '',
                    'error'    => $docs['error'][$docType] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $docs['size'][$docType] ?? 0,
                ];
            }
            return $out;
        }
        // Already-flat array (one file per field key at the top level).
        foreach ($docs as $docType => $file) {
            if (!is_array($file) || !isset($file['tmp_name'])) {
                continue;
            }
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $out[$docType] = $file;
        }
        return $out;
    }

    /**
     * Convert a Jalali date string (YYYY/MM/DD) to a Gregorian date
     * (Y-m-d) for storage. Returns null if the input is empty.
     */
    private function normalizeJalaliToGregorian(string $jalali): ?string
    {
        $jalali = trim($jalali);
        if ($jalali === '') {
            return null;
        }
        try {
            $persianDate = $this->container->make(PersianDate::class);
            return $persianDate->jalaliStringToGregorian(Normalizer::persianText($jalali));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve the in-progress applicant row from the session key.
     */
    private function currentWizardApplicant(): ?Applicant
    {
        $id = (int)$this->session->get('wizard.applicant_id');
        if ($id <= 0) {
            return null;
        }
        return $this->applicants->findById($id);
    }

    /**
     * Pull the current account array from the session.
     *
     * @return array<string,mixed>
     */
    private function currentAccount(): array
    {
        $userAccounts = $this->container->make(UserAccountRepositoryInterface::class);
        $id = (int)$this->session->get('user_id');
        $account = $userAccounts->findById($id);
        if ($account === null) {
            $this->session->remove('user_logged_in');
            $this->session->remove('user_id');
            $this->redirect('/login');
        }
        return $account;
    }

    private function connection(): Connection
    {
        return $this->container->make(Connection::class);
    }

    private function fileStorage(): FileStorage
    {
        return $this->container->make(FileStorage::class);
    }

    /**
     * Look up the title of a national_lookups row by kind + code.
     * Returns the title string (e.g. 'تهران') or '' if not found.
     */
    private function lookupTitle(string $kind, string $code): string
    {
        if ($kind === '' || $code === '') {
            return '';
        }
        $title = $this->connection()->scalar(
            "SELECT title FROM national_lookups WHERE kind = ? AND code = ? AND is_active = 1 LIMIT 1",
            [$kind, $code]
        );
        return is_string($title) ? $title : '';
    }
}
