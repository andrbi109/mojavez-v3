<?php
/**
 * registration/step1.php — wizard step 1: applicant identity.
 *
 * Phase 14 (v1.5.0) — pays D-7-3. The wizard's first step collects the
 * applicant's identity: first name, last name, national code, father
 * name, birth date. This is the core of the golden-link anchor set —
 * the masked national code + father name from the upstream national
 * gateway are matched against these.
 *
 * Expected $data:
 *   $serviceType   array{id, title}  — the chosen service type (from
 *                  the previous step's POST).
 *   $values        array<string,string> — previously-submitted values.
 *   $baseUrl       string.
 *   $csrfField     string.
 *
 * Bootstrap 5 RTL + Vazirmatn. The progress partial (`_progress.php`)
 * is included at the top.
 */
/** @var array<string,mixed> $serviceType */
/** @var array<string,string> $values */
/** @var string $baseUrl */
/** @var string $csrfField */
/** @var string $userDisplayName */

$serviceType ??= [];
$values      ??= [];
$baseUrl     ??= '';
$csrfField   ??= '';
$userDisplayName ??= '';
$currentStep = 1;
$totalSteps  = 4;
$stTitle     = (string)($serviceType['title'] ?? '');
require __DIR__ . '/_progress.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0"><i class="bi bi-person-vcard text-brand"></i>
                    مرحلهٔ ۱ — اطلاعات هویتی
                    <?php if ($stTitle !== ''): ?>
                        <span class="text-muted small">(<?= htmlspecialchars($stTitle, ENT_QUOTES, 'UTF-8') ?>)</span>
                    <?php endif; ?>
                </h1>
            </div>
            <div class="card-body p-4">
                <?php if ($userDisplayName !== '' && trim((string)($values['first_name'] ?? '') . ' ' . (string)($values['last_name'] ?? '')) === ''): ?>
                    <div class="alert alert-light border small d-flex align-items-center gap-2" role="note">
                        <i class="bi bi-person-badge text-muted"></i>
                        <span>
                            نام نمایشی حساب کاربری شما:
                            <strong><?= htmlspecialchars($userDisplayName, ENT_QUOTES, 'UTF-8') ?></strong>
                            — می‌توانید همان را در نام و نام خانوادگی وارد کنید یا نام جدیدی وارد نمایید.
                        </span>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/1">
                    <?= $csrfField ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="first_name" class="form-label">نام <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="first_name" name="first_name"
                                value="<?= htmlspecialchars($values['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="50" required>
                        </div>
                        <div class="col-md-6">
                            <label for="last_name" class="form-label">نام خانوادگی <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="last_name" name="last_name"
                                value="<?= htmlspecialchars($values['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="50" required>
                        </div>
                        <div class="col-md-6">
                            <label for="national_code" class="form-label">کد ملی <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="national_code" name="national_code"
                                value="<?= htmlspecialchars($values['national_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                pattern="[0-9]{10}" maxlength="10"
                                dir="ltr" style="text-align:right;" required>
                            <div class="form-text">۱۰ رقم، بدون فاصله.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="father_name" class="form-label">نام پدر</label>
                            <input type="text" class="form-control" id="father_name" name="father_name"
                                value="<?= htmlspecialchars($values['father_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="50">
                            <div class="form-text">برای تطبیق با درگاه ملی لازم است.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="birth_date" class="form-label">تاریخ تولد</label>
                            <input type="text" class="form-control" id="birth_date" name="birth_date"
                                value="<?= htmlspecialchars($values['birth_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                placeholder="۱۳۷۰/۰۱/۰۱"
                                dir="ltr" style="text-align:right;">
                        </div>
                        <div class="col-md-6">
                            <label for="mobile" class="form-label">شماره موبایل <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" id="mobile" name="mobile"
                                value="<?= htmlspecialchars($values['mobile'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                pattern="09\d{9}" maxlength="11"
                                dir="ltr" style="text-align:right;" required>
                        </div>
                    </div>

                    <div class="mt-4 d-flex justify-content-between">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register"
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
