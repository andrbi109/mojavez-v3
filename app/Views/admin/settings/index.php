<?php
/**
 * admin/settings/index.php — System settings (key/value store).
 *
 * Phase 54 (v2.2.0) — converted from a long scrollable list of cards into
 * Bootstrap 5 nav-tabs + tab-content. Seven tabs group the known settings
 * by purpose so the admin doesn't have to scroll past every fee / sync
 * field just to change the site name:
 *
 *   1. عمومی            — site_name, site_description, default_service_type_id.
 *   2. درباره و مدیر    — about_title, about_content, owner_name,
 *                         owner_username, owner_image.
 *   3. شبکه‌های اجتماعی — social_eitaa, social_rubika, social_bale.
 *   4. همگام‌سازی       — sync_direction, sync_two_way_enabled.
 *   5. هزینه‌ها         — registration_fee_amount, license_reissue_fee_amount,
 *                         fee_default_amount, fee_tariff_active,
 *                         fee_rural_auto_eligible, fee_modal_enabled.
 *   6. قالب پیام        — success_message_template.
 *   7. سایر             — all custom (non-known) settings + add-new row.
 *
 * The submit button is shared (one form wraps all tabs), exactly as the
 * previous single-form design. The image-upload card stays outside the
 * tabs because it's a separate multipart/form-data POST.
 *
 * @var array $settings  key→value map
 * @var array $known     KNOWN_SETTINGS metadata (key→[label,hint,type,options?])
 * @var bool $saved
 * @var array<string,string> $errors  custom-key validation errors (Phase 14)
 * @var string $message  flash message from delete() (Phase 49)
 * @var string $csrfField
 * @var string $baseUrl
 */
$baseUrl ??= '/';
$errors ??= [];
$message ??= '';
$saved ??= false;
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

/**
 * Phase 54 (v2.2.0) — helper to render a list of known-setting keys inside
 * a tab pane. Keeps the per-tab markup short + consistent.
 */
$renderTabPaneFields = static function (array $keys) use ($renderField): void {
    foreach ($keys as $key) {
        $renderField($key);
    }
};
?>
<h2 class="h5 mb-3"><i class="bi bi-gear"></i> تنظیمات سامانه</h2>

<?php if ($message !== ''): ?>
<div class="alert alert-info"><i class="bi bi-info-circle"></i> <?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

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
        <form id="upload-image-form" class="js-settings-upload-form" method="post"
              action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/settings/upload-image"
              enctype="multipart/form-data">
            <?= $csrfField ?>
            <div class="row g-2">
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
                    <span class="js-upload-image-result small text-muted ms-2"></span>
                </div>
            </div>
        </form>
        <script>
        (function () {
            // Phase 57a — class-based AJAX uploader. Any form with class
            // `js-settings-upload-form` posts via fetch and writes the
            // result into the closest `.js-upload-image-result` span.
            // This lets the page host multiple upload forms (the generic
            // site_logo form at the top + a dedicated owner_image form
            // inside the "درباره و مدیر" tab) sharing one handler.
            var forms = document.querySelectorAll('form.js-settings-upload-form');
            if (forms.length === 0) return;
            forms.forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var out = form.querySelector('.js-upload-image-result');
                    if (!out) out = document.getElementById('upload-image-result');
                    if (out) out.textContent = 'در حال آپلود...';
                    var fd = new FormData(form);
                    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
                        .then(function (res) {
                            if (res.ok && res.json && res.json.success) {
                                var msg = '<span class="text-success"><i class="bi bi-check-circle"></i> آپلود شد. مسیر: <code>' +
                                    (res.json.data && res.json.data.path ? res.json.data.path : '') + '</code></span>';
                                if (out) out.innerHTML = msg;
                                // Reload the page after 1.2s so the new image shows in the preview.
                                setTimeout(function () { window.location.reload(); }, 1200);
                            } else {
                                var err = (res.json && res.json.error) ? res.json.error : 'خطای نامشخص';
                                if (out) out.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> خطا: ' + err + '</span>';
                            }
                        })
                        .catch(function () {
                            if (out) out.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> خطای شبکه.</span>';
                        });
                });
            });
        })();
        </script>
    </div>
</div>

