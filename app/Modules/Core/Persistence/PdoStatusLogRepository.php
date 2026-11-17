<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Modules\Core\Domain\StatusLogEntry;
use App\Shared\Contracts\StatusLogRepositoryInterface;

final class PdoStatusLogRepository extends PdoRepository implements StatusLogRepositoryInterface
{
    public function findByApplicantId(int $applicantId): array
    {
        $rows = $this->fetchAll(
            'SELECT * FROM status_log WHERE applicant_id = ? ORDER BY created_at ASC',
            [$applicantId]
        );
        return array_map(static fn (array $r): StatusLogEntry => StatusLogEntry::fromArray($r), $rows);
    }

    public function getStatusDate(int $applicantId, string $statusName): ?string
    {
        $row = $this->fetchOne(
            'SELECT created_at FROM status_log WHERE applicant_id = ? AND status_name = ? ORDER BY created_at DESC LIMIT 1',
            [$applicantId, $statusName]
        );
        return $row['created_at'] ?? null;
    }

    public function create(array $data): int
    {
        $sql = 'INSERT INTO status_log (applicant_id, status_name, description, triggered_by) VALUES (?, ?, ?, ?)';
        return (int)$this->insert($sql, [
            $data['applicant_id'],
            $data['status_name'],
            $data['description'] ?? null,
            $data['triggered_by'] ?? 'admin',
        ]);
    }

    public function recentAutoSync(int $limit = 50): array
    {
        $rows = $this->fetchAll(
            'SELECT * FROM status_log WHERE triggered_by = ? ORDER BY created_at DESC LIMIT ?',
            ['auto_sync', $limit]
        );
        return array_map(static fn (array $r): StatusLogEntry => StatusLogEntry::fromArray($r), $rows);
    }
}
