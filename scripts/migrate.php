<?php
declare(strict_types=1);

/**
 * CLI migration runner.
 *
 * Usage:
 *   php scripts/migrate.php --all
 *   php scripts/migrate.php --status
 *
 * Loads the Container (so Environment + Connection are ready), discovers
 * modules via ModuleLoader, and invokes MigrationRunner::run().
 *
 * Phase 1: only Core has a marker migration. As phases add modules,
 * ModuleLoader discovers their migration files automatically.
 */

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/app/Shared/Support/Environment.php';
require_once ROOT_PATH . '/app/Shared/Support/StructuredLogger.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $relative = str_replace('\\', '/', $relative);
    $file = ROOT_PATH . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Container;
use App\Events\EventBus;
use App\Http\Router;
use App\Modules\ModuleLoader;
use App\Shared\Persistence\MigrationRunner;
use App\Shared\Support\Environment;
use App\Shared\Support\StructuredLogger;

$environment = Environment::load(ROOT_PATH . '/.env');
$logger = new StructuredLogger(
    ROOT_PATH . '/storage/logs/migrate-' . date('Y-m-d') . '.log',
    $environment->debug ? 'debug' : 'info'
);

$container = new Container($environment, $logger);
$container->boot(ROOT_PATH);

$router = $container->make(Router::class);
$migrationRunner = $container->make(MigrationRunner::class);

// EventBus — real class (Phase 1 placeholder, will be fleshed out in Phase 4).
$eventBus = new EventBus();

$moduleLoader = new ModuleLoader(
    $container,
    $router,
    $eventBus,
    $migrationRunner,
    $logger
);
$modulesRoot = ROOT_PATH . '/app/Modules';
$moduleLoader->discover($modulesRoot);
$moduleLoader->collectMigrations($modulesRoot);

$args = $argv;
if (in_array('--status', $args, true)) {
    $applied = $migrationRunner->applied();
    if ($applied === []) {
        echo "No migrations applied yet.\n";
    } else {
        printf("%-50s %-20s %s\n", 'filename', 'module', 'applied_at');
        foreach ($applied as $row) {
            printf("%-50s %-20s %s\n", $row['filename'], $row['module'], $row['applied_at']);
        }
    }
    exit(0);
}

if (in_array('--all', $args, true) || count($args) === 1) {
    $count = $migrationRunner->run();
    echo "Applied $count new migration(s).\n";
    exit(0);
}

fwrite(STDERR, "Usage: php scripts/migrate.php [--all|--status]\n");
exit(1);
