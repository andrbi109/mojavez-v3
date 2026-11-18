<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Events\EventBus;
use App\Events\Events\CacheHit;
use App\Events\Events\CrawlerProgress;
use App\Events\Events\LicenseIssuedOnNational;
use App\Events\Events\LicenseSaved;
use App\Events\Events\SearchCompleted;
use App\Events\Events\SearchRequested;
use App\Modules\NationalGateway\Domain\Filter;
use App\Modules\NationalGateway\Domain\License;
use App\Modules\NationalGateway\Domain\LicenseCollection;
use App\Modules\NationalGateway\Infrastructure\CircuitBreaker;
use App\Modules\NationalGateway\Infrastructure\MojavecClient;
use App\Modules\NationalGateway\Infrastructure\TrackScraper;
use App\Shared\Contracts\NationalLicenseRepositoryInterface;
use App\Shared\Contracts\NationalLookupRepositoryInterface;
use App\Shared\Support\StructuredLogger;

/**
 * LicenseService — orchestrates search, cache, persistence, and events.
 *
 * This is the central service of the NationalGateway module. It wraps
 * MojavecClient + TrackScraper + CacheService + NationalLicenseRepository
 * + NationalLookupRepository + EventBus into a coherent API:
 *
 *   - searchHybrid(filter): cache-first, falls back to upstream, persists
 *     results to national_licenses + cache, dispatches events.
 *   - searchLocal(filterArray, page): local-DB-only query (no upstream).
 *   - fetchAndCacheByTrackNumber(rn): scrape /track/{rn}, cache extended
 *     detail (father name, masked national code, business address, etc.).
 *   - refreshLookups(): fetch provinces/townships/organizations from
 *     upstream and cache in national_lookups (24h TTL).
 *   - stats() + analytics(): for the admin dashboard.
 *
 * Per docs/00-MASTER.md §13.2 and §22.1 — LicenseService is the public
 * face of the gateway. Controllers and Jobs call into this service, never
 * directly into MojavecClient or TrackScraper.
 *
 * CircuitBreaker (ST1 + §23.3): searchHybrid consults the circuit breaker
 * before each upstream call. If OPEN, it serves from cache only (degraded
 * mode) and logs the skip. If HALF_OPEN, it allows one probe request.
 */
final class LicenseService
{
    public function __construct(
        private readonly MojavecClient $client,
        private readonly TrackScraper $trackScraper,
        private readonly CacheService $cache,
        private readonly NationalLicenseRepositoryInterface $licenseRepo,
        private readonly NationalLookupRepositoryInterface $lookupRepo,
        private readonly EventBus $eventBus,
        private readonly StructuredLogger $logger,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly RateLimiterService $rateLimiter,
    ) {}

