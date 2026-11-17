<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Repository contract for the sync_audit_log table (golden-link audit trail).
 *
 * Every action the golden-link orchestrator takes — a successful auto-confirm,
 * an operator-flagged match pending review, an auto-rejected mismatch — is
 * recorded here for auditability (per docs/00-MASTER.md §23.5 Audit & Monitoring).
 *
 * Phase 3 ships the table + interface. Phase 4 wires the ApplicantSyncAdapter
 * to write to it on every golden-link action.
 */
interface SyncAuditLogRepositoryInterface
{
    /**
     * Record a sync attempt for one applicant ↔ national license pair.
     *
     * @param array<string,mixed> $context
     */
    public function log(
        int $applicantId,
        string $nationalRequestNumber,
        string $action,
        string $outcome,
        int $confidence,
        array $context = []
    ): int;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recentForApplicant(int $applicantId, int $limit = 20): array;

    /**
     * Operator-flagged matches pending review.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pendingOperatorReview(int $limit = 50): array;

    /**
     * @return array{auto_confirmed:int, operator_flag:int, auto_rejected:int}
     */
    public function stats(): array;
}
