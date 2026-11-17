<?php
declare(strict_types=1);

/**
 * Top-level config loader for mojavez-v3.
 *
 * Returns the merged config array. Each subordinate file under config/
 * returns an array. We compose them here under namespaced keys.
 *
 * IMPORTANT: no `define()` here — CODING_CONVENTIONS §2 forbids global
 * constants. Constants used app-wide (APP_VERSION, MAX_UPLOAD_SIZE, ...)
 * are exposed via the returned array and accessed through
 * `App\Shared\Support\Config::get('app.version')` instead.
 *
 * @return array<string,mixed>
 */
return (static function (): array {
    $app = require __DIR__ . '/app.php';
    $database = require __DIR__ . '/database.php';
    $antiDetection = require __DIR__ . '/anti-detection.php';
    $filters = require __DIR__ . '/filters.php';
    $statuses = require __DIR__ . '/statuses.php';
    $workflow = require __DIR__ . '/workflow.php';

    // routes.php is loaded lazily by the Router at boot time (it returns
    // closures that depend on container-resolved controllers), so we don't
    // merge it here.

    return [
        'app'             => $app,
        'database'        => $database,
        'anti_detection'  => $antiDetection,
        'filters'         => $filters,
        'statuses'        => $statuses,
        'workflow'        => $workflow,
    ];
})();
