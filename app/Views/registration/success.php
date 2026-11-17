<?php
/**
 * registration/success.php — wizard success page (after final submit).
 *
 * Phase 14 (v1.5.0) — pays D-7-3. Shown after the applicant submits the
 * wizard's final step. Displays the new applicant's tracking code so
 * they can later look up their application status at /tracking.
 *
 * Expected $data:
 *   $trackingCode  string — the new applicant's tracking_code (e.g. 'HG-12345').
 *   $applicantName string — the applicant's full name (for a friendly greeting).
 *   $baseUrl       string.
 *
 * Bootstrap 5 RTL + Vazirmatn. Centered card with a big check icon.
 */
/** @var string $trackingCode */
/** @var string $applicantName */
/** @var string $baseUrl */

$trackingCode ??= '';
$applicantName ??= '';
$baseUrl      ??= '';
?>
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
        <div class="card shadow-sm text-center">
            <div class="card-body p-5">
                <div class="display-1 text-success mb-3">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <h1 class="h4 mb-2">ثبت‌نام با موفقیت انجام شد</h1>
                <p class="text-muted mb-4">
                    <?php if ($applicantName !== ''): ?>
                        <?= htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8') ?> عزیز،
                    <?php endif; ?>
                    درخواست شما در سامانه ثبت شد.
                </p>

                <div class="alert alert-info d-inline-block" role="alert">
                    <i class="bi bi-hash"></i>
                    کد رهگیری شما:
                    <strong class="fs-5" dir="ltr"><?= htmlspecialchars($trackingCode, ENT_QUOTES, 'UTF-8') ?></strong>
                </div>

                <div class="mt-4 d-flex justify-content-center gap-2">
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>tracking"
                        class="btn btn-primary">
                        <i class="bi bi-search"></i> پیگیری وضعیت
                    </a>
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>"
                        class="btn btn-outline-secondary">
                        <i class="bi bi-house"></i> بازگشت به خانه
                    </a>
                </div>

                <p class="text-muted small mt-4 mb-0">
                    کد رهگیری را نگه دارید — برای پیگیری وضعیت درخواست لازم است.
                </p>
            </div>
        </div>
    </div>
</div>