    /**
     * Hybrid search: cache-first, falls back to upstream (with circuit breaker).
     *
     * @param callable(int $page, int $totalPages, int $count):void|null $progressCallback
     */
    public function searchHybrid(Filter $filter, ?callable $progressCallback = null): LicenseCollection
    {
        $start = microtime(true);
        $filterHash = $filter->cacheKey();

        $this->eventBus->dispatch(new SearchRequested([
            'filter_hash' => $filterHash,
            'filter' => $filter->describe(),
        ]));

        // --- Cache check (page-aware) ---
        $cached = $this->cache->getSearchPage($filterHash, $filter->page);
        if ($cached !== null && isset($cached['items'])) {
            $items = array_map(
                static fn (array $row): License => License::fromArray($row),
                $cached['items']
            );
            $rememberedTotal = $this->cache->getFilterTotal($filterHash);
            $collection = new LicenseCollection(
                $items,
                $cached['page'] ?? $filter->page,
                $cached['page_size'] ?? $filter->pageSize,
                $rememberedTotal ?? ($cached['total'] ?? count($items))
            );

            $this->eventBus->dispatch(new CacheHit([
                'filter_hash' => $filterHash,
                'filter' => $filter->describe(),
                'age_seconds' => 0,
            ]));
            $this->eventBus->dispatch(new SearchCompleted([
                'filter_hash' => $filterHash,
                'total' => $collection->total,
                'count' => $collection->count(),
                'elapsed' => microtime(true) - $start,
                'cached' => true,
            ]));

            if ($progressCallback !== null) {
                $progressCallback($filter->page, $collection->totalPages(), $collection->count());
            }
            return $collection;
        }

        // --- Circuit breaker check ---
        if (!$this->circuitBreaker->canProceed()) {
            $this->logger->warning('Circuit breaker OPEN — serving from cache/local only', [
                'filter' => $filter->describe(),
            ]);
            // Fall back to local DB query (degraded mode).
            return $this->searchLocal($filter->toLocalFilterArray(), $filter->page, $filter->pageSize);
        }

        // --- Upstream search with rate limiter ---
        $rateLimiter = fn (): null => $this->rateLimiter->wait();
        try {
            $collection = $this->client->search($filter, $rateLimiter);
            $this->circuitBreaker->recordSuccess();
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();
            $this->logger->error('Upstream search failed', [
                'filter' => $filter->describe(),
                'error' => $e->getMessage(),
            ]);
            // Fall back to local DB.
            return $this->searchLocal($filter->toLocalFilterArray(), $filter->page, $filter->pageSize);
        }

        if ($progressCallback !== null) {
            $progressCallback($filter->page, $collection->totalPages(), $collection->count());
        }

        // --- Persist to DB ---
        if (!$collection->isEmpty()) {
            $filterContext = $filter->toLocalFilterArray();
            $filterContext['filter_hash'] = $filterHash;
            $this->licenseRepo->saveMany($collection->items, null, $filterContext);

            // Dispatch LicenseSaved for first N (avoid event flood).
            $limit = min(count($collection->items), 20);
            for ($i = 0; $i < $limit; $i++) {
                $license = $collection->items[$i];
                $this->eventBus->dispatch(new LicenseSaved([
                    'request_number' => $license->requestNumber,
                    'applicant_name' => $license->applicantName,
                    'license_title' => $license->licenseTitle,
                    'status_slug' => $license->status->value,
                    'filter_hash' => $filterHash,
                ]));
                // §9.5 golden-link trigger: if the license is active (issued),
                // dispatch LicenseIssuedOnNational so SyncSubscriber enqueues a SyncJob.
                if ($license->status->value === 'active') {
                    $this->eventBus->dispatch(new LicenseIssuedOnNational([
                        'request_number' => $license->requestNumber,
                        'applicant_name' => $license->applicantName,
                        'license_title' => $license->licenseTitle,
                        'province' => $license->provinceTitle,
                        'township' => $license->townshipTitle,
                    ]));
                }
            }
        }

        // --- Save to cache (page-aware) ---
        $this->cache->setSearchPage($filterHash, $filter->page, [
            'items' => array_map(static fn (License $l): array => $l->toArray(), $collection->items),
            'page' => $filter->page,
            'page_size' => $filter->pageSize,
            'total' => $collection->total,
        ]);
        $this->cache->setFilterTotal($filterHash, $collection->total);

        $elapsed = microtime(true) - $start;
        $this->eventBus->dispatch(new SearchCompleted([
            'filter_hash' => $filterHash,
            'total' => $collection->total,
            'count' => $collection->count(),
            'elapsed' => $elapsed,
            'cached' => false,
        ]));

        $this->logger->info('Search upstream completed', [
            'filter' => $filter->describe(),
            'page' => $filter->page,
            'total' => $collection->total,
            'elapsed' => round($elapsed, 3),
        ]);

        return $collection;
    }

    /**
     * Local-DB-only search (no upstream call).
     *
     * @param array<string,mixed> $filterArray
     */
    public function searchLocal(array $filterArray, int $page = 1, int $pageSize = 20): LicenseCollection
    {
        $result = $this->licenseRepo->queryCached($filterArray, $page, $pageSize);
        $items = array_map(
            fn (array $row): License => $this->hydrateLicense($row),
            $result['rows']
        );
        return new LicenseCollection($items, $page, $pageSize, $result['total']);
    }

