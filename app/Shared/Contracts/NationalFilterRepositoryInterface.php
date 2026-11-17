<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Repository contract for saved search filters (the `national_filters`
 * table — admin-defined filters persisted across sessions).
 *
 * Stored separately from the Filter value object: a saved filter has
 * an ID, label, the filter data, and run metadata (last_run_at,
 * last_result_count). The Filter VO is the immutable shape; this
 * repository persists user-defined instances.
 */
interface NationalFilterRepositoryInterface
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(): array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function active(): array;

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array;

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int;

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): void;

    public function delete(int $id): void;

    public function markRun(int $id, int $resultCount): void;
}
