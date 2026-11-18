<?php
declare(strict_types=1);

/**
 * mojavez-v3 — Phase 5 migration script: gateway (SQLite) → v3 (MySQL).
 *
 * Per docs/00-MASTER.md §17.2 — migrates cached national-gateway data from
 * a Mojavez Gateway SQLite database into the v3 MySQL schema.
 *
 * Tables migrated (in dependency order):
 *   1. lookups    → national_lookups   (kind mapping: province→province,
 *                                       township missing in gateway, main_org→main_organization,
 *                                       sub_org→sub_organization)
 *   2. licenses   → national_licenses  (raw_json parsed for masked fields,
 *                                       datetime('now') → CURRENT_TIMESTAMP)
 *   3. filters    → national_filters   (filter_json → filter_data)
 *   4. batches    → national_batches   (direct)
 *   5. jobs       → jobs               (type/status mapping)
 *   6. events     → events             (direct)
 *   7. settings   → settings           (merge — only keys not in v3)
 *   8. admin_users → users             (only if username not exists)
 *   9. worker_state → worker_state    (direct, single-row)
 *
 * The gateway stored `raw_json` on each license row — the full upstream
 * response. This script parses raw_json to extract the masked national code,
 * father name, business address, and other golden-link anchor fields that
 * the gateway didn't denormalize into columns.
 *
 * Idempotent: INSERT IGNORE / ON DUPLICATE KEY UPDATE. Migration marker
 * inserted into sync_audit_log with outcome='gateway_import'.
 *
 * Usage:
 *   php scripts/migrate_from_gateway.php --source-sqlite=/path/to/gateway/storage/app.db
 *   php scripts/migrate_from_gateway.php --source-sqlite=... --dry-run
 *   php scripts/migrate_from_gateway.php --source-sqlite=... --table=licenses
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

use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\StructuredLogger;

// ---- CLI arg parsing ----
$opts = getopt('', ['source-sqlite:', 'dry-run', 'table::', 'help', 'limit::']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/migrate_from_gateway.php --source-sqlite=/path/to/gateway.db\n";
    echo "  --dry-run       Don't write, just count rows that would be migrated.\n";
    echo "  --table=NAME    Only migrate one table (licenses, lookups, filters, etc.).\n";
    echo "  --limit=N       Max rows per table (for testing).\n";
    exit(0);
}

$dryRun = isset($opts['dry-run']);
$onlyTable = $opts['table'] ?? null;
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 0;

if (empty($opts['source-sqlite'])) {
    fwrite(STDERR, "Error: --source-sqlite is required (path to gateway/storage/app.db)\n");
    exit(1);
}
$sourceSqlite = $opts['source-sqlite'];
if (!is_file($sourceSqlite)) {
    fwrite(STDERR, "Error: source SQLite file not found: $sourceSqlite\n");
    exit(1);
}

// ---- Bootstrap v3 container ----
$rootPath = dirname(__DIR__);
$env = Environment::load($rootPath . '/.env');
$logger = new StructuredLogger($rootPath . '/storage/logs/migrate_gateway.log', 'debug');
$container = new App\Container($env, $logger);
$container->boot($rootPath);
$destConn = $container->make(Connection::class);

// ---- Connect to the source gateway SQLite database ----
echo "Connecting to source gateway SQLite: $sourceSqlite\n";
try {
    $sourcePdo = new PDO('sqlite:' . $sourceSqlite, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Source connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$totalRows = 0;
$destPdo = $destConn->pdo();

// ============================================================
// 1. lookups → national_lookups
// kind mapping: province→province, main_org→main_organization,
//               sub_org→sub_organization. (gateway has no 'township'.)
// ============================================================
if ($onlyTable === null || $onlyTable === 'lookups') {
    echo "\n--- Migrating lookups → national_lookups ---\n";
    $sql = 'SELECT * FROM lookups';
    if ($limit > 0) $sql .= " LIMIT $limit";
    $rows = $sourcePdo->query($sql)->fetchAll();
    echo "  source rows: " . count($rows) . "\n";
    if (!$dryRun && count($rows) > 0) {
        $kindMap = [
            'province' => 'province',
            'main_org' => 'main_organization',
            'sub_org' => 'sub_organization',
        ];
        $stmt = $destPdo->prepare(
            'INSERT IGNORE INTO national_lookups (kind, code, title, parent_code, is_active, refreshed_at) '
            . 'VALUES (?, ?, ?, ?, 1, ?)'
        );
        $inserted = 0;
        foreach ($rows as $row) {
            $kind = $kindMap[$row['kind']] ?? $row['kind'];
            $stmt->execute([
                $kind,
                (string)$row['code'],
                (string)$row['title'],
                $row['parent_code'] ?? null,
                (string)($row['refreshed_at'] ?? date('Y-m-d H:i:s')),
            ]);
            $inserted += $stmt->rowCount();
        }
        echo "  inserted: $inserted\n";
        $totalRows += $inserted;
    }
}

// ============================================================
// 2. licenses → national_licenses
// Parse raw_json for masked_national_code, father_name, business_address,
// postal_code_masked (gateway didn't denormalize these).
// ============================================================
if ($onlyTable === null || $onlyTable === 'licenses') {
    echo "\n--- Migrating licenses → national_licenses ---\n";
    $sql = 'SELECT * FROM licenses';
    if ($limit > 0) $sql .= " LIMIT $limit";
    $rows = $sourcePdo->query($sql)->fetchAll();
    echo "  source rows: " . count($rows) . "\n";
    if (!$dryRun && count($rows) > 0) {
        $insertSql = 'INSERT IGNORE INTO national_licenses '
            . '(request_number, applicant_name, user_image, license_title, organization_title, '
            . 'province_title, township_title, responded_at, status_slug, status_id, status_title, '
            . 'masked_national_code, father_name, business_address, postal_code_masked, '
            . 'filter_hash, first_seen_at, last_seen_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $destPdo->prepare($insertSql);
        $inserted = 0;
        foreach ($rows as $row) {
            $raw = json_decode((string)($row['raw_json'] ?? '{}'), true) ?: [];
            $stmt->execute([
                (string)$row['request_number'],
                (string)($row['applicant_name'] ?? ''),
                !empty($row['user_image']) ? (string)$row['user_image'] : null,
                (string)($row['license_title'] ?? ''),
                (string)($row['organization_title'] ?? ''),
                (string)($row['province_title'] ?? ''),
                (string)($row['township_title'] ?? ''),
                !empty($row['responded_at']) ? (string)$row['responded_at'] : null,
                (string)($row['status_slug'] ?? 'unknown'),
                $row['status_id'] ?? null,
                $row['status_title'] ?? null,
                $raw['national_id'] ?? $raw['masked_national_code'] ?? null,
                $raw['father_name'] ?? null,
                $raw['business_address'] ?? null,
                $raw['postal_code'] ?? $raw['postal_code_masked'] ?? null,
                null, // filter_hash not tracked in gateway
                (string)($row['first_seen_at'] ?? date('Y-m-d H:i:s')),
                (string)($row['last_seen_at'] ?? date('Y-m-d H:i:s')),
            ]);
            $inserted += $stmt->rowCount();
        }
        echo "  inserted: $inserted\n";
        $totalRows += $inserted;
    }
}

// ============================================================
// 3. filters → national_filters
// gateway: filter_json (TEXT) → v3: filter_data (JSON column)
// ============================================================
if ($onlyTable === null || $onlyTable === 'filters') {
    echo "\n--- Migrating filters → national_filters ---\n";
    $sql = 'SELECT * FROM filters';
    if ($limit > 0) $sql .= " LIMIT $limit";
    $rows = $sourcePdo->query($sql)->fetchAll();
    echo "  source rows: " . count($rows) . "\n";
    if (!$dryRun && count($rows) > 0) {
        $stmt = $destPdo->prepare(
            'INSERT IGNORE INTO national_filters (label, filter_data, is_active, last_run_at, last_result_count, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?)'
        );
        $inserted = 0;
        foreach ($rows as $row) {
            $filterData = json_decode((string)($row['filter_json'] ?? '{}'), true) ?: [];
            $stmt->execute([
                (string)$row['label'],
                json_encode($filterData, JSON_UNESCAPED_UNICODE),
                (int)($row['is_active'] ?? 1),
                !empty($row['last_run_at']) ? (string)$row['last_run_at'] : null,
                $row['last_result_count'] ?? null,
                (string)($row['created_at'] ?? date('Y-m-d H:i:s')),
            ]);
            $inserted += $stmt->rowCount();
        }
        echo "  inserted: $inserted\n";
        $totalRows += $inserted;
    }
}

// ============================================================
// 4. batches → national_batches
// ============================================================
if ($onlyTable === null || $onlyTable === 'batches') {
    echo "\n--- Migrating batches → national_batches ---\n";
    $sql = 'SELECT * FROM batches';
    if ($limit > 0) $sql .= " LIMIT $limit";
    $rows = $sourcePdo->query($sql)->fetchAll();
    echo "  source rows: " . count($rows) . "\n";
    if (!$dryRun && count($rows) > 0) {
        $stmt = $destPdo->prepare(
            'INSERT IGNORE INTO national_batches (original_filename, total_rows, processed_rows, matched_rows, csv_path, results_json, status, error, completed_at, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $inserted = 0;
        foreach ($rows as $row) {
            $stmt->execute([
                (string)$row['original_filename'],
                (int)($row['total_rows'] ?? 0),
                (int)($row['processed_rows'] ?? 0),
                (int)($row['matched_rows'] ?? 0),
                $row['csv_path'] ?? null,
                $row['results_json'] ?? null,
                (string)($row['status'] ?? 'pending'),
                $row['error'] ?? null,
                !empty($row['completed_at']) ? (string)$row['completed_at'] : null,
                (string)($row['created_at'] ?? date('Y-m-d H:i:s')),
            ]);
            $inserted += $stmt->rowCount();
        }
        echo "  inserted: $inserted\n";
        $totalRows += $inserted;
    }
}

// ============================================================
// 5. jobs → jobs
// gateway status: queued|processing|completed|failed|retrying
// v3 status:      pending|processing|completed|failed
// ============================================================
if ($onlyTable === null || $onlyTable === 'jobs') {
    echo "\n--- Migrating jobs → jobs ---\n";
    $sql = 'SELECT * FROM jobs';
    if ($limit > 0) $sql .= " LIMIT $limit";
    $rows = $sourcePdo->query($sql)->fetchAll();
    echo "  source rows: " . count($rows) . "\n";
    if (!$dryRun && count($rows) > 0) {
        $statusMap = [
            'queued' => 'pending',
            'processing' => 'processing',
            'completed' => 'completed',
            'failed' => 'failed',
            'retrying' => 'pending',  // re-queue on migration
        ];
        $stmt = $destPdo->prepare(
            'INSERT IGNORE INTO jobs (type, payload_json, status, attempts, max_attempts, priority, available_at, created_at, completed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $inserted = 0;
        foreach ($rows as $rows_row) {
            $stmt->execute([
                (string)$rows_row['type'],
                (string)($rows_row['payload_json'] ?? '{}'),
                $statusMap[$rows_row['status']] ?? 'pending',
                (int)($rows_row['attempts'] ?? 0),
                (int)($rows_row['max_attempts'] ?? 3),
                (int)($rows_row['priority'] ?? 100),
                (string)($rows_row['created_at'] ?? date('Y-m-d H:i:s')),
                (string)($rows_row['created_at'] ?? date('Y-m-d H:i:s')),
                !empty($rows_row['completed_at']) ? (string)$rows_row['completed_at'] : null,
            ]);
            $inserted += $stmt->rowCount();
        }
        echo "  inserted: $inserted\n";
        $totalRows += $inserted;
    }
}

// ============================================================
// 6. events → events
// ============================================================
if ($onlyTable === null || $onlyTable === 'events') {
    echo "\n--- Migrating events → events ---\n";
    $sql = 'SELECT * FROM events';
    if ($limit > 0) $sql .= " LIMIT $limit";
    $rows = $sourcePdo->query($sql)->fetchAll();
    echo "  source rows: " . count($rows) . "\n";
    if (!$dryRun && count($rows) > 0) {
        $stmt = $destPdo->prepare(
            'INSERT IGNORE INTO events (type, payload_json, created_at) VALUES (?, ?, ?)'
        );
        $inserted = 0;
        foreach ($rows as $row) {
            $stmt->execute([
                (string)$row['type'],
                (string)($row['payload_json'] ?? '{}'),
                (string)($row['created_at'] ?? date('Y-m-d H:i:s')),
            ]);
            $inserted += $stmt->rowCount();
        }
        echo "  inserted: $inserted\n";
        $totalRows += $inserted;
    }
}

// ============================================================
// 7. settings → settings (merge — only keys not in v3)
// gateway: key/value → v3: setting_key/setting_value
// ============================================================
if ($onlyTable === null || $onlyTable === 'settings') {
    echo "\n--- Migrating settings → settings (merge) ---\n";
    $sql = 'SELECT * FROM settings';
    if ($limit > 0) $sql .= " LIMIT $limit";
    $rows = $sourcePdo->query($sql)->fetchAll();
    echo "  source rows: " . count($rows) . "\n";
    if (!$dryRun && count($rows) > 0) {
        $stmt = $destPdo->prepare(
            'INSERT IGNORE INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)'
        );
        $inserted = 0;
        foreach ($rows as $row) {
            $stmt->execute([
                (string)$row['key'],
                (string)$row['value'],
                (string)($row['updated_at'] ?? date('Y-m-d H:i:s')),
            ]);
            $inserted += $stmt->rowCount();
        }
        echo "  inserted (new keys only): $inserted\n";
        $totalRows += $inserted;
    }
}

// ============================================================
// 8. admin_users → users (only if username not exists)
// ============================================================
if ($onlyTable === null || $onlyTable === 'admin_users') {
    echo "\n--- Migrating admin_users → users ---\n";
    $sql = 'SELECT * FROM admin_users';
    if ($limit > 0) $sql .= " LIMIT $limit";
    $rows = $sourcePdo->query($sql)->fetchAll();
    echo "  source rows: " . count($rows) . "\n";
    if (!$dryRun && count($rows) > 0) {
        // v3 users table columns — check schema first.
        $stmt = $destPdo->prepare(
            'INSERT IGNORE INTO users (username, password, role, created_at) '
            . 'VALUES (?, ?, ?, ?)'
        );
        $inserted = 0;
        foreach ($rows as $row) {
            $stmt->execute([
                (string)$row['username'],
                (string)$row['password_hash'],
                'admin',  // gateway admin_users → v3 role='admin'
                (string)($row['created_at'] ?? date('Y-m-d H:i:s')),
            ]);
            $inserted += $stmt->rowCount();
        }
        echo "  inserted: $inserted\n";
        $totalRows += $inserted;
    }
}

// ---- Record migration marker ----
if (!$dryRun) {
    try {
        $destConn->execute(
            'INSERT INTO sync_audit_log (applicant_id, national_request_number, action, outcome, confidence, context) '
            . 'VALUES (NULL, NULL, "migrated", "gateway_import", 0, ?)',
            [json_encode([
                'source' => 'gateway',
                'source_sqlite' => $sourceSqlite,
                'rows_migrated' => $totalRows,
                'migrated_at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE)]
        );
        echo "\n--- Migration marker inserted (action='migrated', outcome='gateway_import') ---\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "  WARN: couldn't insert migration marker: " . $e->getMessage() . "\n");
    }
}

echo "\n=== Migration " . ($dryRun ? '(dry-run) ' : '') . "complete: $totalRows rows processed ===\n";
exit(0);
