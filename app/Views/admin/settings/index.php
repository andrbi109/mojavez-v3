<?php
/** @var array $settings  key→value map */
/** @var array $known     KNOWN_SETTINGS metadata (key→[label,hint,type,options?]) */
/** @var bool $saved */
/** @var array<string,string> $errors  custom-key validation errors (Phase 14) */
/** @var string $csrfField */
$errors ??= [];
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

<form method="post" action="/admin/settings">
    <?= $csrfField ?>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-cloud-arrow-down"></i> همگام‌سازی درگاه ملی</div>
        <div class="card-body">
            <?php foreach (['sync_direction', 'sync_two_way_enabled'] as $key): ?>
                <?php $meta = $known[$key]; $current = $settings[$key] ?? ''; ?>
                <div class="mb-3">
                    <label class="form-label"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></label>
                    <select class="form-select" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>">
                        <?php foreach ($meta['options'] as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val, ENT_QUOTES) ?>" <?= $current === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text"><?= htmlspecialchars($meta['hint'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
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
                <?php $meta = $known[$key]; $current = $settings[$key] ?? ''; ?>
                <div class="mb-3">
                    <label class="form-label"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></label>
                    <?php if ($meta['type'] === 'select'): ?>
                        <select class="form-select" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>">
                            <?php foreach ($meta['options'] as $val => $label): ?>
                                <option value="<?= htmlspecialchars($val, ENT_QUOTES) ?>" <?= $current === $val ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                               value="<?= htmlspecialchars($current, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <div class="form-text"><?= htmlspecialchars($meta['hint'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
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
