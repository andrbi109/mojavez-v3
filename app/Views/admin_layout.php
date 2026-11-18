<?php
/** @var string $content */
/** @var string $title */
/** @var string $baseUrl */
/** @var string $csrfField */
$baseUrl ??= '/';
$title ??= 'پنل مدیریت';
$adminDisplayName = $_SESSION['admin_display_name'] ?? 'مدیر';
$adminRole = $_SESSION['admin_role'] ?? 'operator';

// Phase 41 (v2.0.7) — sidebar reorganised into 7 precise categories that
// mirror v2's grouping: اصلی، درخواست‌ها، درگاه ملی، مدیریت، گزارش‌ها،
// مالی، حساب. The درگاه ملی group is gated on admin/sync_operator role
// (same as before). Many of the links point to routes that are not yet
// implemented in v3 (they will 404 until the corresponding phase lands —
// e.g. /admin/applicant-accounts is deferred to Phase 42). The sidebar
// mirrors v2's structure so the visual layout matches what v2 users
// expect, even when individual targets are not yet wired.
$canGateway = ($adminRole === 'admin' || $adminRole === 'sync_operator');
$roleLabel  = $adminRole === 'admin' ? 'مدیر کل'
            : ($adminRole === 'sync_operator' ? 'اپراتور همگام‌سازی' : 'کارشناس');

/** @param array<int,array{href:string,icon:string,label:string}> $items */
$renderLinks = function (array $items) use ($baseUrl): void {
    foreach ($items as $it) {
        ?>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) . htmlspecialchars($it['href'], ENT_QUOTES) ?>"
            class="nav-link">
            <i class="bi <?= htmlspecialchars($it['icon'], ENT_QUOTES) ?>"></i>
            <?= htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php
    }
};
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
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/favicon.svg">
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
                    <small class="text-muted"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></small>
                </div>
            </div>
        </div>

        <nav>
            <h6>اصلی</h6>
            <?php $renderLinks([
                ['href' => 'admin/dashboard',    'icon' => 'bi-speedometer2', 'label' => 'داشبورد'],
                ['href' => 'admin/applications', 'icon' => 'bi-folder2-open',  'label' => 'درخواست‌ها'],
                ['href' => 'admin/my-tasks',      'icon' => 'bi-list-task',     'label' => 'وظایف من'],
            ]); ?>

            <h6>درخواست‌ها</h6>
            <?php $renderLinks([
                ['href' => 'admin/register',          'icon' => 'bi-person-plus',      'label' => 'ثبت‌نام دستی'],
                ['href' => 'admin/bulk-import',        'icon' => 'bi-cloud-arrow-up',   'label' => 'ایمپورت دسته‌جمعی'],
                ['href' => 'admin/postal-requests',    'icon' => 'bi-mailbox',          'label' => 'درخواست‌های کد پستی'],
                ['href' => 'admin/license-requests',   'icon' => 'bi-award',            'label' => 'درخواست‌های مجوز'],
                ['href' => 'admin/revisions',          'icon' => 'bi-arrow-clockwise',  'label' => 'بازبینی‌ها'],
            ]); ?>

            <?php if ($canGateway): ?>
            <h6>درگاه ملی</h6>
            <?php $renderLinks([
                ['href' => 'admin/gateway',         'icon' => 'bi-cloud',            'label' => 'داشبورد درگاه'],
                ['href' => 'admin/gateway/search',  'icon' => 'bi-search',           'label' => 'جستجوی پیشرفته'],
                ['href' => 'admin/gateway/saved',    'icon' => 'bi-bookmark-star',    'label' => 'مجوزهای ذخیره‌شده'],
                ['href' => 'admin/gateway/filters', 'icon' => 'bi-funnel',           'label' => 'فیلترهای ذخیره‌شده'],
                ['href' => 'admin/gateway/batch',   'icon' => 'bi-collection',       'label' => 'جستجوی دسته‌جمعی'],
                ['href' => 'admin/sync/status',     'icon' => 'bi-cloud-arrow-down', 'label' => 'وضعیت همگام‌سازی'],
                ['href' => 'admin/sync/pending',    'icon' => 'bi-hourglass-split',  'label' => 'در انتظار بررسی'],
            ]); ?>
            <?php endif; ?>

            <h6>مدیریت</h6>
            <?php $renderLinks([
                ['href' => 'admin/users',             'icon' => 'bi-people',           'label' => 'کاربران سامانه'],
                ['href' => 'admin/applicant-accounts', 'icon' => 'bi-person-lines-fill','label' => 'حساب‌های متقاضیان'],
                ['href' => 'admin/service-types',      'icon' => 'bi-grid',            'label' => 'انواع خدمات'],
                ['href' => 'admin/wizard-steps',        'icon' => 'bi-list-ol',         'label' => 'مراحل ویزارد'],
                ['href' => 'admin/form-fields',         'icon' => 'bi-input-cursor-text','label' => 'فیلدهای فرم'],
                ['href' => 'admin/license-titles',     'icon' => 'bi-award',           'label' => 'رشته‌ها (عناوین مجوز)'],
                ['href' => 'admin/fee-tariff',          'icon' => 'bi-cash-stack',      'label' => 'نرخ‌نامه'],
                ['href' => 'admin/settings',           'icon' => 'bi-gear',             'label' => 'تنظیمات'],
                ['href' => 'admin/sms',                'icon' => 'bi-chat-dots',        'label' => 'پیامک'],
            ]); ?>

            <h6>گزارش‌ها</h6>
            <?php $renderLinks([
                ['href' => 'admin/reports/by-province', 'icon' => 'bi-geo',          'label' => 'بر اساس استان'],
                ['href' => 'admin/reports/by-city',      'icon' => 'bi-building',    'label' => 'بر اساس شهر'],
                ['href' => 'admin/reports/by-village',   'icon' => 'bi-houses',      'label' => 'بر اساس روستا'],
                ['href' => 'admin/reports/by-license',   'icon' => 'bi-award',       'label' => 'بر اساس رشته'],
                ['href' => 'admin/reports/by-date',       'icon' => 'bi-calendar3',  'label' => 'بر اساس زمان'],
                ['href' => 'admin/output/general',       'icon' => 'bi-file-bar-graph', 'label' => 'گزارش کلی'],
                ['href' => 'admin/output/grouped',       'icon' => 'bi-collection', 'label' => 'گروه‌بندی‌شده'],
                ['href' => 'admin/output/csv',           'icon' => 'bi-filetype-csv','label' => 'خروجی CSV'],
                ['href' => 'admin/output/pdf',           'icon' => 'bi-filetype-pdf','label' => 'خروجی PDF'],
            ]); ?>

            <h6>مالی</h6>
            <?php $renderLinks([
                ['href' => 'admin/payments',          'icon' => 'bi-credit-card', 'label' => 'پرداخت‌ها'],
                ['href' => 'admin/payment-channels',   'icon' => 'bi-bank',        'label' => 'کانال‌های پرداخت'],
            ]); ?>

            <h6>حساب</h6>
            <?php $renderLinks([
                ['href' => 'admin/logout', 'icon' => 'bi-box-arrow-left', 'label' => 'خروج'],
            ]); ?>
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
