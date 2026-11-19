<?php
/**
 * user/edit_request.php — one-time edit form for bulk-imported applicants.
 *
 * Phase 60 (v2.2.6) — rendered by UserPanelController::editRequest() and
 * re-rendered by updateRequest() on validation failure. A simpler,
 * user-facing subset of admin/edit_application.php:
 *
 *   - Identity: first_name, last_name, mobile, postal_code, father_name,
 *               birth_date (Jalali 3-select), marital_status, children_count,
 *               physical_condition, disability_details, income_decile,
 *               service_type_id, has_court_case, has_active_loan,
 *               has_overdue_installments.
 *   - Job: license_title, experience_months, training_method.
 *   - Property: ownership_status, province, county, city, district,
 *               rural_district, village, electricity_bill_id,
 *               address_street, address_alley, address_plaque.
 *
 * Not editable (identity anchors): national_code, tracking_code.
 * Not shown (out of scope for the user's one-time edit):
 *   - The 18-status change dropdown (admin-only).
 *   - Document upload (admin uses /admin/bulk-import/documents/{code}
 *     or /admin/add-document; the user can't upload from this form).
 *
 * The form POSTs to /panel/requests/{id}/edit (the same URL — the
 * router picks the POST handler by method). On success: redirect to
 * /panel/requests with a success flash. On validation failure: re-render
 * this view with the user's input + the $errors list (the controller
 * merges the submitted body into $row so the $val() helper falls back
 * to the freshly-typed values).
 *
 * View variables:
 *   $applicant      \App\Modules\Core\Domain\Applicant
 *   $row            array<string,mixed>                  raw applicants row (all columns)
 *   $jobDetail      \App\Modules\Core\Domain\JobDetail|null
 *   $propertyDetail \App\Modules\Core\Domain\PropertyDetail|null
 *   $serviceTypes   array<int, array<string,mixed>>
 *   $persianDate    \App\Shared\Support\PersianDate
 *   $errors         array<int,string>
 *   $account        array<string,mixed>
 *   $baseUrl        string
 *   $csrfField      string
 */
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array<string,mixed> $row */
/** @var \App\Modules\Core\Domain\JobDetail|null $jobDetail */
/** @var \App\Modules\Core\Domain\PropertyDetail|null $propertyDetail */
/** @var array<int, array<string,mixed>> $serviceTypes */
/** @var \App\Shared\Support\PersianDate|null $persianDate */
/** @var array<int,string> $errors */
/** @var array<string,mixed> $account */
/** @var string $baseUrl */
/** @var string $csrfField */

use App\Modules\Core\Domain\Applicant;
use App\Modules\Core\Domain\JobDetail;
use App\Modules\Core\Domain\PropertyDetail;
use App\Shared\Support\PersianDate;

$row           ??= [];
$jobDetail     ??= null;
$propertyDetail ??= null;
$serviceTypes  ??= [];
$persianDate   ??= null;
$errors        ??= [];
$csrfField     ??= '';
$baseUrl       ??= '/';

assert($applicant instanceof Applicant);

/**
 * Lookup a field value from the raw applicants row first, then fall
 * back to the domain objects (jobDetail / propertyDetail). Returns the
 * HTML-escaped string. Mirrors the helper in admin/edit_application.php
 * so the form markup below stays structurally identical (cheaper to
 * keep the two views in sync when the field set changes).
 *
 * @return string
 */
$val = static function (string $key, ?string $default = '') use ($row, $jobDetail, $propertyDetail): string {
    if (array_key_exists($key, $row) && isset($row[$key])) {
        return htmlspecialchars((string)$row[$key], ENT_QUOTES, 'UTF-8');
    }
    if ($jobDetail instanceof JobDetail) {
        $map = [
            'license_title'     => $jobDetail->licenseTitle,
            'experience_months' => (string)$jobDetail->experienceMonths,
            'training_method'   => $jobDetail->trainingMethod,
        ];
        if (isset($map[$key])) {
            return htmlspecialchars($map[$key], ENT_QUOTES, 'UTF-8');
        }
    }
    if ($propertyDetail instanceof PropertyDetail) {
        $map = [
            'ownership_status'   => $propertyDetail->ownershipStatus,
            'province'           => $propertyDetail->province,
            'county'             => $propertyDetail->county,
            'city'               => $propertyDetail->city,
            'district'           => (string)($propertyDetail->district ?? ''),
            'rural_district'     => (string)($propertyDetail->ruralDistrict ?? ''),
            'village'            => (string)($propertyDetail->village ?? ''),
            'electricity_bill_id' => $propertyDetail->electricityBillId,
            'address_street'     => (string)($propertyDetail->addressStreet ?? ''),
            'address_alley'      => (string)($propertyDetail->addressAlley ?? ''),
            'address_plaque'     => (string)($propertyDetail->addressPlaque ?? ''),
        ];
        if (isset($map[$key])) {
            return htmlspecialchars($map[$key], ENT_QUOTES, 'UTF-8');
        }
    }
    return htmlspecialchars((string)$default, ENT_QUOTES, 'UTF-8');
};

