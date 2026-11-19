<?php
/**
 * admin/edit_application.php — edit an existing applicant's case file.
 *
 * Phase 17 (v1.8.0): rendered by AdminController::editApplication().
 * Phase 36 (v2.0.2): port v2's full edit form — all identity fields
 *   (birth_date as 3 Jalali selects, marital_status, children_count,
 *   physical_condition, disability_details, income_decile,
 *   has_court_case, has_active_loan, has_overdue_installments), all
 *   property fields (district, rural_district, village), and the
 *   18-status change dropdown (reuses the existing POST /admin/change-status).
 *
 * @var \App\Modules\Core\Domain\Applicant $applicant
 * @var array<string,mixed>                  $row  raw applicants row (all columns)
 * @var \App\Modules\Core\Domain\JobDetail|null    $jobDetail
 * @var \App\Modules\Core\Domain\PropertyDetail|null $propertyDetail
 * @var array<int, \App\Modules\Core\Domain\Document> $documents
 * @var array<int, array<string,mixed>>       $serviceTypes
 * @var array<int, array<string,mixed>>       $allStatuses
 * @var \App\Shared\Support\PersianDate       $persianDate
 * @var array<int,string>                     $errors
 * @var string $csrfField
 * @var string $baseUrl
 */
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array<string,mixed> $row */
/** @var array<int, \App\Modules\Core\Domain\Document> $documents */
/** @var array<int, array<string,mixed>> $serviceTypes */
/** @var array<int, array<string,mixed>> $allStatuses */
/** @var \App\Shared\Support\PersianDate $persianDate */
/** @var array<int,string> $errors */
/** @var string $csrfField */
/** @var string $baseUrl */

use App\Modules\Core\Domain\Document;
use App\Modules\Core\Domain\JobDetail;
use App\Modules\Core\Domain\PropertyDetail;
use App\Shared\Support\PersianDate;

$row           ??= [];
$jobDetail     ??= null;
$propertyDetail ??= null;
$documents     ??= [];
$serviceTypes  ??= [];
$allStatuses   ??= [];
$persianDate   ??= null;
$errors        ??= [];
$csrfField     ??= '';
$baseUrl       ??= '/';

/** @return string */
$val = static function (string $key, ?string $default = '') use ($row, $jobDetail, $propertyDetail): string {
    // Look up by key in $row first; fall back to the domain objects.
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
$currentStatus = $applicant->currentStatus;

// Parse the stored Gregorian birth_date into 3 Jalali Y/M/D values for
// the 3-select dropdown.
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
        // ignore — leave empty
    }
}

$months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
$currentPersianYear = $persianDate instanceof PersianDate
    ? (int)explode('/', $persianDate->todayJalali())[0]
    : 1400;

$docLabels = [
    'national_card'    => 'کارت ملی',
    'birth_cert'       => 'شناسنامه',
    'ownership_doc'    => 'مدرک مالکیت',
    'vocational_cert'  => 'گواهی فنی‌وحرفه‌ای',
    'education_degree' => 'مدرک تحصیلی',
    'product_photos'   => 'تصویر محصول',
    'optional'         => 'سایر',
];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h5 mb-1"><i class="bi bi-pencil-square"></i> ویرایش پروندهٔ متقاضی</h2>
        <span class="moj-tracking-code"><?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/view/<?= $applicantId ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-eye"></i> مشاهدهٔ پرونده
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

