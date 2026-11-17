<?php
/** @var string $content */
/** @var string $siteName */
/** @var string $siteDescription */
/** @var string $title */
/** @var string $csrfField */
$siteName ??= 'سامانهٔ مجوز';
$siteDescription ??= 'صدور مجوز کسب‌وکار خانگی';
$title ??= $siteName;
$baseUrl ??= '/';
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
        <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($siteDescription, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="text-muted">نسخهٔ <?= htmlspecialchars(\App\Shared\Support\Environment::version(), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </footer>

    <script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/vendor/bootstrap/js/bootstrap.bundle.min.js" defer></script>
    <script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>assets/js/app.js" defer></script>
</body>
</html>
