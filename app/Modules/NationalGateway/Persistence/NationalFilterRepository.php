<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Persistence;

use App\Shared\Contracts\NationalFilterRepositoryInterface;
use App\Shared\Persistence\Connection;

/**
 * PDO implementation of NationalFilterRepositoryInterface.
 *
 * Persists admin-defined search filter presets in the `national_filters`
 * table. Each row: label + filter_data (JSON) + last_run_at + last_result_count.
 */
final class NationalFilterRepository implements NationalFilterRepositoryInterface
{
    public function __construct(private readonly Connection $conn) {}

    public function all(): array
    {
        return $this->conn->all('SELECT * FROM national_filters ORDER BY created_at DESC');
    }

    public function active(): array
    {
        return $this->conn->all('SELECT * FROM national_filters WHERE is_active = 1 ORDER BY last_run_at DESC');
    }

    public function find(int $id): ?array
    {
        return $this->conn->one('SELECT * FROM national_filters WHERE id = ?', [$id]);
    }

    public function create(array $data): int
    {
        $id = $this->conn->insert(
            'INSERT INTO national_filters (label, filter_data, is_active) VALUES (?, ?, ?)',
            [
                (string)$data['label'],
                json_encode($data['filter_data'] ?? [], JSON_UNESCAPED_UNICODE),
                isset($data['is_active']) ? (int)$data['is_active'] : 1,
            ]
        );
        return (int)$id;
    }

    public function update(int $id, array $data): void
    {
        $sets = [];
        $params = [];
        if (isset($data['label'])) {
            $sets[] = 'label = ?';
            $params[] = (string)$data['label'];
        }
        if (isset($data['filter_data'])) {
            $sets[] = 'filter_data = ?';
            $params[] = json_encode($data['filter_data'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['is_active'])) {
            $sets[] = 'is_active = ?';
            $params[] = (int)$data['is_active'];
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->conn->execute(
            'UPDATE national_filters SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }

    public function delete(int $id): void
    {
        $this->conn->execute('DELETE FROM national_filters WHERE id = ?', [$id]);
    }

    public function markRun(int $id, int $resultCount): void
    {
        $this->conn->execute(
            'UPDATE national_filters SET last_run_at = NOW(), last_result_count = ? WHERE id = ?',
            [$resultCount, $id]
        );
    }
}
