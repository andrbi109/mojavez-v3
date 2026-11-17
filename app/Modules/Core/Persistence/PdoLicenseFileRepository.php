<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Modules\Core\Domain\LicenseFile;
use App\Shared\Contracts\LicenseFileRepositoryInterface;

final class PdoLicenseFileRepository extends PdoRepository implements LicenseFileRepositoryInterface
{
    public function findByApplicantId(int $applicantId): ?LicenseFile
    {
        $row = $this->fetchOne('SELECT * FROM license_files WHERE applicant_id = ? LIMIT 1', [$applicantId]);
        return $row === null ? null : LicenseFile::fromArray($row);
    }

    public function upsert(int $applicantId, int $documentId): void
    {
        $this->execute(
            'INSERT INTO license_files (applicant_id, document_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE document_id = VALUES(document_id)',
            [$applicantId, $documentId]
        );
    }

    public function incrementDownload(int $id): void
    {
        $this->execute(
            'UPDATE license_files
             SET download_count = download_count + 1,
                 first_accessed_at = COALESCE(first_accessed_at, NOW())
             WHERE id = ?',
            [$id]
        );
    }
}
