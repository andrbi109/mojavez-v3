<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Events\EventBus;
use App\Events\Events\ApplicantMatched;
use App\Events\Events\SyncCycleCompleted;
use App\Modules\NationalGateway\Domain\Filter;
use App\Shared\Contracts\NationalLicenseRepositoryInterface;
use App\Shared\Contracts\SyncAuditLogRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;

/**
 * NationalSyncOrchestrator — drives the full golden-link sync cycle.
 *
 * Per docs/00-MASTER.md §9.5 — the cycle is:
 *   1. trigger (cron / poor man's cron / manual from admin panel)
 *   2. scrape: for each unmatched national_license with masked_national_code,
 *      run PatternMatcher against all pre-issue applicants.
 *   3. match: ApplicantSyncAdapter::matchAndAct() per (license, applicant).
 *   4. act: the adapter performs the atomic per-applicant transaction.
 *   5. notify + log: dispatch ApplicantMatched (for NotificationSubscriber)
 *      + SyncCycleCompleted.
 *   6. report: return a summary for the /admin/sync/status panel.
 *
 * Per §9.6 safety rules:
 *   - Idempotent: matched licenses are skipped (WHERE matched_applicant_id
 *     IS NULL).
 *   - Atomic per-applicant (the adapter wraps the act step).
 *   - Double-action prevented: applicants.national_license_request_number
 *     UNIQUE constraint.
 *   - Full audit: every decision logged to sync_audit_log by the adapter.
 *
 * The orchestrator does NOT scrape upstream itself — that's LicenseService's
 * job (searchHybrid + fetchAndCacheByTrackNumber populate national_licenses).
 * This class only runs the matcher against the already-cached licenses.
 */
final class NationalSyncOrchestrator
{
    /**
     * Per-orchestrator-instance dedup set for Stage 2 pushes.
     *
     * Per D-8-3 — Stage 2 push is keyed by matched_at falling within the
     * cycle window [startedAt, endedAt]. In a long cycle, the same applicant
     * could be returned by the SQL window more than once (e.g. if
     * runCycle() is invoked twice within the same second, or if a
     * previously-pushed applicant's matched_at is updated by another
     * cycle in the same window). OutboundSyncService has idempotency at
     * the integration_log layer, but a dedup set here is cleaner: we
     * skip + log instead of going through the idempotency lookup.
     *
     * The set is reset at the start of each runCycle() call so each cycle
     * starts fresh (per D-8-3 requirement #4).
     *
     * @var array<int,true>
     */
    private array $pushedApplicantIds = [];

    public function __construct(
        private readonly LicenseService $licenseService,
        private readonly ApplicantSyncAdapter $adapter,
        private readonly NationalLicenseRepositoryInterface $licenseRepo,
        private readonly SyncAuditLogRepositoryInterface $auditRepo,
        private readonly EventBus $eventBus,
        private readonly StructuredLogger $logger,
        private readonly Connection $conn,
        private readonly ?OutboundSyncService $outboundService = null,
        private readonly ?Hash $hash = null,
    ) {}

