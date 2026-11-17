<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Repository contract for ServiceType entities.
 *
 * Service types are static lookup data: صنایع دستی، مشاغل خانگی مستقل،
 * پشتیبان، گردشگری، هنری. They gate which workflow statuses apply
 * (household-business-only statuses are scoped to service_type_id = 2).
 */
interface ServiceTypeRepositoryInterface
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function getActive(): array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getAll(): array;

    public function findById(int $id): ?array;

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int;

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): void;

    public function delete(int $id): bool;
}
