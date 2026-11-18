<?php
/** @var array $settings  key→value map */
/** @var array $known     KNOWN_SETTINGS metadata (key→[label,hint,type,options?]) */
/** @var bool $saved */
/** @var array<string,string> $errors  custom-key validation errors (Phase 14) */
/** @var string $csrfField */
/** @var string $baseUrl */
$baseUrl ??= '/';
$errors ??= [];
$siteLogoPath = $settings['site_logo'] ?? '';

/**
 * Phase 43 (v2.0.9) — single field renderer for known settings.
 *
 * Reads the per-key metadata ($known[$key]) and renders the correct
 * control based on `type`:
 *   - 'select'   → <select> with $meta['options'] as <option>s.
 *   - 'textarea' → <textarea> (multi-line, used by about_content +
 *                  success_message_template).
 *   - 'image'    → preview of the currently-stored path (if file exists)
 *                  + link to the upload form (top of the page) with the
 *                  key pre-filled + a plain text input for the raw path.
 *   - 'text' (default) → <input type="text">.
 *
 * All output goes through htmlspecialchars(). Persian UI text, RTL.
 */
$renderField = static function (string $key) use ($known, $settings, $baseUrl): void {
    $meta    = $known[$key] ?? null;
    $current = (string)($settings[$key] ?? '');
    if (!is_array($meta)) {
        return;
    }
    $label = htmlspecialchars($meta['label'] ?? $key, ENT_QUOTES, 'UTF-8');
    $hint  = htmlspecialchars($meta['hint'] ?? '',     ENT_QUOTES, 'UTF-8');
    $type  = (string)($meta['type'] ?? 'text');
    $id    = 'setting-' . htmlspecialchars($key, ENT_QUOTES);
    ?>
    <div class="mb-3">
        <label class="form-label" for="<?= $id ?>"><?= $label ?></label>
        <?php if ($type === 'select' && isset($meta['options']) && is_array($meta['options'])): ?>
            <select class="form-select" id="<?= $id ?>" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>">
                <?php foreach ($meta['options'] as $val => $optLabel): ?>
                    <option value="<?= htmlspecialchars((string)$val, ENT_QUOTES) ?>" <?= $current === (string)$val ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$optLabel, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($type === 'textarea'): ?>
            <textarea class="form-control" id="<?= $id ?>" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                      rows="6" dir="rtl"><?= htmlspecialchars($current, ENT_QUOTES, 'UTF-8') ?></textarea>
        <?php elseif ($type === 'image'): ?>
            <?php if ($current !== '' && is_file(ROOT_PATH . '/' . $current)): ?>
                <div class="mb-2 d-flex align-items-center gap-3">
                    <img src="<?= htmlspecialchars($baseUrl . $current, ENT_QUOTES) ?>" alt=""
                         class="img-thumbnail"
                         style="max-height: 80px; max-width: 240px;">
                    <a class="btn btn-sm btn-outline-secondary"
                       href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/settings#upload-image-form">
                        <i class="bi bi-cloud-upload"></i> تغییر تصویر
                    </a>
                </div>
            <?php else: ?>
                <div class="small text-muted mb-2">
                    <i class="bi bi-info-circle"></i> هنوز تصویری آپلود نشده. از فرم آپلود بالا با کلید
                    <code dir="ltr"><?= htmlspecialchars($key, ENT_QUOTES) ?></code> استفاده کنید.
                </div>
            <?php endif; ?>
            <input type="text" class="form-control" id="<?= $id ?>" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                   value="<?= htmlspecialchars($current, ENT_QUOTES, 'UTF-8') ?>" dir="ltr">
        <?php else: ?>
            <input type="text" class="form-control" id="<?= $id ?>" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                   value="<?= htmlspecialchars($current, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <?php if ($hint !== ''): ?>
            <div class="form-text"><?= $hint ?></div>
        <?php endif; ?>
    </div>
    <?php
};
?>
<h2 class="h5 mb-3"><i class="bi bi-gear"></i> تنظیمات سامانه</h2>

