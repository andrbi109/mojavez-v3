<?php
/**
 * user_layout.php — قالب پنل کاربری (Phase 9).
 *
 * الهام‌گرفته از v2 app/Views/user_layout.php ولی بر پایهٔ asset‌های خود-میزبانی
 * v3 (Bootstrap 5 RTL + Vazirmatn + app.css) ساخته شده — هیچ کد v2 کپی نشده.
 *
 * متغیرهای مورد انتظار:
 *   $content       string   — HTML رندرشدهٔ view
 *   $title         string   — عنوان تب
 *   $activeMenu    string   — کلید آیتم فعال در سایدبار
 *   $account       array    — ردیف user_accounts کاربر جاری
 *   $unreadCount   int      — تعداد اعلان‌های خوانده‌نشده (badge)
 *   $baseUrl       string   — پایه URL
 *   $csrfField     string   — فیلد CSRF برای فرم‌ها
 */
/** @var string $content */
/** @var string $title */
/** @var string $activeMenu */
/** @var array $account */
/** @var int $unreadCount */
/** @var string $baseUrl */
/** @var string $csrfField */

$baseUrl ??= '/';
$title ??= 'پنل کاربری';
$activeMenu ??= 'overview';
$unreadCount ??= 0;
$account ??= [];

$displayName = (string)($account['username'] ?? $account['mobile'] ?? 'کاربر');
$mobile = (string)($account['mobile'] ?? '');
$initial = mb_substr($displayName, 0, 1) ?: '؟';

$nav = [
    'overview'      => ['پیشخوان',       'bi-grid-1x2-fill',     '/panel'],
    'requests'      => ['درخواست‌های من',  'bi-folder2-open',       '/panel/requests'],
    'payments'      => ['پرداخت‌ها',      'bi-credit-card-2-front','/panel/payments'],
    'licenses'      => ['مجوزها',         'bi-patch-check',         '/panel/licenses'],
    'notifications' => ['اعلان‌ها',       'bi-bell',                '/panel/notifications'],
    'tracking'      => ['پیگیری',         'bi-search',              '/panel/tracking'],
    'profile'       => ['پروفایل',        'bi-person-circle',       '/panel/profile'],
];
?>
<!doctype html>
<html lang="fa" dir="rtl" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="theme-color" content="#0f766e">
    <title><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?> — پنل کاربری</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($this->csrf->token(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/favicon.svg">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/vazirmatn/css/vazirmatn.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/css/app.css">
    <style>
        .moj-user-body { min-height: calc(100vh - var(--moj-topbar-h)); background: #f4f6f9; }
        .moj-user-sidebar {
            background: linear-gradient(180deg, #0f2942 0%, #101e2e 100%);
            width: 260px; max-width: 100%;
            color: #b9c4cf; position: fixed; right: 0;
            top: var(--moj-topbar-h); bottom: 0;
            z-index: 1000; overflow-y: auto; display: flex; flex-direction: column;
        }
        .moj-user-sidebar .brand { color:#d9a441; font-weight:700; }
        .moj-user-sidebar .nav-link {
            color: #b9c4cf; padding: .65rem .9rem; border-radius: .5rem;
            display: flex; align-items: center; gap: .6rem; margin: .15rem .5rem;
        }
        .moj-user-sidebar .nav-link:hover { background: rgba(255,255,255,.06); color:#fff; }
        .moj-user-sidebar .nav-link.active {
            background: rgba(217,164,65,.16); color:#fff; font-weight:600;
        }
        .moj-user-sidebar .nav-link .badge { margin-inline-start: auto; }
        .moj-user-main { margin-inline-start: 260px; }
        @media (max-width: 991.98px) {
            .moj-user-sidebar { position: static; min-height: auto; width: 100%; top: auto; }
            .moj-user-main { margin-inline-start: 0; }
        }
        .moj-user-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: linear-gradient(135deg, #14b8a6, #0d9488);
            color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center;
        }
        /* Phase 44b — page-level header inside main column (replaces the
           old .moj-user-topbar that was sticky at top:0 and overlapped the
           new panel-level .moj-topbar on scroll). */
        .moj-user-pagehead {
            background: #fff; border-bottom: 1px solid #e9ecef;
            padding: .75rem 1.25rem;
        }
        [data-bs-theme="dark"] .moj-user-body { background: var(--moj-bg); }
        [data-bs-theme="dark"] .moj-user-pagehead {
            background: var(--moj-surface); border-bottom-color: var(--moj-border);
        }
    </style>
</head>
<body>
<div class="moj-topbar">
    <div class="moj-topbar-title">
        <i class="bi bi-patch-check-fill"></i>
        <span>پنل کاربری</span>
    </div>
    <div class="moj-topbar-actions">
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/notifications"
           class="position-relative text-decoration-none"
           title="اعلان‌ها" aria-label="اعلان‌ها">
            <i class="bi bi-bell fs-5"></i>
            <?php if ($unreadCount > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.65rem">
                    <?= (int)$unreadCount ?>
                </span>
            <?php endif; ?>
        </a>
        <button type="button" class="moj-theme-toggle" aria-label="تغییر تم روز/شب" title="تغییر تم روز/شب">
            <i class="bi bi-moon-stars"></i>
            <i class="bi bi-sun"></i>
        </button>
        <span class="moj-topbar-user" title="<?= htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8') ?>">
            <span class="moj-user-avatar" style="width:28px;height:28px;font-size:.85rem;">
                <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?>
            </span>
            <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
        </span>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>logout" class="moj-topbar-logout">
            <i class="bi bi-box-arrow-left"></i> خروج
        </a>
    </div>
</div>
<div class="moj-user-body d-flex flex-column flex-lg-row">
    <aside class="moj-user-sidebar">
        <nav class="nav flex-column flex-grow-1 py-2">
            <?php foreach ($nav as $key => [$label, $icon, $href]): ?>
                <a href="<?= htmlspecialchars($baseUrl . ltrim($href, '/'), ENT_QUOTES) ?>"
                   class="nav-link <?= $activeMenu === $key ? 'active' : '' ?>">
                    <i class="bi <?= htmlspecialchars($icon, ENT_QUOTES) ?>"></i>
                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($key === 'notifications' && $unreadCount > 0): ?>
                        <span class="badge bg-danger rounded-pill"><?= (int)$unreadCount ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="p-3 border-top border-secondary border-opacity-25">
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register" class="btn btn-sm btn-primary w-100">
                <i class="bi bi-plus-lg"></i> شروع ثبت‌نام
            </a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>" class="btn btn-sm btn-outline-light w-100 mt-2">
                <i class="bi bi-house"></i> بازگشت به سایت
            </a>
        </div>
    </aside>

    <main class="moj-user-main flex-grow-1">
        <div class="moj-user-pagehead d-flex align-items-center justify-content-between">
            <h1 class="h4 mb-0"><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        <div class="p-4">
            <?= $content ?>
        </div>
    </main>
</div>
<script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/js/bootstrap.bundle.min.js" defer></script>
<script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/js/app.js" defer></script>
</body>
</html>
