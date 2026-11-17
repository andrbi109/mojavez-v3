<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Events\EventBus;
use App\Http\Request;
use App\Http\Response;
use App\Shared\Support\Environment;

/**
 * SSE streaming endpoint — `/api/v1/stream`.
 *
 * In production (Apache/Nginx + PHP-FPM, multiprocess): holds a
 * `text/event-stream` connection open and pushes events from the EventBus
 * (persisted to the events table) to the browser. The client passes
 * ?last_id=N to resume after a disconnect.
 *
 * In development (PHP built-in server, single-threaded): returns the latest
 * events as a JSON array (poor man's polling fallback) so the single-threaded
 * PHP built-in server is not blocked by a long-lived connection.
 *
 * Per docs/00-MASTER.md §13.5 — SSE (not WebSocket) because:
 *  - One-way server → client is sufficient for our events.
 *  - mod_php + shared hosting makes WebSocket impractical.
 *  - SSE is plain HTTP, just held open.
 */
final class SseController
{
    /** Max events to send in one polling batch. */
    private const POLL_LIMIT = 50;

    /** Max seconds to hold the SSE connection open before closing (client reconnects). */
    private const MAX_STREAM_SECONDS = 25;

    public function __construct(
        private readonly Environment $environment,
        private readonly EventBus $eventBus,
    ) {}

    public function stream(Request $request, array $params = []): Response
    {
        $lastId = (int)($request->queryParam('last_id', 0) ?? 0);

        if ($this->environment->isDevelopment()) {
            // Dev: return the latest events as JSON (poor man's polling).
            $events = $this->eventBus->eventsSince($lastId, self::POLL_LIMIT);
            $newLastId = $lastId;
            foreach ($events as $ev) {
                if ($ev['id'] > $newLastId) {
                    $newLastId = $ev['id'];
                }
            }
            return Response::json([
                'events' => $events,
                'last_id' => $newLastId,
                'poll_interval_ms' => 3000,
            ]);
        }

        // Production: real SSE streaming.
        @set_time_limit(self::MAX_STREAM_SECONDS + 5);
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $deadline = time() + self::MAX_STREAM_SECONDS;
        $currentId = $lastId;

        while (time() < $deadline) {
            $events = $this->eventBus->eventsSince($currentId, self::POLL_LIMIT);
            foreach ($events as $ev) {
                $currentId = max($currentId, $ev['id']);
                $this->emitSseEvent($ev);
            }
            echo ": heartbeat\n\n";
            @flush();

            if ($events === []) {
                sleep(1);
            }
        }
        exit;
    }

    /**
     * Emit one event in the SSE wire format.
     *
     * @param array{id:int,type:string,payload:array<string,mixed>,created_at:string} $ev
     */
    private function emitSseEvent(array $ev): void
    {
        echo "id: {$ev['id']}\n";
        echo "event: {$ev['type']}\n";
        echo "data: " . json_encode($ev['payload'], JSON_UNESCAPED_UNICODE) . "\n\n";
        @flush();
    }
}
