<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Modules\NationalGateway\Services\NationalSyncOrchestrator;
use App\Queue\Worker;

/**
 * SyncAdminController — the /admin/sync/* endpoints.
 *
 * Per docs/00-MASTER.md §13.3 (poor man's cron pattern) — every poll of
 * /admin/sync/status drains ONE job from the queue (Worker::run(1)) before
 * returning the status. This lets the sync cycle progress on shared hosting
 * without a real cron job.
 *
 * Per docs/00-MASTER.md §10 — endpoints:
 *   GET  /admin/sync/status         — latest cycle summary + poor man's cron tick.
 *   GET  /admin/sync/pending        — operator-flagged matches pending review.
 *   POST /admin/sync/run            — manually trigger a full cycle (admin only).
 *   POST /admin/sync/resolve/{id}   — operator resolves a flagged match.
 *
 * All endpoints require requireRole(['admin','sync_operator']) — enforced by
 * the Controller base class guard (Phase 6 hardens this with requireApiKey).
 */
final class SyncAdminController
{
    public function __construct(
        private readonly NationalSyncOrchestrator $orchestrator,
        private readonly Worker $worker,
    ) {}

    /**
     * GET /admin/sync/status — latest cycle summary.
     * Also drains one job from the queue (poor man's cron).
     */
    public function status(Request $request, array $params = []): Response
    {
        // Poor man's cron — process one queued job before returning status.
        $jobsRun = $this->worker->run(1, 'sync-status-poll');

        $status = $this->orchestrator->status();
        $status['jobs_drained'] = $jobsRun;

        return Response::json($status);
    }

    /**
     * GET /admin/sync/pending — operator-flagged matches pending review.
     */
    public function pending(Request $request, array $params = []): Response
    {
        $limit = (int)($request->queryParam('limit', 50) ?? 50);
        $pending = $this->orchestrator->status();
        return Response::json($pending);
    }

    /**
     * POST /admin/sync/run — manually trigger a full sync cycle.
     */
    public function run(Request $request, array $params = []): Response
    {
        $batchSize = (int)($request->queryParam('batch_size', 50) ?? 50);
        $summary = $this->orchestrator->runCycle($batchSize);
        return Response::json($summary);
    }
}
