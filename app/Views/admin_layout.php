<?php
/** @var string $content */
/** @var string $title */
/** @var string $baseUrl */
/** @var string $csrfField */
$baseUrl ??= '/';
$title ??= 'پنل مدیریت';
$adminDisplayName = $_SESSION['admin_display_name'] ?? 'مدیر';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <title><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?> — پنل مدیریت</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($this->csrf->token(), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
    <aside class="admin-sidebar">
        <h3>پنل مدیریت</h3>
        <p>کاربر: <?= htmlspecialchars((string)$adminDisplayName, ENT_QUOTES, 'UTF-8') ?></p>
        <nav>
            <a href="/admin/dashboard">داشبورد</a>
            <a href="/admin/applications">درخواست‌ها</a>
            <a href="/admin/logout">خروج</a>
        </nav>
    </aside>
    <main class="admin-main">
        <h1><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?></h1>
        <?= $content ?>
    </main>
</body>
</html>
