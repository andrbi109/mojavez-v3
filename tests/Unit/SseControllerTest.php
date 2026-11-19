<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for App\Modules\Core\Controllers\SseController — dev-mode polling
 * response shape.
 *
 * Per Phase 24 (v1.9.6) / D-4-5 — the SSE controller has two paths:
 *   - Dev mode (PHP built-in server, single-threaded): returns a JSON
 *     polling envelope with the X-SSE-Mode: dev-polling header so the
 *     client knows to poll this endpoint every ~5 seconds instead of
 *     keeping a long-lived SSE connection that would block the
 *     single-threaded built-in server.
 *   - Production mode (Apache/Nginx + PHP-FPM, multiprocess): holds a
 *     text/event-stream connection open and pushes events.
 *
 * The production path can't be unit-tested here because it calls
 * header()/echo/flush/exit directly against the live response buffer.
 * CI runs an integration check on GitHub Actions. This test pins the
 * dev-mode shape: JSON body, X-SSE-Mode: dev-polling header, mode +
 * message fields, and the events array.
 */
final class SseControllerTest extends TestCase
{
    public function name(): string
    {
        return 'SseControllerTest';
    }

    public function run(): void
    {
        // Build a real Environment pinned to dev mode. The project's
        // actual .env says APP_ENV=development, but we use a temp .env
        // so the test is deterministic even if the repo .env changes.
        $tmpEnv = sys_get_temp_dir() . '/sse-test-' . uniqid() . '.env';
        file_put_contents($tmpEnv, "APP_ENV=development\nAPP_DEBUG=true\n");
        $env = \App\Shared\Support\Environment::load($tmpEnv);
        @unlink($tmpEnv);
        $this->assertTrue($env->isDevelopment(), 'env loaded as development');

        // Build an EventBus with no DB connection — eventsSince() returns
        // [] gracefully when the connection is null.
        $logger = new \App\Shared\Support\StructuredLogger(
            sys_get_temp_dir() . '/sse-test-' . uniqid() . '.log',
            'debug'
        );
        $bus = new \App\Events\EventBus($logger, null);

        $controller = new \App\Modules\Core\Controllers\SseController($env, $bus);

        // Build a Request from globals. CLI defaults to GET method with
        // empty query; we set last_id=0 so the dev branch fetches from
        // the start (no events since EventBus has no connection).
        $_GET['last_id'] = '0';
        $request = \App\Http\Request::fromGlobals();

        $response = $controller->stream($request, []);

        // Clean up the request global we mutated.
        unset($_GET['last_id']);

        // 1. Dev mode returns 200 status (Response::json default).
        $this->assertSame(200, $response->status(), 'dev mode returns 200');

        // 2. The X-SSE-Mode header is set to 'dev-polling'.
        $headers = $response->headers();
        $this->assertArrayHasKey('X-SSE-Mode', $headers, 'X-SSE-Mode header set');
        $this->assertSame('dev-polling', $headers['X-SSE-Mode'], 'X-SSE-Mode value');

        // 3. Content-Type is application/json (dev mode is JSON polling,
        //    not text/event-stream).
        $this->assertStringContainsString(
            'application/json',
            $headers['Content-Type'] ?? '',
            'Content-Type application/json in dev mode'
        );

        // 4. Body decodes to JSON with mode + message + events + last_id
        //    + poll_interval_ms.
        $body = json_decode($response->body(), true);
        $this->assertArrayHasKey('mode', $body, 'body has mode field');
        $this->assertSame('polling', $body['mode'], 'mode = polling');
        $this->assertArrayHasKey('message', $body, 'body has message field');
        $this->assertStringContainsString(
            'poll this endpoint',
            $body['message'],
            'message tells client to poll'
        );
        $this->assertArrayHasKey('events', $body, 'body has events array');
        $this->assertArrayHasKey('last_id', $body, 'body has last_id');
        $this->assertArrayHasKey('poll_interval_ms', $body, 'body has poll_interval_ms');

        // 5. Poll interval is 5000ms (5s) — matches the documented cadence.
        $this->assertSame(5000, $body['poll_interval_ms'], 'poll_interval_ms = 5000');

        // 6. With an empty EventBus (no connection), events is [] and
        //    last_id stays at 0 (the requested offset).
        $this->assertSame([], $body['events'], 'events is [] with no DB');
        $this->assertSame(0, $body['last_id'], 'last_id unchanged with no events');
    }
}

return new SseControllerTest();
