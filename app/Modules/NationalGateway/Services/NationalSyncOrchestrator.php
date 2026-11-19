<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Events\EventBus;
use App\Events\Events\ApplicantMatched;
use App\Events\Events\SyncCycleCompleted;
use App\Shared\Contracts\NationalLicenseRepositoryInterface;
use App\Shared\Contracts\SyncAuditLogRepositoryInterface;
use App\Shared\Persistence\Connection;
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
    public function __construct(
        private readonly LicenseService $licenseService,
        private readonly ApplicantSyncAdapter $adapter,
        private readonly NationalLicenseRepositoryInterface $licenseRepo,
        private readonly SyncAuditLogRepositoryInterface $auditRepo,
        private readonly EventBus $eventBus,
        private readonly StructuredLogger $logger,
        private readonly Connection $conn,
        private readonly ?OutboundSyncService $outboundService = null,
    ) {}

    /**
     * Run one full sync cycle. Returns a summary for the admin panel.
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

        $startedAt = date('Y-m-d H:i:s');
        $this->logger->info('Sync cycle started', ['at' => $startedAt, 'direction' => $direction]);

        // §9.5 step 2: find unmatched licenses with golden-link anchors.
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
        $twoWayEnabled = $this->readSetting('sync_two_way_enabled', '0') === '1'
                         && $direction === 'two_way';
        if ($twoWayEnabled && $this->outboundService !== null && $autoConfirmed > 0) {
            $recentlyMatched = $this->conn->all(
                'SELECT id, current_status FROM applicants
                 WHERE matched_at IS NOT NULL
                   AND matched_at BETWEEN ? AND ?
                   AND national_license_request_number IS NOT NULL',
                [$startedAt, $endedAt]
            );
            foreach ($recentlyMatched as $row) {
                $status = \App\Modules\Core\Domain\Enums\ApplicantStatus::fromSlug((string)$row['current_status']);
                if ($status === null) {
                    continue;
                }
                $result = $this->outboundService->pushStatusChange(
                    (int)$row['id'],
                    $status,
                    'auto_sync'
                );
                if ($result['outcome'] === 'conflict') {
                    $outboundConflicts++;
                } elseif ($result['outcome'] === 'skipped') {
                    $outboundSkipped++;
                } else {
                    $outboundPushed++;
                }
            }
            $this->logger->info('Stage 2 outbound pushes completed', [
                'pushed' => $outboundPushed,
                'conflicts' => $outboundConflicts,
                'skipped' => $outboundSkipped,
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
            'two_way_enabled' => $twoWayEnabled,
        ]));

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
            'two_way_enabled' => $twoWayEnabled,
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
            'two_way_enabled' => $twoWayEnabled,
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
}