    /**
     * Run one full sync cycle. Returns a summary for the admin panel.
     *
     * Per docs/00-MASTER.md §9.5 — the cycle is:
     *   1. trigger (cron / poor man's cron / manual from admin panel)
     *   2. fetch upstream: LicenseService::searchUpstream() pulls one page
     *      of fresh licenses from qr.mojavez.ir and persists them to
     *      national_licenses. Falls back gracefully (degraded mode) if
     *      the upstream is unreachable / circuit breaker is OPEN.
     *   3. match: for each unmatched national_license with golden-link
     *      anchors, run ApplicantSyncAdapter against all eligible
     *      applicants.
     *   4. act: the adapter performs the atomic per-applicant transaction.
     *   5. notify + log: dispatch ApplicantMatched + SyncCycleCompleted.
     *   6. report: return a summary for the /admin/sync/status panel.
     *
     * The cycle also writes a 'cycle_started' + 'cycle_completed' row to
     * sync_audit_log so the status panel can read the last-cycle timestamps
     * back from the audit trail rather than in-memory state. The upstream
     * fetch is logged to integration_log (direction='inbound',
     * operation='fetch_license') per §29.1 (the bidirectional audit trail).
     *
     * @param int $licenseBatchSize  Max unmatched licenses to process per cycle.
     * @return array{
     *   started_at:string,
     *   ended_at:string,
     *   licenses_processed:int,
     *   applicants_tried:int,
     *   auto_confirmed:int,
     *   operator_flagged:int,
     *   auto_rejected:int
     * }
     */
    public function runCycle(int $licenseBatchSize = 50): array
    {
        // §29 (Rollout Stage 1) — read the sync_direction setting from the
        // settings table. If 'disabled', skip the entire cycle (runtime
        // kill-switch, no deploy needed). If 'inbound' (Stage 1 default),
        // run the cycle but the adapter only auto-transitions AutoConfirmed
        // matches — no writes are sent to upstream. If 'two_way' (Stage 2,
        // future), the cycle runs with two-way sync enabled (requires
        // sync_two_way_enabled=1 AND a 3-month stabilized Stage 1).
        $direction = $this->readSetting('sync_direction', 'inbound');
        if ($direction === 'disabled') {
            $this->logger->warning('Sync cycle skipped — sync_direction=disabled', []);
            return [
                'started_at' => date('Y-m-d H:i:s'),
                'ended_at' => date('Y-m-d H:i:s'),
                'licenses_processed' => 0,
                'applicants_tried' => 0,
                'auto_confirmed' => 0,
                'operator_flagged' => 0,
                'auto_rejected' => 0,
                'skipped' => true,
                'skip_reason' => 'sync_direction=disabled',
            ];
        }

        // Per D-8-3 — reset the per-cycle dedup set at the start of each
        // runCycle() call so each cycle starts fresh.
        $this->pushedApplicantIds = [];

        $startedAt = date('Y-m-d H:i:s');
        $this->logger->info('Sync cycle started', ['at' => $startedAt, 'direction' => $direction]);

        // §9.5 step 1: write a 'cycle_started' audit row so the status panel
        // can read the last-cycle start time back from sync_audit_log.
        $this->auditRepo->log(
            applicantId: 0,
            nationalRequestNumber: '',
            action: 'cycle_started',
            outcome: 'cycle_started',
            confidence: 0,
            context: ['direction' => $direction, 'batch_size' => $licenseBatchSize]
        );

        // §9.5 step 2 (Phase 42 v2.0.8): pull a fresh page from upstream so
        // the cycle isn't a no-op when the cache is empty. The fetch is
        // logged to integration_log (the bidirectional audit trail, §29.1).
        // If upstream is unreachable / circuit breaker OPEN, the searchUpstream
        // helper returns a 'degraded' summary and the cycle continues with
        // whatever the local cache has (the matcher step below will still run
        // against existing national_licenses rows).
        $upstreamFilter = new Filter(page: 1, pageSize: 20);
        $upstreamSummary = $this->licenseService->searchUpstream($upstreamFilter);
        $this->logIntegrationFetch($upstreamSummary);

        // §9.5 step 2 (cont.): find unmatched licenses with golden-link anchors.
        $licenses = $this->licenseRepo->findUnmatched($licenseBatchSize);

        $licensesProcessed = 0;
        $applicantsTried = 0;
        $autoConfirmed = 0;
        $operatorFlagged = 0;
        $autoRejected = 0;

        foreach ($licenses as $license) {
            $licensesProcessed++;

            // §9.5 step 3 + 4: match this license against all eligible applicants.
            $summary = $this->adapter->matchLicenseAgainstApplicants($license);

            $applicantsTried += $summary['tried'];
            $autoConfirmed += $summary['auto_confirmed'];
            $operatorFlagged += $summary['operator_flagged'];
            $autoRejected += $summary['auto_rejected'];

            // §9.5 step 5: if at least one applicant was auto-confirmed for
            // this license, dispatch ApplicantMatched so NotificationSubscriber
            // sends SMS + in-app notification.
            if ($summary['auto_confirmed'] > 0) {
                $this->eventBus->dispatch(new ApplicantMatched([
                    'request_number' => $license->requestNumber,
                    'license_title' => $license->licenseTitle,
                    'auto_confirmed_count' => $summary['auto_confirmed'],
                    // The actual applicant_id is the one that got matched —
                    // the adapter set it on the license row. We'd read it
                    // back here for a single-applicant match; for multi
                    // (unlikely but possible) we send the count.
                    'applicant_id' => 0,  // filled by NotificationSubscriber via licenseRepo lookup
                ]));
            }
        }

        $endedAt = date('Y-m-d H:i:s');

        // §29.1 Stage 2 — if two-way sync is enabled, push status changes for
        // applicants matched during THIS cycle back to upstream. We identify
        // them by matched_at falling within the cycle window [startedAt, endedAt].
        // The OutboundSyncService handles idempotency + conflict detection
        // + the auto-disable kill-switch (§29.3) internally.
        $outboundPushed = 0;
        $outboundConflicts = 0;
        $outboundSkipped = 0;
        $outboundDedupSkipped = 0;
        $twoWayEnabled = $this->readSetting('sync_two_way_enabled', '0') === '1'
                         && $direction === 'two_way';
        if ($twoWayEnabled && $this->outboundService !== null && $autoConfirmed > 0) {
            // Per D-8-1 — if the outbound push is still stubbed (no callable
            // injected), emit a clear warning so production logs surface
            // "outbound push is stubbed" rather than silently succeeding.
            if ($this->outboundService->isStubActive()) {
                $this->logger->warning(
                    'Stage 2 outbound push running with STUB — upstream has '
                    . 'no writable endpoint yet (§19.8). Pushes will log as '
                    . "'skipped'. Inject a real callable via "
                    . 'OutboundSyncService::setPushCallable() when upstream '
                    . 'exposes one.',
                    ['cycle_started_at' => $startedAt]
                );
            }
            $recentlyMatched = $this->conn->all(
                'SELECT id, current_status FROM applicants
                 WHERE matched_at IS NOT NULL
                   AND matched_at BETWEEN ? AND ?
                   AND national_license_request_number IS NOT NULL',
                [$startedAt, $endedAt]
            );
            $pushSummary = $this->pushStage2ForRows($recentlyMatched);
            $outboundPushed = $pushSummary['pushed'];
            $outboundConflicts = $pushSummary['conflicts'];
            $outboundSkipped = $pushSummary['skipped'];
            $outboundDedupSkipped = $pushSummary['dedup_skipped'];
            $this->logger->info('Stage 2 outbound pushes completed', [
                'pushed' => $outboundPushed,
                'conflicts' => $outboundConflicts,
                'skipped' => $outboundSkipped,
                'dedup_skipped' => $outboundDedupSkipped,
                'stub_active' => $this->outboundService->isStubActive(),
            ]);
        }

        // §9.5 step 5: dispatch SyncCycleCompleted.
        $this->eventBus->dispatch(new SyncCycleCompleted([
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'licenses_processed' => $licensesProcessed,
            'applicants_tried' => $applicantsTried,
            'auto_confirmed' => $autoConfirmed,
            'operator_flagged' => $operatorFlagged,
            'auto_rejected' => $autoRejected,
            'outbound_pushed' => $outboundPushed,
            'outbound_conflicts' => $outboundConflicts,
            'outbound_skipped' => $outboundSkipped,
            'outbound_dedup_skipped' => $outboundDedupSkipped,
            'two_way_enabled' => $twoWayEnabled,
            'upstream_reached'  => $upstreamSummary['reached'],
            'upstream_fetched'  => $upstreamSummary['fetched'],
            'upstream_degraded' => $upstreamSummary['degraded'],
        ]));

        // §9.5 step 1 (book-end): write a 'cycle_completed' audit row so the
        // status panel can read the last-cycle end time back from sync_audit_log.
        $this->auditRepo->log(
            applicantId: 0,
            nationalRequestNumber: '',
            action: 'cycle_completed',
            outcome: 'cycle_completed',
            confidence: 0,
            context: [
                'started_at'        => $startedAt,
                'ended_at'          => $endedAt,
                'licenses_processed'=> $licensesProcessed,
                'applicants_tried'  => $applicantsTried,
                'auto_confirmed'    => $autoConfirmed,
                'operator_flagged'  => $operatorFlagged,
                'auto_rejected'     => $autoRejected,
                'upstream_reached'  => $upstreamSummary['reached'],
                'upstream_fetched'  => $upstreamSummary['fetched'],
                'upstream_degraded' => $upstreamSummary['degraded'],
                'upstream_error'    => $upstreamSummary['error'],
            ]
        );

        $this->logger->info('Sync cycle completed', [
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'licenses_processed' => $licensesProcessed,
            'applicants_tried' => $applicantsTried,
            'auto_confirmed' => $autoConfirmed,
            'operator_flagged' => $operatorFlagged,
            'auto_rejected' => $autoRejected,
            'outbound_pushed' => $outboundPushed,
            'outbound_conflicts' => $outboundConflicts,
            'outbound_dedup_skipped' => $outboundDedupSkipped,
            'two_way_enabled' => $twoWayEnabled,
            'upstream_reached' => $upstreamSummary['reached'],
            'upstream_fetched' => $upstreamSummary['fetched'],
            'upstream_degraded' => $upstreamSummary['degraded'],
        ]);

        return [
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'licenses_processed' => $licensesProcessed,
            'applicants_tried' => $applicantsTried,
            'auto_confirmed' => $autoConfirmed,
            'operator_flagged' => $operatorFlagged,
            'auto_rejected' => $autoRejected,
            'outbound_pushed' => $outboundPushed,
            'outbound_conflicts' => $outboundConflicts,
            'outbound_skipped' => $outboundSkipped,
            'outbound_dedup_skipped' => $outboundDedupSkipped,
            'two_way_enabled' => $twoWayEnabled,
            'upstream_reached'  => $upstreamSummary['reached'],
            'upstream_fetched'  => $upstreamSummary['fetched'],
            'upstream_total'    => $upstreamSummary['total'],
            'upstream_degraded' => $upstreamSummary['degraded'],
            'upstream_error'    => $upstreamSummary['error'],
        ];
    }

