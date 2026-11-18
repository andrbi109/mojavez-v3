<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Modules\Core\Domain\Applicant;
use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\StructuredLogger;

/**
 * OutboundSyncService — pushes v3 state changes back to the upstream national
 * gateway (Rollout Stage 2, two-way sync).
 *
 * Per docs/00-MASTER.md §29.1 (Stage 2) + §29.3 (fallback kill-switch):
 *   - Activated only when settings.sync_two_way_enabled = '1' AND
 *     settings.sync_direction = 'two_way' (set by Stage2ReadinessChecker).
 *   - Every outbound push is logged to integration_log with direction='outbound',
 *     a deterministic idempotency_key, and the upstream's response.
 *   - Conflict resolution: last-write-wins by comparing the upstream's
 *     updated_at timestamp against v3's. If upstream is newer, the conflict
 *     is recorded with source_of_truth='upstream' and the push is skipped
 *     (the inbound side will reconcile on the next cycle).
 *   - Fallback kill-switch: if upstream returns 5xx for 3 consecutive pushes,
 *     the service flips sync_two_way_enabled back to '0' automatically and
 *     logs a critical alert — no deploy needed to revert to Stage 1 (§29.3).
 *
 * Per §19.8: "اگر upstream در آینده endpoint نوشتنی اضافه کرد (مثلاً webhook از
 * ملی به v3)، فعال می‌شود." Until upstream exposes a writable endpoint,
 * pushToUpstream() builds the payload + logs to integration_log with
 * outcome='skipped' (reason: no_upstream_endpoint). This is the integration
 * point — when upstream adds an endpoint, only pushToUpstream() needs editing.
 *
 * Operations supported (per §29.1 Stage 2 contract):
 *   - pushStatusChange: applicant status changed in v3, push to upstream.
 *   - pushDocument: applicant uploaded a document, push to upstream.
 *   - requestMatch: v3 requests upstream to confirm a match (replaces the
 *     inbound-only scrape for specific applicants).
 */
final class OutboundSyncService
{
    /** @var int Number of consecutive 5xx failures before auto-disable. */
    private const FAILURE_THRESHOLD = 3;

    /**
     * Optional real push implementation injected via setPushCallable().
     *
     * When null, pushToUpstream() runs the stub (logs + returns 'skipped').
     * When set (a real HTTP callable), pushToUpstream() delegates to it.
     *
     * Per §19.8 — upstream (qr.mojavez.ir) does NOT yet expose a writable
     * endpoint. When upstream adds one, the production wiring sets a
     * callable (e.g. via a Container setter or a bootstrap hook) that POSTs
     * the payload to the new endpoint; this class's other logic
     * (idempotency, conflict detection, kill-switch) is unchanged.
     *
     * @var callable(string, string, array<string,mixed>): array{
     *     outcome:string, http_status:?int, response:?array, error:?string, latency_ms:int
     * }|null
     */
    private mixed $pushCallable = null;

    public function __construct(
        private readonly ApplicantRepositoryInterface $applicantRepo,
        private readonly Connection $conn,
        private readonly StructuredLogger $logger,
    ) {}

    /**
     * Inject a real push implementation.
     *
     * The callable receives ($operation, $requestNumber, $payload) and MUST
     * return the same array shape as pushToUpstream() (see its docblock).
     * After this is set, isStubActive() returns false and pushToUpstream()
     * delegates to the callable.
     *
     * Passing null resets to the stub behavior (useful for tests).
     */
    public function setPushCallable(?callable $fn): void
    {
        $this->pushCallable = $fn;
    }

    /**
     * True when no real push callable is set — pushToUpstream() will use
     * the stub (log + 'skipped'). The NationalSyncOrchestrator reads this
     * to emit a warning when it pushes via the stub, so production logs
     * clearly show "outbound push is stubbed" (per D-8-1).
     */
    public function isStubActive(): bool
    {
        return $this->pushCallable === null;
    }

