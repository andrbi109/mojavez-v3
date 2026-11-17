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
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/vazirmatn/css/vazirmatn.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/css/app.css">
    <style>
        .moj-user-body { min-height: 100vh; background: #f4f6f9; }
        .moj-user-sidebar {
            background: linear-gradient(180deg, #0f2942 0%, #101e2e 100%);
            width: 260px; max-width: 100%; min-height: 100vh;
            color: #b9c4cf; position: fixed; right: 0; top: 0; bottom: 0;
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
            .moj-user-sidebar { position: static; min-height: auto; width: 100%; }
            .moj-user-main { margin-inline-start: 0; }
        }
        .moj-user-topbar {
            background: #fff; border-bottom: 1px solid #e9ecef;
            padding: .75rem 1.25rem; position: sticky; top: 0; z-index: 100;
        }
        .moj-user-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: linear-gradient(135deg, #14b8a6, #0d9488);
            color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center;
        }
    </style>
</head>
<body>
<div class="moj-user-body d-flex flex-column flex-lg-row">
    <aside class="moj-user-sidebar">
        <div class="p-3 d-flex align-items-center gap-2 border-bottom border-secondary border-opacity-25">
            <i class="bi bi-patch-check-fill text-brand fs-4 brand"></i>
            <span class="brand fs-5">پنل کاربری</span>
        </div>
        <div class="px-3 py-3 border-bottom border-secondary border-opacity-25">
            <div class="d-flex align-items-center gap-2">
                <div class="moj-user-avatar"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="small">
                    <div class="text-white fw-semibold"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-secondary small"><?= htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
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
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>" class="btn btn-sm btn-outline-light w-100">
                <i class="bi bi-house"></i> بازگشت به سایت
            </a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>logout" class="btn btn-sm btn-outline-danger w-100 mt-2">
                <i class="bi bi-box-arrow-right"></i> خروج
            </a>
        </div>
    </aside>

    <main class="moj-user-main flex-grow-1">
        <div class="moj-user-topbar d-flex align-items-center justify-content-between">
            <h1 class="h4 mb-0"><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="d-flex align-items-center gap-3">
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/notifications"
                   class="position-relative text-decoration-none text-muted fs-5">
                    <i class="bi bi-bell"></i>
                    <?php if ($unreadCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.65rem">
                            <?= (int)$unreadCount ?>
                        </span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        <div class="p-4">
            <?= $content ?>
        </div>
    </main>
</div>
</body>
</html>
