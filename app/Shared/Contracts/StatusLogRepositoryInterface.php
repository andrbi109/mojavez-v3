<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Core\Domain\StatusLogEntry;

/**
 * Repository contract for the status_log table (workflow history).
 *
 * Each call to ApplicantRepositoryInterface::changeStatus() inserts a row
 * here with the appropriate triggered_by value.
 */
interface StatusLogRepositoryInterface
{
    /**
     * @return array<int,StatusLogEntry>
     */
    public function findByApplicantId(int $applicantId): array;

    public function getStatusDate(int $applicantId, string $statusName): ?string;

    /**
     * Insert a new status_log entry.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int;

    /**
     * Recent auto_sync transitions (used by the /admin/sync/status panel).
     *
     * @return array<int,StatusLogEntry>
     */
    public function recentAutoSync(int $limit = 50): array;
}
