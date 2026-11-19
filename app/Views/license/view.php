<?php
/**
 * license/view.php — public view of a license by 72h access token.
 *
 * Phase 19 (v1.9.1): rendered by LicenseController::viewByToken(). This
 * view has THREE rendering modes:
 *
 *   1. notApproved=true  — the request exists but the admin has not yet
 *      approved it (status != 'تأیید شد'). Shows a "request pending
 *      review" message.
 *   2. notIssued=true    — the request was approved + the token is
 *      valid, BUT no license_files row exists yet for the applicant
 *      (the admin hasn't uploaded the license image). Shows a "license
 *      not yet issued" message.
 *   3. (default)         — the controller streams the file inline (this
 *      view is NOT used in the default case — the controller returns a
 *      binary Response with Content-Disposition: inline). This view is
 *      only used for the two "not yet" cases above.
 *
 * Rendered with layout='none' (self-contained HTML — no admin sidebar,
 * no site nav; this is a public page accessible via a token link).
 *
 * Expected $data:
 *   $title         string — page title.
 *   $notIssued     bool   — license image not yet uploaded.
 *   $notApproved   bool   — request not yet approved.
 *   $applicantName string — the applicant's full name (for display).
 *   $trackingCode  string — the applicant's tracking code.
 *
 * @var string $title
 * @var bool   $notIssued
 * @var bool   $notApproved
 * @var string $applicantName
 * @var string $trackingCode
 * @var string $baseUrl
 * @var string $csrfField
 */

$title         ??= 'دسترسی به تصویر مجوز';
$notIssued     ??= false;
$notApproved   ??= false;
$applicantName ??= '';
$trackingCode  ??= '';
$baseUrl       ??= '/';
$csrfField     ??= '';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/vazirmatn/css/vazirmatn.css">
    <style>
        body {
            background: rgb(248, 249, 250);
            font-family: Vazirmatn, Tahoma, Arial, sans-serif;
        }
        .moj-license-card {
            max-width: 600px;
            margin: 4rem auto;
            padding: 2rem;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
            text-align: center;
        }
        .moj-license-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <main class="moj-license-card">
        <?php if ($notApproved): ?>
            <i class="bi bi-hourglass-split text-warning moj-license-icon"></i>
            <h1 class="h4 fw-bold mb-2">درخواست شما در انتظار بررسی است</h1>
            <p class="text-muted">
                درخواست دسترسی به تصویر مجوز برای پروندهٔ
                <code><?= htmlspecialchars($trackingCode, ENT_QUOTES, 'UTF-8') ?></code>
                ثبت شده و در انتظار تأیید مدیر سامانه است.
            </p>
            <p class="text-muted small">
                پس از تأیید، لینک جدیدی برای شما ارسال خواهد شد.
            </p>
        <?php elseif ($notIssued): ?>
            <i class="bi bi-file-earmark-image text-info moj-license-icon"></i>
            <h1 class="h4 fw-bold mb-2">تصویر مجوز هنوز صادر نشده است</h1>
            <p class="text-muted">
                دسترسی شما تأیید شده است، اما تصویر مجوز برای پروندهٔ
                <code><?= htmlspecialchars($trackingCode, ENT_QUOTES, 'UTF-8') ?></code>
                هنوز توسط مدیر بارگذاری نشده است.
            </p>
            <p class="text-muted small">
                پس از صدور و بارگذاری تصویر مجوز، این لینک به‌صورت خودکار
                تصویر را نمایش خواهد داد (تا پایان اعتبار ۷۲ ساعته لینک).
            </p>
        <?php else: ?>
            <i class="bi bi-exclamation-triangle text-danger moj-license-icon"></i>
            <h1 class="h4 fw-bold mb-2">خطا</h1>
            <p class="text-muted">دسترسی به تصویر مجوز امکان‌پذیر نیست.</p>
        <?php endif; ?>

        <hr class="my-4">
        <?php if ($applicantName !== ''): ?>
            <p class="small text-muted mb-0">
                متقاضی: <?= htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>" class="btn btn-outline-secondary btn-sm mt-3">
            <i class="bi bi-house"></i> بازگشت به صفحهٔ اصلی
        </a>
    </main>
</body>
</html>
