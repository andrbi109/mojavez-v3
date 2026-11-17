<?php
declare(strict_types=1);

/**
 * PHP built-in dev server router.
 *
 * Run with:
 *   php -S localhost:8000 -t public public/router.php
 *
 * For each request:
 *   1. If the path is a real file under public/ (CSS, JS, vendor assets,
 *      robots.txt, favicon), serve it directly.
 *   2. Otherwise route everything to index.php.
 *
 * Note: the built-in server is single-threaded, so /api/v1/stream returns
 * 204 in dev (see SseController).
 */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = __DIR__ . $uri;
if ($uri !== '/' && is_file($path)) {
    return false;  // let PHP's built-in server serve the file
}
require __DIR__ . '/index.php';