    /**
     * Match a single license (by request number) against all applicants.
     * Used by the SyncJob (queued by SyncSubscriber when LicenseIssuedOnNational
     * fires). Lighter than runCycle — processes one license, not a batch.
     *
     * @return array{tried:int,auto_confirmed:int,operator_flagged:int,auto_rejected:int}
     */
    public function matchOne(string $requestNumber): array
    {
        $license = $this->licenseRepo->findByRequestNumber($requestNumber);
        if ($license === null) {
            // License not in local cache yet — fetchAndCacheByTrackNumber first.
            $license = $this->licenseService->fetchAndCacheByTrackNumber($requestNumber);
            if ($license === null) {
                $this->logger->info('matchOne: license not found on upstream', ['rn' => $requestNumber]);
                return ['tried' => 0, 'auto_confirmed' => 0, 'operator_flagged' => 0, 'auto_rejected' => 0];
            }
        }

        // Idempotency: if the license is already matched, skip (no re-matching).
        if ($license->matchedApplicantId !== null) {
            $this->logger->info('matchOne: license already matched, skipping', [
                'rn' => $requestNumber,
                'matched_applicant_id' => $license->matchedApplicantId,
            ]);
            return ['tried' => 0, 'auto_confirmed' => 0, 'operator_flagged' => 0, 'auto_rejected' => 0];
        }

        return $this->adapter->matchLicenseAgainstApplicants($license);
    }

