<?php
/**
 * registration/step4.php — wizard step 4: documents + review + submit.
 *
 * Phase 41 (v2.0.7) — rewritten to use v2-style specific named file inputs
 * (national_card_front, national_card_back, birth_cert, ownership_doc,
 * vocational_cert, education_degree, product_photos[], optional_N) instead
 * of the generic `docs[doc_type]` array. Each document type gets its own
 * clickable upload box (CSS class .upload-box) with a hidden <input type=file>
 * and a small JS handler that shows the selected file name.
 *
 * The visibility of optional documents is controlled by the $sysActive map
 * (set by the future RegistrationController from the form_fields config):
 *   $sysActive = [
 *     'national_card_back' => true,   // show back-of-card upload (default true)
 *     'vocational_cert'     => true,
 *     'education_degree'    => true,
 *     'product_photos'      => true,
 *     'optional_1'          => false,
 *     'optional_2'          => false,
 *     'optional_3'          => false,
 *   ];
 *
 * Phase 15.1 (v1.6.1) — removed the static HTML5 `required` attribute
 * from the file inputs because the document_types table was empty
 * in a fresh install and the wizard blocked form submission when no
 * docs were uploaded. Kept the `*` asterisk for visual indication.
 *
 * Phase 16 (v1.7.0) — restored the `required` attribute dynamically
 * based on each document_type's `is_required` field. The
 * `RegistrationController::requiredDocs()` reads the column seeded by
 * migration 010, so the wizard reflects what's actually configured.
 *
 * Phase 22 (v1.9.4) — `is_required` is now read from the real
 * `document_types.is_required` column (migration 012) instead of being
 * computed from `doc_type_key`. The view is unchanged — it still reads
 * `$doc['is_required']` (truthy/falsy int).
 *
 * Expected $data:
 *   $sysActive       array<string,bool> — toggle flags for optional docs.
 *   $ownershipStatus string  — 'شخص' | 'مستأجر' | 'سرپرست' (for the
 *                              ownership_doc label).
 *   $summary         array<string,string> — read-only summary of steps 1..3.
 *   $errors          array<string,array<int,string>> — per-field errors
 *                   (keyed by field name; e.g. $errors['national_card_front']).
 *   $skipDocuments   bool    — when true, only a confirm + finalise button is
 *                              shown (home-business independent path).
 *   $stepPosition    int     — current step number (default 4).
 *   $baseUrl, $csrfField.
 */
/** @var array<string,bool> $sysActive */
/** @var string $ownershipStatus */
/** @var array<string,string> $summary */
/** @var array<string,array<int,string>> $errors */
/** @var bool $skipDocuments */
/** @var int $stepPosition */
/** @var string $baseUrl */
/** @var string $csrfField */

$sysActive      ??= [];
$ownershipStatus ??= '';
$summary        ??= [];
$errors         ??= [];
$skipDocuments  ??= false;
$stepPosition   ??= 4;
$baseUrl        ??= '';
$csrfField      ??= '';

// Default toggles: required docs are always on; optional docs default ON
// unless the admin explicitly disabled them in form_fields config.
//
// Phase 45 (v2.1.1) — this MUST be an arrow function (auto-captures
// $sysActive by value). The previous `static function () { ... }`
// closure did NOT capture $sysActive, so every $on('foo') call
// returned `true` regardless of the configured toggles — every
// optional upload box was always shown.
$on = fn (string $key): bool => $sysActive[$key] ?? true;

$ownershipLabels = [
    'شخص'   => 'سند مالکیت یا قولنامه',
    'مستأجر' => 'اجاره‌نامه یا رضایت‌نامهٔ مالک',
    'سرپرست' => 'حکم سرپرستی یا رضایت‌نامهٔ مرجع',
];
$ownershipLabel = $ownershipLabels[$ownershipStatus] ?? 'مدرک محل فعالیت';

