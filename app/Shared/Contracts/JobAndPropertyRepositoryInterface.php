<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Core\Domain\JobDetail;
use App\Modules\Core\Domain\PropertyDetail;

/**
 * Repository contract for JobDetail and PropertyDetail entities (1:1 each
 * with applicants).
 *
 * Grouped into one interface because they are always created/updated
 * together during the registration wizard.
 */
interface JobAndPropertyRepositoryInterface
{
    // ---- JobDetail ----

    public function findJobDetailByApplicantId(int $applicantId): ?JobDetail;

    /**
     * Upsert a JobDetail row.
     *
     * @param array<string,mixed> $data
     */
    public function upsertJobDetail(int $applicantId, array $data): void;

    // ---- PropertyDetail ----

    public function findPropertyDetailByApplicantId(int $applicantId): ?PropertyDetail;

    /**
     * Upsert a PropertyDetail row.
     *
     * @param array<string,mixed> $data
     */
    public function upsertPropertyDetail(int $applicantId, array $data): void;
}