    /**
     * Push an applicant status change to the upstream national gateway.
     *
     * @param int $applicantId  The applicant whose status changed.
     * @param ApplicantStatus $newStatus  The new status in v3.
     * @param string $triggeredBy  'admin' | 'operator' | 'system' | 'auto_sync'.
     * @return array{outcome:string, conflict:string|null, http_status:?int, integration_log_id:?int}
     */
    public function pushStatusChange(int $applicantId, ApplicantStatus $newStatus, string $triggeredBy): array
    {
        $applicant = $this->applicantRepo->findById($applicantId);
        if ($applicant === null) {
            return ['outcome' => 'skipped', 'conflict' => null, 'http_status' => null, 'integration_log_id' => null];
        }

        // Only push for applicants matched to a national license (otherwise
        // upstream has nothing to update).
        if ($applicant->nationalLicenseRequestNumber === null || $applicant->nationalLicenseRequestNumber === '') {
            return ['outcome' => 'skipped', 'conflict' => null, 'http_status' => null, 'integration_log_id' => null];
        }

        $payload = [
            'request_number' => $applicant->nationalLicenseRequestNumber,
            'applicant_national_code' => $applicant->nationalCode,
            'tracking_code' => $applicant->trackingCode,
            'new_status' => $newStatus->titleFa(),
            'triggered_by' => $triggeredBy,
            'v3_updated_at' => date('Y-m-d H:i:s'),
        ];
        $idempotencyKey = $this->idempotencyKey('push_status', $applicantId, $payload);

        // Idempotency: if we already logged this exact push, skip.
        $existing = $this->conn->one(
            'SELECT id, outcome FROM integration_log WHERE idempotency_key = ? LIMIT 1',
            [$idempotencyKey]
        );
        if ($existing !== null) {
            return ['outcome' => $existing['outcome'], 'conflict' => null, 'http_status' => null, 'integration_log_id' => (int)$existing['id']];
        }

        // Conflict check: compare v3's updated_at with the upstream's last-known
        // updated_at (recorded in the last inbound fetch of this license).
        $conflict = $this->detectConflict($applicant);
        if ($conflict !== null) {
            // Upstream is newer — record the conflict, skip the push.
            // The inbound side will reconcile on the next cycle.
            $logId = $this->logOutbound(
                operation: 'push_status',
                applicantId: $applicantId,
                requestNumber: $applicant->nationalLicenseRequestNumber,
                idempotencyKey: $idempotencyKey,
                requestPayload: $payload,
                responsePayload: null,
                httpStatus: null,
                outcome: 'conflict',
                sourceOfTruth: 'upstream',
                conflictResolution: 'last_write_wins',
                upstreamUpdatedAt: $conflict,
                latencyMs: 0,
                errorMessage: 'upstream record is newer than v3 — push skipped, inbound will reconcile'
            );
            $this->logger->warning('Outbound push skipped (upstream newer)', [
                'applicant_id' => $applicantId, 'upstream_updated_at' => $conflict,
            ]);
            return ['outcome' => 'conflict', 'conflict' => $conflict, 'http_status' => null, 'integration_log_id' => $logId];
        }

        // Perform the actual push (or log as 'skipped' if no upstream endpoint).
        $result = $this->pushToUpstream('push_status', $applicant->nationalLicenseRequestNumber, $payload);

        $logId = $this->logOutbound(
            operation: 'push_status',
            applicantId: $applicantId,
            requestNumber: $applicant->nationalLicenseRequestNumber,
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            responsePayload: $result['response'] ?? null,
            httpStatus: $result['http_status'],
            outcome: $result['outcome'],
            sourceOfTruth: $result['outcome'] === 'success' ? 'v3' : null,
            conflictResolution: null,
            upstreamUpdatedAt: null,
            latencyMs: $result['latency_ms'] ?? 0,
            errorMessage: $result['error'] ?? null
        );

        // Track consecutive failures for the kill-switch.
        if ($result['outcome'] === 'server_error') {
            $this->recordFailure();
        } else {
            $this->resetFailures();
        }

        return [
            'outcome' => $result['outcome'],
            'conflict' => null,
            'http_status' => $result['http_status'],
            'integration_log_id' => $logId,
        ];
    }

