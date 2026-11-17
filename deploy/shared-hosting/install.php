<?php
declare(strict_types=1);

/**
 * mojavez-v3 — install.php (shared hosting one-time installer).
 *
 * Per docs/00-MASTER.md §16.5 — two-step web installer:
 *   Step 1 (GET):  show a form for DB host/name/user/pass + admin username.
 *   Step 2 (POST): write .env with the given values + generated secrets
 *                  (CRON_SECRET, SYNC_SECRET, GATEWAY_API_KEY, ADMIN_PASSWORD),
 *                  run migrations, create the admin user, then self-delete.
 *
 * Security:
 *   - Only runs if .env does NOT exist (idempotent — once installed, locked out).
 *   - Generates secrets with random_bytes(32) = 64 hex chars.
 *   - Self-deletes after success (the user can also delete manually).
 *   - No CSRF (one-time installer, not reachable in normal operation).
 *
 * This file lives in public/ (web-accessible). Delete after install.
 */

$rootPath = dirname(__DIR__);

// Lock-out: if .env already exists, refuse to run.
if (is_file($rootPath . '/.env')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><h1>۴۰۳</h1><p>نصب قبلاً انجام شده است. فایل .env موجود است. اگر می‌خواهید دوباره نصب کنید، ابتدا .env را پاک کنید.</p>';
    exit;
}

$step = $_SERVER['REQUEST_METHOD'] === 'POST' ? 2 : 1;
$errors = [];
$generated = [];