<?php
/**
 * Phase 54 (v2.2.0) — Bootstrap 5 nav-tabs + tab-content.
 *
 * The shared submit button sits OUTSIDE the tab-content (still inside the
 * one form element wrapping everything) so a single click submits all
 * known settings + custom settings, regardless of which tab is currently
 * active. We keep a per-tab hash deep-link target (e.g. /admin/settings#fees)
 * so the admin can jump to a specific tab via URL fragment.
 */
$tabs = [
    'general'  => ['label' => 'عمومی',            'icon' => 'bi-ui-radios'],
    'about'    => ['label' => 'درباره و مدیر',     'icon' => 'bi-info-circle'],
    'social'   => ['label' => 'شبکه‌های اجتماعی',  'icon' => 'bi-chat-dots'],
    'sync'     => ['label' => 'همگام‌سازی',         'icon' => 'bi-cloud-arrow-down'],
    'fees'     => ['label' => 'هزینه‌ها',           'icon' => 'bi-cash-stack'],
    'template' => ['label' => 'قالب پیام',         'icon' => 'bi-chat-left-text'],
    'custom'   => ['label' => 'سایر',              'icon' => 'bi-sliders'],
];

// Resolve the active tab from the URL fragment so deep-links work. The
// Bootstrap tab JS toggles the .active class itself when the admin clicks
// a tab; the inline initial state here just defaults to the first tab.
$activeTab = 'general';
if (isset($_SERVER['REQUEST_URI'])) {
    $fragment = parse_url($_SERVER['REQUEST_URI'], PHP_URL_FRAGMENT);
    if (is_string($fragment) && isset($tabs[$fragment])) {
        $activeTab = $fragment;
    }
}
?>
<form method="post" action="/admin/settings">
    <?= $csrfField ?>

    <ul class="nav nav-tabs mb-3" id="settingsTabs" role="tablist">
        <?php foreach ($tabs as $id => $t): ?>
            <li class="nav-item" role="presentation">
                <button type="button"
                        class="nav-link<?= $id === $activeTab ? ' active' : '' ?>"
                        id="tab-<?= $id ?>"
                        data-bs-toggle="tab"
                        data-bs-target="#pane-<?= $id ?>"
                        role="tab"
                        aria-controls="pane-<?= $id ?>"
                        aria-selected="<?= $id === $activeTab ? 'true' : 'false' ?>">
                    <i class="bi <?= htmlspecialchars($t['icon'], ENT_QUOTES) ?>"></i>
                    <?= htmlspecialchars($t['label'], ENT_QUOTES, 'UTF-8') ?>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content" id="settingsTabsContent">

        <!-- ===== Tab 1 — عمومی ===== -->
        <div class="tab-pane fade<?= $activeTab === 'general' ? ' show active' : '' ?>"
             id="pane-general" role="tabpanel" aria-labelledby="tab-general">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-ui-radios"></i> عمومی</div>
                <div class="card-body">
                    <?php $renderTabPaneFields(['site_name', 'site_description', 'default_service_type_id']); ?>
                </div>
            </div>
        </div>

        <!-- ===== Tab 2 — درباره و مدیر ===== -->
        <div class="tab-pane fade<?= $activeTab === 'about' ? ' show active' : '' ?>"
             id="pane-about" role="tabpanel" aria-labelledby="tab-about">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-info-circle"></i> صفحهٔ دربارهٔ ما و مدیر سامانه</div>
                <div class="card-body">
                    <?php $renderTabPaneFields([
                        'about_title', 'about_content',
                        'owner_name', 'owner_username', 'owner_image',
                    ]); ?>

                    <!-- Phase 57a — dedicated owner_image upload form. The
                         general upload form above is shared across all
                         image settings, but the admin landing on the
                         "درباره و مدیر" tab without an existing
                         owner_image sees only the "هنوز تصویری آپلود
                         نشده" hint and has no inline upload button — they
                         would have to scroll back to the top, switch
                         the setting_key to `owner_image`, and submit.
                         This dedicated form posts directly with
                         setting_key=owner_image so the upload is a
                         single click from the tab the admin is already
                         on. Shares the class-based `.js-settings-upload-form`
                         AJAX handler defined above. -->
                    <div class="mt-3 border-top pt-3">
                        <h3 class="h6 mb-2"><i class="bi bi-person-badge"></i> آپلود تصویر مدیر</h3>
                        <p class="text-muted small mb-2">
                            تصویر مدیر در نوار کناری پنل مدیریت و در صفحهٔ «دربارهٔ ما» نمایش داده می‌شود.
                            فقط JPEG/PNG/WEBP حداکثر ۲ مگابایت.
                        </p>
                        <?php $ownerImagePath = (string)($settings['owner_image'] ?? ''); ?>
                        <?php if ($ownerImagePath !== '' && is_file(ROOT_PATH . '/' . $ownerImagePath)): ?>
                            <div class="mb-3 d-flex align-items-center gap-3">
                                <img src="<?= htmlspecialchars($baseUrl . $ownerImagePath, ENT_QUOTES) ?>"
                                     alt="تصویر فعلی مدیر"
                                     class="img-thumbnail rounded-circle"
                                     style="max-height: 80px; max-width: 80px; object-fit: cover;">
                                <div class="small text-muted">
                                    تصویر فعلی:<br>
                                    <code dir="ltr"><?= htmlspecialchars($ownerImagePath, ENT_QUOTES, 'UTF-8') ?></code>
                                </div>
                            </div>
                        <?php endif; ?>
                        <form class="js-settings-upload-form" method="post"
                              action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/settings/upload-image"
                              enctype="multipart/form-data">
                            <?= $csrfField ?>
                            <input type="hidden" name="setting_key" value="owner_image">
                            <div class="row g-2">
                                <div class="col-12 col-sm-8">
                                    <label class="form-label small">فایل تصویر مدیر</label>
                                    <input type="file" class="form-control form-control-sm" name="image"
                                           accept="image/jpeg,image/png,image/webp" required>
                                </div>
                                <div class="col-12 col-sm-4 d-flex align-items-end">
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        <i class="bi bi-cloud-upload"></i> آپلود تصویر مدیر
                                    </button>
                                </div>
                                <div class="col-12">
                                    <span class="js-upload-image-result small text-muted ms-2"></span>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== Tab 3 — شبکه‌های اجتماعی ===== -->
        <div class="tab-pane fade<?= $activeTab === 'social' ? ' show active' : '' ?>"
             id="pane-social" role="tabpanel" aria-labelledby="tab-social">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-chat-dots"></i> شبکه‌های اجتماعی</div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        آیدی هر پیام‌رسان را وارد کنید (بدون @). اگر آیدی یک پیام‌رسان خالی باشد،
                        مقدار «نام کاربری شبکه‌های اجتماعی» به‌عنوان آیدی پیش‌فرض استفاده می‌شود.
                    </p>
                    <?php $renderTabPaneFields(['social_eitaa', 'social_rubika', 'social_bale']); ?>
                </div>
            </div>
        </div>

        <!-- ===== Tab 4 — همگام‌سازی ===== -->
        <div class="tab-pane fade<?= $activeTab === 'sync' ? ' show active' : '' ?>"
             id="pane-sync" role="tabpanel" aria-labelledby="tab-sync">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-cloud-arrow-down"></i> همگام‌سازی درگاه ملی</div>
                <div class="card-body">
                    <?php $renderTabPaneFields(['sync_direction', 'sync_two_way_enabled']); ?>
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle"></i>
                        مرحلهٔ ۱ (یک‌طرفه ورودی): scraper از درگاه ملی می‌خواند، فقط AutoConfirmed وضعیت را تغییر می‌دهد،
                        هیچ نوشتهای به upstream ارسال نمی‌شود.
                        مرحلهٔ ۲ (دوطرفه) پس از ۳ ماه تثبیت در تولید فعال شود (docs/00-MASTER.md §29).
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== Tab 5 — هزینه‌ها ===== -->
        <div class="tab-pane fade<?= $activeTab === 'fees' ? ' show active' : '' ?>"
             id="pane-fees" role="tabpanel" aria-labelledby="tab-fees">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-cash-stack"></i> هزینه‌ها</div>
                <div class="card-body">
                    <?php $renderTabPaneFields([
                        'registration_fee_amount', 'license_reissue_fee_amount',
                        'fee_default_amount',
                        'fee_tariff_active',
                        'fee_rural_auto_eligible',
                        'fee_modal_enabled',
                    ]); ?>
                </div>
            </div>
        </div>

        <!-- ===== Tab 6 — قالب پیام ===== -->
        <div class="tab-pane fade<?= $activeTab === 'template' ? ' show active' : '' ?>"
             id="pane-template" role="tabpanel" aria-labelledby="tab-template">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-chat-left-text"></i> قالب پیام موفقیت</div>
                <div class="card-body">
                    <?php $renderField('success_message_template'); ?>
                </div>
            </div>
        </div>

        <!-- ===== Tab 7 — سایر (custom settings) ===== -->
        <div class="tab-pane fade<?= $activeTab === 'custom' ? ' show active' : '' ?>"
             id="pane-custom" role="tabpanel" aria-labelledby="tab-custom">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-sliders"></i> تنظیمات سفارشی</div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        برای افزودن کلید دلخواه، ردیف جدید را پر کنید. مقادیر موجود نیز ویرایش می‌شوند.
                        برای حذف دائمی یک کلید سفارشی، روی دکمهٔ سطل زباله در همان ردیف کلیک کنید
                        (تنظیمات سیستمی بالا قابل حذف نیستند).
                    </p>
                    <?php
                    $customKeys = array_diff(array_keys($settings), array_keys($known));
                    $i = 0;
                    foreach ($customKeys as $ck):
                        $cv = $settings[$ck];
                    ?>
                        <div class="row g-2 mb-2 align-items-center">
                            <div class="col-12 col-sm-4"><input type="text" class="form-control" name="custom_keys[]" value="<?= htmlspecialchars($ck, ENT_QUOTES, 'UTF-8') ?>" dir="ltr"></div>
                            <div class="col-10 col-sm-6"><input type="text" class="form-control" name="custom_values[]" value="<?= htmlspecialchars($cv, ENT_QUOTES, 'UTF-8') ?>" dir="ltr"></div>
                            <div class="col-2 col-sm-2 d-grid">
                                <button type="button" class="btn btn-sm btn-outline-danger py-1"
                                        data-delete-key="<?= htmlspecialchars($ck, ENT_QUOTES, 'UTF-8') ?>"
                                        title="حذف دائمی این تنظیم سفارشی">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php $i++; endforeach; ?>
                    <!-- One empty row for adding a new custom setting. -->
                    <div class="row g-2 mb-2 align-items-center">
                        <div class="col-12 col-sm-4"><input type="text" class="form-control" name="custom_keys[]" placeholder="کلید جدید" dir="ltr"></div>
                        <div class="col-12 col-sm-8"><input type="text" class="form-control" name="custom_values[]" placeholder="مقدار" dir="ltr"></div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.tab-content -->

    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> ذخیره تنظیمات</button>