<!-- ====== Phase 36: Status change dropdown — reuses /admin/change-status ====== -->
<div class="card border-primary mb-3">
    <div class="card-header bg-primary-subtle d-flex align-items-center gap-2">
        <i class="bi bi-arrow-repeat text-primary"></i>
        <strong>تغییر وضعیت پرونده (چرخهٔ ۱۸ وضعیت)</strong>
    </div>
    <div class="card-body">
        <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/change-status">
            <?= $csrfField ?>
            <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">وضعیت فعلی</label>
                    <div class="form-control-plaintext">
                        <span class="badge text-bg-<?= htmlspecialchars($currentStatus->badgeColor(), ENT_QUOTES, 'UTF-8') ?> fs-6">
                            <?= htmlspecialchars($currentStatus->titleFa(), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <small class="text-muted ms-2">(ترتیب: <?= $currentStatus->order() ?>)</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <label for="new_status" class="form-label">انتخاب وضعیت جدید</label>
                    <select class="form-select" id="new_status" name="new_status" required>
                        <option value="">— انتخاب کنید —</option>
                        <?php foreach ($allStatuses as $s): ?>
                            <?php $isCurrent = $s['value'] === $currentStatus->value; ?>
                            <option value="<?= htmlspecialchars($s['value'], ENT_QUOTES, 'UTF-8') ?>"
                                <?= $isCurrent ? 'disabled' : '' ?>
                                data-color="<?= htmlspecialchars($s['badgeColor'], ENT_QUOTES) ?>"
                                data-terminal="<?= $s['isTerminal'] ? '1' : '0' ?>">
                                <?= htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8') ?>
                                <?= $s['isTerminal'] ? ' (پایانی)' : '' ?>
                                <?= $isCurrent ? ' (فعلی)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="status_description" class="form-label">توضیح (اختیاری)</label>
                    <input type="text" class="form-control" id="status_description" name="description"
                           placeholder="دلیل تغییر وضعیت..." maxlength="500">
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary" id="btn-change-status">
                    <i class="bi bi-check2-circle"></i> اعمال تغییر وضعیت
                </button>
            </div>
        </form>
    </div>
</div>

<form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/update">
    <?= $csrfField ?>
    <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">

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
                           value="<?= $val('mobile') ?>" maxlength="11" pattern="09\d{9}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">کد پستی <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="postal_code"
                           value="<?= $val('postal_code') ?>" maxlength="10" pattern="\d{10}" required>
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
                           value="<?= $val('electricity_bill_id') ?>" maxlength="20">
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
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/view/<?= $applicantId ?>" class="btn btn-outline-secondary">انصراف</a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> ذخیره تغییرات
        </button>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-file-earmark"></i> مدارک
            <span class="badge text-bg-secondary ms-1"><?= count($documents) ?></span>
        </span>
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse"
                data-bs-target="#addDocForm" aria-expanded="false" aria-controls="addDocForm">
            <i class="bi bi-plus-lg"></i> افزودن مدرک
        </button>
    </div>
    <div class="card-body">
        <div class="collapse mb-3" id="addDocForm">
            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/add-document"
                  enctype="multipart/form-data">
                <?= $csrfField ?>
                <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">نوع مدرک</label>
                        <select class="form-select" name="doc_type">
                            <option value="optional">سایر</option>
                            <option value="national_card">کارت ملی</option>
                            <option value="birth_cert">شناسنامه</option>
                            <option value="ownership_doc">مدرک مالکیت</option>
                            <option value="vocational_cert">گواهی فنی‌وحرفه‌ای</option>
                            <option value="education_degree">مدرک تحصیلی</option>
                            <option value="product_photos">تصویر محصولات</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">فایل</label>
                        <input type="file" class="form-control" name="document_file" required
                               accept="image/jpeg,image/png,application/pdf">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-upload"></i> بارگذاری
                        </button>
                    </div>
                </div>
                <div class="form-text small text-muted mt-2">
                    پسوندهای مجاز: jpg، jpeg، png، pdf. حداکثر حجم: ۲ مگابایت.
                </div>
            </form>
        </div>

        <?php if ($documents === []): ?>
            <div class="text-muted text-center py-4">هیچ مدرکی ثبت نشده است.</div>
        <?php else: ?>
            <!-- Phase 36: document thumbnails with type label — mirrors v2 edit form -->
            <div class="row g-2">
                <?php foreach ($documents as $doc): ?>
                    <?php \assert($doc instanceof Document); ?>
                    <?php $docLabel = $docLabels[$doc->docType] ?? $doc->docType; ?>
                    <div class="col-6 col-md-3 col-xl-2">
                        <div class="card h-100 p-2 text-center" style="border-style:dashed;">
                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>document/inline/<?= (int)$doc->id ?>" target="_blank" rel="noopener">
                                <?php if ($doc->isImage()): ?>
                                    <img src="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>document/thumb/<?= (int)$doc->id ?>"
                                         class="rounded mb-1" style="height:70px;width:100%;object-fit:cover;"
                                         alt="<?= htmlspecialchars($docLabel, ENT_QUOTES, 'UTF-8') ?>">
                                <?php elseif ($doc->isPdf()): ?>
                                    <span class="d-block py-3" style="color:var(--bs-danger, #dc3545)">
                                        <i class="bi bi-file-earmark-pdf" style="font-size:1.8rem"></i>
                                    </span>
                                <?php else: ?>
                                    <span class="d-block py-3" style="color:var(--bs-secondary, #6c757d)">
                                        <i class="bi bi-file-earmark-text" style="font-size:1.8rem"></i>
                                    </span>
                                <?php endif; ?>
                            </a>
                            <span class="badge text-bg-info mb-1" style="font-size:.65rem"><?= htmlspecialchars($docLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            <small class="text-muted text-truncate d-block"
                                   title="<?= htmlspecialchars($doc->originalName, ENT_QUOTES, 'UTF-8') ?>"
                                   style="font-size:.7rem"><?= htmlspecialchars($doc->originalName, ENT_QUOTES, 'UTF-8') ?></small>
                            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/delete-document"
                                  class="mt-1"
                                  onsubmit="return confirm('حذف این مدرک؟ این عمل قابل بازگشت نیست.')">
                                <?= $csrfField ?>
                                <input type="hidden" name="document_id" value="<?= $doc->id ?>">
                                <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger w-100" style="font-size:.7rem">
                                    <i class="bi bi-trash"></i> حذف
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Marital status → children count toggle
(function() {
    var ms = document.getElementById('marital_status_edit');
    var cw = document.getElementById('children_wrapper_edit');
    if (ms && cw) {
        ms.addEventListener('change', function() {
            cw.style.display = this.value === 'متأهل' ? 'block' : 'none';
        });
    }
    // Physical condition → disability details toggle
    var pc = document.getElementById('physical_condition_edit');
    var dw = document.getElementById('disability_wrapper_edit');
    if (pc && dw) {
        pc.addEventListener('change', function() {
            dw.style.display = this.value === 'معلولیت' ? 'block' : 'none';
        });
    }
})();

// Phase 36: status change dropdown — color the submit button by the
// selected target status (mirrors the view_application.php behaviour).
(function() {
    var sel = document.getElementById('new_status');
    if (!sel) return;
    sel.addEventListener('change', function() {
        var opt = sel.options[sel.selectedIndex];
        var btn = document.getElementById('btn-change-status');
        if (!opt || !opt.value) { btn.className = 'btn btn-primary'; return; }
        var color = opt.getAttribute('data-color') || 'primary';
        var terminal = opt.getAttribute('data-terminal') === '1';
        btn.className = 'btn btn-' + (terminal ? 'danger' : color);
    });
})();

// Phase 36: hide days beyond the Jalali month length (1-31 → 29/30/31)
(function() {
    var ySel = document.getElementById('birth_year_edit');
    var mSel = document.getElementById('birth_month_edit');
    var dSel = document.getElementById('birth_day_edit');
    if (!ySel || !mSel || !dSel) return;
    function updateDays() {
        var year = parseInt(ySel.value, 10) || 1399;
        var month = parseInt(mSel.value, 10) || 1;
        var maxDays = (month <= 6) ? 31 : (month <= 11) ? 30 : (((year + 12) % 33 % 4) === 1) ? 30 : 29;
        for (var i = 1; i <= 31; i++) {
            var opt = dSel.querySelector('option[value="' + i + '"]');
            if (opt) opt.style.display = (i <= maxDays) ? '' : 'none';
        }
        if (parseInt(dSel.value, 10) > maxDays) dSel.value = '';
    }
    ySel.addEventListener('change', updateDays);
    mSel.addEventListener('change', updateDays);
    updateDays();
})();
</script>
