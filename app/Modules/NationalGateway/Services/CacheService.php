<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Shared\Support\StructuredLogger;

/**
 * File-based cache for upstream responses.
 *
 * Stores JSON responses keyed by filter hash. TTL configurable per-kind:
 *   - search: 30 minutes
 *   - lookups: 24 hours
 */
final class CacheService
{
    private const TTL_SEARCH = 1800;        // 30 minutes
    private const TTL_LOOKUPS = 86400;      // 24 hours

    public function __construct(
        private readonly string $cacheDir,
        private readonly StructuredLogger $logger
    ) {
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
    }

    public function getSearch(string $filterHash): ?array
    {
        return $this->get($this->searchKey($filterHash), self::TTL_SEARCH);
    }

    public function setSearch(string $filterHash, array $data): void
    {
        $this->set($this->searchKey($filterHash), $data);
    }

    public function invalidateSearch(string $filterHash): void
    {
        $this->delete($this->searchKey($filterHash));
        // Also drop any page-scoped search cache entries for this filter.
        // Page entries are stored as "search_<hash>__p<page>"; we keep a small
        // index of which pages we cached so we can invalidate without scanning
        // the whole cache directory.
        $index = $this->get('search_pages_' . $filterHash, self::TTL_SEARCH) ?? [];
        if (is_array($index)) {
            foreach ($index as $p) {
                $this->delete('search_' . $filterHash . '__p' . $p);
            }
            $this->delete('search_pages_' . $filterHash);
        }
        // Drop the remembered upstream total too — the next search will
        // re-fetch it fresh.
        $this->delete($this->filterTotalKey($filterHash));
    }

    /**
     * Page-scoped search cache. The filter hash (Filter::cacheKey) excludes
     * page/pageSize so the same filter across different pages shares a hash —
     * but each page's items differ. The old single-slot setSearch/getSearch
     * stored ONLY one page per filter, so a page-2 request HIT on the page-1
     * cache and returned page-1 items for page 2. This page-aware variant
     * stores each page under "search_<hash>__p<page>".
     */
    public function getSearchPage(string $filterHash, int $page): ?array
    {
        return $this->get('search_' . $filterHash . '__p' . $page, self::TTL_SEARCH);
    }

    public function setSearchPage(string $filterHash, int $page, array $data): void
    {
        $this->set('search_' . $filterHash . '__p' . $page, $data);
        // Track this page in the filter's page-index so invalidateSearch can
        // find and clear it.
        $index = $this->get('search_pages_' . $filterHash, self::TTL_SEARCH) ?? [];
        if (!is_array($index)) {
            $index = [];
        }
        if (!in_array($page, $index, true)) {
            $index[] = $page;
            $this->set('search_pages_' . $filterHash, $index);
        }
    }

    public function getLookups(string $kind): ?array
    {
        return $this->get($this->lookupsKey($kind), self::TTL_LOOKUPS);
    }

    public function setLookups(string $kind, array $data): void
    {
        $this->set($this->lookupsKey($kind), $data);
    }

    /**
     * Remember the upstream TOTAL for a given filter (page-independent — keyed
     * by the filter hash, which excludes page/pageSize).
     *
     * This is what fixes the pagination bug: when a user searches a filter
     * that returns upstream total=239 (12 pages), then paginates to page 2,
     * searchHybrid may serve page 2 from the local cache (which holds only
     * the rows cached so far). The local count is NOT 239 — it's whatever was
     * cached. Without remembering the upstream total, the paginator would
     * recompute "total = localCount" and shrink from "12 pages" to "2 pages",
     * confusing the user. By remembering the real upstream total, the
     * paginator stays consistent across pages.
     *
     * Returns null when the total is unknown (e.g. upstream never fetched for
     * this filter) — the caller should then fall back to the local count.
     */
    public function getFilterTotal(string $filterHash): ?int
    {
        $data = $this->get($this->filterTotalKey($filterHash), self::TTL_SEARCH);
        if (is_array($data) && isset($data['total']) && is_int($data['total'])) {
            return $data['total'];
        }
        return null;
    }

    public function setFilterTotal(string $filterHash, int $total): void
    {
        $this->set($this->filterTotalKey($filterHash), ['total' => $total]);
    }

    private function filterTotalKey(string $filterHash): string
    {
        return 'filter_total_' . $filterHash;
    }

    private function searchKey(string $filterHash): string
    {
        return 'search_' . $filterHash;
    }

    private function lookupsKey(string $kind): string
    {
        return 'lookups_' . $kind;
    }

    private function get(string $key, int $ttl): ?array
    {
        $file = $this->path($key);
        if (!is_file($file)) return null;
        if (time() - filemtime($file) > $ttl) {
            @unlink($file);
            return null;
        }
        $data = file_get_contents($file);
        if ($data === false) return null;
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function set(string $key, array $data): void
    {
        $file = $this->path($key);
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function delete(string $key): void
    {
        $file = $this->path($key);
        if (is_file($file)) @unlink($file);
    }

    private function path(string $key): string
    {
        return $this->cacheDir . '/' . preg_replace('/[^a-zA-Z0-9_]/', '_', $key) . '.json';
    }

    public function clearAll(): int
    {
        $count = 0;
        $files = glob($this->cacheDir . '/*.json') ?: [];
        foreach ($files as $f) {
            if (@unlink($f)) $count++;
        }
        $this->logger->info('Cache cleared', ['files' => $count]);
        return $count;
    }
}