$currentStep = 4;
$totalSteps  = 4;
require __DIR__ . '/_progress.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0"><i class="bi bi-file-earmark-text text-brand"></i>
                    مرحلهٔ ۴ — مدارک و بازبینی
                </h1>
            </div>
            <div class="card-body p-4">

            <?php if ($skipDocuments): ?>
                <!-- مشاغل خانگی مستقل: بدون مدارک — فقط تأیید و نهایی‌سازی -->
                <div class="alert alert-info d-flex align-items-center gap-2 py-3 mb-3">
                    <span style="font-size:1.4rem"><i class="bi bi-stars"></i></span>
                    <div class="small">
                        <strong>برای مشاغل خانگی مستقل نیازی به بارگذاری مدارک نیست.</strong><br>
                        عکس پرسنلی شما قبلاً در پروفایل کاربری دریافت شده است.
                        لطفاً اطلاعات واردشده را بررسی کرده و دکمهٔ «تأیید و نهایی‌سازی» را بزنید.
                    </div>
                </div>

                <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/<?= (int)$stepPosition ?>">
                    <?= $csrfField ?>
                    <div class="mt-4 d-flex justify-content-between">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/<?= max(1, (int)$stepPosition - 1) ?>"
                            class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-return-right"></i> بازگشت
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check2-circle"></i> تأیید و نهایی‌سازی
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <!-- حالت عادی: فرم بارگذاری مدارک -->
                <p class="text-muted small mb-3">
                    فقط فرمت‌های jpg، jpeg، png و pdf — حداکثر ۲ مگابایت برای هر فایل.
                </p>

                <?php if (!empty($errors['files'])): ?>
                    <div class="alert alert-danger border-end border-4 py-2">
                        <?php foreach ($errors['files'] as $e): ?>
                            <div><?= htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post"
                    action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/<?= (int)$stepPosition ?>"
                    enctype="multipart/form-data">
                    <?= $csrfField ?>

                    <!-- ===== مدارک هویتی (اجباری) ===== -->
                    <h2 class="h6 mb-3"><i class="bi bi-person-vcard"></i> مدارک هویتی</h2>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="upload-box" onclick="document.getElementById('national_card_front').click()">
                                <input type="file" id="national_card_front" name="national_card_front"
                                    accept=".jpg,.jpeg,.png,.pdf" style="display:none"
                                    onchange="mojShowFileName(this)">
                                <div class="mb-1"><i class="bi bi-person-vcard fs-3 text-brand"></i></div>
                                <strong class="small">تصویر کارت ملی (رو) <span class="text-danger">*</span></strong>
                                <p class="text-muted small mb-0" id="file_national_card_front">فایلی انتخاب نشده</p>
                            </div>
                            <?php if (!empty($errors['national_card_front'])): ?>
                                <div class="invalid-feedback d-block small">
                                    <?= htmlspecialchars(implode(' / ', $errors['national_card_front']), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($on('national_card_back')): ?>
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
                        <?php endif; ?>

                        <div class="col-md-6">
                            <div class="upload-box" onclick="document.getElementById('birth_cert').click()">
                                <input type="file" id="birth_cert" name="birth_cert"
                                    accept=".jpg,.jpeg,.png,.pdf" style="display:none"
                                    onchange="mojShowFileName(this)">
                                <div class="mb-1"><i class="bi bi-file-earmark-text fs-3 text-brand"></i></div>
                                <strong class="small">تصویر شناسنامه <span class="text-danger">*</span></strong>
                                <p class="text-muted small mb-0" id="file_birth_cert">فایلی انتخاب نشده</p>
                            </div>
                            <?php if (!empty($errors['birth_cert'])): ?>
                                <div class="invalid-feedback d-block small">
                                    <?= htmlspecialchars(implode(' / ', $errors['birth_cert']), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <div class="upload-box" onclick="document.getElementById('ownership_doc').click()">
                                <input type="file" id="ownership_doc" name="ownership_doc"
                                    accept=".jpg,.jpeg,.png,.pdf" style="display:none"
                                    onchange="mojShowFileName(this)">
                                <div class="mb-1"><i class="bi bi-house-door fs-3 text-brand"></i></div>
                                <strong class="small">
                                    <?= htmlspecialchars($ownershipLabel, ENT_QUOTES, 'UTF-8') ?>
                                    <span class="text-danger">*</span>
                                </strong>
                                <p class="text-muted small mb-0" id="file_ownership_doc">فایلی انتخاب نشده</p>
                            </div>
                            <?php if (!empty($errors['ownership_doc'])): ?>
                                <div class="invalid-feedback d-block small">
                                    <?= htmlspecialchars(implode(' / ', $errors['ownership_doc']), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ===== مدارک تکمیلی (مشروط به تنظیم مدیر) ===== -->
                    <?php if ($on('vocational_cert') || $on('education_degree')): ?>
                    <hr class="my-4">
                    <h2 class="h6 mb-3"><i class="bi bi-patch-plus"></i> مدارک تکمیلی (اختیاری)</h2>
                    <div class="row g-3 mb-3">
                        <?php if ($on('vocational_cert')): ?>
                        <div class="col-md-6">
                            <div class="upload-box" onclick="document.getElementById('vocational_cert').click()">
                                <input type="file" id="vocational_cert" name="vocational_cert"
                                    accept=".jpg,.jpeg,.png,.pdf" style="display:none"
                                    onchange="mojShowFileName(this)">
                                <div class="mb-1"><i class="bi bi-award fs-3 text-brand"></i></div>
                                <strong class="small">گواهینامهٔ فنی‌وحرفه‌ای</strong>
                                <p class="text-muted small mb-0" id="file_vocational_cert">فایلی انتخاب نشده</p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($on('education_degree')): ?>
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
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- ===== تصاویر محصولات (مشروط) ===== -->
                    <?php if ($on('product_photos')): ?>
                    <hr class="my-4">
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
                    <?php endif; ?>

                    <!-- ===== مدارک اختیاری (مشروط — حداکثر ۳ فایل) ===== -->
                    <?php if ($on('optional_1') || $on('optional_2') || $on('optional_3')): ?>
                    <hr class="my-4">
                    <h2 class="h6 mb-3"><i class="bi bi-paperclip"></i> سایر مدارک (حداکثر ۳ فایل)</h2>
                    <div class="row g-3 mb-3">
                        <?php foreach ([1, 2, 3] as $i):
                            if (!$on("optional_{$i}")) continue; ?>
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
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- ===== بازبینی اطلاعات ===== -->
                    <hr class="my-4">
                    <h2 class="h6 mb-3"><i class="bi bi-clipboard-check"></i> بازبینی اطلاعات</h2>
                    <dl class="row mb-0 small">
                        <?php foreach ($summary as $k => $v):
                            $fa = match ($k) {
                                'first_name'           => 'نام',
                                'last_name'            => 'نام خانوادگی',
                                'national_code'        => 'کد ملی',
                                'father_name'          => 'نام پدر',
                                'mobile'               => 'موبایل',
                                'province'             => 'استان',
                                'township'             => 'شهرستان',
                                'postal_code'          => 'کد پستی',
                                'address'              => 'نشانی',
                                'license_title'        => 'عنوان مجوز',
                                'business_description'=> 'توضیح فعالیت',
                                default                => htmlspecialchars($k, ENT_QUOTES, 'UTF-8'),
                            };
                        ?>
                            <dt class="col-sm-4 text-muted"><?= $fa ?></dt>
                            <dd class="col-sm-8"><?= htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') ?></dd>
                        <?php endforeach; ?>
                    </dl>

                    <div class="form-check mb-3 mt-3">
                        <input type="checkbox" class="form-check-input" id="consent" name="consent" value="1" required>
                        <label class="form-check-label" for="consent">
                            صحت اطلاعات را تأیید می‌کنم و با اشتراک‌گذاری داده‌ها با درگاه ملی موافقم.
                        </label>
                    </div>

                    <div class="mt-4 d-flex justify-content-between">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/<?= max(1, (int)$stepPosition - 1) ?>"
                            class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-return-right"></i> بازگشت
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check2-circle"></i> تأیید و نهایی‌سازی
                        </button>
                    </div>
                </form>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
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
    document.querySelectorAll('[id^="file_"]').forEach(function (el) {
        el.dataset.default = el.textContent.trim();
    });
});
</script>
