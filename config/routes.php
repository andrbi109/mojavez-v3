<?php
declare(strict_types=1);

/**
 * Route registry — ~140 routes total.
 *
 * Order matters: this is a first-match router. Special routes
 * (`/saved`, `/export`, `/search`, `/save-all`, `/{id}/save`,
 * `/{id}/track`, `/sync/*`) MUST come BEFORE the generic `/{id}` route.
 *
 * In Phase 1, this file declares only the meta routes that the kernel
 * itself owns (`/health`, `/check-cancel`, `/check-sync`, `/api/v1/stream`
 * in dev). Module-specific routes are aggregated from each module's
 * `Module::routes()` method by the ModuleLoader at boot time.
 *
 * @param \App\Http\Router $router
 * @return void
 */
return static function (\App\Http\Router $router): void {
    // Health — no auth, no rate limit. For monitoring.
    $router->get('/health', \App\Modules\Core\Controllers\HealthController::class, 'health');

    // Cron-style triggers — protected by hash_equals(CRON_SECRET/SYNC_SECRET).
    $router->get('/check-cancel', \App\Modules\Core\Controllers\CronController::class, 'cancel');
    $router->get('/check-sync',   \App\Modules\Core\Controllers\CronController::class, 'sync');

    // SSE in production; in dev (PHP built-in server) the index.php front
    // controller short-circuits this to 204 to avoid blocking all other
    // requests on the single-threaded dev server.
    $router->get('/api/v1/stream', \App\Modules\Core\Controllers\SseController::class, 'stream');

    // Module routes are appended by ModuleLoader::collectRoutes() AFTER
    // this file returns. Generic /{id} catch-alls MUST be registered LAST
    // in their respective module (NationalGateway registers `/{requestNumber}`
    // for the license detail page).
};
