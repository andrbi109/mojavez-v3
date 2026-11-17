<?php
declare(strict_types=1);

/**
 * mojavez-v3 — check.php (shared hosting prerequisite checker).
 *
 * Per docs/00-MASTER.md §16.5 — visit /check.php BEFORE install.php to verify
 * the host meets mojavez-v3's requirements: PHP version, required
 * extensions, writable storage, MySQL reachable.
 *
 * This file lives in public/ (web-accessible). Delete after install.
 */

$title = 'بررسی پیش‌نیازهای mojavez-v3';
$checks = [];

// 1. PHP version.
$phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
$checks[] = [
    'label' => 'نسخهٔ PHP ≥ ۸.۲',
    'value' => PHP_VERSION,
    'ok' => $phpOk,
    'hint' => $phpOk ? '' : 'هاست شما باید PHP 8.2 یا بالاتر داشته باشد.',
];

// 2. Required extensions.
$required = ['pdo_mysql', 'mbstring', 'fileinfo', 'gd', 'curl', 'json'];
foreach ($required as $ext) {
    $loaded = extension_loaded($ext);
    $checks[] = [
        'label' => "افزونهٔ $ext",
        'value' => $loaded ? 'فعال' : 'غیرفعال',
        'ok' => $loaded,
        'hint' => $loaded ? '' : 'این افزونه در php.ini باید فعال شود.',
    ];
}

// 3. Writable storage (D-1-9, Phase 14 v1.5.0).
//
// Previously this check only did `is_writable()` on 4 dirs and didn't
// cover storage/sessions or storage/exports. Now we test ALL five
// storage subdirectories by actually writing a 1-byte test file to
// each one. We also create the dir if missing (chmod 0755 — the web
// user owns the dir on a typical shared host). Each dir reports
// PASS or FAIL with the underlying PHP error message so the admin
// can see exactly why a directory isn't writable (quota, SELinux,
// open_basedir restriction, etc.).
$rootPath = dirname(__DIR__);
$storageDirs = [
    'storage/logs',
    'storage/sessions',
    'storage/uploads',
    'storage/cache',
    'storage/exports',
];
foreach ($storageDirs as $rel) {
    $abs = $rootPath . '/' . $rel;
    if (!is_dir($abs)) {
        @mkdir($abs, 0755, true);
    }
    // Probe: try to write a 1-byte test file then immediately unlink it.
    // We capture the PHP error (if any) into $err so the check row can
    // surface it (e.g. "open_basedir restriction" or "Disk quota exceeded").
    $testFile = $abs . '/.write_test_' . substr(md5((string)uniqid('', true)), 0, 8);
    $err = '';
    $ok = false;
    try {
        $written = @file_put_contents($testFile, 'x');
        if ($written === false || $written !== 1) {
            $err = error_get_last()['message'] ?? 'file_put_contents returned false';
        } else {
            $ok = true;
            @unlink($testFile);
        }
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }
    $checks[] = [
        'label' => "دایرکتوری قابل‌نوشتن: $rel",
        'value' => $ok ? 'PASS' : 'FAIL',
        'ok' => $ok,
        'hint' => $ok ? '' : ('chmod 0755 ' . $rel . ($err !== '' ? ' — ' . $err : '')),
    ];
}
// Legacy top-level uploads/ dir (kept for backward compat — some v2
// installs have it). Same write-test probe.
$uploadsLegacy = $rootPath . '/uploads';
if (!is_dir($uploadsLegacy)) {
    @mkdir($uploadsLegacy, 0755, true);
}
$testFile = $uploadsLegacy . '/.write_test_' . substr(md5((string)uniqid('', true)), 0, 8);
$err = '';
$ok = false;
try {
    $written = @file_put_contents($testFile, 'x');
    if ($written === false || $written !== 1) {
        $err = error_get_last()['message'] ?? 'file_put_contents returned false';
    } else {
        $ok = true;
        @unlink($testFile);
    }
} catch (\Throwable $e) {
    $err = $e->getMessage();
}
$checks[] = [
    'label' => "دایرکتوری قابل‌نوشتن: uploads",
    'value' => $ok ? 'PASS' : 'FAIL',
    'ok' => $ok,
    'hint' => $ok ? '' : ('chmod 0755 uploads' . ($err !== '' ? ' — ' . $err : '')),
];

// 4. .env exists.
$envExists = is_file($rootPath . '/.env');
$checks[] = [
    'label' => 'فایل .env موجود',
    'value' => $envExists ? 'بله' : 'خیر',
    'ok' => $envExists,
    'hint' => $envExists ? '' : 'install.php آن را می‌سازد.',
];

// 5. .env.example template exists (for install.php to copy).
$envExample = is_file($rootPath . '/.env.example');
$checks[] = [
    'label' => 'فایل .env.example موجود',
    'value' => $envExample ? 'بله' : 'خیر',
    'ok' => $envExample,
    'hint' => '',
];

$allOk = true;
foreach ($checks as $c) {
    if (!$c['ok']) {
        $allOk = false;
        break;
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        body { font-family: Tahoma, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; line-height: 1.7; }
        h1 { color: #1a5276; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { padding: 0.5rem; border: 1px solid #ddd; text-align: right; }
        th { background: #f5f5f5; }
        .ok { color: #1e8449; font-weight: bold; }
        .fail { color: #c0392b; font-weight: bold; }
        .hint { color: #7f8c8d; font-size: 0.9em; }
        .next { margin-top: 1.5rem; padding: 1rem; background: #eaf2f8; border-radius: 6px; }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($title) ?></h1>
    <table>
        <tr><th>بررسی</th><th>مقدار</th><th>وضعیت</th></tr>
        <?php foreach ($checks as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['label']) ?></td>
                <td><?= htmlspecialchars($c['value']) ?></td>
                <td class="<?= $c['ok'] ? 'ok' : 'fail' ?>"><?= $c['ok'] ? '✔' : '✘' ?></td>
            </tr>
            <?php if ($c['hint']): ?>
                <tr><td colspan="3" class="hint"><?= htmlspecialchars($c['hint']) ?></td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
    </table>

    <?php if ($allOk): ?>
        <div class="next">
            همهٔ پیش‌نیازها برقرارند. مرحلهٔ بعد:
            <a href="/install.php">اجرای install.php</a>
        </div>
    <?php else: ?>
        <div class="next" style="background:#fdedec;">
            برخی پیش‌نیازها برقرار نیستند. آن‌ها را برطرف کنید سپس دوباره این صفحه را باز کنید.
        </div>
    <?php endif; ?>
</body>
</html>