</form>

<!-- Phase 49 (v2.1.5) — delete-custom-setting handler (separate from the main
     update form so we don't nest <form> elements, which is invalid HTML).
     The button reads the existing CSRF token from the main form above and
     POSTs via fetch to /admin/settings/delete; on success the page reloads
     and the row disappears. -->
<script>
(function () {
    var buttons = document.querySelectorAll('button[data-delete-key]');
    if (buttons.length === 0) return;
    // The main update form holds the CSRF token in <input name="_csrf">.
    var csrfInput = document.querySelector('form[action$="/admin/settings"] input[name="_csrf"]');
    var csrfToken = csrfInput ? csrfInput.value : '';
    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = btn.getAttribute('data-delete-key') || '';
            if (!key) return;
            if (!window.confirm('تنظیم سفارشی «' + key + '» برای همیشه حذف شود؟')) return;
            // Disable the button while the request is in flight.
            btn.disabled = true;
            var body = new URLSearchParams();
            body.append('setting_key', key);
            body.append('_csrf', csrfToken);
            fetch('/admin/settings/delete', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) {
                if (r.ok && r.redirected) {
                    // The controller returns a 302 to /admin/settings; follow
                    // it by reloading the page so the flash message shows.
                    window.location.reload();
                    return;
                }
                return r.text().then(function (txt) {
                    throw new Error('HTTP ' + r.status + ': ' + txt.slice(0, 200));
                });
            }).catch(function (err) {
                btn.disabled = false;
                window.alert('حذف ناموفق بود: ' + (err && err.message ? err.message : 'خطای شبکه'));
            });
        });
    });
})();
</script>
