<?php
declare(strict_types=1);

/**
 * mojavez-v3 — Phase 5: seed the national_lookups table.
 *
 * Per docs/00-MASTER.md §22.1 — fetches provinces, townships, and organizations
 * from the upstream national gateway via LicenseService::refreshLookups() and
 * caches them in national_lookups (24h TTL).
 *
 * This is needed because:
 *   - The gateway migration (5b) only carries province + main_org + sub_org
 *     (no townships — gateway didn't track them).
 *   - v2 migration (5a) doesn't carry any national-gateway lookups.
 *   - The upstream is the authoritative source.
 *
 * Phase 24 (v1.9.6): pays D-5-1. The script now has three modes:
 *
 *   1. Production with upstream access (no flag):
 *        `php scripts/seed_lookups.php`
 *        Calls LicenseService::refreshLookups() to fetch real data.
 *
 *   2. Sandbox with upstream unreachable (auto-detect):
 *        `php scripts/seed_lookups.php`
 *        The upstream fetch fails (DNS / timeout / geofence). The script
 *        auto-detects the failure and falls back to the built-in dev seed
 *        (scripts/seed_lookups_dev_data.php — 8 provinces + 28 townships +
 *        4 main organizations, same as migration 005).
 *
 *   3. Explicit dev-seed mode (skip upstream):
 *        `php scripts/seed_lookups.php --from-dev-seed`
 *        Skips upstream entirely and uses the built-in dev seed. Useful
 *        for offline sandboxes and CI runs that should never touch the
 *        network.
 *
 *   Legacy `--from-gateway` flag is kept for backward compatibility: it
 *   still seeds townships from the gateway-imported sub_organization data
 *   (same behaviour as before). The new `--from-dev-seed` flag is the
 *   preferred fallback because it includes provinces + organizations too.
 *
 * Usage:
 *   php scripts/seed_lookups.php
 *   php scripts/seed_lookups.php --dry-run        # just report current cache state
 *   php scripts/seed_lookups.php --from-dev-seed  # built-in 8/28/4 dev seed
 *   php scripts/seed_lookups.php --from-gateway   # legacy: townships from sub_org only
 *   php scripts/seed_lookups.php --help
 *
 * Exit codes:
 *   0 — success (upstream fetch OK, or dev-seed applied)
 *   1 — error (unrecoverable)
 *   2 — degraded mode (upstream unreachable, fell back to dev-seed or gateway)
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

$opts = getopt('', ['dry-run', 'from-gateway', 'from-dev-seed', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/seed_lookups.php\n";
    echo "  --dry-run        Just report current cache state, don't fetch.\n";
    echo "  --from-dev-seed  Use the built-in 8-province + 28-township + 4-org\n";
    echo "                   dev seed (offline, no upstream access needed).\n";
    echo "  --from-gateway   Legacy: build townships from gateway-imported\n";
    echo "                   sub_organization data (no provinces / no orgs).\n";
    exit(0);
}

$dryRun       = isset($opts['dry-run']);
$fromGateway  = isset($opts['from-gateway']);
$fromDevSeed  = isset($opts['from-dev-seed']);

$rootPath = dirname(__DIR__);
$env = App\Shared\Support\Environment::load($rootPath . '/.env');
$logger = new App\Shared\Support\StructuredLogger($rootPath . '/storage/logs/seed_lookups.log', 'debug');
$container = new App\Container($env, $logger);
$container->boot($rootPath);
$conn = $container->make(Connection::class);

// ---- Report current state ----
$provinceCount = (int)$conn->scalar("SELECT COUNT(*) FROM national_lookups WHERE kind='province'");
$townshipCount = (int)$conn->scalar("SELECT COUNT(*) FROM national_lookups WHERE kind='township'");
$mainOrgCount  = (int)$conn->scalar("SELECT COUNT(*) FROM national_lookups WHERE kind='main_organization'");
$subOrgCount   = (int)$conn->scalar("SELECT COUNT(*) FROM national_lookups WHERE kind='sub_organization'");

echo "Current national_lookups cache:\n";
echo "  provinces:          $provinceCount\n";
echo "  townships:          $townshipCount\n";
echo "  main_organizations: $mainOrgCount\n";
echo "  sub_organizations:  $subOrgCount\n";

if ($dryRun) {
    echo "(dry-run — no fetch)\n";
    exit(0);
}

// ---- --from-dev-seed: built-in 8/28/4 dev seed (offline) ----
if ($fromDevSeed) {
    $count = seedFromDevSeed($conn);
    echo "\n--from-dev-seed: applied built-in dev seed\n";
    echo "  inserted/upserted: $count rows (8 provinces + 28 townships + 4 main organizations)\n";
    echo "\nDone (dev-seed mode — offline, no upstream access).\n";
    exit(0);
}

// ---- --from-gateway legacy fallback ----
// If the sandbox is behind a geofence (can't reach qr.mojavez.ir), seed
// townships from the gateway-imported sub_organization data (which has
// parent_code = main_org code). This is a best-effort fallback — the real
// township data should come from upstream in production.
if ($fromGateway) {
    echo "\n--from-gateway: seeding townships from sub_organization data\n";
    // Each sub_organization has a parent_code (the main_org code). We treat
    // each sub_org as a township (close enough for sandbox testing — in
    // production, refreshLookups() fetches the real township list).
    $subOrgs = $conn->all("SELECT code, title, parent_code FROM national_lookups WHERE kind='sub_organization'");
    $inserted = 0;
    foreach ($subOrgs as $so) {
        $conn->execute(
            'INSERT IGNORE INTO national_lookups (kind, code, title, parent_code, is_active, refreshed_at) '
            . 'VALUES (?, ?, ?, ?, 1, NOW())',
            ['township', (string)$so['code'], (string)$so['title'], (string)$so['parent_code']]
        );
        $inserted++;
    }
    echo "  seeded $inserted townships from sub_organization data\n";
    echo "\nDone (degraded mode — from gateway data; prefer --from-dev-seed for full 8/28/4).\n";
    exit(2);
}

// ---- Normal mode: fetch from upstream, with auto-fallback to dev-seed ----
echo "\nFetching lookups from upstream via LicenseService::refreshLookups()...\n";
$licenseService = $container->make(\App\Modules\NationalGateway\Services\LicenseService::class);

try {
    $summary = $licenseService->refreshLookups();
    echo "  provinces fetched:      {$summary['provinces']}\n";
    echo "  townships fetched:       {$summary['townships']}\n";
    echo "  organizations fetched:   {$summary['organizations']}\n";

    // Auto-fallback: if the upstream returned ZERO rows (DNS failure
    // masked as success, empty response, quota exhausted, geofence
    // silent drop), fall back to the built-in dev seed. This catches
    // the case where refreshLookups() doesn't throw but the sandbox
    // clearly has no upstream access.
    $totalFetched = (int)$summary['provinces'] + (int)$summary['townships'] + (int)$summary['organizations'];
    if ($totalFetched === 0) {
        fwrite(STDERR, "WARNING: upstream returned 0 rows (unreachable / silent drop).\n");
        fwrite(STDERR, "  Falling back to built-in dev seed (8 provinces, 28 townships, 4 organizations).\n");
        fwrite(STDERR, "  Pass --from-dev-seed to skip this upstream attempt in the future.\n");
        $count = seedFromDevSeed($conn);
        echo "  inserted/upserted: $count rows from built-in dev seed\n";
        echo "\nDone (degraded mode — upstream silent, used built-in dev seed).\n";
        exit(2);
    }

    echo "\nDone.\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "WARNING: upstream fetch failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "  Falling back to built-in dev seed (8 provinces, 28 townships, 4 organizations).\n");
    fwrite(STDERR, "  Pass --from-dev-seed to skip this upstream attempt in the future.\n");
    $count = seedFromDevSeed($conn);
    echo "  inserted/upserted: $count rows from built-in dev seed\n";
    echo "\nDone (degraded mode — upstream unreachable, used built-in dev seed).\n";
    exit(2);
}

/**
 * Insert the built-in dev seed (8 provinces + 28 townships + 4 main orgs)
 * into national_lookups. Idempotent — re-runs are no-ops via the
 * UNIQUE(kind, code) constraint's ON DUPLICATE KEY UPDATE.
 *
 * @return int  Number of rows upserted (always the full count: 40).
 */
function seedFromDevSeed(Connection $conn): int
{
    $rows = require __DIR__ . '/seed_lookups_dev_data.php';
    $inserted = 0;
    foreach ($rows as $r) {
        $conn->execute(
            'INSERT INTO national_lookups (kind, code, title, parent_code, is_active, refreshed_at) '
            . 'VALUES (?, ?, ?, ?, ?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE title = VALUES(title), parent_code = VALUES(parent_code), is_active = VALUES(is_active)',
            [
                $r['kind'],
                $r['code'],
                $r['title'],
                $r['parent_code'],
                $r['is_active'],
            ]
        );
        $inserted++;
    }
    return $inserted;
}
