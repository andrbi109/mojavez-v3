<?php
/**
 * registration/step2.php — wizard step 2: business address / property.
 *
 * Phase 14 (v1.5.0) — pays D-7-3. Collects the applicant's business
 * address (province, township, street, alley, plaque) and postal
 * code. The property_details table stores these (1:1 with applicants).
 * The golden-link matcher reads province + township + business_address
 * from property_details for the 9-field quorum match.
 *
 * Expected $data:
 *   $provinces  array<int, array{id, title}> — for the province select.
 *   $townships  array<int, array{id, title, province_id}> — for the
 *               township select (filtered by chosen province via JS).
 *   $values     array<string,string> — previously-submitted values.
 *   $baseUrl, $csrfField.
 */
/** @var array<int, array<string,mixed>> $provinces */
/** @var array<int, array<string,mixed>> $townships */
/** @var array<string,string> $values */
/** @var string $baseUrl */
/** @var string $csrfField */

$provinces ??= [];
$townships ??= [];
$values    ??= [];
$baseUrl   ??= '';
$csrfField ??= '';
$currentStep = 2;
$totalSteps  = 4;
require __DIR__ . '/_progress.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0"><i class="bi bi-geo-alt text-brand"></i>
                    مرحلهٔ ۲ — محل کسب‌وکار
                </h1>
            </div>
            <div class="card-body p-4">
                <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/2">
                    <?= $csrfField ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="province_id" class="form-label">استان <span class="text-danger">*</span></label>
                            <select class="form-select" id="province_id" name="province_id" required>
                                <option value="">— انتخاب کنید —</option>
                                <?php foreach ($provinces as $p): ?>
                                    <option value="<?= htmlspecialchars((string)$p['id'], ENT_QUOTES) ?>"
                                        <?= ($values['province_id'] ?? '') === (string)$p['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)($p['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="township_id" class="form-label">شهرستان <span class="text-danger">*</span></label>
                            <select class="form-select" id="township_id" name="township_id" required>
                                <option value="">— انتخاب کنید —</option>
                                <?php foreach ($townships as $t): ?>
                                    <option value="<?= htmlspecialchars((string)$t['id'], ENT_QUOTES) ?>"
                                        data-province-id="<?= htmlspecialchars((string)($t['province_id'] ?? ''), ENT_QUOTES) ?>"
                                        <?= ($values['township_id'] ?? '') === (string)$t['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)($t['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="address_street" class="form-label">خیابان</label>
                            <input type="text" class="form-control" id="address_street" name="address_street"
                                value="<?= htmlspecialchars($values['address_street'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="100">
                        </div>
                        <div class="col-md-3">
                            <label for="address_alley" class="form-label">کوچه</label>
                            <input type="text" class="form-control" id="address_alley" name="address_alley"
                                value="<?= htmlspecialchars($values['address_alley'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="100">
                        </div>
                        <div class="col-md-3">
                            <label for="address_plaque" class="form-label">پلاک</label>
                            <input type="text" class="form-control" id="address_plaque" name="address_plaque"
                                value="<?= htmlspecialchars($values['address_plaque'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="20">
                        </div>
                        <div class="col-md-6">
                            <label for="postal_code" class="form-label">کد پستی <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="postal_code" name="postal_code"
                                value="<?= htmlspecialchars($values['postal_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                pattern="[0-9]{10}" maxlength="10"
                                dir="ltr" style="text-align:right;" required>
                        </div>
                    </div>

                    <div class="mt-4 d-flex justify-content-between">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/1"
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
