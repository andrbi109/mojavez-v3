<?php
declare(strict_types=1);

/**
 * mojavez-v3 — scripts/run_worker.php
 *
 * CLI worker entry point. Drains:
 *   1. The SMS queue (user_sms_entries with status='در انتظار') → SmsService::drainQueue().
 *   2. The job queue (jobs with status='pending') → Queue\Worker::run().
 *
 * Invoked by the systemd timer (deploy/linux/install-service.sh, every 60s)
 * or by the poor-man's cron (admin page visit triggers this via HTTP).
 *
 * Usage:
 *   php scripts/run_worker.php --max-jobs=5 --max-sms=50
 *
 * Exit codes: 0 = success, 1 = error.
 */

require_once __DIR__ . '/../app/Shared/Support/Environment.php';
require_once __DIR__ . '/../app/Shared/Support/StructuredLogger.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// --- Parse args ---
$opts = getopt('', ['max-jobs::', 'max-sms::', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/run_worker.php [--max-jobs=N] [--max-sms=N]\n";
    echo "  Drains the SMS queue and the job queue.\n";
    echo "  --max-jobs=N  Process at most N jobs (default 5).\n";
    echo "  --max-sms=N   Send at most N pending SMS (default 50).\n";
    exit(0);
}
$maxJobs = (int)($opts['max-jobs'] ?? 5);
$maxSms  = (int)($opts['max-sms'] ?? 50);

$rootPath = dirname(__DIR__);
try {
    $env = App\Shared\Support\Environment::load($rootPath . '/.env');
    $logger = new App\Shared\Support\StructuredLogger(
        $rootPath . '/storage/logs/worker.log',
        $env->debug ? 'debug' : 'info'
    );
    $container = new App\Container($env, $logger);
    $container->boot($rootPath);

    // --- 1. Drain SMS queue ---
    $smsService = $container->make(App\Modules\Core\Services\SmsService::class);
    $smsStats = $smsService->drainQueue($maxSms);
    echo sprintf(
        "[worker] SMS: sent=%d, dev_skipped=%d, failed=%d\n",
        $smsStats['sent'],
        $smsStats['dev_skipped'],
        $smsStats['failed']
    );

    // --- 2. Drain job queue ---
    $worker = $container->make(App\Queue\Worker::class);
    $processed = $worker->run($maxJobs, 'cron');
    echo sprintf("[worker] Jobs: processed=%d\n", $processed);

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[worker] FATAL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