    /**
     * Latest cycle summary for /admin/sync/status. Reads from sync_audit_log
     * (the persisted audit trail) rather than in-memory state.
     *
     * @return array{cycle_started_at:?string,cycle_ended_at:?string,counts:array<string,int>}
     */
    public function status(): array
    {
        return $this->auditRepo->latestCycleSummary();
    }

    /**
     * Return summary info about the sync system for the status page.
     * Includes: direction, two_way_enabled, last cycle info, counts.
     *
     * @return array<string,mixed>
     */
    public function syncInfo(): array
    {
        $direction = $this->readSetting('sync_direction', 'inbound');
        $twoWay = $this->readSetting('sync_two_way_enabled', '0') === '1'
               && $direction === 'two_way';
        $lastCycle = $this->auditRepo->latestCycleSummary();

        // Count pending operator-flagged matches.
        $pendingCount = 0;
        try {
            $conn = $this->conn;
            $row = $conn->one(
                "SELECT COUNT(*) AS cnt FROM sync_audit_log
                 WHERE action = 'operator_flagged'
                   AND (review_decision IS NULL OR review_decision = '')"
            );
            if ($row !== null) {
                $pendingCount = (int)$row['cnt'];
            }
        } catch (\Throwable) {
            // best-effort
        }

        return [
            'direction'        => $direction,
            'two_way_enabled'  => $twoWay,
            'last_cycle'       => $lastCycle,
            'pending_count'    => $pendingCount,
        ];
    }

