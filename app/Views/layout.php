<?php
/** @var string $content */
/** @var string $siteName */
/** @var string $siteDescription */
/** @var string $title */
$siteName ??= 'mojavez-v3';
$title ??= $siteName;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <title><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
    <header>
        <nav>
            <a href="/">خانه</a>
            <a href="/tracking">پیگیری</a>
            <a href="/guide">راهنما</a>
            <a href="/about">دربارهٔ ما</a>
            <a href="/login">ورود کاربر</a>
            <a href="/admin/login">ورود مدیر</a>
        </nav>
    </header>
    <main>
        <?= $content ?>
    </main>
    <footer>
        <p><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($siteDescription ?? '', ENT_QUOTES, 'UTF-8') ?></p>
    </footer>
</body>
</html>
