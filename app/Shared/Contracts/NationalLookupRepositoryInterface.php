<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Repository contract for upstream lookup data (provinces, townships,
 * organizations) cached locally in the `national_lookups` table.
 *
 * The MojavecClient fetches these once and LookupRepository caches them
 * for 24h (CACHE_TTL_LOOKUPS).
 */
interface NationalLookupRepositoryInterface
{
    /**
     * @param array<int,array{id:string,title:string}> $provinces
     */
    public function refreshProvinces(array $provinces): int;

    /**
     * @param array<int,array{id:string,title:string}> $townships
     */
    public function refreshTownships(int $provinceId, array $townships): int;

    /**
     * @return array<int,array{id:string,title:string}>
     */
    public function townships(?string $provinceId = null): array;

    /**
     * @param array<int,array{id:string,title:string}> $main
     * @param array<int,array{id:string,title:string}> $sub
     */
    public function refreshOrganizations(array $main, array $sub = []): int;

    /**
     * @return array<int,array{id:string,title:string}>
     */
    public function provinces(): array;

    /**
     * @return array<int,array{id:string,title:string}>
     */
    public function mainOrganizations(): array;

    /**
     * @return array<int,array{id:string,title:string}>
     */
    public function subOrganizations(?string $parentCode = null): array;

    /**
     * @param array<int,array{id:string,title:string}> $sub
     */
    public function refreshSubOrganizations(string $mainOrgCode, array $sub): int;

    public function lastRefreshedAt(string $kind): ?string;

    public function setting(string $key, ?string $default = null): ?string;

    public function setSetting(string $key, string $value): void;
}
