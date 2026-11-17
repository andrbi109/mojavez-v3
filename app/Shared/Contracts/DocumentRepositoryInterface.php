<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Core\Domain\Document;

/**
 * Repository contract for Document entities (uploaded files, 1:N with applicants).
 */
interface DocumentRepositoryInterface
{
    public function findById(int $id): ?Document;

    /**
     * @return array<int,Document>
     */
    public function findByApplicantId(int $applicantId): array;

    /**
     * Create a new Document row.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int;

    public function delete(int $id): bool;
}
