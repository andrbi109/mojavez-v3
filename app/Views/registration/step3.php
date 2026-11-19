<?php
/**
 * registration/step3.php — wizard step 3: job / license detail.
 *
 * Phase 14 (v1.5.0) — pays D-7-3. Collects the applicant's job + license
 * detail: license_title (loaded dynamically from /api/v1/license-titles/
 * by-service/{id} — the public endpoint added in Phase 13), business
 * description, and any wizard_steps custom fields (rendered via the
 * _dynamic_fields partial).
 *
 * Expected $data:
 *   $licenseTitles  array<int, array{id, title, category}> — the
 *                   available license_titles for the chosen service
 *                   type (Phase 13 public endpoint).
 *   $fields         array<int, array{...}>  — custom wizard form fields
 *                   for this step (from the form_fields table joined
 *                   with wizard_steps).
 *   $values         array<string,string> — previously-submitted values.
 *   $baseUrl, $csrfField.
 */
/** @var array<int, array<string,mixed>> $licenseTitles */
/** @var array<int, array<string,mixed>> $fields */
/** @var array<string,string> $values */
/** @var string $baseUrl */
/** @var string $csrfField */

$licenseTitles ??= [];
$fields       ??= [];
$values       ??= [];
$baseUrl      ??= '';
$csrfField    ??= '';
$currentStep = 3;
$totalSteps  = 4;
require __DIR__ . '/_progress.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0"><i class="bi bi-briefcase text-brand"></i>
                    مرحلهٔ ۳ — مشخصات شغلی
                </h1>
            </div>
            <div class="card-body p-4">
                <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/3">
                    <?= $csrfField ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="license_title_id" class="form-label">عنوان مجوز <span class="text-danger">*</span></label>
                            <select class="form-select" id="license_title_id" name="license_title_id" required>
                                <option value="">— انتخاب کنید —</option>
                                <?php foreach ($licenseTitles as $lt): ?>
                                    <option value="<?= (int)$lt['id'] ?>"
                                        <?= ($values['license_title_id'] ?? '') === (string)$lt['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)($lt['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($lt['category'])): ?>
                                            (<?= htmlspecialchars((string)$lt['category'], ENT_QUOTES, 'UTF-8') ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="business_description" class="form-label">توضیح فعالیت</label>
                            <input type="text" class="form-control" id="business_description" name="business_description"
                                value="<?= htmlspecialchars($values['business_description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="500">
                        </div>
                    </div>

                    <hr class="my-4">
                    <h2 class="h6 mb-3 text-muted">فیلدهای پویای این مرحله</h2>
                    <?php require __DIR__ . '/_dynamic_fields.php'; ?>

                    <div class="mt-4 d-flex justify-content-between">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/2"
                            class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-return-right"></i> بازگشت
                        </a>
                        <button type="submit" class="btn btn-primary">
                            مرحلهٔ بعد <i class="bi bi-arrow-left"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