    /**
     * Force a single upstream search page (cache-skipping). Phase 42 (v2.0.8).
     *
     * Used by NationalSyncOrchestrator::runCycle() to make sure the cycle
     * actually pulls fresh data from qr.mojavez.ir rather than only ever
     * re-matching the existing local cache. The method:
     *
     *   1. Consults the circuit breaker. If OPEN, returns the local cache
     *      (degraded mode) and the caller treats the cycle as "partial".
     *   2. Calls MojavecClient::search() (which itself does retry + rate-
     *      limit respect) to fetch one page of the upstream search.
     *   3. Persists whatever it got to national_licenses (via saveMany).
     *   4. Dispatches LicenseSaved + LicenseIssuedOnNational for the
     *      golden-link trigger (same as searchHybrid).
     *
     * Returns a small summary array — the orchestrator merges this into
     * the cycle report so the admin sees whether the upstream was
     * actually reached or the cycle ran in degraded (cache-only) mode.
     *
     * @return array{
     *   reached:bool,
     *   fetched:int,
     *   total:?int,
     *   degraded:bool,
     *   error:?string,
     * }
     */
    public function searchUpstream(Filter $filter): array
    {
        $summary = [
            'reached'  => false,
            'fetched'  => 0,
            'total'    => null,
            'degraded' => false,
            'error'    => null,
        ];

        // Circuit breaker check — if OPEN, serve from cache (degraded).
        if (!$this->circuitBreaker->canProceed()) {
            $this->logger->warning('searchUpstream: circuit breaker OPEN — degraded mode', [
                'filter' => $filter->describe(),
            ]);
            $local = $this->searchLocal($filter->toLocalFilterArray(), $filter->page, $filter->pageSize);
            $summary['degraded'] = true;
            $summary['fetched'] = $local->count();
            $summary['total']   = $local->total;
            return $summary;
        }

        try {
            $collection = $this->client->search($filter, fn (): null => $this->rateLimiter->wait());
            $this->circuitBreaker->recordSuccess();
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();
            $this->logger->error('searchUpstream: upstream call failed', [
                'filter' => $filter->describe(),
                'error'  => $e->getMessage(),
            ]);
            // Fall back to local cache (degraded mode) — cycle still runs the
            // matcher against the existing cache, so it isn't a no-op.
            $local = $this->searchLocal($filter->toLocalFilterArray(), $filter->page, $filter->pageSize);
            $summary['degraded'] = true;
            $summary['fetched'] = $local->count();
            $summary['total']   = $local->total;
            $summary['error']   = $e->getMessage();
            return $summary;
        }

        $summary['reached'] = true;
        $summary['fetched'] = $collection->count();
        $summary['total']   = $collection->total;

        // Persist whatever we got to the local cache.
        if (!$collection->isEmpty()) {
            $filterContext = $filter->toLocalFilterArray();
            $filterContext['filter_hash'] = $filter->cacheKey();
            $this->licenseRepo->saveMany($collection->items, null, $filterContext);

            // Dispatch golden-link triggers (same as searchHybrid — capped
            // at 20 events per call to avoid flooding the bus on a large page).
            $limit = min(count($collection->items), 20);
            for ($i = 0; $i < $limit; $i++) {
                $license = $collection->items[$i];
                $this->eventBus->dispatch(new LicenseSaved([
                    'request_number'  => $license->requestNumber,
                    'applicant_name'  => $license->applicantName,
                    'license_title'   => $license->licenseTitle,
                    'status_slug'     => $license->status->value,
                    'filter_hash'     => $filter->cacheKey(),
                ]));
                if ($license->status->value === 'active') {
                    $this->eventBus->dispatch(new LicenseIssuedOnNational([
                        'request_number'        => $license->requestNumber,
                        'applicant_name'         => $license->applicantName,
                        'license_title'          => $license->licenseTitle,
                        'province'               => $license->provinceTitle,
                        'township'               => $license->townshipTitle,
                    ]));
                }
            }
        }

        // Refresh the local page-cache snapshot so subsequent searchHybrid
        // calls for the same filter see the fresh data without re-hitting
        // the upstream.
        $this->cache->setSearchPage($filter->cacheKey(), $filter->page, [
            'items'     => array_map(static fn (License $l): array => $l->toArray(), $collection->items),
            'page'      => $filter->page,
            'page_size' => $filter->pageSize,
            'total'     => $collection->total,
        ]);
        $this->cache->setFilterTotal($filter->cacheKey(), $collection->total);

        $this->logger->info('searchUpstream: upstream call succeeded', [
            'filter'  => $filter->describe(),
            'page'    => $filter->page,
            'fetched' => $collection->count(),
            'total'   => $collection->total,
        ]);

        return $summary;
    }

