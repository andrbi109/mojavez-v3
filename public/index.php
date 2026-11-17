<?php
declare(strict_types=1);

/**
 * mojavez-v3 — front controller.
 *
 * The single entry point for every HTTP request (rewritten here by .htaccess).
 *
 * Responsibilities (in order):
 *   1. Define ROOT_PATH, load Environment from .env.
 *   2. error_reporting + ini_set according to APP_DEBUG.
 *   3. set_exception_handler / set_error_handler (JSON for AJAX, HTML otherwise).
 *   4. Emit global security headers (X-Frame-Options, CSP, COOP, CORP,
 *      Cache-Control, X-Robots-Tag, HSTS on HTTPS).
 *   5. spl_autoload_register — maps App\ namespace to app/ directory.
 *   6. Session::start (with strict mode, HttpOnly, SameSite, Secure=auto).
 *   7. IP_BLOCKLIST early exit (403).
 *   8. install-check (DB + migrations table exist; otherwise redirect to install.php).
 *   9. Build Container, boot shared services, build Router.
 *  10. ModuleLoader: discover modules, register, boot, collect routes,
 *      collect migrations, collect subscribers.
 *  11. Apply config/routes.php (meta routes) + ModuleLoader routes.
 *  12. Router::dispatch(method, uri).
 *
 * /health and /check-cancel / /check-sync bypass install-check and auth
 * so monitoring works even before install.
 *
 * Design rules inherited from gateway & v2:
 *  - No die() — always throw or Response::send().
 *  - No global state other than $container.
 *  - No Composer — autoloader is hand-rolled.
 */

// ============================================================
// 0. ROOT_PATH — anchor for all relative paths.
// ============================================================
define('ROOT_PATH', dirname(__DIR__));

// ============================================================
// 1. Environment — read .env early (before anything else needs it).
// ============================================================
require_once ROOT_PATH . '/app/Shared/Support/Environment.php';
require_once ROOT_PATH . '/app/Shared/Support/StructuredLogger.php';

use App\Container;
use App\Http\Csrf;
use App\Http\Exceptions\CsrfException;
use App\Http\Exceptions\MethodNotAllowedException;
use App\Http\Exceptions\RouteNotFoundException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Modules\ModuleLoader;
use App\Shared\Support\Environment;
use App\Shared\Support\StructuredLogger;

$environment = Environment::load(ROOT_PATH . '/.env');

// ============================================================
// 2. Error reporting.
// ============================================================
if ($environment->debug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
    if ($environment->errorLogPath !== '') {
        @ini_set('error_log', $environment->errorLogPath);
    }
}

