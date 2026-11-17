<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Shared\Support\Environment;

/**
 * Cron-triggered endpoints — protected by hash_equals(SECRET, provided).
 *
 * - `/check-cancel?key=...` or `X-Cron-Secret` header — triggers the
 *   AutoCancelService (Phase 2).
 * - `/check-sync?key=...` or `X-Sync-Secret` header — triggers a golden-link
 *   sync cycle (Phase 4).
 *
 * If the secret is empty in .env, the endpoint is disabled (returns 503).
 * This forces ops to set a strong secret before enabling cron triggers.
 *
 * Per docs/00-MASTER.md §12.10 — both use the same hash_equals pattern.
 */
final class CronController
{
    public function __construct(
        private readonly Environment $environment,
    ) {}

    public function cancel(Request $request, array $params = []): Response
    {
        return $this->guardAndRun(
            $this->environment->cronSecret,
            $request,
            'check-cancel',
            function (): Response {
                // Phase 2 will call AutoCancelService::run() here.
                return Response::json([
                    'success'  => true,
                    'message'  => 'AutoCancelService not yet wired (Phase 2).',
                    'cancelled_count' => 0,
                ]);
            }
        );
    }

    public function sync(Request $request, array $params = []): Response
    {
        return $this->guardAndRun(
            $this->environment->syncSecret,
            $request,
            'check-sync',
            function (): Response {
                // Phase 4 will call NationalSyncOrchestrator::runCycle() here.
                return Response::json([
                    'success'  => true,
                    'message'  => 'NationalSyncOrchestrator not yet wired (Phase 4).',
                    'cycle_started' => false,
                ]);
            }
        );
    }

    /**
     * Verify the secret from either query string (`key=`) or header
     * (`X-Cron-Secret` / `X-Sync-Secret`). Run the callback if it matches.
     *
     * @param callable():Response $onAuthorized
     */
    private function guardAndRun(string $secret, Request $request, string $kind, callable $onAuthorized): Response
    {
        if ($secret === '') {
            return Response::json(
                ['error' => "$kind is disabled — set the matching *_SECRET in .env"],
                503
            );
        }
        $provided = (string)($request->queryParam('key') ?? '');
        if ($provided === '') {
            $provided = (string)($request->header('X-Cron-Secret') ?? $request->header('X-Sync-Secret') ?? '');
        }
        if (!hash_equals($secret, $provided)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return $onAuthorized();
    }
}
