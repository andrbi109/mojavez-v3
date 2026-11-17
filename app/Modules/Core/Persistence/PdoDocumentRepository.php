<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Modules\Core\Domain\Document;
use App\Shared\Contracts\DocumentRepositoryInterface;

final class PdoDocumentRepository extends PdoRepository implements DocumentRepositoryInterface
{
    public function findById(int $id): ?Document
    {
        $row = $this->fetchOne('SELECT * FROM documents WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : Document::fromArray($row);
    }

    public function findByApplicantId(int $applicantId): array
    {
        $rows = $this->fetchAll(
            'SELECT * FROM documents WHERE applicant_id = ? ORDER BY uploaded_at DESC',
            [$applicantId]
        );
        return array_map(static fn (array $r): Document => Document::fromArray($r), $rows);
    }

    public function create(array $data): int
    {
        return (int)$this->insert(
            'INSERT INTO documents (applicant_id, doc_type, file_name, original_name, file_path) VALUES (?, ?, ?, ?, ?)',
            [
                $data['applicant_id'],
                $data['doc_type'],
                $data['file_name'],
                $data['original_name'],
                $data['file_path'],
            ]
        );
    }

    public function delete(int $id): bool
    {
        return $this->execute('DELETE FROM documents WHERE id = ?', [$id]) > 0;
    }
}