$applicantId = $applicant->id;

// Parse the stored Gregorian birth_date into 3 Jalali Y/M/D values for
// the 3-select dropdown. Mirrors admin/edit_application.php's logic.
$birthParts = ['year' => '', 'month' => '', 'day' => ''];
$birthGreg = (string)($row['birth_date'] ?? '');
if ($birthGreg !== '' && $birthGreg !== '0000-00-00' && $persianDate instanceof PersianDate) {
    try {
        $parts = explode('-', $birthGreg);
        if (count($parts) === 3) {
            [$jy, $jm, $jd] = $persianDate->gregorianToJalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
            $birthParts['year']  = (string)$jy;
            $birthParts['month'] = (string)$jm;
            $birthParts['day']   = (string)$jd;
        }
    } catch (\Throwable) {
        // ignore — leave empty so the dropdowns default to "—"
    }
}

$months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
$currentPersianYear = $persianDate instanceof PersianDate
    ? (int)explode('/', $persianDate->todayJalali())[0]
    : 1400;
?>

<div class="alert alert-info d-flex align-items-start gap-2 mb-3" role="alert">
    <i class="bi bi-info-circle-fill fs-5 mt-1"></i>
    <div>
        <strong>توجه:</strong>
        شما می‌توانید اطلاعات پروندهٔ خود را تنها <strong>یک‌بار</strong> ویرایش و تکمیل کنید.
        پس از ذخیره، ویرایش‌های بعدی تنها از طریق مدیر سامانه ممکن خواهد بود.
        لطفاً پیش از ثبت، تمامی موارد را با دقت وارد کنید.
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h5 mb-1"><i class="bi bi-pencil-square"></i> ویرایش و تکمیل درخواست</h2>
        <span class="moj-tracking-code">کد پیگیری: <?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/requests" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-return-right"></i> بازگشت به فهرست
    </a>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/requests/<?= (int)$applicantId ?>/edit">
    <?= $csrfField ?>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-person-vcard"></i> اطلاعات هویتی</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">نام <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="first_name"
                           value="<?= $val('first_name') ?>" maxlength="50" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">نام خانوادگی <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="last_name"
                           value="<?= $val('last_name') ?>" maxlength="50" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">کد ملی <span class="text-muted small">(غیرقابل ویرایش)</span></label>
                    <input type="text" class="form-control" value="<?= $val('national_code') ?>" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label">موبایل <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="mobile"
                           value="<?= $val('mobile') ?>" maxlength="11" pattern="09\d{9}" required
                           inputmode="numeric" style="direction:ltr;text-align:right;">
                </div>
                <div class="col-md-6">
                    <label class="form-label">کد پستی <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="postal_code"
                           value="<?= $val('postal_code') ?>" maxlength="10" pattern="\d{10}" required
                           inputmode="numeric" style="direction:ltr;text-align:right;">
                </div>
                <div class="col-md-6">
                    <label class="form-label">نام پدر</label>
                    <input type="text" class="form-control" name="father_name"
                           value="<?= $val('father_name') ?>" maxlength="50">
                </div>
                <div class="col-md-6">
                    <label class="form-label">تاریخ تولد (شمسی)</label>
                    <div class="row g-1">
                        <div class="col">
                            <select class="form-select" name="birth_year" id="birth_year_edit">
                                <option value="">سال</option>
                                <?php for ($y = 1300; $y <= $currentPersianYear; $y++): ?>
                                    <option value="<?= $y ?>" <?= $birthParts['year'] === (string)$y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col">
                            <select class="form-select" name="birth_month" id="birth_month_edit">
                                <option value="">ماه</option>
                                <?php foreach ($months as $i => $name):
                                    $m = $i + 1; ?>
                                    <option value="<?= $m ?>" <?= $birthParts['month'] === (string)$m ? 'selected' : '' ?>><?= $name ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col">
                            <select class="form-select" name="birth_day" id="birth_day_edit">
                                <option value="">روز</option>
                                <?php for ($d = 1; $d <= 31; $d++): ?>
                                    <option value="<?= $d ?>" <?= $birthParts['day'] === (string)$d ? 'selected' : '' ?>><?= $d ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">وضعیت تأهل</label>
                    <select class="form-select" name="marital_status" id="marital_status_edit">
                        <option value="">—</option>
                        <option value="مجرد" <?= $val('marital_status') === 'مجرد' ? 'selected' : '' ?>>مجرد</option>
                        <option value="متأهل" <?= $val('marital_status') === 'متأهل' ? 'selected' : '' ?>>متأهل</option>
                    </select>
                </div>
                <div class="col-md-6" id="children_wrapper_edit" style="display: <?= $val('marital_status') === 'متأهل' ? 'block' : 'none' ?>;">
                    <label class="form-label">تعداد فرزندان</label>
                    <input type="number" class="form-control" name="children_count"
                           value="<?= $val('children_count', '0') ?>" min="0">
                </div>
                <div class="col-md-6">
                    <label class="form-label">وضعیت جسمانی</label>
                    <select class="form-select" name="physical_condition" id="physical_condition_edit">
                        <option value="سالم" <?= $val('physical_condition') === 'سالم' ? 'selected' : '' ?>>سالم</option>
                        <option value="معلولیت" <?= $val('physical_condition') === 'معلولیت' ? 'selected' : '' ?>>معلولیت</option>
                    </select>
                </div>
                <div class="col-md-6" id="disability_wrapper_edit" style="display: <?= $val('physical_condition') === 'معلولیت' ? 'block' : 'none' ?>;">
                    <label class="form-label">توضیحات معلولیت</label>
                    <input type="text" class="form-control" name="disability_details"
                           value="<?= $val('disability_details') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">دهک درآمدی (اختیاری)</label>
                    <input type="number" class="form-control" name="income_decile"
                           value="<?= $val('income_decile') ?>" min="1" max="10">
                </div>
                <div class="col-md-6">
                    <label class="form-label">نوع خدمت</label>
                    <select class="form-select" name="service_type_id">
                        <option value="">—</option>
                        <?php foreach ($serviceTypes as $st): ?>
                            <?php $sid = (int)$st['id']; ?>
                            <option value="<?= $sid ?>"
                                <?= ((int)($row['service_type_id'] ?? 0) === $sid) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$st['title'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <hr class="mt-3">
            <h6>سؤالات تکمیلی</h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">پروندهٔ قضایی؟</label>
                    <select class="form-select" name="has_court_case">
                        <option value="خیر" <?= $val('has_court_case') === 'خیر' ? 'selected' : '' ?>>خیر</option>
                        <option value="بله" <?= $val('has_court_case') === 'بله' ? 'selected' : '' ?>>بله</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">وام فعال؟</label>
                    <select class="form-select" name="has_active_loan">
                        <option value="خیر" <?= $val('has_active_loan') === 'خیر' ? 'selected' : '' ?>>خیر</option>
                        <option value="بله" <?= $val('has_active_loan') === 'بله' ? 'selected' : '' ?>>بله</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">اقساط معوقه؟</label>
                    <select class="form-select" name="has_overdue_installments">
                        <option value="خیر" <?= $val('has_overdue_installments') === 'خیر' ? 'selected' : '' ?>>خیر</option>
                        <option value="بله" <?= $val('has_overdue_installments') === 'بله' ? 'selected' : '' ?>>بله</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-briefcase"></i> اطلاعات شغلی</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">عنوان مجوز</label>
                    <input type="text" class="form-control" name="license_title"
                           value="<?= $val('license_title') ?>" maxlength="100">
                </div>
                <div class="col-md-3">
                    <label class="form-label">سابقه (ماه)</label>
                    <input type="number" class="form-control" name="experience_months"
                           value="<?= $val('experience_months', '0') ?>" min="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label">نحوه آموزش</label>
                    <select class="form-select" name="training_method">
                        <?php foreach (['در خانه', 'استاد شاگردی', 'کارگاهی'] as $tm): ?>
                            <option value="<?= htmlspecialchars($tm, ENT_QUOTES, 'UTF-8') ?>"
                                <?= ($val('training_method') === $tm) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tm, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-house"></i> اطلاعات ملک</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">وضعیت مالکیت</label>
                    <select class="form-select" name="ownership_status">
                        <?php foreach (['شخص', 'مستأجر', 'سرپرست'] as $os): ?>
                            <option value="<?= htmlspecialchars($os, ENT_QUOTES, 'UTF-8') ?>"
                                <?= ($val('ownership_status') === $os) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($os, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">استان</label>
                    <input type="text" class="form-control" name="province"
                           value="<?= $val('province') ?>" maxlength="50">
                </div>
                <div class="col-md-4">
                    <label class="form-label">شهرستان</label>
                    <input type="text" class="form-control" name="county"
                           value="<?= $val('county') ?>" maxlength="50">
                </div>
                <div class="col-md-4">
                    <label class="form-label">شهر</label>
                    <input type="text" class="form-control" name="city"
                           value="<?= $val('city') ?>" maxlength="50">
                </div>
                <div class="col-md-4">
                    <label class="form-label">بخش</label>
                    <input type="text" class="form-control" name="district"
                           value="<?= $val('district') ?>" maxlength="50" placeholder="بخش">
                </div>
                <div class="col-md-4">
                    <label class="form-label">دهستان</label>
                    <input type="text" class="form-control" name="rural_district"
                           value="<?= $val('rural_district') ?>" maxlength="50" placeholder="دهستان">
                </div>
                <div class="col-md-4">
                    <label class="form-label">روستا</label>
                    <input type="text" class="form-control" name="village"
                           value="<?= $val('village') ?>" maxlength="50" placeholder="روستا">
                </div>
                <div class="col-md-4">
                    <label class="form-label">شناسه قبض برق</label>
                    <input type="text" class="form-control" name="electricity_bill_id"
                           value="<?= $val('electricity_bill_id') ?>" maxlength="20"
                           inputmode="numeric" style="direction:ltr;text-align:right;">
                </div>
                <div class="col-md-6">
                    <label class="form-label">خیابان</label>
                    <input type="text" class="form-control" name="address_street"
                           value="<?= $val('address_street') ?>" maxlength="100">
                </div>
                <div class="col-md-3">
                    <label class="form-label">کوچه</label>
                    <input type="text" class="form-control" name="address_alley"
                           value="<?= $val('address_alley') ?>" maxlength="100">
                </div>
                <div class="col-md-3">
                    <label class="form-label">پلاک</label>
                    <input type="text" class="form-control" name="address_plaque"
                           value="<?= $val('address_plaque') ?>" maxlength="20">
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-3">
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/requests" class="btn btn-outline-secondary">
            انصراف
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> ثبت نهایی اطلاعات
        </button>
    </div>
</form>

<script>
// Toggle the "تعداد فرزندان" field visibility based on marital_status
// (only show for متأهل) and the "توضیحات معلولیت" field based on
// physical_condition (only show for معلولیت). Mirrors the admin edit
// form's behaviour. Inline script — the form is server-rendered and
// these toggles are simple enough to live next to the markup.
(function () {
    var marital = document.getElementById('marital_status_edit');
    var childrenWrap = document.getElementById('children_wrapper_edit');
    var phys = document.getElementById('physical_condition_edit');
    var disabilityWrap = document.getElementById('disability_wrapper_edit');

    function syncMarital() {
        if (!marital || !childrenWrap) return;
        childrenWrap.style.display = (marital.value === 'متأهل') ? 'block' : 'none';
    }
    function syncPhys() {
        if (!phys || !disabilityWrap) return;
        disabilityWrap.style.display = (phys.value === 'معلولیت') ? 'block' : 'none';
    }
    if (marital) { marital.addEventListener('change', syncMarital); syncMarital(); }
    if (phys)   { phys.addEventListener('change', syncPhys); syncPhys(); }
})();
</script>
