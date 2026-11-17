<?php
declare(strict_types=1);

namespace App\Modules\Core\Domain;

/**
 * Document — an uploaded file attached to an applicant (1:N).
 *
 * Inherited from v2's `documents` table. The file_path is RELATIVE to
 * UPLOAD_DIR (e.g. `/HG-ABC12345/national_card_front_a1b2c3.jpg`). The
 * full path is `UPLOAD_DIR . file_path`. Files are served only through
 * DocumentController with requireLogin() + ownership check.
 *
 * Storage: `documents` table.
 */
final readonly class Document
{
    public function __construct(
        public int $id,
        public int $applicantId,
        public string $docType,                  // 'national_card_front', 'license_image', ...
        public string $fileName,                 // stored filename (with rand6)
        public string $originalName,             // user's filename
        public string $filePath,                 // relative path under UPLOAD_DIR
        public string $uploadedAt = '',
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (int)($row['id'] ?? 0),
            applicantId: (int)($row['applicant_id'] ?? 0),
            docType: (string)($row['doc_type'] ?? ''),
            fileName: (string)($row['file_name'] ?? ''),
            originalName: (string)($row['original_name'] ?? ''),
            filePath: (string)($row['file_path'] ?? ''),
            uploadedAt: (string)($row['uploaded_at'] ?? ''),
        );
    }

    /**
     * File extension (lowercase, no dot).
     */
    public function extension(): string
    {
        $dot = strrpos($this->fileName, '.');
        return $dot === false ? '' : strtolower(substr($this->fileName, $dot + 1));
    }

    /**
     * Whether this is an image file (jpg/jpeg/png).
     */
    public function isImage(): bool
    {
        return in_array($this->extension(), ['jpg', 'jpeg', 'png'], true);
    }

    /**
     * Whether this is a PDF file.
     */
    public function isPdf(): bool
    {
        return $this->extension() === 'pdf';
    }
}
