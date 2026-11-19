<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Controllers\Controller;
use App\Modules\NationalGateway\Services\NationalSyncOrchestrator;
use App\Queue\Worker;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;

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
 *
 * Auth: requireRole(['admin', 'sync_operator']) — both realms accepted.
 *
 * Phase 10 fix: this controller previously did NOT extend the base Controller
 * class and therefore bypassed requireLogin()/requireRole() entirely — meaning
 * /admin/sync/* was reachable by unauthenticated users (the docblock claimed
 * the base class enforced auth, but the class didn't extend it). Now extends
 * Controller and calls requireRole() at the top of every action. This is both
 * a security fix and a 404-adjacent fix (unauthenticated users now get a 302
 * to /admin/login instead of raw JSON, which is the expected admin-area UX).
 */
final class SyncAdminController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly NationalSyncOrchestrator $orchestrator,
        private readonly Worker $worker,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /admin/sync/status — latest cycle summary.
     * Also drains one job from the queue (poor man's cron).
     */
    public function status(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);

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
        $this->requireRole(['admin', 'sync_operator']);
        $limit = (int)($request->queryParam('limit', 50) ?? 50);
        $pending = $this->orchestrator->status();
        return Response::json($pending);
    }

    /**
     * POST /admin/sync/run — manually trigger a full sync cycle.
     */
    public function run(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $batchSize = (int)($request->queryParam('batch_size', 50) ?? 50);
        $summary = $this->orchestrator->runCycle($batchSize);
        return Response::json($summary);
    }

    /**
     * GET /admin/sync/readiness — Stage2ReadinessChecker report (HTML view).
     *
     * Renders the 4 §29.2 readiness conditions as Bootstrap cards with
     * pass/fail badges + the overall `ready` flag + a hint about how to
     * enable Stage 2 via SettingsController.
     *
     * Phase 11 (v1.2.0): pays D-8-4 (DEBT_REVIEW).
     */
    public function readiness(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);
        /** @var \App\Modules\NationalGateway\Services\Stage2ReadinessChecker $checker */
        $checker = $this->container->make(\App\Modules\NationalGateway\Services\Stage2ReadinessChecker::class);
        $report = $checker->check();
        return $this->render('admin/sync_readiness', [
            'title'  => 'آمادگی مرحلهٔ ۲',
            'report' => $report,
        ]);
    }
}
