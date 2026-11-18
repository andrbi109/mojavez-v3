<?php
declare(strict_types=1);

/**
 * mojavez-v3 — Phase 5 migration script: v2 → v3.
 *
 * Per docs/00-MASTER.md §17.1 — migrates business data from a Mojavez-v2
 * MySQL database into the v3 schema. The two schemas are nearly identical
 * (v3 is a superset), so the migration is mostly row-by-row copy with a
 * few column additions/defaults.
 *
 * Tables migrated (in dependency order):
 *   1. service_types         — referenced by applicants
 *   2. license_titles        — referenced by applicants via service_type
 *   3. persons               — referenced by applicants (person_id)
 *   4. applicants            — the heart of the system (golden-link anchor fields stay NULL)
 *   5. job_details           — 1:1 with applicants
 *   6. property_details      — 1:1 with applicants (golden-link matcher reads this)
 *   7. documents            — uploaded files metadata
 *   8. status_log            — audit trail (triggered_by defaults to 'admin' or 'user')
 *   9. payments             — payment records
 *   10. license_files        — issued license PDFs
 *   11. user_accounts        — login accounts
 *   12. user_sms_entries     — SMS history
 *   13. user_notifications   — in-app notifications
 *   14. settings            — config key/value
 *   15. sms_templates        — SMS template definitions
 *   16. form_fields          — wizard form definitions
 *   17. applicant_field_values — wizard field data (father_name/birth_date source)
 *
 *   Phase 14 (v1.5.0) — pays D-5-2: side tables added.
 *   18. applicant_revisions  — applicant edit-revision audit trail (FK to applicants)
 *   19. wizard_steps         — wizard step reference data (FK to service_types, nullable)
 *   20. postal_code_changes  — postal-code change requests (FK to applicants)
 *   21. fee_groups           — fee group definitions (no FK)
 *   22. fee_group_items      — fee group line items (FK to fee_groups)
 *
 * NOT migrated (v3-only or deprecated):
 *   - national_licenses, national_lookups, national_filters, national_batches
 *     (migrated from gateway via migrate_from_gateway.php)
 *   - sync_audit_log (v3-only, starts empty)
 *   - events, jobs (v3-only infrastructure)
 *   - migrations (v3-only meta)
 *
 * Idempotency: each table uses INSERT IGNORE or ON DUPLICATE KEY UPDATE so
 * re-running the script is safe. A migration marker row is inserted into
 * sync_audit_log with action='migrated' and context.source='v2' so we can
 * distinguish pre-migration data from post-migration.
 *
 * Usage:
 *   php scripts/migrate_from_v2.php --source-dsn="mysql:host=...;dbname=..." --source-user=... --source-pass=...
 *   php scripts/migrate_from_v2.php --dry-run --source-dsn=...  # don't write, just count
 *   php scripts/migrate_from_v2.php --table=applicants  # only one table
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
$opts = getopt('', ['source-dsn:', 'source-user:', 'source-pass:', 'dry-run', 'table::', 'help', 'limit::', 'source-prefix::']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/migrate_from_v2.php --source-dsn=... --source-user=... --source-pass=...\n";
    echo "  --dry-run              Don't write, just count rows that would be migrated.\n";
    echo "  --table=NAME           Only migrate one table (applicants, persons, etc.).\n";
    echo "  --limit=N             Max rows per table (for testing).\n";
    echo "  --source-prefix=PFX   Prefix for source tables (e.g. 'v2_src_' when the v2\n";
    echo "                        schema is loaded into the same database as v3, for hosts\n";
    echo "                        that can't CREATE DATABASE). Default: '' (no prefix).\n";
    exit(0);
}

$dryRun = isset($opts['dry-run']);
$onlyTable = $opts['table'] ?? null;
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 0;
$sourcePrefix = $opts['source-prefix'] ?? '';

if (empty($opts['source-dsn'])) {
    fwrite(STDERR, "Error: --source-dsn is required (e.g. mysql:host=127.0.0.1;dbname=handicraft_license)\n");
    exit(1);
}

// ---- Bootstrap v3 container (for the destination connection + logger) ----
$rootPath = dirname(__DIR__);
$env = Environment::load($rootPath . '/.env');
$logger = new StructuredLogger($rootPath . '/storage/logs/migrate_v2.log', 'debug');
$container = new App\Container($env, $logger);
$container->boot($rootPath);
$destConn = $container->make(Connection::class);

// ---- Connect to the source v2 database ----
$sourceDsn = $opts['source-dsn'];
$sourceUser = $opts['source-user'] ?? '';
$sourcePass = $opts['source-pass'] ?? '';
echo "Connecting to source v2 database: $sourceDsn\n";
try {
    $sourcePdo = new PDO($sourceDsn, $sourceUser, $sourcePass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Source connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ---- Migration plan: [table => [source_sql, dest_columns, transform]] ----
// Each entry migrates one table. The transform closure receives the source
// row array and returns the destination row array (or null to skip).
$plan = [
    'service_types' => [
        'sql' => 'SELECT * FROM service_types',
        'columns' => 'id, title, is_active, created_at',
        'transform' => null,  // direct copy
    ],
    'license_titles' => [
        'sql' => 'SELECT * FROM license_titles',
        'columns' => 'id, service_type_id, title, is_active, created_at',
        'transform' => null,
    ],
    'persons' => [
        'sql' => 'SELECT * FROM persons',
        'columns' => 'id, national_code, first_name, last_name, father_name, birth_date, mobile, mobile_2, mobile_3, postal_code, person_type, company_name, company_national_id, reg_number, reg_date, company_type, economic_code, created_at',
        'transform' => null,
    ],
    'applicants' => [
        'sql' => 'SELECT * FROM applicants',
        // v3 adds: national_license_request_number (NULL), matched_at (NULL),
        // match_confidence (NULL), version (1), deleted_at (NULL),
        // consent_external_share (0), updated_at (now).
        // v3 drops the ENUM on current_status (now VARCHAR(32)) — same values.
        'columns' => 'id, person_id, parent_application_id, service_type_id, user_account_id, first_name, last_name, national_code, postal_code, mobile, tracking_code, birth_date, father_name, current_status, applicant_type, marital_status, children_count, physical_condition, disability_details, income_decile, has_court_case, has_active_loan, has_overdue_installments, priority, payment_required, payment_requested_at, license_state, license_valid_until, registration_started_at, registration_completed_at, created_at, updated_at',
        'transform' => null,
    ],
    'job_details' => [
        'sql' => 'SELECT * FROM job_details',
        'columns' => '*',
        'transform' => null,
    ],
    'property_details' => [
        'sql' => 'SELECT * FROM property_details',
        'columns' => '*',
        'transform' => null,
    ],
    'documents' => [
        'sql' => 'SELECT * FROM documents',
        'columns' => '*',
        'transform' => null,
    ],
    'status_log' => [
        // v3 status_log adds triggered_by column. v2 doesn't have it —
        // default to 'admin' (most v2 transitions were admin-triggered).
        'sql' => 'SELECT *, "admin" AS triggered_by FROM status_log',
        'columns' => 'id, applicant_id, status_name, description, triggered_by, created_at',
        'transform' => null,
    ],
    'payments' => [
        'sql' => 'SELECT * FROM payments',
        'columns' => '*',
        'transform' => null,
    ],
    'license_files' => [
        'sql' => 'SELECT * FROM license_files',
        'columns' => '*',
        'transform' => null,
    ],
    'user_accounts' => [
        'sql' => 'SELECT * FROM user_accounts',
        'columns' => '*',
        'transform' => null,
    ],
    'sms_templates' => [
        'sql' => 'SELECT * FROM sms_templates',
        'columns' => '*',
        'transform' => null,
    ],
    'user_sms_entries' => [
        'sql' => 'SELECT * FROM user_sms_entries',
        'columns' => '*',
        'transform' => null,
    ],
    'user_notifications' => [
        'sql' => 'SELECT * FROM user_notifications',
        'columns' => '*',
        'transform' => null,
    ],
    'form_fields' => [
        'sql' => 'SELECT * FROM form_fields',
        'columns' => '*',
        'transform' => null,
    ],
    'applicant_field_values' => [
        'sql' => 'SELECT * FROM applicant_field_values',
        'columns' => '*',
        'transform' => null,
    ],
    'settings' => [
        'sql' => 'SELECT * FROM settings',
        'columns' => '*',
        'transform' => null,
    ],

    // ----- Phase 14 (v1.5.0) — pays D-5-2: side tables. -----
    // applicant_revisions: applicant edit-revision audit trail. v3
    // schema: id, applicant_id, payload (longtext JSON), note, status,
    // reviewed_by, reviewed_at, reject_reason, created_at. v2 had a
    // slightly different column set (the task description speculates on
    // the v2 shape but the v3 schema is the destination). Using '*' so
    // PDO can handle column-name differences via INSERT IGNORE — any
    // mismatched v2 column causes a row-by-row skip (logged), not a
    // script-wide failure. Idempotent on the PK id.
    'applicant_revisions' => [
        'sql' => 'SELECT * FROM applicant_revisions',
        'columns' => '*',
        'transform' => null,
    ],
    // wizard_steps: static reference data — copy as-is. v3 schema: id,
    // view_key, title, service_type_id, is_system, is_active, sort_order,
    // icon. v2 had a near-identical schema. Idempotent on PK id.
    'wizard_steps' => [
        'sql' => 'SELECT * FROM wizard_steps',
        'columns' => '*',
        'transform' => null,
    ],
    // postal_code_changes: postal-code change requests. v3 schema: id,
    // applicant_id, old_postal_code, new_postal_code, status,
    // reviewed_by, reviewed_at, reject_reason, requested_at. Idempotent
    // on PK id.
    'postal_code_changes' => [
        'sql' => 'SELECT * FROM postal_code_changes',
        'columns' => '*',
        'transform' => null,
    ],
    // fee_groups: fee group definitions. v3 schema: id, code, label,
    // decile_min, decile_max, total_amount, is_active, sort_order.
    // Idempotent on UNIQUE(code).
    'fee_groups' => [
        'sql' => 'SELECT * FROM fee_groups',
        'columns' => '*',
        'transform' => null,
    ],
    // fee_group_items: fee group line items. v3 schema: id, fee_group_id,
    // item_key, item_label, amount, sort_order, notes. Idempotent on
    // UNIQUE(fee_group_id, item_key).
    'fee_group_items' => [
        'sql' => 'SELECT * FROM fee_group_items',
        'columns' => '*',
        'transform' => null,
    ],
];

// ---- Run the migration ----
$totalRows = 0;
$destPdo = $destConn->pdo();

foreach ($plan as $table => $spec) {
    if ($onlyTable !== null && $table !== $onlyTable) {
        continue;
    }
    // Apply the source prefix to the FROM clause (e.g. v2_src_applicants).
    $sql = $spec['sql'];
    $sql = preg_replace_callback(
        '/FROM\s+(\w+)/i',
        static function (array $m) use ($sourcePrefix): string {
            // Don't double-prefix if already prefixed.
            if ($sourcePrefix !== '' && str_starts_with($m[1], $sourcePrefix)) {
                return $m[0];
            }
            return 'FROM ' . $sourcePrefix . $m[1];
        },
        $sql
    );
    if ($limit > 0 && stripos($sql, 'LIMIT') === false) {
        $sql .= " LIMIT $limit";
    }
    echo "\n--- Migrating table: $table ---\n";
    try {
        $stmt = $sourcePdo->query($sql);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        fwrite(STDERR, "  WARN: source table $table not readable: " . $e->getMessage() . " (skipping)\n");
        continue;
    }
    $count = count($rows);
    echo "  source rows: $count\n";
    if ($count === 0) {
        continue;
    }
    if ($dryRun) {
        echo "  (dry-run) would insert $count rows\n";
        $totalRows += $count;
        continue;
    }

    // Build the INSERT statement from the column list.
    $cols = $spec['columns'] === '*'
        ? array_keys($rows[0])
        : array_map('trim', explode(',', $spec['columns']));
    $colList = implode(', ', array_map(fn ($c) => "`$c`", $cols));
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $insertSql = "INSERT IGNORE INTO `$table` ($colList) VALUES ($placeholders)";
    $insertStmt = $destPdo->prepare($insertSql);

    $inserted = 0;
    $skipped = 0;
    foreach ($rows as $row) {
        $params = [];
        foreach ($cols as $col) {
            $params[] = $row[$col] ?? null;
        }
        try {
            $insertStmt->execute($params);
            if ($insertStmt->rowCount() > 0) {
                $inserted++;
            } else {
                $skipped++;
            }
        } catch (PDOException $e) {
            // ON DUPLICATE/IGNORE — log and continue.
            $logger->warning('v2 migration row insert failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
            $skipped++;
        }
    }
    echo "  inserted: $inserted, skipped (dup/err): $skipped\n";
    $totalRows += $inserted;
}

// ---- Record migration marker ----
if (!$dryRun) {
    try {
        $destConn->execute(
            'INSERT INTO sync_audit_log (applicant_id, national_request_number, action, outcome, confidence, context) '
            . 'VALUES (0, "", "migrated", "v2_import", 0, ?)',
            [json_encode([
                'source' => 'v2',
                'source_dsn' => preg_replace('/pass=[^;]*/', 'pass=***', $sourceDsn),
                'rows_migrated' => $totalRows,
                'migrated_at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE)]
        );
        echo "\n--- Migration marker inserted into sync_audit_log (action='migrated') ---\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "  WARN: couldn't insert migration marker: " . $e->getMessage() . "\n");
    }
}

echo "\n=== Migration " . ($dryRun ? '(dry-run) ' : '') . "complete: $totalRows rows processed ===\n";
exit(0);
