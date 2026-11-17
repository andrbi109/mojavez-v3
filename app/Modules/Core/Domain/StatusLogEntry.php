<?php
declare(strict_types=1);

namespace App\Modules\Core\Domain;

/**
 * StatusLogEntry — a single status-change event in an applicant's history.
 *
 * Inherited from v2's `status_log` table, extended for v3 with the new
 * `triggered_by` field:
 *   'admin'      — admin user action
 *   'user'       — applicant self-service
 *   'system'     — system/cron (e.g. auto-cancel)
 *   'auto_sync'  — golden-link sync orchestrator (NEW in v3)
 *
 * The golden-link orchestrator sets triggered_by='auto_sync' on the row
 * it inserts when transitioning an applicant to 'صدور مجوز'.
 *
 * Storage: `status_log` table.
 */
final readonly class StatusLogEntry
{
    public function __construct(
        public int $id,
        public int $applicantId,
        public string $statusName,                // the new status (Persian)
        public ?string $description = null,
        public string $createdAt = '',
        // NEW in v3:
        public string $triggeredBy = 'admin',    // admin|user|system|auto_sync
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (int)($row['id'] ?? 0),
            applicantId: (int)($row['applicant_id'] ?? 0),
            statusName: (string)($row['status_name'] ?? ''),
            description: isset($row['description']) ? (string)$row['description'] : null,
            createdAt: (string)($row['created_at'] ?? ''),
            triggeredBy: (string)($row['triggered_by'] ?? 'admin'),
        );
    }

    /**
     * Whether this entry was created by the golden-link orchestrator.
     */
    public function isAutoSync(): bool
    {
        return $this->triggeredBy === 'auto_sync';
    }
}
