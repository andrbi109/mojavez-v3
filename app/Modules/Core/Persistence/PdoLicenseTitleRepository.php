<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Shared\Contracts\LicenseTitleRepositoryInterface;

final class PdoLicenseTitleRepository extends PdoRepository implements LicenseTitleRepositoryInterface
{
    public function groupedActiveByService(?int $serviceTypeId): array
    {
        if ($serviceTypeId === null) {
            $rows = $this->fetchAll(
                'SELECT * FROM license_titles WHERE is_active = 1 ORDER BY sort_order, title'
            );
        } else {
            $rows = $this->fetchAll(
                'SELECT * FROM license_titles WHERE is_active = 1 AND service_type_id = ? ORDER BY sort_order, title',
                [$serviceTypeId]
            );
        }
        $out = [];
        foreach ($rows as $r) {
            $category = $r['category'] ?? 'عمومی';
            $out[$category][] = $r;
        }
        return $out;
    }

    public function getAll(?int $serviceTypeId = null): array
    {
        if ($serviceTypeId === null) {
            return $this->fetchAll('SELECT * FROM license_titles ORDER BY sort_order, title');
        }
        return $this->fetchAll(
            'SELECT * FROM license_titles WHERE service_type_id = ? ORDER BY sort_order, title',
            [$serviceTypeId]
        );
    }

    public function getCategories(?int $serviceTypeId = null): array
    {
        if ($serviceTypeId === null) {
            $rows = $this->fetchAll('SELECT DISTINCT category FROM license_titles WHERE category IS NOT NULL ORDER BY category');
        } else {
            $rows = $this->fetchAll(
                'SELECT DISTINCT category FROM license_titles WHERE service_type_id = ? AND category IS NOT NULL ORDER BY category',
                [$serviceTypeId]
            );
        }
        return array_map(static fn (array $r): string => (string)$r['category'], $rows);
    }

    public function findById(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM license_titles WHERE id = ? LIMIT 1', [$id]);
    }

    public function isTitleUniqueInService(string $title, ?int $serviceTypeId, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            $count = $this->fetchScalar(
                'SELECT COUNT(*) FROM license_titles WHERE title = ? AND (service_type_id = ? OR (? IS NULL AND service_type_id IS NULL))',
                [$title, $serviceTypeId, $serviceTypeId]
            );
        } else {
            $count = $this->fetchScalar(
                'SELECT COUNT(*) FROM license_titles WHERE title = ? AND (service_type_id = ? OR (? IS NULL AND service_type_id IS NULL)) AND id != ?',
                [$title, $serviceTypeId, $serviceTypeId, $exceptId]
            );
        }
        return (int)$count === 0;
    }

    public function create(array $data): int
    {
        return (int)$this->insert(
            'INSERT INTO license_titles (service_type_id, category, title, is_active, sort_order) VALUES (?, ?, ?, ?, ?)',
            [
                $data['service_type_id'] ?? null,
                $data['category'] ?? null,
                $data['title'],
                $data['is_active'] ?? 1,
                $data['sort_order'] ?? 0,
            ]
        );
    }

    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE license_titles
             SET service_type_id = ?, category = ?, title = ?, is_active = ?, sort_order = ?
             WHERE id = ?',
            [
                $data['service_type_id'] ?? null,
                $data['category'] ?? null,
                $data['title'],
                $data['is_active'] ?? 1,
                $data['sort_order'] ?? 0,
                $id,
            ]
        );
    }

    public function delete(int $id): bool
    {
        return $this->execute('DELETE FROM license_titles WHERE id = ?', [$id]) > 0;
    }
}