<?php if (!empty($saved)): ?>
<div class="alert alert-success"><i class="bi bi-check-circle"></i> تنظیمات با موفقیت ذخیره شد.</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    برخی کلیدهای سفارشی ذخیره نشدند:
    <ul class="mb-0">
        <?php foreach ($errors as $badKey => $msg): ?>
            <li><code dir="ltr"><?= htmlspecialchars($badKey, ENT_QUOTES, 'UTF-8') ?></code> —
                <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Phase 20 (v1.9.2): image upload (site_logo + similar brand assets). -->
<!-- Phase 43 (v2.0.9): the same upload form is used for owner_image — set the
     `setting_key` input to `owner_image` to upload the owner avatar that
     appears on the about page and the admin sidebar header. -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-image"></i> تصویر سامانه (لوگو) و تصویر مدیر</div>
    <div class="card-body">
        <p class="text-muted small">
            تصویر لوگوی سرصفحه/پاصفحه یا تصویر مدیر را آپلود کنید. فقط JPEG/PNG/WEBP حداکثر ۲ مگابایت.
            مسیر فایل در تنظیمی که در فیلد «کلید تنظیم» وارد می‌کنید ذخیره می‌شود
            (پیش‌فرض: <code dir="ltr">site_logo</code>؛ برای تصویر مدیر: <code dir="ltr">owner_image</code>).
        </p>
        <?php if ($siteLogoPath !== '' && is_file(ROOT_PATH . '/' . $siteLogoPath)): ?>
            <div class="mb-3 d-flex align-items-center gap-3">
                <img src="<?= htmlspecialchars($baseUrl . $siteLogoPath, ENT_QUOTES) ?>"
                     alt="لوگوی فعلی"
                     class="img-thumbnail"
                     style="max-height: 80px; max-width: 240px;">
                <div class="small text-muted">
                    لوگوی فعلی ذخیره شده:<br>
                    <code dir="ltr"><?= htmlspecialchars($siteLogoPath, ENT_QUOTES, 'UTF-8') ?></code>
                </div>
            </div>
        <?php else: ?>
            <div class="mb-3 text-muted small"><i class="bi bi-info-circle"></i> هنوز تصویری آپلود نشده.</div>
        <?php endif; ?>
        <form id="upload-image-form" method="post"
              action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/settings/upload-image"
              enctype="multipart/form-data">
            <?= $csrfField ?>
            <div class="row g-2 align-items-end">
                <div class="col-12 col-sm-5">
                    <label class="form-label small">کلید تنظیم</label>
                    <input type="text" class="form-control form-control-sm" name="setting_key"
                           value="site_logo" dir="ltr" required
                           pattern="[a-zA-Z0-9_.\-]+">
                    <div class="form-text">پیش‌فرض: site_logo. برای تصویر مدیر: owner_image. فقط حروف انگلیسی/اعداد/_/.-</div>
                </div>
                <div class="col-12 col-sm-7">
                    <label class="form-label small">فایل تصویر</label>
                    <input type="file" class="form-control form-control-sm" name="image"
                           accept="image/jpeg,image/png,image/webp" required>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-cloud-upload"></i> آپلود تصویر
                    </button>
                    <span id="upload-image-result" class="small text-muted ms-2"></span>
                </div>
            </div>
        </form>
        <script>
        (function () {
            var form = document.getElementById('upload-image-form');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var out = document.getElementById('upload-image-result');
                out.textContent = 'در حال آپلود...';
                var fd = new FormData(form);
                fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
                    .then(function (res) {
                        if (res.ok && res.json && res.json.success) {
                            out.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> آپلود شد. مسیر: <code>' +
                                (res.json.data && res.json.data.path ? res.json.data.path : '') + '</code></span>';
                            // Reload the page after 1.2s so the new image shows in the preview.
                            setTimeout(function () { window.location.reload(); }, 1200);
                        } else {
                            var err = (res.json && res.json.error) ? res.json.error : 'خطای نامشخص';
                            out.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> خطا: ' + err + '</span>';
                        }
                    })
                    .catch(function () {
                        out.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> خطای شبکه.</span>';
                    });
            });
        })();
        </script>
    </div>
</div>