    /**
     * Fetch the extended detail for a license via the /track/{rn} page
     * and persist the golden-link anchor fields.
     *
     * Returns the cached License (with anchors populated), or null when the
     * license is not found on the upstream.
     */
    public function fetchAndCacheByTrackNumber(string $requestNumber): ?License
    {
        if ($requestNumber === '') {
            return null;
        }

        // Circuit breaker check.
        if (!$this->circuitBreaker->canProceed()) {
            $this->logger->warning('Circuit breaker OPEN — serving cached track data only', [
                'rn' => $requestNumber,
            ]);
            return $this->licenseRepo->findByRequestNumber($requestNumber);
        }

        $this->rateLimiter->wait();
        try {
            $track = $this->trackScraper->fetch($requestNumber, false);
            $this->circuitBreaker->recordSuccess();
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();
            $this->logger->error('TrackScraper failed', ['rn' => $requestNumber, 'error' => $e->getMessage()]);
            return null;
        }

        if (!is_array($track) || empty($track['found']) || $track['error'] !== null) {
            $this->logger->info('fetchAndCacheByTrackNumber: not found on upstream', ['rn' => $requestNumber]);
            return null;
        }

        $license = License::fromTrackPage($track);
        if ($license->licenseTitle === '') {
            $this->logger->info('fetchAndCacheByTrackNumber: empty license_title', ['rn' => $requestNumber]);
            return null;
        }

        // Preserve any previously-saved match result (don't overwrite it).
        $existing = $this->licenseRepo->findByRequestNumber($requestNumber);
        $this->licenseRepo->save($license, null, null);

        // Dispatch LicenseSaved with the track-page detail.
        $this->eventBus->dispatch(new LicenseSaved([
            'request_number' => $license->requestNumber,
            'license_title' => $license->licenseTitle,
            'masked_national_code' => $license->maskedNationalCode,
            'father_name' => $license->fatherName,
            'source' => 'track_page',
        ]));

        // §9.5 golden-link trigger: track page shows status — if active,
        // dispatch LicenseIssuedOnNational for the matcher to pick up.
        if ($license->status->value === 'active' && $license->maskedNationalCode !== null) {
            $this->eventBus->dispatch(new LicenseIssuedOnNational([
                'request_number' => $license->requestNumber,
                'license_title' => $license->licenseTitle,
                'masked_national_code' => $license->maskedNationalCode,
                'father_name' => $license->fatherName,
                'province' => $license->provinceTitle,
                'township' => $license->townshipTitle,
            ]));
        }

        return $license;
    }

    /**
     * Fetch a license by request number (local cache first, then track page).
     */
    public function getByRequestNumber(string $requestNumber): ?License
    {
        $cached = $this->licenseRepo->findByRequestNumber($requestNumber);
        if ($cached !== null && $cached->maskedNationalCode !== null) {
            // Cache hit with full track-page detail — no upstream call needed.
            return $cached;
        }
        // No cache or no track detail — fetch from upstream.
        return $this->fetchAndCacheByTrackNumber($requestNumber);
    }

    /**
     * Refresh the lookup tables (provinces, townships, organizations) from
     * upstream. Called by the admin panel or a periodic sync job.
     *
     * @return array<string,int>
     */
    public function refreshLookups(): array
    {
        $this->rateLimiter->wait();
        $provinces = $this->client->provinces();
        $provinceCount = $this->lookupRepo->refreshProvinces($provinces);

        $townshipCount = 0;
        foreach ($provinces as $p) {
            $this->rateLimiter->wait();
            $townships = $this->client->townships((int)$p['id']);
            $townshipCount += $this->lookupRepo->refreshTownships((int)$p['id'], $townships);
        }

        $this->rateLimiter->wait();
        $orgs = $this->client->organizations();
        $orgCount = $this->lookupRepo->refreshOrganizations(
            $orgs['main'] ?? [],
            $orgs['sub'] ?? []
        );

        $this->logger->info('Lookups refreshed', [
            'provinces' => $provinceCount,
            'townships' => $townshipCount,
            'organizations' => $orgCount,
        ]);

        return [
            'provinces' => $provinceCount,
            'townships' => $townshipCount,
            'organizations' => $orgCount,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function stats(): array
    {
        return [
            'total' => $this->licenseRepo->countAll(),
            'saved' => $this->licenseRepo->countSaved(),
            'by_status' => $this->licenseRepo->countByStatus(),
            'last_seen_at' => $this->licenseRepo->lastSeenAt(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function analytics(): array
    {
        return [
            'top_provinces' => $this->licenseRepo->topProvinces(10),
            'top_organizations' => $this->licenseRepo->topOrganizations(10),
            'by_month' => $this->licenseRepo->countByMonth(12),
            'by_status' => $this->licenseRepo->countByStatus(),
        ];
    }

    /**
     * Hydrate a License from a DB row array (from queryCached).
     *
     * Phase 14 (v1.5.0) — pays D-4-6: now passes the golden-link anchor
     * fields (`masked_national_code`, `father_name`) from the DB row
     * through to `License::fromArray`. Previously `fromArray` dropped
     * them (the cache-snapshot path didn't include them), so `searchLocal`
     * results always had `null` anchors even though the DB row had them.
     * `fromArray` was updated to read them too — but we keep this method
     * explicit so future code reading this site knows the contract:
     * `hydrateLicense` is the DB-row path and is the only path that
     * populates the anchors from a SELECT * row.
     *
     * @param array<string,mixed> $row
     */
    private function hydrateLicense(array $row): License
    {
        // License::fromArray now reads masked_national_code + father_name
        // (Phase 14 / D-4-6). The DB row from queryCached is SELECT *,
        // so the columns are present — we just pass the row through.
        return License::fromArray($row);
    }
}
