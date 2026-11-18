<?php
/**
 * layout.php — public-facing site shell.
 *
 * Phase 43 (v2.0.9) — the footer now shows the manager's name (if set in
 * the `owner_name` setting) and the three Persian messenger social links
 * (eitaa / rubika / bale) when the corresponding `social_*` settings are
 * non-empty. The owner-name + social-links data is sourced from the
 * SettingRepositoryInterface (fetched lazily via the Controller's Container
 * — `$this` in a view is the Controller that called render()). When the
 * controller already injected the values into the view (HomeController::
 * index/guide/about does), the layout uses those without re-querying the
 * settings table — the `??=` pattern below.
 */
/** @var string $content */
/** @var string $siteName */
/** @var string $siteDescription */
/** @var string $title */
/** @var string $csrfField */
/** @var string $ownerName */
/** @var array<string,array{label:string,handle:string,url:string}> $socialLinks */

$siteName ??= 'سامانهٔ مجوز';
$siteDescription ??= 'صدور مجوز کسب‌وکار خانگی';
$title ??= $siteName;
$baseUrl ??= '/';
$ownerName ??= '';
$socialLinks ??= [];

// Best-effort fallback: if the controller didn't pass ownerName / socialLinks
// (e.g. tracking, services, records pages that still use this layout), pull
// them from the settings repository via the Container. Wrapped in try-catch
// so a DB hiccup never breaks the page render — the footer just falls back
// to its base state (siteName + version).
if ($ownerName === '' || $socialLinks === []) {
    try {
        $repo = $this->container->make(\App\Shared\Contracts\SettingRepositoryInterface::class);
        if ($ownerName === '') {
            $ownerName = $repo->get('owner_name', '');
        }
        if ($socialLinks === []) {
            $username = $repo->get('owner_username', '');
            $eitaa    = $repo->get('social_eitaa',  $username);
            $rubika   = $repo->get('social_rubika', $username);
            $bale     = $repo->get('social_bale',   $username);
            $socialLinks = [
                'eitaa'  => ['label' => 'ایتا',   'handle' => $eitaa,  'url' => $eitaa  !== '' ? 'https://eitaa.com/'  . $eitaa  : ''],
                'rubika' => ['label' => 'روبیکا', 'handle' => $rubika, 'url' => $rubika !== '' ? 'https://rubika.ir/' . $rubika : ''],
                'bale'   => ['label' => 'بله',   'handle' => $bale,   'url' => $bale   !== '' ? 'https://bale.ai/'   . $bale   : ''],
            ];
        }
    } catch (\Throwable) {
        // best-effort — leave $ownerName / $socialLinks at their defaults.
    }
}

// Filter out social links with no handle/url so we don't render dead anchors.
$activeSocial = [];
foreach ($socialLinks as $k => $sl) {
    if (!is_array($sl)) {
        continue;
    }
    $url = (string)($sl['url'] ?? '');
    if ($url !== '') {
        $activeSocial[$k] = $sl;
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="theme-color" content="#0f766e">
    <title><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?></title>
    <?php if (!empty($csrfField)): ?>
    <meta name="csrf-token" content="<?= htmlspecialchars($this->csrf->token(), ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>

    <!-- Self-hosted vendor CSS (CSP default-src 'self', no external CDN) -->
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/favicon.svg">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/vazirmatn/css/vazirmatn.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/css/app.css">
</head>
<body>
    <header class="moj-header">
        <nav class="navbar navbar-expand-lg container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>">
                <i class="bi bi-patch-check-fill fs-4"></i>
                <span><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mojNav" aria-controls="mojNav" aria-expanded="false" aria-label="نمایش منو">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mojNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-1">
                    <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>">خانه</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>services">خدمات</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>tracking">پیگیری</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>guide">راهنما</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>about">دربارهٔ ما</a></li>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="moj-theme-toggle" aria-label="تغییر تم روز/شب" title="تغییر تم روز/شب">
                        <i class="bi bi-moon-stars"></i>
                        <i class="bi bi-sun"></i>
                    </button>
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>login" class="btn btn-outline-light btn-sm">ورود کاربر</a>
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/login" class="btn btn-light btn-sm">ورود مدیر</a>
                </div>
            </div>
        </nav>
    </header>

    <main class="moj-main">
        <div class="container">
            <?= $content ?>
        </div>
    </main>

    <footer class="moj-footer">
        <div class="container">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>
                    <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($siteDescription, ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($ownerName !== ''): ?>
                        <span class="moj-footer-owner text-muted small ms-1">
                            · مدیریت: <?= htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </span>
                <span class="text-muted">نسخهٔ <?= htmlspecialchars(\App\Shared\Support\Environment::version(), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php if (!empty($activeSocial)): ?>
            <div class="moj-footer-social d-flex flex-wrap justify-content-center gap-3 mt-2 pt-2 border-top border-light-subtle">
                <?php foreach ($activeSocial as $k => $sl): ?>
                    <a href="<?= htmlspecialchars((string)$sl['url'], ENT_QUOTES) ?>"
                       target="_blank" rel="noopener noreferrer"
                       class="text-decoration-none small"
                       title="<?= htmlspecialchars((string)$sl['label'], ENT_QUOTES, 'UTF-8') ?>">
                        <i class="bi bi-chat-dots"></i>
                        <?= htmlspecialchars((string)$sl['label'], ENT_QUOTES, 'UTF-8') ?>
                        <span dir="ltr">@<?= htmlspecialchars((string)($sl['handle'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </footer>

    <script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/js/bootstrap.bundle.min.js" defer></script>
    <script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/js/app.js" defer></script>
</body>
</html>
