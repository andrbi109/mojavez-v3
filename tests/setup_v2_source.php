<?php
declare(strict_types=1);

/**
 * Test setup: creates the v2_src_* source tables in the mojavez_v3 database
 * (prefixed copy of the v2 schema) so MigrateFromV2Test can run.
 *
 * Run once before tests/run.php:
 *   php tests/setup_v2_source.php
 *
 * Idempotent: DROP TABLE IF EXISTS before CREATE.
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
    $file = ROOT_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$env = App\Shared\Support\Environment::load(ROOT_PATH . '/.env');
$logger = new App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/setup-v2.log', 'debug');
$container = new App\Container($env, $logger);
$container->boot(ROOT_PATH);
$conn = $container->make(App\Shared\Persistence\Connection::class);

// Load the v2 schema, strip CREATE DATABASE/USE, prefix tables with v2_src_.
$v2SchemaPath = '/home/z/my-project/src_repos/Mojavez-v2/database/schema.sql';
if (!is_file($v2SchemaPath)) {
    fwrite(STDERR, "v2 schema not found at $v2SchemaPath — skipping setup\n");
    exit(0);
}

$raw = file_get_contents($v2SchemaPath);
// Strip CREATE DATABASE ... ; and USE ...; statements.
$cleaned = preg_replace('/CREATE\s+DATABASE\s+[^;]+;\s*/is', '', $raw);
$cleaned = preg_replace('/^USE\s+[^;]+;\s*$/m', '', $cleaned);
// Prefix all CREATE TABLE statements.
$prefixed = preg_replace('/CREATE TABLE IF NOT EXISTS\s+/i', 'CREATE TABLE IF NOT EXISTS v2_src_', $cleaned);

// Drop existing v2_src_ tables first (in reverse order to avoid FK issues).
$conn->execute('SET FOREIGN_KEY_CHECKS = 0');
$tables = $conn->all("SHOW TABLES LIKE 'v2_src_%'");
foreach ($tables as $t) {
    $name = (string)reset($t);
    $conn->execute("DROP TABLE IF EXISTS `$name`");
}
$conn->execute('SET FOREIGN_KEY_CHECKS = 1');

// Execute the prefixed schema. Split on ";\n" to run statements individually
// (some hosts limit multi-statement execution).
$statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $prefixed)));
$created = 0;
$pdo = $conn->pdo();
foreach ($statements as $stmt) {
    if ($stmt === '' || str_starts_with($stmt, '--')) {
        continue;
    }
    try {
        $pdo->exec($stmt);
        if (preg_match('/CREATE TABLE/i', $stmt)) {
            $created++;
        }
    } catch (\Throwable $e) {
        // Ignore "already exists" errors; log others.
        if (!str_contains($e->getMessage(), 'already exists')) {
            fwrite(STDERR, "  WARN: " . substr($e->getMessage(), 0, 120) . "\n");
        }
    }
}
$conn->execute('SET FOREIGN_KEY_CHECKS = 1');

$count = (int)$conn->scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE 'v2_src_%'", [$env->dbName]);
echo "Created $count v2_src_* tables in database {$env->dbName}.\n";
echo "MigrateFromV2Test can now run.\n";
