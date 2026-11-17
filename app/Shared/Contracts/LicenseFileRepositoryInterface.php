<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Core\Domain\LicenseFile;

/**
 * Repository contract for LicenseFile entities (issued license image, 1:1
 * with applicants).
 */
interface LicenseFileRepositoryInterface
{
    public function findByApplicantId(int $applicantId): ?LicenseFile;

    public function upsert(int $applicantId, int $documentId): void;

    public function incrementDownload(int $id): void;
}