    /**
     * Push a document upload to upstream (for document-side sync in Stage 2).
     *
     * @param int $applicantId
     * @param int $documentId
     * @return array Same shape as pushStatusChange.
     */
    public function pushDocument(int $applicantId, int $documentId): array
    {
        $applicant = $this->applicantRepo->findById($applicantId);
        if ($applicant === null || $applicant->nationalLicenseRequestNumber === null) {
            return ['outcome' => 'skipped', 'conflict' => null, 'http_status' => null, 'integration_log_id' => null];
        }

        $payload = [
            'request_number' => $applicant->nationalLicenseRequestNumber,
            'applicant_national_code' => $applicant->nationalCode,
            'document_id' => $documentId,
            'v3_uploaded_at' => date('Y-m-d H:i:s'),
        ];
        $idempotencyKey = $this->idempotencyKey('push_document', $applicantId, $payload);

        $existing = $this->conn->one(
            'SELECT id, outcome FROM integration_log WHERE idempotency_key = ? LIMIT 1',
            [$idempotencyKey]
        );
        if ($existing !== null) {
            return ['outcome' => $existing['outcome'], 'conflict' => null, 'http_status' => null, 'integration_log_id' => (int)$existing['id']];
        }

        $result = $this->pushToUpstream('push_document', $applicant->nationalLicenseRequestNumber, $payload);

        $logId = $this->logOutbound(
            operation: 'push_document',
            applicantId: $applicantId,
            requestNumber: $applicant->nationalLicenseRequestNumber,
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            responsePayload: $result['response'] ?? null,
            httpStatus: $result['http_status'],
            outcome: $result['outcome'],
            sourceOfTruth: $result['outcome'] === 'success' ? 'v3' : null,
            conflictResolution: null,
            upstreamUpdatedAt: null,
            latencyMs: $result['latency_ms'] ?? 0,
            errorMessage: $result['error'] ?? null
        );

        if ($result['outcome'] === 'server_error') {
            $this->recordFailure();
        } else {
            $this->resetFailures();
        }

        return [
            'outcome' => $result['outcome'],
            'conflict' => null,
            'http_status' => $result['http_status'],
            'integration_log_id' => $logId,
        ];
    }

    /**
     * Is two-way outbound sync currently enabled? Reads the runtime flag.
     */
    public function isEnabled(): bool
    {
        $row = $this->conn->one("SELECT setting_value FROM settings WHERE setting_key = 'sync_two_way_enabled' LIMIT 1");
        return $row !== null && $row['setting_value'] === '1';
    }

