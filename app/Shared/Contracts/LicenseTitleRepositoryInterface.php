<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Repository contract for LicenseTitle entities.
 *
 * License titles are scoped to a service type (UNIQUE on
 * (service_type_id, title)). They are used by the registration wizard
 * to populate the "رشتهٔ فعالیت" dropdown.
 */
interface LicenseTitleRepositoryInterface
{
    /**
     * Grouped active titles by service type ID.
     *
     * @return array<string,array<string,mixed>>  keyed by category → list of titles
     */
    public function groupedActiveByService(?int $serviceTypeId): array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getAll(?int $serviceTypeId = null): array;

    /**
     * @return array<int,string>
     */
    public function getCategories(?int $serviceTypeId = null): array;

    public function findById(int $id): ?array;

    public function isTitleUniqueInService(string $title, ?int $serviceTypeId, ?int $exceptId = null): bool;

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
