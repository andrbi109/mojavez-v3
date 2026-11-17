#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * mojavez-v3 — Phase 27 (v1.9.9) route test sweep.
 *
 * Introspects every registered route, hits each one via curl (unauth),
 * and reports the HTTP status. Categorizes the result by acceptable
 * status-code ranges:
 *
 *   - GET:  expected 200 (public page) OR 302 (redirect to login/admin
 *           login because the route requires auth).
 *   - POST: expected 200/302 (form submitted) OR 419 (CSRF blocked —
 *           expected when no CSRF token is sent).
 *   - Any 5xx → FAIL (server error).
 *   - Any other 4xx (e.g. 404) → FAIL.
 *
 * Path-param routes (e.g. /tracking/{code}, /admin/edit/{id}) are SKIPped
 * because we don't have a valid ID to substitute. Cron routes
 * (/check-cancel, /check-sync) require a secret — SKIPped. The SSE
 * endpoint (/api/v1/stream) would block — SKIPped.
 *
 * The script also verifies:
 *   - The 404 page renders (GET an unmapped path → 404).
 *   - The 404 page is wrapped in layout.php (HTML body contains the
 *     site nav).
 *
 * Usage:
 *   php scripts/phase27_route_sweep.php
 *
 * Exits 0 on no FAILs (SKIPs allowed).
 */

// Bootstrap the container for route introspection.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $relative = str_replace('\\', '/', $relative);
    $file = __DIR__ . '/../app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require_once __DIR__ . '/../app/Shared/Support/Environment.php';
require_once __DIR__ . '/../app/Shared/Support/StructuredLogger.php';

$env = App\Shared\Support\Environment::load(__DIR__ . '/..');
$logger = new App\Shared\Support\StructuredLogger('/tmp/phase27-route-sweep.log', 'warning');
$router = new App\Http\Router($logger);

// 1. Load config/routes.php (Phase 1 meta routes — duplicates of Core).
$configRoutes = require __DIR__ . '/../config/routes.php';
$configRoutes($router);

// 2. Collect module routes.
$modulesRoot = __DIR__ . '/../app/Modules';
$bootOrder = ['Core', 'Queue', 'NationalGateway', 'Api', 'Reporting'];
foreach ($bootOrder as $name) {
    $moduleFile = $modulesRoot . '/' . $name . '/Module.php';
    if (!is_file($moduleFile)) {
        continue;
    }
    $class = 'App\\Modules\\' . $name . '\\Module';
    /** @var App\Modules\ModuleInterface $module */
    $module = new $class();
    foreach ($module->routes() as $route) {
        $router->add($route['method'], $route['path'], $route['handler'], $route['action']);
    }
}

// Introspect the registered routes via reflection.
$reflection = new ReflectionObject($router);
$prop = $reflection->getProperty('routes');
/** @var array<int, array{method:string,pattern:string,handler:string,action:string}> $routes */
$routes = $prop->getValue($router);

$totalRoutes = count($routes);
echo "Total routes registered: $totalRoutes\n";

// De-duplicate (config/routes.php and Core/Module.php both register
// /health, /check-cancel, /check-sync, /api/v1/stream).
$uniq = [];
foreach ($routes as $r) {
    $key = $r['method'] . ' ' . $r['pattern'];
    $uniq[$key] = $r;
}
$uniqueRoutes = array_values($uniq);
echo "Unique (method, path) routes: " . count($uniqueRoutes) . "\n";
echo str_repeat('-', 80) . "\n";

$BASE = 'http://127.0.0.1:8000';
$pass = 0;
$fail = 0;
$skip = 0;
$failures = [];

foreach ($uniqueRoutes as $r) {
    $method = $r['method'];
    $pattern = $r['pattern'];

    // Skip routes with path params (would need real IDs to test).
    if (str_contains($pattern, '{')) {
        $skip++;
        printf("  SKIP  %-6s %-50s  (path-param)\n", $method, $pattern);
        continue;
    }

    // Skip cron routes (require secret) and SSE (would block).
    if (in_array($pattern, ['/check-cancel', '/check-sync', '/api/v1/stream'], true)) {
        $skip++;
        printf("  SKIP  %-6s %-50s  (cron secret / SSE)\n", $method, $pattern);
        continue;
    }

    $url = $BASE . $pattern;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/json'],
    ]);
    if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        $fail++;
        $failures[] = "$method $pattern → curl error: $err";
        printf("  FAIL  %-6s %-50s  (curl error: %s)\n", $method, $pattern, $err);
        continue;
    }

    // Acceptable status codes:
    //   - GET: 200 (public), 302 (redirect to login — protected page).
    //   - POST: 200/302 (form processed), 419 (CSRF blocked without token).
    $ok = false;
    if ($method === 'GET' && ($code === 200 || $code === 302)) {
        $ok = true;
    } elseif (($method === 'POST' || $method === 'PUT' || $method === 'DELETE')
              && in_array($code, [200, 302, 419], true)) {
        $ok = true;
    }

    if ($ok) {
        $pass++;
        printf("  PASS  %-6s %-50s  → %d\n", $method, $pattern, $code);
    } else {
        $fail++;
        $failures[] = "$method $pattern → got $code (expected GET 200/302 or POST 200/302/419)";
        printf("  FAIL  %-6s %-50s  → %d (unexpected)\n", $method, $pattern, $code);
    }
}

echo str_repeat('-', 80) . "\n";
echo "Route sweep summary: $pass PASS, $fail FAIL, $skip SKIP (out of " . count($uniqueRoutes) . " unique routes)\n";

// 404 test — an unmapped path.
echo str_repeat('-', 80) . "\n";
echo "404 page test:\n";
$ch = curl_init("$BASE/this-route-does-not-exist");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 10,
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo "  GET /this-route-does-not-exist → $code (expected 404)\n";
if ($code === 404) {
    echo "  PASS — 404 status code rendered\n";
    $pass++;
    if (str_contains((string)$resp, 'سامانهٔ مجوز') || str_contains((string)$resp, 'moj-header')) {
        echo "  PASS — 404 page wrapped in layout.php (site nav present)\n";
        $pass++;
    } else {
        echo "  FAIL — 404 page is bare HTML (no site layout)\n";
        $fail++;
        $failures[] = "404 page is bare HTML (no site layout)";
    }
} else {
    echo "  FAIL — expected 404, got $code\n";
    $fail++;
    $failures[] = "404 page → got $code, expected 404";
}

// 500 handler test — the front controller catches exceptions and
// falls back to a bare "خطای سرور" string when no errors/500.php view
// exists. We don't have a deterministic way to trigger a 500 in a
// safe manner — the 404 view is the only error view in v3 (per
// Phase 10 docstring).
echo str_repeat('-', 80) . "\n";
echo "500 handler test:\n";
echo "  SKIP — v3 ships only the 404 error view. The front controller\n";
echo "  catches exceptions and falls back to a bare error string.\n";
echo "  Phase 10 (v1.9.2) added the 404 view; a 500 view is not yet\n";
echo "  part of the codebase (no errors/500.php file exists).\n";
$skip++;

echo str_repeat('-', 80) . "\n";
echo "FINAL: $pass PASS, $fail FAIL, $skip SKIP\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
exit(0);