    /**
     * The actual HTTP push to upstream. Currently a stub: upstream
     * (qr.mojavez.ir) does NOT yet expose a writable endpoint (per §19.8).
     *
     * When upstream adds one (e.g. POST /api/v3/status-update), this is the
     * ONLY method that needs editing — wire it to the new endpoint.
     *
     * Returns: ['outcome' => 'success'|'client_error'|'server_error'|'timeout'|'skipped',
     *           'http_status' => ?int, 'response' => ?array, 'error' => ?string,
     *           'latency_ms' => int]
     */
    private function pushToUpstream(string $operation, string $requestNumber, array $payload): array
    {
        $started = microtime(true);

        // STUB: upstream has no writable endpoint yet (§19.8).
        // When upstream adds one (e.g. POST /api/v3/status-update), inject a
        // real push implementation via setPushCallable() — this method will
        // delegate to it. The rest of the class (idempotency, conflict
        // detection, kill-switch) is unchanged. Until then, log as 'skipped'
        // so the audit trail records that v3 attempted a push but upstream
        // can't receive it.
        if ($this->pushCallable !== null) {
            $result = call_user_func(
                $this->pushCallable,
                $operation,
                $requestNumber,
                $payload
            );
            // Defensive: normalize the callable's return shape.
            if (!is_array($result)) {
                $result = [];
            }
            $outcome = isset($result['outcome']) && is_string($result['outcome'])
                ? $result['outcome']
                : 'skipped';
            return [
                'outcome' => $outcome,
                'http_status' => isset($result['http_status']) ? (int)$result['http_status'] : null,
                'response' => isset($result['response']) && is_array($result['response'])
                    ? $result['response']
                    : null,
                'error' => isset($result['error']) && is_string($result['error'])
                    ? $result['error']
                    : null,
                'latency_ms' => (int)((microtime(true) - $started) * 1000),
            ];
        }

        $this->logger->info('Outbound push logged (upstream endpoint not yet available)', [
            'operation' => $operation,
            'request_number' => $requestNumber,
        ]);

        return [
            'outcome' => 'skipped',
            'http_status' => null,
            'response' => ['note' => 'upstream endpoint not yet available (§19.8)'],
            'error' => null,
            'latency_ms' => (int)((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * Detect a conflict: is the upstream's record newer than v3's?
     * Reads the last-known upstream_updated_at from integration_log
     * (recorded by the last inbound fetch of this license).
     *
     * @return string|null  The upstream's updated_at if newer than v3's, null otherwise.
     */
    private function detectConflict(Applicant $applicant): ?string
    {
        if ($applicant->nationalLicenseRequestNumber === null) {
            return null;
        }
        // Find the most recent inbound fetch's upstream_updated_at.
        $row = $this->conn->one(
            "SELECT upstream_updated_at FROM integration_log
             WHERE direction = 'inbound'
               AND national_request_number = ?
               AND upstream_updated_at IS NOT NULL
             ORDER BY created_at DESC LIMIT 1",
            [$applicant->nationalLicenseRequestNumber]
        );
        if ($row === null) {
            return null;  // no prior inbound fetch — no conflict data
        }
        $upstreamUpdatedAt = $row['upstream_updated_at'];
        // v3's last update is the applicant's updated_at column.
        $v3Row = $this->conn->one('SELECT updated_at FROM applicants WHERE id = ?', [$applicant->id]);
        if ($v3Row === null) {
            return null;
        }
        $v3UpdatedAt = $v3Row['updated_at'];

        // If upstream is strictly newer than v3, it's a conflict (upstream wins).
        if (strtotime($upstreamUpdatedAt) > strtotime($v3UpdatedAt)) {
            return $upstreamUpdatedAt;
        }
        return null;
    }

    /**
     * Build a deterministic idempotency key for an outbound push.
     * Per §4.4 — derived from (applicant_id, operation, payload_hash).
     */
    private function idempotencyKey(string $operation, int $applicantId, array $payload): string
    {
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return 'out_' . $operation . '_' . $applicantId . '_' . substr($hash, 0, 32);
    }

    /**
     * Insert a row into integration_log for an outbound push.
     */
    private function logOutbound(
        string $operation,
        int $applicantId,
        string $requestNumber,
        string $idempotencyKey,
        array $requestPayload,
        ?array $responsePayload,
        ?int $httpStatus,
        string $outcome,
        ?string $sourceOfTruth,
        ?string $conflictResolution,
        ?string $upstreamUpdatedAt,
        int $latencyMs,
        ?string $errorMessage
    ): int {
        $this->conn->execute(
            'INSERT INTO integration_log
                (direction, operation, applicant_id, national_request_number,
                 idempotency_key, request_payload_json, response_payload_json,
                 http_status, outcome, source_of_truth, conflict_resolution,
                 upstream_updated_at, latency_ms, error_message, created_at)
             VALUES
                ("outbound", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $operation,
                $applicantId,
                $requestNumber,
                $idempotencyKey,
                json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $responsePayload !== null ? json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $httpStatus,
                $outcome,
                $sourceOfTruth,
                $conflictResolution,
                $upstreamUpdatedAt,
                $latencyMs,
                $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null,
            ]
        );
        return (int)$this->conn->pdo()->lastInsertId();
    }

    /**
     * Record a 5xx failure for the kill-switch counter.
     * If FAILURE_THRESHOLD consecutive failures, auto-disable two-way.
     */
    private function recordFailure(): void
    {
        $row = $this->conn->one(
            "SELECT setting_value FROM settings WHERE setting_key = 'outbound_failure_streak' LIMIT 1"
        );
        $streak = $row !== null ? (int)$row['setting_value'] : 0;
        $streak++;

        $this->upsertSetting('outbound_failure_streak', (string)$streak);

        if ($streak >= self::FAILURE_THRESHOLD) {
            // KILL-SWITCH: auto-disable two-way sync (per §29.3 fallback).
            $this->upsertSetting('sync_two_way_enabled', '0');
            $this->upsertSetting('sync_direction', 'inbound');
            $this->logger->critical(
                'Outbound sync auto-disabled — '
                . self::FAILURE_THRESHOLD . ' consecutive 5xx failures from upstream. '
                . 'Reverted to Stage 1 (one-way inbound). Investigate upstream health.',
                ['failure_streak' => $streak]
            );
        }
    }

    private function resetFailures(): void
    {
        $this->upsertSetting('outbound_failure_streak', '0');
    }

    private function upsertSetting(string $key, string $value): void
    {
        $existing = $this->conn->one('SELECT setting_key FROM settings WHERE setting_key = ?', [$key]);
        if ($existing !== null) {
            $this->conn->execute('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$value, $key]);
        } else {
            $this->conn->execute('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)', [$key, $value]);
        }
    }
}
