<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Request;
use App\Http\Response;
use App\Shared\Persistence\Connection;
use App\Support\Environment;
use PDOException;

/**
 * Health endpoint — `/health`.
 *
 * No auth, no rate limit. Must respond even when the DB is down (so external
 * monitoring like uptime checks can distinguish "app is alive" from "DB is
 * down"). The DB status is included as a sub-check.
 *
 * Per docs/00-MASTER.md §10.1 and §25.3:
 *  - In normal mode: returns 200 with app version, env, db status, circuit
 *    breaker state, last upstream success.
 *  - In degraded mode (DB down, circuit open): still 200 but with flags.
 *
 * Output is a JSON object so it can be parsed by uptime monitors.
 */
final class HealthController
{
    public function __construct(
        private readonly Environment $environment,
        private readonly Container $container,
    ) {}

    public function health(Request $request, array $params = []): Response
    {
        $dbOk = false;
        $dbError = null;
        try {
            $conn = $this->container->make(Connection::class);
            $conn->scalar('SELECT 1');
            $dbOk = true;
        } catch (PDOException $e) {
            $dbError = $e->getMessage();
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }

        $payload = [
            'status'   => $dbOk ? 'ok' : 'degraded',
            'version'  => trim((string)@file_get_contents(dirname(__DIR__, 4) . '/VERSION') ?: '0.0.0'),
            'env'      => $this->environment->appEnv,
            'checks'   => [
                'database' => $dbOk ? 'ok' : 'fail',
            ],
            'timestamp' => date('c'),
        ];

        // Circuit breaker state will be filled by Phase 3 — leave null for now.
        $payload['circuit_breaker_state'] = null;
        $payload['last_upstream_success_at'] = null;

        if (!$dbOk) {
            $payload['checks']['database_error'] = $dbError;
        }

        return Response::json($payload, $dbOk ? 200 : 503);
    }
}
