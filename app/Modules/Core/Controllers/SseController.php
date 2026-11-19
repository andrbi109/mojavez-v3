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
 * In development (PHP built-in server, single-threaded): returns a
 * JSON polling envelope with the X-SSE-Mode: dev-polling header so the
 * client knows to poll this endpoint every ~5 seconds instead of
 * keeping a long-lived SSE connection. The PHP built-in server is
 * single-threaded, so a long-lived connection would block all other
 * requests.
 *
 * Per docs/00-MASTER.md §13.5 — SSE (not WebSocket) because:
 *  - One-way server → client is sufficient for our events.
 *  - mod_php + shared hosting makes WebSocket impractical.
 *  - SSE is plain HTTP, just held open.
 *
 * Phase 24 (v1.9.6): pays D-4-5. The dev response now sets an explicit
 * `X-SSE-Mode: dev-polling` header and includes a `mode` + `message`
 * body so the client can render a "dev mode — polling every 5s"
 * banner instead of silently waiting on an EventSource that never
 * re-streams (because the built-in server would block on the next
 * request). The polling interval is bumped from 3000ms to 5000ms to
 * match the documented "every 5 seconds" cadence.
 */
final class SseController
{
    /** Max events to send in one polling batch. */
    private const POLL_LIMIT = 50;

    /** Max seconds to hold the SSE connection open before closing (client reconnects). */
    private const MAX_STREAM_SECONDS = 30;

    /** Polling interval (ms) advertised to the client in dev mode. */
    private const DEV_POLL_INTERVAL_MS = 5000;

    /** Human-readable explanation included in the dev-mode JSON body. */
    private const DEV_POLLING_MESSAGE = 'SSE requires Apache/PHP-FPM. In dev, poll this endpoint every 5s.';

    public function __construct(
        private readonly Environment $environment,
        private readonly EventBus $eventBus,
    ) {}

    public function stream(Request $request, array $params = []): Response
    {
        $lastId = (int)($request->queryParam('last_id', 0) ?? 0);

        if ($this->environment->isDevelopment()) {
            return $this->streamDevMode($lastId);
        }

        return $this->streamProductionMode($lastId);
    }

    /**
     * Dev-mode response: JSON polling envelope with X-SSE-Mode: dev-polling.
     *
     * The body carries the latest events (so a client that polls still
     * sees new events fire) plus a `mode` + `message` pair so the client
     * can render a "polling in dev" banner. The HTTP header lets the
     * client decide its strategy without parsing the body.
     */
    private function streamDevMode(int $lastId): Response
    {
        $events = $this->eventBus->eventsSince($lastId, self::POLL_LIMIT);
        $newLastId = $lastId;
        foreach ($events as $ev) {
            if ($ev['id'] > $newLastId) {
                $newLastId = $ev['id'];
            }
        }
        return Response::json([
            'mode'              => 'polling',
            'message'           => self::DEV_POLLING_MESSAGE,
            'events'            => $events,
            'last_id'           => $newLastId,
            'poll_interval_ms'  => self::DEV_POLL_INTERVAL_MS,
        ])->withHeader('X-SSE-Mode', 'dev-polling');
    }

    /**
     * Production SSE response: text/event-stream held open up to
     * MAX_STREAM_SECONDS. Emits `data: {...}\n\n` events for any new
     * rows in the events table since $lastId, then a `: heartbeat`
     * comment every second the bus is idle. Closes when the deadline
     * hits so the client reconnects (and re-enters with a higher
     * last_id).
     *
     * This branch is exercised end-to-end in CI on GitHub Actions
     * (Apache + PHP-FPM multiprocess). It is NOT unit-tested here
     * because it calls header()/echo/flush/exit directly against the
     * live response buffer.
     */
    private function streamProductionMode(int $lastId): Response
    {
        @set_time_limit(self::MAX_STREAM_SECONDS + 5);
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        header('X-SSE-Mode: production-stream');
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