    /**
     * Return pending operator-flagged matches for review.
     *
     * @param int $limit
     * @return array<int,array<string,mixed>>
     */
    public function pending(int $limit = 50): array
    {
        try {
            return $this->conn->all(
                "SELECT sal.id, sal.applicant_id, sal.license_id,
                        sal.action, sal.confidence, sal.context,
                        sal.created_at, sal.reviewed_by, sal.reviewed_at,
                        sal.review_decision,
                        a.tracking_code, a.first_name, a.last_name,
                        a.national_code, a.current_status,
                        nl.request_number AS license_number,
                        nl.status AS license_status
                 FROM sync_audit_log sal
                 LEFT JOIN applicants a ON sal.applicant_id = a.id
                 LEFT JOIN national_licenses nl ON sal.license_id = nl.id
                 WHERE sal.action IN ('operator_flagged', 'auto_confirmed')
                   AND (sal.review_decision IS NULL OR sal.review_decision = '')
                 ORDER BY sal.created_at DESC
                 LIMIT ?",
                [$limit]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Push Stage 2 status changes for an iterable of recently-matched
     * applicant rows. Extracted from runCycle() so the per-row dedup set
     * (D-8-3) is testable in isolation — the SQL window in runCycle()
     * only ever returns unique applicant IDs (PK), so the dedup is a
     * defensive guard against future changes that might cause the same
     * applicant to appear more than once (e.g. if the SQL is changed to
     * a JOIN that produces duplicates).
     *
     * Each row MUST have 'id' (int) and 'current_status' (string). Rows
     * whose current_status is not a known ApplicantStatus slug are
     * skipped (no push, no dedup marking).
     *
     * Public for testability — called only from runCycle() in production.
     *
     * @param array<int,array{id:mixed,current_status:mixed}> $rows
     * @return array{pushed:int, conflicts:int, skipped:int, dedup_skipped:int}
     */
    public function pushStage2ForRows(array $rows): array
    {
        $pushed = 0;
        $conflicts = 0;
        $skipped = 0;
        $dedupSkipped = 0;

        if ($this->outboundService === null) {
            return ['pushed' => 0, 'conflicts' => 0, 'skipped' => 0, 'dedup_skipped' => 0];
        }

        foreach ($rows as $row) {
            $applicantId = (int)$row['id'];

            // Per D-8-3 — dedup set: if we've already pushed for this
            // applicant in THIS cycle (e.g. a JOIN-based SQL returned them
            // more than once, or a future change causes duplicates), skip +
            // log instead of going through the idempotency lookup at
            // OutboundSyncService.
            if (array_key_exists($applicantId, $this->pushedApplicantIds)) {
                $dedupSkipped++;
                $this->logger->info('Stage 2 push skipped: already pushed in this cycle', [
                    'applicant_id' => $applicantId,
                ]);
                continue;
            }

            $status = \App\Modules\Core\Domain\Enums\ApplicantStatus::fromSlug((string)$row['current_status']);
            if ($status === null) {
                continue;
            }
            $result = $this->outboundService->pushStatusChange(
                $applicantId,
                $status,
                'auto_sync'
            );
            if ($result['outcome'] === 'conflict') {
                $conflicts++;
            } elseif ($result['outcome'] === 'skipped') {
                $skipped++;
            } else {
                $pushed++;
            }
            // Mark this applicant as pushed for this cycle — even if the
            // outcome was 'conflict' or 'skipped' (idempotency / conflict
            // are stable across re-pushes; no need to retry in-cycle).
            $this->pushedApplicantIds[$applicantId] = true;
        }

        return [
            'pushed' => $pushed,
            'conflicts' => $conflicts,
            'skipped' => $skipped,
            'dedup_skipped' => $dedupSkipped,
        ];
    }

    /**
     * Read a setting from the settings table (key→value).
     * Returns $default if the key is missing or the table is unreachable.
     *
     * Per §29 — used to read sync_direction + sync_two_way_enabled at runtime.
     */
    private function readSetting(string $key, string $default = ''): string
    {
        try {
            $row = $this->conn->one(
                'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1',
                [$key]
            );
            if ($row !== null && $row['setting_value'] !== null && $row['setting_value'] !== '') {
                return (string)$row['setting_value'];
            }
        } catch (\Throwable $e) {
            // Settings table might not exist yet (pre-install) — fail safe.
            $this->logger->warning('readSetting failed, using default', [
                'key' => $key, 'default' => $default, 'error' => $e->getMessage(),
            ]);
        }
        return $default;
    }

    /**
     * Write one row to integration_log recording the upstream fetch the
     * cycle just performed. Per §29.1 (the bidirectional audit trail) —
     * every interaction between v3 and the upstream national gateway is
     * recorded here so the audit team can replay any cycle.
     *
     * The idempotency_key is derived from (operation, page, bucket) so
     * re-runs of the same cycle within the same minute deduplicate
     * naturally — the UNIQUE constraint on integration_log.idempotency_key
     * will reject a duplicate insert (we silently swallow that error so
     * the cycle doesn't fail on a tight cron schedule).
     *
     * @param array{reached:bool,fetched:int,total:?int,degraded:bool,error:?string} $summary
     */
    private function logIntegrationFetch(array $summary): void
    {
        $bucket = date('Y-m-d H:i');  // 1-minute bucket
        $idempotencyKey = 'fetch_license:page=1:' . $bucket;
        if ($this->hash !== null) {
            $idempotencyKey = 'fetch_license:' . $this->hash->short($idempotencyKey);
        }

        $outcome = $summary['degraded'] ? 'skipped' : ($summary['reached'] ? 'success' : 'timeout');
        $payload = [
            'operation'  => 'fetch_license',
            'page'       => 1,
            'page_size'  => 20,
            'reached'    => $summary['reached'],
            'fetched'    => $summary['fetched'],
            'total'      => $summary['total'],
            'degraded'   => $summary['degraded'],
            'error'      => $summary['error'],
        ];

        try {
            $this->conn->insert(
                'INSERT INTO integration_log
                    (direction, operation, idempotency_key, request_payload_json,
                     response_payload_json, outcome, source_of_truth, latency_ms, error_message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'inbound',
                    'fetch_license',
                    $idempotencyKey,
                    json_encode(['page' => 1, 'page_size' => 20], JSON_UNESCAPED_UNICODE),
                    json_encode($payload, JSON_UNESCAPED_UNICODE),
                    $outcome,
                    $summary['reached'] ? 'upstream' : 'v3',
                    null,
                    $summary['error'],
                ]
            );
        } catch (\Throwable $e) {
            // Two known error modes — both are safe to swallow:
            //   1. SQLSTATE 23000 — duplicate idempotency_key (UNIQUE
            //      constraint): the same cycle already ran this minute.
            //   2. The integration_log table doesn't exist yet (pre-install
            //      or migration not applied): we still want the cycle to run.
            $this->logger->debug('logIntegrationFetch: insert skipped', [
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
