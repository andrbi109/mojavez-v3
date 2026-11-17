<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\NationalGateway\Domain\License;
use App\Modules\NationalGateway\Domain\LicenseCollection;

/**
 * Repository contract for national license cache.
 *
 * Stores licenses fetched from the upstream national gateway
 * (qr.mojavez.ir) in the `national_licenses` table. The golden-link
 * orchestrator (Phase 4) reads from here via the PatternMatcher.
 */
interface NationalLicenseRepositoryInterface
{
    public function save(License $license, ?array $rawRow = null, ?array $filterContext = null): void;

    /**
     * @param array<int,License> $licenses
     */
    public function saveMany(array $licenses, ?array $rawRows = null, ?array $filterContext = null): int;

    public function findByRequestNumber(string $requestNumber): ?License;

    /**
     * Query the local cache with the same filter shape used upstream.
     *
     * @param array<string,mixed> $filter
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public function queryCached(array $filter, int $page = 1, int $pageSize = 20): array;

    public function countAll(): int;

    /**
     * @param array<string,mixed> $filter
     */
    public function countCached(array $filter): int;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function countByStatus(): array;

    public function lastSeenAt(): ?string;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function topProvinces(int $limit = 10): array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function topOrganizations(int $limit = 10): array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function countByMonth(int $limit = 12): array;

    public function markSaved(string $requestNumber): bool;

    public function markUnsaved(string $requestNumber): bool;

    /**
     * @param array<string,mixed> $filter
     */
    public function markSavedByFilter(array $filter): int;

    public function countSaved(): int;

    /**
     * @param array<int,string> $requestNumbers
     * @return array<int,string>  request numbers that are already saved
     */
    public function findSavedRequestNumbers(array $requestNumbers): array;
}
