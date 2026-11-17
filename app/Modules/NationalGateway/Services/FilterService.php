<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Modules\NationalGateway\Domain\Filter;
use App\Modules\NationalGateway\Infrastructure\MojavecClient;
use App\Persistence\FilterRepository;
use App\Persistence\LookupRepository;

/**
 * Manages saved filters (CRUD + lookup hydration).
 */
final class FilterService
{
    public function __construct(
        private readonly FilterRepository $filterRepo,
        private readonly LookupRepository $lookupRepo,
        private readonly MojavecClient $client
    ) {}

    public function all(): array
    {
        return $this->filterRepo->all();
    }

    public function find(int $id): ?array
    {
        return $this->filterRepo->find($id);
    }

    public function create(array $data): int
    {
        $filterArray = $data['filter'] ?? [];
        $filter = Filter::fromArray($filterArray);
        $data['filter_json'] = json_encode($filterArray, JSON_UNESCAPED_UNICODE);
        $data['main_org_code'] = $filter->mainOrgCode;
        $data['province_id'] = $filter->provinceId;
        $data['township_id'] = $filter->townshipId;
        $data['issue_start_date'] = $filter->issueStartDate;
        $data['issue_end_date'] = $filter->issueEndDate;
        return $this->filterRepo->create($data);
    }

    public function update(int $id, array $data): void
    {
        $filterArray = $data['filter'] ?? [];
        $filter = Filter::fromArray($filterArray);
        $data['filter_json'] = json_encode($filterArray, JSON_UNESCAPED_UNICODE);
        $data['main_org_code'] = $filter->mainOrgCode;
        $data['province_id'] = $filter->provinceId;
        $data['township_id'] = $filter->townshipId;
        $data['issue_start_date'] = $filter->issueStartDate;
        $data['issue_end_date'] = $filter->issueEndDate;
        $this->filterRepo->update($id, $data);
    }

    public function delete(int $id): void
    {
        $this->filterRepo->delete($id);
    }

    /**
     * Return a filter (as array) from a saved filter row.
     */
    public function toFilterArray(array $savedRow): array
    {
        $filter = json_decode((string)($savedRow['filter_json'] ?? '{}'), true);
        return is_array($filter) ? $filter : [];
    }

    /**
     * Lookups for the filter UI (provinces + main organizations).
     *
     * Cache-first. If the cache is empty (e.g. a fresh install that hasn't run
     * seed_lookups.php), fall back to a live upstream fetch so the UI is never
     * left with empty dropdowns. The fetched rows are persisted so subsequent
     * loads are fast.
     */
    public function lookups(): array
    {
        $provinces = $this->lookupRepo->provinces();
        $organizations = $this->lookupRepo->mainOrganizations();

        if (empty($provinces) && empty($organizations)) {
            // Both empty — refresh from upstream once.
            $freshProvinces = $this->client->provinces();
            $freshOrgs = $this->client->organizations();
            if (!empty($freshProvinces)) {
                $this->lookupRepo->refreshProvinces($freshProvinces);
                $provinces = $freshProvinces;
            }
            if (!empty($freshOrgs['main'])) {
                $this->lookupRepo->refreshOrganizations($freshOrgs['main'], $freshOrgs['sub']);
                $organizations = $freshOrgs['main'];
            }
        }

        return [
            'provinces' => $provinces,
            'organizations' => $organizations,
        ];
    }

    /**
     * Get townships for a province — cache-first, fall back to upstream.
     *
     * @return list<array{id:string,title:string}>
     */
    public function townships(int $provinceId): array
    {
        $cached = $this->lookupRepo->townships((string)$provinceId);
        if (!empty($cached)) {
            return $cached;
        }
        // Not cached yet — fetch from upstream and persist.
        $fetched = $this->client->townships($provinceId);
        if (!empty($fetched)) {
            $this->lookupRepo->refreshTownships($provinceId, $fetched);
        }
        return $fetched;
    }

    /**
     * Get sub-organizations for a main org — cache-first, fall back to upstream.
     *
     * @return list<array{id:string,title:string}>
     */
    public function subOrganizations(string $mainOrgCode): array
    {
        $cached = $this->lookupRepo->subOrganizations($mainOrgCode);
        if (!empty($cached)) {
            // Strip parent_code from the output (UI doesn't need it).
            return array_map(static fn(array $s) => ['id' => $s['id'], 'title' => $s['title']], $cached);
        }
        // Not cached yet — fetch from upstream and persist.
        $fetched = $this->client->subOrganizations($mainOrgCode);
        if (!empty($fetched)) {
            // Mark each sub-org with its parent so we can query later without
            // hitting upstream again.
            $withParent = array_map(
                static fn(array $s) => ['id' => $s['id'], 'title' => $s['title'], 'parent_code' => $mainOrgCode],
                $fetched
            );
            $this->lookupRepo->refreshSubOrganizations($mainOrgCode, $withParent);
        }
        return $fetched;
    }
}
