<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Shared\Support\Environment;

/**
 * SSE streaming endpoint — `/api/v1/stream`.
 *
 * In production (Apache/Nginx + PHP-FPM, multiprocess): holds a
 * `text/event-stream` connection open and pushes events from the EventBus
 * (Phase 4) to the browser.
 *
 * In development (PHP built-in server, single-threaded): returns 204 to
 * avoid blocking all other requests on the dev server. Phase 4 will
 * introduce the real streaming logic with the EventBus.
 *
 * Per docs/00-MASTER.md §13.5 — SSE (not WebSocket) because:
 *  - One-way server → client is sufficient for our events.
 *  - mod_php + shared hosting makes WebSocket impractical.
 *  - SSE is plain HTTP, just held open.
 */
final class SseController
{
    public function __construct(
        private readonly Environment $environment,
    ) {}

    public function stream(Request $request, array $params = []): Response
    {
        if ($this->environment->isDevelopment()) {
            // Dev: return 204 No Content so the single-threaded PHP
            // built-in server is not blocked.
            return Response::noContent();
        }

        // Production: Phase 4 will implement the real streaming loop:
        //   1. set headers: Content-Type: text/event-stream, no-cache
        //   2. while connection alive:
        //        - poll EventBus::eventsSince($lastId)
        //        - emit "id: ...\nevent: ...\ndata: ...\n\n"
        //        - flush()
        //        - sleep 1s
        // For Phase 1, we return a placeholder 501.
        return Response::json(
            ['error' => 'sse_not_implemented', 'phase' => 4],
            501
        );
    }
}
