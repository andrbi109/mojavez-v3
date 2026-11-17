<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Shared\Contracts\ServiceTypeRepositoryInterface;

final class PdoServiceTypeRepository extends PdoRepository implements ServiceTypeRepositoryInterface
{
    public function getActive(): array
    {
        return $this->all('SELECT * FROM service_types WHERE is_active = 1 ORDER BY sort_order, id');
    }

    public function getAll(): array
    {
        return $this->all('SELECT * FROM service_types ORDER BY sort_order, id');
    }

    public function findById(int $id): ?array
    {
        return $this->one('SELECT * FROM service_types WHERE id = ? LIMIT 1', [$id]);
    }

    public function create(array $data): int
    {
        return (int)$this->insert(
            'INSERT INTO service_types (title, authority, description, is_active, sort_order) VALUES (?, ?, ?, ?, ?)',
            [
                $data['title'],
                $data['authority'] ?? null,
                $data['description'] ?? null,
                $data['is_active'] ?? 1,
                $data['sort_order'] ?? 0,
            ]
        );
    }

    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE service_types SET title = ?, authority = ?, description = ?, is_active = ?, sort_order = ? WHERE id = ?',
            [
                $data['title'],
                $data['authority'] ?? null,
                $data['description'] ?? null,
                $data['is_active'] ?? 1,
                $data['sort_order'] ?? 0,
                $id,
            ]
        );
    }

    public function delete(int $id): bool
    {
        return $this->execute('DELETE FROM service_types WHERE id = ?', [$id]) > 0;
    }
}
