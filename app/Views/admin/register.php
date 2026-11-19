<?php
/**
 * admin/register.php — manual registration form for an admin.
 *
 * Phase 35 (v2.0.1): ported the full v2 41-field form. The admin fills in
 * identity (11), service (2), property (13), job (3), income (1),
 * consent (1), and the controller stamps 5 tracking fields
 * (tracking_code, current_status, registration_started_at,
 * registration_completed_at, version) on the applicants row.
 *
 * Phase 51 (v2.1.7): upgraded the documents section to v2-style
 * `.upload-box` clickable file pickers (national_card_front,
 * national_card_back, birth_cert, ownership_doc, vocational_cert,
 * education_degree, product_photos[] 1..15, optional_1..3) — same
 * set as the public wizard step4. The controller's
 * handleDocumentUploads() was widened to match these field names.
 *
 * Adapted to v3's Bootstrap 5 RTL + Vazirmatn style. The CSRF field is
 * emitted via $csrfField. Asset URLs use $baseUrl. License titles are
 * loaded dynamically via /admin/license-titles/by-service/{id} (admin-only
 * JSON endpoint) when the admin picks a service_type.
 *
 * @var array<int, array<string,mixed>> $serviceTypes
 * @var array<int,string>               $errors
 * @var array<string,mixed>             $form  submitted values from a failed POST
 * @var string $csrfField
 * @var string $baseUrl
 */
/** @var array<int, array<string,mixed>> $serviceTypes */
/** @var array<int,string> $errors */
/** @var array<string,mixed> $form */
/** @var string $csrfField */
/** @var string $baseUrl */

$serviceTypes ??= [];
$errors       ??= [];
$form         ??= [];
$csrfField    ??= '';
$baseUrl      ??= '/';

/**
 * Echo a previously-submitted value safely.
 */