function gen_secret(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

if ($step === 2) {
    // --- Validate input ---
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $baseUrl = trim($_POST['base_url'] ?? '/public/');
    $adminUser = trim($_POST['admin_user'] ?? 'admin');

    if ($dbName === '' || $dbUser === '') {
        $errors[] = 'نام دیتابیس و کاربر الزامی هستند.';
    }
    if ($adminUser === '') {
        $errors[] = 'نام کاربری مدیر الزامی است.';
    }

    // --- Test DB connection ---
    if ($errors === []) {
        try {
            $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            $errors[] = 'اتصال به دیتابیس ناموفق: ' . htmlspecialchars($e->getMessage());
        }
    }

    // --- Generate secrets ---
    if ($errors === []) {
        $generated = [
            'CRON_SECRET' => gen_secret(),
            'SYNC_SECRET' => gen_secret(),
            'GATEWAY_API_KEY' => gen_secret(),
            'ADMIN_PASSWORD' => gen_secret(8),  // 16 hex chars — readable for first login
        ];
    }

    // --- Write .env ---
    if ($errors === []) {
        $envContent = "APP_ENV=production\n"
            . "BASE_URL={$baseUrl}\n\n"
            . "DB_HOST={$dbHost}\n"
            . "DB_PORT=3306\n"
            . "DB_NAME={$dbName}\n"
            . "DB_USER={$dbUser}\n"
            . "DB_PASS={$dbPass}\n\n"
            . "CRON_SECRET={$generated['CRON_SECRET']}\n"
            . "SYNC_SECRET={$generated['SYNC_SECRET']}\n"
            . "GATEWAY_API_KEY={$generated['GATEWAY_API_KEY']}\n\n"
            . "ADMIN_USERNAME={$adminUser}\n"
            . "ADMIN_PASSWORD={$generated['ADMIN_PASSWORD']}\n";
        if (file_put_contents($rootPath . '/.env', $envContent) === false) {
            $errors[] = 'نوشتن فایل .env ناموفق بود. دسترسی نوشتن را بررسی کنید.';
        }
    }

    // --- Run migrations + create admin user ---
    if ($errors === []) {
        // Bootstrap the app and run migrations.
        require_once $rootPath . '/app/Shared/Support/Environment.php';
        require_once $rootPath . '/app/Shared/Support/StructuredLogger.php';
        spl_autoload_register(static function (string $class) use ($rootPath): void {
            $prefix = 'App\\';
            if (!str_starts_with($class, $prefix)) return;
            $rel = substr($class, strlen($prefix));
            $file = $rootPath . '/app/' . str_replace('\\', '/', $rel) . '.php';
            if (is_file($file)) require_once $file;
        });

        try {
            $env = App\Shared\Support\Environment::load($rootPath . '/.env');
            $logger = new App\Shared\Support\StructuredLogger($rootPath . '/storage/logs/install.log', 'debug');
            $container = new App\Container($env, $logger);
            $container->boot($rootPath);
            $container->make(App\Shared\Persistence\MigrationRunner::class)->run();

            // Create admin user with bcrypt hash of generated password.
            $conn = $container->make(App\Shared\Persistence\Connection::class);
            $hash = password_hash($generated['ADMIN_PASSWORD'], PASSWORD_BCRYPT);
            $conn->execute(
                'INSERT INTO users (username, password, role, created_at) VALUES (?, ?, "admin", NOW())',
                [$adminUser, $hash]
            );
        } catch (\Throwable $e) {
            $errors[] = 'خطا در اجرای migration یا ساخت کاربر مدیر: ' . htmlspecialchars($e->getMessage());
        }
    }

    // --- Self-delete on success ---
    if ($errors === []) {
        @unlink(__FILE__);
        @unlink($rootPath . '/public/check.php');
    }
}

// --- Render ---
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>نصب mojavez-v3</title>
    <style>
        body { font-family: Tahoma, sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; line-height: 1.7; }
        h1 { color: #1a5276; }
        label { display: block; margin-top: 0.75rem; font-weight: bold; }
        input[type=text], input[type=password] { width: 100%; padding: 0.5rem; margin-top: 0.25rem; box-sizing: border-box; }
        button { margin-top: 1.5rem; padding: 0.75rem 2rem; background: #1a5276; color: white; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
        .error { background: #fdedec; border: 1px solid #f5b7b1; padding: 0.75rem; border-radius: 4px; margin: 1rem 0; }
        .success { background: #eafaf1; border: 1px solid #abebc6; padding: 1rem; border-radius: 4px; margin: 1rem 0; }
        .secret { font-family: monospace; background: #fef9e7; padding: 0.25rem 0.5rem; border-radius: 3px; word-break: break-all; }
        .hint { color: #7f8c8d; font-size: 0.9em; }
    </style>
</head>
<body>
    <h1>نصب mojavez-v3</h1>

    <?php if ($errors): ?>
        <div class="error">
            <strong>خطاها:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($step === 2 && $errors === []): ?>
        <div class="success">
            <h2>نصب با موفقیت انجام شد! 🎉</h2>
            <p>فایل <code>.env</code> ساخته شد، migration‌ها اجرا شدند، و کاربر مدیر ساخته شد.</p>
            <p>این فایل (<code>install.php</code>) و <code>check.php</code> به‌صورت خودکار حذف شدند.</p>
            <h3>اطلاعات ورود مدیر:</h3>
            <p>نام کاربری: <code><?= htmlspecialchars($adminUser) ?></code></p>
            <p>رمز عبور: <span class="secret"><?= htmlspecialchars($generated['ADMIN_PASSWORD']) ?></span></p>
            <p class="hint">این رمز را در جایی امن ذخیره کنید. پس از اولین ورود آن را تغییر دهید.</p>
            <p style="margin-top:1.5rem;"><a href="<?= htmlspecialchars($baseUrl) ?>">ورود به سامانه ←</a></p>
        </div>
    <?php else: ?>
        <form method="post">
            <h3>تنظیمات دیتابیس</h3>
            <label>هاست دیتابیس</label>
            <input type="text" name="db_host" value="localhost" required>
            <label>نام دیتابیس</label>
            <input type="text" name="db_name" placeholder="mojavez_v3" required>
            <label>کاربر دیتابیس</label>
            <input type="text" name="db_user" required>
            <label>رمز دیتابیس</label>
            <input type="password" name="db_pass" value="">

            <h3 style="margin-top:1.5rem;">تنظیمات سامانه</h3>
            <label>BASE_URL (مسیر نسبی public/)</label>
            <input type="text" name="base_url" value="/public/">
            <span class="hint">اگر mojavez-v3 در ریشه دامنه است، <code>/</code> بگذارید. اگر در public_html/mojavez-v3/ است، <code>/mojavez-v3/public/</code>.</span>
            <label>نام کاربری مدیر</label>
            <input type="text" name="admin_user" value="admin" required>

            <button type="submit">شروع نصب</button>
        </form>
    <?php endif; ?>
</body>
</html>