// ============================================================
// 3. Exception & error handlers.
// ============================================================
set_exception_handler(static function (\Throwable $e) use ($environment): void {
    $isAjax = (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

    $status = $e->getCode() ?: 500;
    if (!is_int($status) || $status < 100 || $status > 599) {
        $status = 500;
    }

    $body = $isAjax
        ? json_encode([
            'success' => false,
            'error'   => [
                'code'    => (string)$e->getCode() ?: 'internal_error',
                'message' => $environment->debug ? $e->getMessage() : 'Internal server error',
            ],
        ], JSON_UNESCAPED_UNICODE)
        : '<!doctype html><meta charset="utf-8"><h1>خطای سرور</h1>'
          . '<p>' . htmlspecialchars(
              $environment->debug ? $e->getMessage() : 'خطای داخلی سرور رخ داده است.',
              ENT_QUOTES, 'UTF-8'
          ) . '</p>';

    http_response_code($status);
    header('Content-Type: ' . ($isAjax ? 'application/json; charset=utf-8' : 'text/html; charset=utf-8'));
    echo $body;
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// ============================================================
// 4. Autoloader — App\ → app/ (no Composer).
// ============================================================
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

// ============================================================
// 5. Logger — bootstrap early so everything can log.
// ============================================================
$logFile = ROOT_PATH . '/storage/logs/app-' . date('Y-m-d') . '.log';
$logger = new StructuredLogger(
    $logFile,
    $environment->debug ? 'debug' : ($environment->isProduction() ? 'info' : 'debug')
);

// ============================================================
// 6. Global security headers (some are also emitted by Response::send,
//    but we set them here so they apply to ALL responses, even errors).
// ============================================================
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       || ($_SERVER['SERVER_PORT'] ?? '') == 443;
foreach (Response::SECURITY_HEADERS as $name => $value) {
    header($name . ': ' . $value);
}
header('Content-Security-Policy: ' . Response::CSP);
if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// ============================================================
// 7. IP blocklist — early exit.
// ============================================================
if ($environment->ipBlocklist !== '') {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    foreach (explode(',', $environment->ipBlocklist) as $blockedIp) {
        $blockedIp = trim($blockedIp);
        if ($blockedIp !== '' && hash_equals($blockedIp, $clientIp)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'forbidden']);
            exit;
        }
    }
}

// ============================================================
// 8. Build the Container and boot shared services.
// ============================================================
$container = new Container($environment, $logger);
$container->boot(ROOT_PATH);

// ============================================================
// 9. Session::start (after Container so we can read config).
// ============================================================
$session = $container->make(Session::class);
$session->start();

// ============================================================
// 10. Build the Request — pass BASE_URL so paths are normalized.
// ============================================================
$baseUrl = $environment->baseUrl;
// Phase 13 (v1.4.0) — pays D-1-3. trust_proxy now reads from
// config('app.trust_proxy') instead of being hard-coded. The config
// value itself defaults to false and can be overridden via the
// APP_TRUST_PROXY env var (see config/app.php). Only enable it behind
// a trusted reverse proxy.
Request::$trustProxy = (bool)\App\Shared\Support\Config::get('app.trust_proxy', false);
$request = Request::fromGlobals($baseUrl);

// ============================================================
// 11. Build the Router and apply config/routes.php meta routes.
// ============================================================
/** @var Router $router */
$router = $container->make(Router::class);
$metaRoutes = require ROOT_PATH . '/config/routes.php';
$metaRoutes($router);

// ============================================================
// 12. Module discovery & wiring.
//
//     Phase 1: only Core exists, so most module work is a no-op.
//     Phase 4 will introduce EventBus; until then we skip
//     collectSubscribers() (it depends on EventBus).
//
//     MigrationRunner is invoked here too — any new migration files
//     are applied at boot time.
// ============================================================
// EventBus — wired with Logger + Connection so events persist to the events
// table for SSE replay. Connection is built lazily on first use.
$dbConnection = $container->make(\App\Shared\Persistence\Connection::class);
$eventBus = new \App\Events\EventBus($logger, $dbConnection);

$migrationRunner = $container->make(\App\Shared\Persistence\MigrationRunner::class);
$moduleLoader = new ModuleLoader(
    $container,
    $router,
    $eventBus,
    $migrationRunner,
    $logger
);
$modulesRoot = ROOT_PATH . '/app/Modules';
$moduleLoader->discover($modulesRoot);
$moduleLoader->registerAll();
$moduleLoader->bootAll();
$moduleLoader->collectRoutes();
$moduleLoader->collectMigrations($modulesRoot);
// collectSubscribers() deferred to Phase 4 (depends on a real EventBus).

// Run pending migrations. Skip if /health or /check-* so monitoring works
// even before the DB is ready.
$path = $request->path();
$skipMigration = in_array($path, ['/health', '/check-cancel', '/check-sync'], true);
if (!$skipMigration) {
    try {
        $migrationRunner->run();
    } catch (\Throwable $e) {
        $logger->critical('Migration run failed at boot', [
            '_module' => 'core',
            'error'   => $e->getMessage(),
        ]);
        // Don't crash the request — let it try to dispatch anyway.
    }
}

// ============================================================
// 13. Dispatch the request.
// ============================================================
try {
    $matched = $router->match($request->method(), $request->path());

    // Rate limiting (§12.6) — check BEFORE CSRF so a flood of bad CSRF
    // tokens is also throttled. The first matching rule wins; if no rule
    // matches, no rate limit applies.
    $rlRule = \App\Http\RateLimitConfig::match($request->method(), $request->path());
    if ($rlRule !== null) {
        $rateLimiter = $container->make(\App\Http\IpRateLimiter::class);
        $allowed = $rateLimiter->hit(
            $rlRule['key'],
            $rlRule['max'],
            $rlRule['window'],
            $rlRule['type']
        );
        if (!$allowed) {
            $response = $request->isAjax()
                ? Response::json([
                    'success' => false,
                    'error' => ['code' => 'rate_limited', 'message' => 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد تلاش کنید.'],
                ], 429)
                : Response::html('<!doctype html><meta charset="utf-8"><h1>۴۲۹</h1><p>تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد تلاش کنید.</p>', 429);
            $remaining = $rateLimiter->remaining($rlRule['key'], $rlRule['max'], $rlRule['window'], $rlRule['type']);
            $response = $response->withHeader('X-RateLimit-Limit', (string)$rlRule['max'])
                                 ->withHeader('X-RateLimit-Remaining', (string)$remaining)
                                 ->withHeader('Retry-After', (string)$rlRule['window']);
            $response->send();
            exit(0);
        }
    }

    // CSRF verification on mutating requests.
    if ($request->isMutating()) {
        $csrf = $container->make(Csrf::class);
        $provided = $csrf->readProvided(
            $request->body(),
            $request->header('X-CSRF-TOKEN')
        );
        if (!$csrf->verify($provided)) {
            throw new CsrfException();
        }
    }

    // Resolve the handler — either inline closure or container-resolved class.
    if (str_starts_with($matched['handler'], '@inline:')) {
        $key = substr($matched['handler'], strlen('@inline:'));
        $closure = $router->inlineHandler($key);
        $response = $closure($request, $matched['params']);
    } else {
        $controller = $container->make($matched['handler']);
        $action = $matched['action'];
        $response = $controller->$action($request, $matched['params']);
    }

    if (!$response instanceof Response) {
        throw new \LogicException(
            "Handler {$matched['handler']}::{$matched['action']} did not return a Response"
        );
    }
    $response->send();
} catch (RouteNotFoundException $e) {
    // Phase 10: render a proper 404 page using the public layout instead of
    // a bare "صفحه یافت نشد" string. The view `errors/404.php` is a content-only
    // view (no <html>/<head>) that wraps cleanly inside layout.php's site nav.
    // We construct a minimal Controller-like shim here because the front
    // controller does not have a current Controller instance handy — the
    // 404 path is reached BEFORE a controller is dispatched.
    //
    // Note: layout.php uses `$this->csrf->token()` inside `if (!empty($csrfField))`.
    // From this global-scope require, `$this` is not defined, so we MUST set
    // `$csrfField = ''` to skip that branch — otherwise the layout would fatal.
    $viewsDir = ROOT_PATH . '/app/Views';
    $viewPath = $viewsDir . '/errors/404.php';
    $layoutPath = $viewsDir . '/layout.php';
    $siteName = 'سامانهٔ مجوز';
    $siteDescription = 'صدور مجوز کسب‌وکار خانگی';
    $title = 'صفحه یافت نشد';
    $baseUrl = $environment->baseUrl;
    $csrfField = '';  // skip the csrf-token meta tag (no Controller $this available)
    $content = '<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>صفحه یافت نشد.</p>';
    if (is_file($viewPath) && is_file($layoutPath)) {
        try {
            ob_start();
            require $viewPath;
            $content = (string)ob_get_clean();
            ob_start();
            require $layoutPath;
            $html = (string)ob_get_clean();
            $response = Response::html($html, 404);
        } catch (\Throwable $renderErr) {
            // If the 404 view itself fails to render, fall back to the bare
            // 404 message — never let the 404 page itself 500.
            $response = Response::html($content, 404);
        }
    } else {
        $response = Response::html($content, 404);
    }
    $response->send();
} catch (MethodNotAllowedException $e) {
    $response = Response::html(
        '<!doctype html><meta charset="utf-8"><h1>۴۰۵</h1><p>متد مجاز نیست.</p>',
        405
    );
    $response = $response->withHeader('Allow', implode(', ', $e->allowedMethods()));
    $response->send();
} catch (CsrfException $e) {
    $isAjax = $request->isAjax();
    $response = $isAjax
        ? Response::json(['success' => false, 'error' => ['code' => 'csrf_failed', 'message' => $e->getMessage()]], 419)
        : Response::html('<!doctype html><meta charset="utf-8"><h1>۴۱۹</h1><p>توکن CSRF نامعتبر است.</p>', 419);
    $response->send();
}