$formValue = static function (string $key) use ($form): string {
    return htmlspecialchars((string)($form[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};

/** Build a <select> option's `selected` attribute for a value. */
$selected = static function (string $key, string $value) use ($form): string {
    return (string)($form[$key] ?? '') === $value ? ' selected' : '';
};

/** Persian month names for the birth-date select. */
$months = [
    1  => 'فروردین', 2  => 'اردیبهشت', 3  => 'خرداد', 4  => 'تیر',
    5  => 'مرداد', 6  => 'شهریور', 7  => 'مهر', 8  => 'آبان',
    9  => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
];

/** Range of birth years (1300 → current Persian year). */
$currentPersianYear = 1404; // fallback; JS adjusts to today on the client.
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-person-plus"></i> ثبت‌نام دستی متقاضی</h2>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applications" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-right"></i> بازگشت
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

<div class="card">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data"
              action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/register">
            <?= $csrfField ?>

            <!-- ========== اطلاعات هویتی (Identity) — 11 fields ========== -->
            <fieldset class="mb-4">
                <legend class="h6 mb-3"><i class="bi bi-person-vcard"></i> اطلاعات هویتی</legend>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">نام <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="first_name"
                               value="<?= $formValue('first_name') ?>"
                               maxlength="50" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">نام خانوادگی <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="last_name"
                               value="<?= $formValue('last_name') ?>"
                               maxlength="50" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">کد ملی <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="national_code"
                               value="<?= $formValue('national_code') ?>"
                               maxlength="10" pattern="\d{10}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">شماره تلفن همراه <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="mobile"
                               value="<?= $formValue('mobile') ?>"
                               maxlength="11" pattern="09\d{9}" placeholder="09XXXXXXXXX" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">نام پدر</label>
                        <input type="text" class="form-control" name="father_name"
                               value="<?= $formValue('father_name') ?>"
                               maxlength="50">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">نوع متقاضی</label>
                        <select class="form-select" name="applicant_type" id="applicant_type">
                            <option value="حقیقی"<?= $selected('applicant_type', 'حقیقی') ?>>حقیقی</option>
                            <option value="حقوقی"<?= $selected('applicant_type', 'حقوقی') ?>>حقوقی</option>
                        </select>
                    </div>

                    <!-- Birth date — three selects (year/month/day) -->
                    <div class="col-md-6">
                        <label class="form-label">تاریخ تولد</label>
                        <div class="row g-2">
                            <div class="col">
                                <select class="form-select" name="birth_year" id="birth_year_reg">
                                    <option value="">سال</option>
                                    <?php for ($y = 1300; $y <= $currentPersianYear; $y++): ?>
                                        <option value="<?= $y ?>"<?= $selected('birth_year', (string)$y) ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col">
                                <select class="form-select" name="birth_month" id="birth_month_reg">
                                    <option value="">ماه</option>
                                    <?php foreach ($months as $m => $name): ?>
                                        <option value="<?= $m ?>"<?= $selected('birth_month', (string)$m) ?>><?= $name ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col">
                                <select class="form-select" name="birth_day" id="birth_day_reg">
                                    <option value="">روز</option>
                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                        <option value="<?= $d ?>"<?= $selected('birth_day', (string)$d) ?>><?= $d ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">وضعیت تأهل</label>
                        <select class="form-select" name="marital_status" id="marital_status_reg">
                            <option value="">انتخاب...</option>
                            <option value="مجرد"<?= $selected('marital_status', 'مجرد') ?>>مجرد</option>
                            <option value="متأهل"<?= $selected('marital_status', 'متأهل') ?>>متأهل</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="children_wrapper_reg"
                         style="display: <?= ($form['marital_status'] ?? '') === 'متأهل' ? 'block' : 'none' ?>;">
                        <label class="form-label">تعداد فرزندان</label>
                        <input type="number" class="form-control" name="children_count"
                               value="<?= $formValue('children_count') !== '' ? $formValue('children_count') : '0' ?>"
                               min="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">وضعیت جسمانی</label>
                        <select class="form-select" name="physical_condition" id="physical_condition_reg">
                            <option value="سالم"<?= $selected('physical_condition', 'سالم') ?>>سالم</option>
                            <option value="معلولیت"<?= $selected('physical_condition', 'معلولیت') ?>>معلولیت</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="disability_wrapper_reg"
                         style="display: <?= ($form['physical_condition'] ?? '') === 'معلولیت' ? 'block' : 'none' ?>;">
                        <label class="form-label">توضیحات معلولیت</label>
                        <input type="text" class="form-control" name="disability_details"
                               value="<?= $formValue('disability_details') ?>"
                               maxlength="255">
                    </div>
                </div>

                <hr class="mt-3">
                <h6>سؤالات تکمیلی</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">آیا پرونده قضایی دارید؟</label>
                        <select class="form-select" name="has_court_case">
                            <option value="خیر"<?= $selected('has_court_case', 'خیر') ?>>خیر</option>
                            <option value="بله"<?= $selected('has_court_case', 'بله') ?>>بله</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">وام فعال دارید؟</label>
                        <select class="form-select" name="has_active_loan">
                            <option value="خیر"<?= $selected('has_active_loan', 'خیر') ?>>خیر</option>
                            <option value="بله"<?= $selected('has_active_loan', 'بله') ?>>بله</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">اقساط معوقه دارید؟</label>
                        <select class="form-select" name="has_overdue_installments">
                            <option value="خیر"<?= $selected('has_overdue_installments', 'خیر') ?>>خیر</option>
                            <option value="بله"<?= $selected('has_overdue_installments', 'بله') ?>>بله</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">دهک درآمدی (اختیاری)</label>
                        <input type="number" class="form-control" name="income_decile"
                               value="<?= $formValue('income_decile') ?>"
                               min="1" max="10">
                    </div>
                </div>
            </fieldset>

            <!-- ========== اطلاعات خدمت و شغل (Service + Job) — 5 fields ========== -->
            <fieldset class="mb-4">
                <legend class="h6 mb-3"><i class="bi bi-briefcase"></i> اطلاعات خدمت و شغل</legend>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">نوع خدمت <span class="text-danger">*</span></label>
                        <select class="form-select" name="service_type_id" id="service_type_id_reg"
                                onchange="reloadLicenseTitlesByService(this.value)" required>
                            <option value="">انتخاب کنید...</option>
                            <?php foreach ($serviceTypes as $st): ?>
                                <?php $sid = (int)$st['id']; ?>
                                <option value="<?= $sid ?>"
                                    <?= ((string)($form['service_type_id'] ?? '') === (string)$sid) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$st['title'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">عنوان مجوز <span class="text-danger">*</span></label>
                        <select class="form-select" name="license_title" id="license_title_reg"
                                onchange="toggleCustomLicenseReg()" required>
                            <option value="">انتخاب کنید...</option>
                            <option value="سایر"<?= str_starts_with((string)($form['license_title'] ?? ''), 'سایر') ? ' selected' : '' ?>>
                                سایر (عنوان دلخواه)
                            </option>
                        </select>
                        <div id="custom_license_wrapper_reg"
                             style="display: <?= str_starts_with((string)($form['license_title'] ?? ''), 'سایر') ? 'block' : 'none' ?>; margin-top:0.5rem;">
                            <label class="form-label">عنوان مجوز دلخواه را وارد کنید:</label>
                            <input type="text" class="form-control" name="custom_license_title"
                                   value="<?= $formValue('custom_license_title') ?>"
                                   maxlength="100">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">سابقه کار (ماه)</label>
                        <input type="number" class="form-control" name="experience_months"
                               value="<?= $formValue('experience_months') !== '' ? $formValue('experience_months') : '0' ?>"
                               min="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">نحوه آموزش</label>
                        <div class="d-flex gap-3 pt-2">
                            <?php foreach (['در خانه', 'استاد شاگردی', 'کارگاهی'] as $method): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="training_method"
                                           value="<?= htmlspecialchars($method, ENT_QUOTES) ?>"
                                           id="tm_<?= htmlspecialchars($method, ENT_QUOTES) ?>"
                                           <?= ($form['training_method'] ?? '') === $method ? 'checked' : '' ?>>
                                    <label class="form-check-label"
                                           for="tm_<?= htmlspecialchars($method, ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($method, ENT_QUOTES) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </fieldset>

            <!-- ========== اطلاعات ملک (Property) — 13 fields ========== -->
            <fieldset class="mb-4">
                <legend class="h6 mb-3"><i class="bi bi-house"></i> اطلاعات ملک</legend>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">کد پستی <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="postal_code"
                               value="<?= $formValue('postal_code') ?>"
                               maxlength="10" pattern="\d{10}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">وضعیت مالکیت</label>
                        <select class="form-select" name="ownership_status">
                            <?php foreach (['شخص', 'مستأجر', 'سرپرست'] as $os): ?>
                                <option value="<?= htmlspecialchars($os, ENT_QUOTES) ?>"
                                    <?= $selected('ownership_status', $os) ?>>
                                    <?= htmlspecialchars($os, ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">استان <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="province"
                               value="<?= $formValue('province') ?>" maxlength="50" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">شهرستان <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="county"
                               value="<?= $formValue('county') ?>" maxlength="50" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">شهر</label>
                        <input type="text" class="form-control" name="city"
                               value="<?= $formValue('city') ?>" maxlength="50">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">بخش</label>
                        <input type="text" class="form-control" name="district"
                               value="<?= $formValue('district') ?>" maxlength="50">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">دهستان</label>
                        <input type="text" class="form-control" name="rural_district"
                               value="<?= $formValue('rural_district') ?>" maxlength="50">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">روستا</label>
                        <input type="text" class="form-control" name="village"
                               value="<?= $formValue('village') ?>" maxlength="50">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">شناسه قبض برق</label>
                        <input type="text" class="form-control" name="electricity_bill_id"
                               value="<?= $formValue('electricity_bill_id') ?>" maxlength="20">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">خیابان</label>
                        <input type="text" class="form-control" name="address_street"
                               value="<?= $formValue('address_street') ?>" maxlength="100">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">کوچه</label>
                        <input type="text" class="form-control" name="address_alley"
                               value="<?= $formValue('address_alley') ?>" maxlength="100">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">پلاک</label>
                        <input type="text" class="form-control" name="address_plaque"
                               value="<?= $formValue('address_plaque') ?>" maxlength="20">
                    </div>
                </div>
            </fieldset>

            <!-- ========== مدارک (Documents) — v2-style .upload-box fields ========== -->
            <fieldset class="mb-4">
                <legend class="h6 mb-3"><i class="bi bi-file-earmark"></i> مدارک</legend>
                <p class="text-muted small mb-3">
                    فقط فرمت‌های jpg، jpeg، png و pdf — حداکثر ۲ مگابایت برای هر فایل.
                </p>

                <!-- مدارک هویتی -->
                <h2 class="h6 mb-3"><i class="bi bi-person-vcard"></i> مدارک هویتی</h2>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="upload-box" onclick="document.getElementById('national_card_front').click()">
                            <input type="file" id="national_card_front" name="national_card_front"
                                   accept=".jpg,.jpeg,.png,.pdf" style="display:none"
                                   onchange="mojShowFileName(this)">
                            <div class="mb-1"><i class="bi bi-person-vcard fs-3 text-brand"></i></div>
                            <strong class="small">تصویر کارت ملی (رو)</strong>
                            <p class="text-muted small mb-0" id="file_national_card_front">فایلی انتخاب نشده</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="upload-box" onclick="document.getElementById('national_card_back').click()">
                            <input type="file" id="national_card_back" name="national_card_back"
                                   accept=".jpg,.jpeg,.png,.pdf" style="display:none"
                                   onchange="mojShowFileName(this)">
                            <div class="mb-1"><i class="bi bi-person-vcard-fill fs-3 text-brand"></i></div>
                            <strong class="small">تصویر کارت ملی (پشت)</strong>
                            <p class="text-muted small mb-0" id="file_national_card_back">فایلی انتخاب نشده</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="upload-box" onclick="document.getElementById('birth_cert').click()">
                            <input type="file" id="birth_cert" name="birth_cert"
                                   accept=".jpg,.jpeg,.png,.pdf" style="display:none"
                                   onchange="mojShowFileName(this)">
                            <div class="mb-1"><i class="bi bi-file-earmark-text fs-3 text-brand"></i></div>
                            <strong class="small">تصویر شناسنامه</strong>
                            <p class="text-muted small mb-0" id="file_birth_cert">فایلی انتخاب نشده</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="upload-box" onclick="document.getElementById('ownership_doc').click()">
                            <input type="file" id="ownership_doc" name="ownership_doc"
                                   accept=".jpg,.jpeg,.png,.pdf" style="display:none"
                                   onchange="mojShowFileName(this)">
                            <div class="mb-1"><i class="bi bi-house-door fs-3 text-brand"></i></div>
                            <strong class="small">مدرک مالکیت</strong>
                            <p class="text-muted small mb-0" id="file_ownership_doc">فایلی انتخاب نشده</p>
                        </div>
                    </div>
                </div>

                <!-- مدارک تکمیلی -->
                <hr class="my-3">
                <h2 class="h6 mb-3"><i class="bi bi-patch-plus"></i> مدارک تکمیلی</h2>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="upload-box" onclick="document.getElementById('vocational_cert').click()">
                            <input type="file" id="vocational_cert" name="vocational_cert"
                                   accept=".jpg,.jpeg,.png,.pdf" style="display:none"
                                   onchange="mojShowFileName(this)">
                            <div class="mb-1"><i class="bi bi-award fs-3 text-brand"></i></div>
                            <strong class="small">گواهینامه فنی‌وحرفه‌ای</strong>
                            <p class="text-muted small mb-0" id="file_vocational_cert">فایلی انتخاب نشده</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="upload-box" onclick="document.getElementById('education_degree').click()">
                            <input type="file" id="education_degree" name="education_degree"
                                   accept=".jpg,.jpeg,.png,.pdf" style="display:none"
                                   onchange="mojShowFileName(this)">
                            <div class="mb-1"><i class="bi bi-mortarboard fs-3 text-brand"></i></div>
                            <strong class="small">آخرین مدرک تحصیلی</strong>
                            <p class="text-muted small mb-0" id="file_education_degree">فایلی انتخاب نشده</p>
                        </div>
                    </div>
                </div>

                <!-- تصاویر متقاضی با محصولات -->
                <hr class="my-3">
                <h2 class="h6 mb-3"><i class="bi bi-images"></i> تصاویر متقاضی با محصولات حین تولید</h2>
                <p class="text-muted small">حداقل ۵ و حداکثر ۱۵ عکس روشن و واضح بارگذاری کنید.</p>
                <div class="row g-2 mb-3" id="product-photos">
                    <?php for ($i = 1; $i <= 15; $i++): ?>
                        <div class="col-6 col-md-4 col-lg-2">
                            <div class="upload-box p-2" onclick="document.getElementById('product_photo_<?= $i ?>').click()">
                                <input type="file" id="product_photo_<?= $i ?>" name="product_photos[]"
                                       accept=".jpg,.jpeg,.png" style="display:none"
                                       onchange="mojShowFileName(this)">
                                <div><i class="bi bi-camera fs-4 text-brand"></i></div>
                                <strong class="small">عکس <?= $i ?></strong>
                                <p class="text-muted mb-0" style="font-size:.68rem"
                                   id="file_product_photo_<?= $i ?>">انتخاب نشده</p>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- مدارک اختیاری -->
                <hr class="my-3">
                <h2 class="h6 mb-3"><i class="bi bi-paperclip"></i> سایر مدارک (حداکثر ۳ فایل)</h2>
                <div class="row g-3">
                    <?php for ($i = 1; $i <= 3; $i++): ?>
                        <div class="col-md-4">
                            <div class="upload-box" onclick="document.getElementById('optional_<?= $i ?>').click()">
                                <input type="file" id="optional_<?= $i ?>" name="optional_<?= $i ?>"
                                       accept=".jpg,.jpeg,.png,.pdf" style="display:none"
                                       onchange="mojShowFileName(this)">
                                <div class="mb-1"><i class="bi bi-file-earmark-arrow-up fs-3 text-brand"></i></div>
                                <strong class="small">سایر <?= $i ?></strong>
                                <p class="text-muted small mb-0" id="file_optional_<?= $i ?>">فایلی انتخاب نشده</p>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </fieldset>

            <!-- ========== رضایت (Consent) — 1 checkbox ========== -->
            <fieldset class="mb-4">
                <legend class="h6 mb-3"><i class="bi bi-check2-square"></i> رضایت</legend>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="consent_external_share"
                           value="1" id="consent_external_share"
                           <?= !empty($form['consent_external_share']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="consent_external_share">
                        با اشتراک‌گذاری اطلاعات این پرونده با نهادهای مجاز برای بررسی و صدور مجوز موافقم.
                    </label>
                </div>
            </fieldset>

            <div class="d-flex justify-content-end gap-2">
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applications"
                   class="btn btn-outline-secondary">انصراف</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> ثبت متقاضی
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Toggle children_count visibility based on marital_status.
    document.getElementById('marital_status_reg').addEventListener('change', function () {
        document.getElementById('children_wrapper_reg').style.display =
            this.value === 'متأهل' ? 'block' : 'none';
    });

    // Toggle disability_details visibility based on physical_condition.
    document.getElementById('physical_condition_reg').addEventListener('change', function () {
        document.getElementById('disability_wrapper_reg').style.display =
            this.value === 'معلولیت' ? 'block' : 'none';
    });

    // Toggle custom-license input when "سایر" is selected.
    function toggleCustomLicenseReg() {
        const select = document.getElementById('license_title_reg');
        document.getElementById('custom_license_wrapper_reg').style.display =
            select.value.startsWith('سایر') ? 'block' : 'none';
    }

    // Dynamically load license titles by service_type_id via the admin
    // /admin/license-titles/by-service/{id} JSON endpoint.
    const initialLicenseReg = <?= json_encode((string)($form['license_title'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
    const baseReg = <?= json_encode(rtrim($baseUrl, '/') . '/', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function reloadLicenseTitlesByService(serviceId) {
        const select = document.getElementById('license_title_reg');
        if (!select) return;
        if (!serviceId || serviceId === '0') {
            select.innerHTML = '<option value="">انتخاب کنید...</option>' +
                '<option value="سایر">سایر (عنوان دلخواه)</option>';
            toggleCustomLicenseReg();
            return;
        }
        fetch(baseReg + 'admin/license-titles/by-service/' + encodeURIComponent(serviceId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success || !data.data) return;
                const rows = data.data;
                // Group rows by their `category` field (the v2 endpoint returns
                // {groups: {cat: [titles]}}, v3 returns a flat array with
                // `category` on each row — we synthesise optgroups here).
                const groups = {};
                rows.forEach(function (row) {
                    const cat = row.category || 'عمومی';
                    if (!groups[cat]) groups[cat] = [];
                    groups[cat].push(row.title);
                });
                let html = '<option value="">انتخاب کنید...</option>';
                for (const cat in groups) {
                    html += '<optgroup label="' + cat + '">';
                    groups[cat].forEach(function (t) {
                        if (t.startsWith('سایر')) return;
                        const sel = (t === initialLicenseReg) ? ' selected' : '';
                        html += '<option value="' + t + '"' + sel + '>' + t + '</option>';
                    });
                    html += '</optgroup>';
                }
                html += '<option value="سایر"' +
                    (initialLicenseReg.startsWith('سایر') ? ' selected' : '') +
                    '>سایر (عنوان دلخواه)</option>';
                select.innerHTML = html;
                toggleCustomLicenseReg();
            })
            .catch(function () { /* leave list as-is on network error */ });
    }

    // Adjust day count based on the Jalali month (1-6 → 31, 7-11 → 30, 12 → 29/30).
    function updateDaysReg() {
        const year = parseInt(document.getElementById('birth_year_reg').value) || 1399;
        const month = parseInt(document.getElementById('birth_month_reg').value) || 1;
        const daySelect = document.getElementById('birth_day_reg');
        const maxDays = month <= 6 ? 31 : (month <= 11 ? 30 :
            (((year + 12) % 33 % 4) === 1 ? 30 : 29));
        for (let i = 1; i <= 31; i++) {
            const opt = daySelect.querySelector('option[value="' + i + '"]');
            if (opt) opt.style.display = (i <= maxDays) ? '' : 'none';
        }
        if (parseInt(daySelect.value) > maxDays) daySelect.value = '';
    }
    document.getElementById('birth_year_reg').addEventListener('change', updateDaysReg);
    document.getElementById('birth_month_reg').addEventListener('change', updateDaysReg);
    updateDaysReg();

    // Initial custom-license toggle.
    toggleCustomLicenseReg();

    // ---- v2-style .upload-box filename display (Phase 51, v2.1.7) ----
    // Mirrors the helper in registration/step4.php. Each `.upload-box` wraps
    // a hidden <input type=file> plus a <p id="file_{input_id}">; when the
    // admin picks a file, we replace the <p> text with the file's name and
    // flag the box with the `.has-file` success state.
    function mojShowFileName(input) {
        var hasFile = input.files && input.files.length > 0;
        var p = document.getElementById('file_' + input.id);
        if (p) {
            if (!p.dataset.default) p.dataset.default = p.textContent.trim();
            p.textContent = hasFile ? input.files[0].name : p.dataset.default;
        }
        var box = input.closest('.upload-box');
        if (box) box.classList.toggle('has-file', hasFile);
        if (hasFile && input.files[0].size > 2 * 1024 * 1024) {
            alert('⚠️ حجم فایل «' + input.files[0].name + '» بیش از ۲ مگابایت است و پذیرفته نمی‌شود.');
        }
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('#product-photos [id^="file_"], [id^="file_national_card_"], [id^="file_birth_cert"], [id^="file_ownership_doc"], [id^="file_vocational_cert"], [id^="file_education_degree"], [id^="file_optional_"]').forEach(function (el) {
            el.dataset.default = el.textContent.trim();
        });
    });
</script>
