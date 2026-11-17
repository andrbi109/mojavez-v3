<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Persistence;

use App\Shared\Contracts\SyncAuditLogRepositoryInterface;
use App\Shared\Persistence\Connection;

/**
 * PDO implementation of SyncAuditLogRepositoryInterface.
 *
 * Persists every golden-link decision to `sync_audit_log` — the audit trail
 * mandated by docs/00-MASTER.md §23.5 and §9.6 safety rule 5.
 *
 * Every action the NationalSyncOrchestrator takes is recorded:
 *   - match_attempted  — a PatternMatcher run (always logged, even mismatches)
 *   - auto_confirmed    — confidence >= 90, applicant auto-transitioned
 *   - operator_flagged  — confidence 50-89, pending manual review
 *   - auto_rejected     — anchor mismatch, applicant skipped
 *   - notification_sent — SMS + in-app notification dispatched (AutoConfirmed only)
 *   - operator_resolved — an admin reviewed a pending flag and confirmed/rejected
 *
 * The /admin/sync/status panel reads from this table to show the latest
 * cycle's stats + the list of pending-operator-review items.
 */
final class SyncAuditLogRepository implements SyncAuditLogRepositoryInterface
{
    public function __construct(private readonly Connection $conn) {}

    public function log(
        int $applicantId,
        string $nationalRequestNumber,
        string $action,
        string $outcome,
        int $confidence,
        array $context = []
    ): int {
        $id = $this->conn->insert(
            'INSERT INTO sync_audit_log (applicant_id, national_request_number, action, outcome, confidence, context) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
            [
                $applicantId,
                $nationalRequestNumber,
                $action,
                $outcome,
                $confidence,
                json_encode($context, JSON_UNESCAPED_UNICODE),
            ]
        );
        return (int)$id;
    }

    public function recentForApplicant(int $applicantId, int $limit = 20): array
    {
        return $this->conn->all(
            'SELECT * FROM sync_audit_log WHERE applicant_id = ? ORDER BY created_at DESC LIMIT ?',
            [$applicantId, $limit]
        );
    }

    public function pendingOperatorReview(int $limit = 50): array
    {
        return $this->conn->all(
            "SELECT s.* FROM sync_audit_log s "
            . "WHERE s.action = 'operator_flagged' AND s.reviewed_by IS NULL "
            . "ORDER BY s.created_at DESC LIMIT ?",
            [$limit]
        );
    }

    public function stats(): array
    {
        $rows = $this->conn->all(
            "SELECT outcome, COUNT(*) AS c FROM sync_audit_log "
            . "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) "
            . "GROUP BY outcome"
        );
        $out = ['auto_confirmed' => 0, 'operator_flag' => 0, 'auto_rejected' => 0];
        foreach ($rows as $row) {
            $outcome = (string)$row['outcome'];
            if (isset($out[$outcome])) {
                $out[$outcome] = (int)$row['c'];
            } elseif ($outcome === 'auto_confirmed') {
                $out['auto_confirmed'] = (int)$row['c'];
            } elseif ($outcome === 'operator_flagged') {
                $out['operator_flag'] = (int)$row['c'];
            } elseif ($outcome === 'auto_rejected') {
                $out['auto_rejected'] = (int)$row['c'];
            }
        }
        return $out;
    }

    /**
     * Resolve an operator-flagged item — admin confirms or rejects.
     */
    public function resolve(int $logId, int $reviewerId, string $decision, ?string $note = null): void
    {
        $this->conn->execute(
            'UPDATE sync_audit_log SET reviewed_by = ?, reviewed_at = NOW(), review_decision = ?, review_note = ? WHERE id = ?',
            [$reviewerId, $decision, $note, $logId]
        );
    }

    /**
     * Latest sync cycle summary for /admin/sync/status.
     *
     * @return array{cycle_started_at:?string,cycle_ended_at:?string,counts:array<string,int>}
     */
    public function latestCycleSummary(): array
    {
        $startedRow = $this->conn->one(
            "SELECT created_at FROM sync_audit_log WHERE action = 'cycle_started' ORDER BY created_at DESC LIMIT 1"
        );
        $endedRow = $this->conn->one(
            "SELECT created_at FROM sync_audit_log WHERE action = 'cycle_completed' ORDER BY created_at DESC LIMIT 1"
        );
        $counts = $this->stats();
        return [
            'cycle_started_at' => $startedRow['created_at'] ?? null,
            'cycle_ended_at' => $endedRow['created_at'] ?? null,
            'counts' => $counts,
        ];
    }
}
