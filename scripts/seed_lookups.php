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
 * Usage:
 *   php scripts/seed_lookups.php
 *   php scripts/seed_lookups.php --dry-run   # just report what's cached
 *   php scripts/seed_lookups.php --from-gateway  # use gateway-imported data
 *                                                  # instead of upstream (when
 *                                                  # geofence blocks upstream)
 *
 * Exit codes:
 *   0 — success
 *   1 — error (upstream unreachable, no fallback)
 *   2 — degraded mode (upstream unreachable, fell back to gateway data)
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

$opts = getopt('', ['dry-run', 'from-gateway', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/seed_lookups.php\n";
    echo "  --dry-run       Just report current cache state, don't fetch.\n";
    echo "  --from-gateway  Use gateway-imported national_lookups data instead\n";
    echo "                  of fetching from upstream (for sandboxes behind a geofence).\n";
    exit(0);
}

$dryRun = isset($opts['dry-run']);
$fromGateway = isset($opts['from-gateway']);

$rootPath = dirname(__DIR__);
$env = App\Shared\Support\Environment::load($rootPath . '/.env');
$logger = new App\Shared\Support\StructuredLogger($rootPath . '/storage/logs/seed_lookups.log', 'debug');
$container = new App\Container($env, $logger);
$container->boot($rootPath);
$conn = $container->make(Connection::class);

// ---- Report current state ----
$provinceCount = (int)$conn->scalar("SELECT COUNT(*) FROM national_lookups WHERE kind='province'");
$townshipCount = (int)$conn->scalar("SELECT COUNT(*) FROM national_lookups WHERE kind='township'");
$mainOrgCount = (int)$conn->scalar("SELECT COUNT(*) FROM national_lookups WHERE kind='main_organization'");
$subOrgCount = (int)$conn->scalar("SELECT COUNT(*) FROM national_lookups WHERE kind='sub_organization'");

echo "Current national_lookups cache:\n";
echo "  provinces:          $provinceCount\n";
echo "  townships:          $townshipCount\n";
echo "  main_organizations: $mainOrgCount\n";
echo "  sub_organizations:  $subOrgCount\n";

if ($dryRun) {
    echo "(dry-run — no fetch)\n";
    exit(0);
}

// ---- --from-gateway fallback ----
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
    echo "\nDone (degraded mode — from gateway data).\n";
    exit(2);
}

// ---- Normal mode: fetch from upstream ----
echo "\nFetching lookups from upstream via LicenseService::refreshLookups()...\n";
$licenseService = $container->make(\App\Modules\NationalGateway\Services\LicenseService::class);

try {
    $summary = $licenseService->refreshLookups();
    echo "  provinces fetched:      {$summary['provinces']}\n";
    echo "  townships fetched:       {$summary['townships']}\n";
    echo "  organizations fetched:   {$summary['organizations']}\n";
    echo "\nDone.\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: upstream fetch failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "  If you're behind a geofence (e.g. outside Iran), re-run with --from-gateway\n");
    fwrite(STDERR, "  to seed townships from the gateway-imported sub_organization data instead.\n");
    exit(1);
}
