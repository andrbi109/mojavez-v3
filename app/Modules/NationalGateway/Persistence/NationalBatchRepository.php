<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Persistence;

use App\Shared\Contracts\NationalBatchRepositoryInterface;
use App\Shared\Persistence\Connection;

/**
 * PDO implementation of NationalBatchRepositoryInterface.
 *
 * Tracks CSV batch import jobs in the `national_batches` table. Each row:
 * original_filename + total/processed/matched counts + csv_path + results_json
 * (LONGTEXT, appended per row) + status (pending/processing/completed/failed)
 * + error.
 *
 * Per docs/00-MASTER.md §13.4 — the Worker calls:
 *   create()        → INSERT with status='pending'
 *   updateProgress() / incrementProgress() → atomic count bumps
 *   appendResult()  → append to results_json (LONGTEXT, never UPDATEd in place)
 *   complete() / fail() → finalize status
 */
final class NationalBatchRepository implements NationalBatchRepositoryInterface
{
    public function __construct(private readonly Connection $conn) {}

    public function create(string $originalFilename, int $totalRows, string $csvPath): int
    {
        $id = $this->conn->insert(
            'INSERT INTO national_batches (original_filename, total_rows, csv_path, status) VALUES (?, ?, ?, ?)',
            [$originalFilename, $totalRows, $csvPath, 'pending']
        );
        return (int)$id;
    }

    public function find(int $id): ?array
    {
        return $this->conn->one('SELECT * FROM national_batches WHERE id = ?', [$id]);
    }

    public function updateProgress(int $id, int $processed, int $matched): void
    {
        $this->conn->execute(
            'UPDATE national_batches SET processed_rows = ?, matched_rows = ?, status = ? WHERE id = ?',
            [$processed, $matched, 'processing', $id]
        );
    }

    public function complete(int $id, int $processed, int $matched, ?string $resultsJson = null): void
    {
        $this->conn->execute(
            'UPDATE national_batches SET processed_rows = ?, matched_rows = ?, status = ?, completed_at = NOW()'
            . ($resultsJson !== null ? ', results_json = ?' : '') . ' WHERE id = ?',
            $resultsJson !== null
                ? [$processed, $matched, 'completed', $resultsJson, $id]
                : [$processed, $matched, 'completed', $id]
        );
    }

    public function fail(int $id, string $error): void
    {
        $this->conn->execute(
            'UPDATE national_batches SET status = ?, error = ?, completed_at = NOW() WHERE id = ?',
            ['failed', mb_substr($error, 0, 65535), $id]
        );
    }

    public function incrementProgress(int $id, int $matchedDelta): void
    {
        // Atomic increment — separate statements because MariaDB doesn't
        // support incrementing + status flip in one prepared statement cleanly.
        $this->conn->execute(
            'UPDATE national_batches SET processed_rows = processed_rows + 1, matched_rows = matched_rows + ?, status = ? WHERE id = ?',
            [$matchedDelta, 'processing', $id]
        );
    }

    public function appendResult(int $id, array $result): void
    {
        // Read-append-write on results_json. For high-throughput batches a
        // JSON_APPEND function would be better, but MySQL JSON_APPEND works
        // on JSON arrays not LONGTEXT. We use read-modify-write here.
        $row = $this->conn->one('SELECT results_json FROM national_batches WHERE id = ?', [$id]);
        $existing = $row === null ? '[]' : (string)($row['results_json'] ?? '[]');
        $arr = json_decode($existing, true);
        if (!is_array($arr)) {
            $arr = [];
        }
        $arr[] = $result;
        $this->conn->execute(
            'UPDATE national_batches SET results_json = ? WHERE id = ?',
            [json_encode($arr, JSON_UNESCAPED_UNICODE), $id]
        );
    }

    public function markCompleted(int $id): void
    {
        $this->conn->execute(
            'UPDATE national_batches SET status = ?, completed_at = NOW() WHERE id = ?',
            ['completed', $id]
        );
    }

    public function recent(int $limit = 20): array
    {
        return $this->conn->all(
            'SELECT * FROM national_batches ORDER BY created_at DESC LIMIT ?',
            [$limit]
        );
    }
}
