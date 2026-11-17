<?php
/** @var string $content */
/** @var string $title */
/** @var string $baseUrl */
/** @var string $csrfField */
$baseUrl ??= '/';
$title ??= 'پنل مدیریت';
$adminDisplayName = $_SESSION['admin_display_name'] ?? 'مدیر';
$adminRole = $_SESSION['admin_role'] ?? 'operator';
?>
<!doctype html>
<html lang="fa" dir="rtl" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="theme-color" content="#0f766e">
    <title><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?> — پنل مدیریت</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($this->csrf->token(), ENT_QUOTES, 'UTF-8') ?>">

    <!-- Self-hosted vendor CSS (CSP default-src 'self', no external CDN) -->
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/vazirmatn/css/vazirmatn.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/css/app.css">
</head>
<body>
<div class="moj-admin-body">
    <aside class="moj-admin-sidebar">
        <div class="d-flex align-items-center justify-content-between mb-3 px-2">
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/dashboard" class="text-decoration-none text-brand fw-bold fs-5 d-flex align-items-center gap-2">
                <i class="bi bi-patch-check-fill"></i>
                <span>پنل مدیریت</span>
            </a>
        </div>
        <div class="px-2 mb-3">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-person-circle fs-4 text-muted"></i>
                <div>
                    <div class="fw-medium text-body"><?= htmlspecialchars((string)$adminDisplayName, ENT_QUOTES, 'UTF-8') ?></div>
                    <small class="text-muted">
                        <?= $adminRole === 'admin' ? 'مدیر کل' : ($adminRole === 'sync_operator' ? 'اپراتور همگام‌سازی' : 'کارشناس') ?>
                    </small>
                </div>
            </div>
        </div>

        <nav>
            <h6>اصلی</h6>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/dashboard" class="nav-link">
                <i class="bi bi-speedometer2"></i> داشبورد
            </a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applications" class="nav-link">
                <i class="bi bi-folder2-open"></i> درخواست‌ها
            </a>

            <h6>مدیریت</h6>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/users" class="nav-link">
                <i class="bi bi-people"></i> کاربران
            </a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/form-fields" class="nav-link">
                <i class="bi bi-input-cursor-text"></i> فیلدهای فرم
            </a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff" class="nav-link">
                <i class="bi bi-cash-stack"></i> تعرفهٔ هزینه
            </a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/license-titles" class="nav-link">
                <i class="bi bi-award"></i> عناوین مجوز
            </a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/settings" class="nav-link">
                <i class="bi bi-gear"></i> تنظیمات
            </a>

            <?php if ($adminRole === 'admin' || $adminRole === 'sync_operator'): ?>
            <h6>درگاه ملی</h6>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/status" class="nav-link">
                <i class="bi bi-cloud-arrow-down"></i> وضعیت همگام‌سازی
            </a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/pending" class="nav-link">
                <i class="bi bi-hourglass-split"></i> در انتظار بررسی
            </a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/readiness" class="nav-link">
                <i class="bi bi-clipboard2-check"></i> آمادگی مرحلهٔ ۲
            </a>
            <?php endif; ?>

            <h6>حساب</h6>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/logout" class="nav-link">
                <i class="bi bi-box-arrow-left"></i> خروج
            </a>
        </nav>

        <div class="mt-3 px-2">
            <button type="button" class="moj-theme-toggle" aria-label="تغییر تم روز/شب" title="تغییر تم روز/شب">
                <i class="bi bi-moon-stars"></i>
                <i class="bi bi-sun"></i>
            </button>
        </div>
    </aside>

    <main class="moj-admin-main">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0"><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?></h1>
            <span class="text-muted small">نسخهٔ <?= htmlspecialchars(\App\Shared\Support\Environment::version(), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?= $content ?>
    </main>
</div>

<script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/js/bootstrap.bundle.min.js" defer></script>
<script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/js/app.js" defer></script>
</body>
</html>
