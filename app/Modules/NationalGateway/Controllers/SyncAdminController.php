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
 *   GET  /admin/sync/status         — HTML: latest cycle summary + poor man's cron tick.
 *   GET  /admin/sync/pending        — HTML: operator-flagged matches pending review.
 *   POST /admin/sync/run            — manually trigger a full cycle (admin only). JSON.
 *   POST /admin/sync/resolve        — confirm or reject a pending flagged match.
 *
 * Phase 41 (v2.0.7) — /admin/sync/status and /admin/sync/pending were
 * converted from JSON responses to HTML views (gateway_sync_status.php +
 * gateway_sync_pending.php). The /admin/sync/readiness route was removed
 * (route + method + view + sidebar link). A new /admin/sync/resolve POST
 * endpoint was added so the pending page's approve/reject buttons work.
 *
 * Auth: requireRole(['admin', 'sync_operator']) — both realms accepted.
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
     * GET /admin/sync/status — latest cycle summary as HTML.
     * Also drains one job from the queue (poor man's cron).
     */
    public function status(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);

        // Poor man's cron — process one queued job before returning status.
        $jobsRun = $this->worker->run(1, 'sync-status-poll');

        $status = $this->orchestrator->status();
        $status['jobs_drained'] = $jobsRun;
        $syncInfo = $this->orchestrator->syncInfo();

        return $this->render('admin/gateway_sync_status', [
            'title'     => 'وضعیت همگام‌سازی درگاه ملی',
            'status'    => $status,
            'syncInfo'  => $syncInfo,
            'jobsRun'   => $jobsRun,
        ]);
    }

    /**
     * GET /admin/sync/pending — operator-flagged matches pending review (HTML table).
     */
    public function pending(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);
        $limit = (int)($request->queryParam('limit', 50) ?? 50);
        $pending = $this->orchestrator->pending($limit);
        return $this->render('admin/gateway_sync_pending', [
            'title'   => 'تطبیق‌های در انتظار بررسی',
            'pending' => $pending,
            'limit'   => $limit,
        ]);
    }

    /**
     * POST /admin/sync/run — manually trigger a full sync cycle (JSON).
     */
    public function run(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $batchSize = (int)($request->queryParam('batch_size', 50) ?? 50);
        $summary = $this->orchestrator->runCycle($batchSize);
        return Response::json($summary);
    }

    /**
     * POST /admin/sync/resolve — confirm or reject a pending flagged match.
     *
     * Form fields: log_id (int), decision ('confirmed'|'rejected'), note (?string).
     * After resolving, redirects back to /admin/sync/pending.
     */
    public function resolve(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);
        $logId    = (int)($request->input('log_id', 0) ?? 0);
        $decision = (string)($request->input('decision', '') ?? '');
        $note     = $request->input('note');
        $note     = is_string($note) && $note !== '' ? $note : null;

        if ($logId <= 0 || !in_array($decision, ['confirmed', 'rejected'], true)) {
            $this->jsonError(422, 'invalid_input', 'log_id یا decision نامعتبر است.');
        }

        $reviewerId = (int)($this->session->get('admin_user_id', 0) ?? 0);
        $this->orchestrator->resolve($logId, $reviewerId, $decision, $note);

        $this->redirect($this->environment->baseUrl . 'admin/sync/pending');
    }
}