<form method="post" action="/admin/settings">
    <?= $csrfField ?>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-cloud-arrow-down"></i> همگام‌سازی درگاه ملی</div>
        <div class="card-body">
            <?php foreach (['sync_direction', 'sync_two_way_enabled'] as $key): ?>
                <?php $renderField($key); ?>
            <?php endforeach; ?>
            <div class="alert alert-info small mb-0">
                <i class="bi bi-info-circle"></i>
                مرحلهٔ ۱ (یک‌طرفه ورودی): scraper از درگاه ملی می‌خواند، فقط AutoConfirmed وضعیت را تغییر می‌دهد،
                هیچ نوشتهای به upstream ارسال نمی‌شود.
                مرحلهٔ ۲ (دوطرفه) پس از ۳ ماه تثبیت در تولید فعال شود (docs/00-MASTER.md §29).
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-ui-radios"></i> عمومی</div>
        <div class="card-body">
            <?php foreach (['site_name', 'site_description', 'default_service_type_id'] as $key): ?>
                <?php $renderField($key); ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ===== Phase 43 (v2.0.9) — دربارهٔ ما و مدیر سامانه ===== -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-info-circle"></i> صفحهٔ دربارهٔ ما و مدیر سامانه</div>
        <div class="card-body">
            <?php foreach (['about_title', 'about_content', 'owner_name', 'owner_username', 'owner_image'] as $key): ?>
                <?php $renderField($key); ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ===== Phase 43 (v2.0.9) — شبکه‌های اجتماعی ===== -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-chat-dots"></i> شبکه‌های اجتماعی</div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                آیدی هر پیام‌رسان را وارد کنید (بدون @). اگر آیدی یک پیام‌رسان خالی باشد،
                مقدار «نام کاربری شبکه‌های اجتماعی» به‌عنوان آیدی پیش‌فرض استفاده می‌شود.
            </p>
            <?php foreach (['social_eitaa', 'social_rubika', 'social_bale'] as $key): ?>
                <?php $renderField($key); ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ===== Phase 43 (v2.0.9) — قالب پیام موفقیت ===== -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-chat-left-text"></i> قالب پیام موفقیت</div>
        <div class="card-body">
            <?php $renderField('success_message_template'); ?>
        </div>
    </div>

    <!-- ===== Phase 43 (v2.0.9) — هزینه‌ها ===== -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-cash-stack"></i> هزینه‌ها</div>
        <div class="card-body">
            <?php foreach ([
                'registration_fee_amount', 'license_reissue_fee_amount',
                'fee_tariff_active', 'fee_default_amount',
                'fee_rural_auto_eligible', 'fee_modal_enabled',
            ] as $key): ?>
                <?php $renderField($key); ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Custom (unknown) settings — for power users / debugging. -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-sliders"></i> تنظیمات سفارشی</div>
        <div class="card-body">
            <p class="text-muted small mb-3">برای افزودن کلید دلخواه، ردیف جدید را پر کنید. مقادیر موجود نیز ویرایش می‌شوند.</p>
            <?php
            $customKeys = array_diff(array_keys($settings), array_keys($known));
            $i = 0;
            foreach ($customKeys as $ck):
                $cv = $settings[$ck];
            ?>
                <div class="row g-2 mb-2">
                    <div class="col-5"><input type="text" class="form-control" name="custom_keys[]" value="<?= htmlspecialchars($ck, ENT_QUOTES, 'UTF-8') ?>" dir="ltr"></div>
                    <div class="col-7"><input type="text" class="form-control" name="custom_values[]" value="<?= htmlspecialchars($cv, ENT_QUOTES, 'UTF-8') ?>" dir="ltr"></div>
                </div>
            <?php $i++; endforeach; ?>
            <!-- One empty row for adding a new custom setting. -->
            <div class="row g-2 mb-2">
                <div class="col-5"><input type="text" class="form-control" name="custom_keys[]" placeholder="کلید جدید" dir="ltr"></div>
                <div class="col-7"><input type="text" class="form-control" name="custom_values[]" placeholder="مقدار" dir="ltr"></div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> ذخیره تنظیمات</button>
</form>
