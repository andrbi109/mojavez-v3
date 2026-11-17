<?php
declare(strict_types=1);

namespace App\Modules\Core\Domain;

/**
 * LicenseFile — the issued license image (1:1 with applicants).
 *
 * Inherited from v2's `license_files` table. Stores the document_id of
 * the license image (in the documents table) and tracks download_count
 * for the "one-time download" rule (first download is free; subsequent
 * downloads require a new LicenseAccessRequest + possibly payment).
 *
 * Storage: `license_files` table.
 */
final readonly class LicenseFile
{
    public function __construct(
        public int $id,
        public int $applicantId,                // UNIQUE — 1:1 with applicants
        public int $documentId,                 // FK to documents.id
        public int $downloadCount,
        public ?string $firstAccessedAt = null,
        public string $issuedAt = '',
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (int)($row['id'] ?? 0),
            applicantId: (int)($row['applicant_id'] ?? 0),
            documentId: (int)($row['document_id'] ?? 0),
            downloadCount: (int)($row['download_count'] ?? 0),
            firstAccessedAt: isset($row['first_accessed_at']) ? (string)$row['first_accessed_at'] : null,
            issuedAt: (string)($row['issued_at'] ?? ''),
        );
    }

    /**
     * Whether this license file has been downloaded at least once
     * (the "one-time download" rule — further downloads need a new
     * LicenseAccessRequest).
     */
    public function hasBeenAccessed(): bool
    {
        return $this->downloadCount > 0;
    }
}
