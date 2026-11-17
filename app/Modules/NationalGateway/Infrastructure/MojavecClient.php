<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Infrastructure;

use App\Modules\NationalGateway\Domain\Enums\LicenseStatus;
use App\Modules\NationalGateway\Domain\License;
use App\Modules\NationalGateway\Domain\LicenseCollection;
use App\Modules\NationalGateway\Domain\Filter;
use App\Modules\NationalGateway\Infrastructure\Parser\ResponseParserInterface;
use App\Shared\Support\StructuredLogger;

/**
 * Client for qr.mojavez.ir upstream API.
 *
 * Wraps HttpClient with:
 *   - retry logic (exponential backoff)
 *   - rate-limit respect (delegates to RateLimiterService via container)
 *   - JSON parsing into domain objects
 *
 * Endpoints used:
 *   GET  /api/v1/lookups/provinces
 *   GET  /api/v1/lookups/organizations
 *   POST /api/v1/licenses/search
 *   POST /api/v1/licenses/count
 */
final class MojavecClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ResponseParserInterface $parser,
        private readonly string $upstreamBase,
        private readonly StructuredLogger $logger
    ) {}

    /**
     * Search licenses on upstream with the given filter.
     * Returns a LicenseCollection (hydrated from upstream rows).
     *
     * @param callable():void|null $rateLimiter Optional callback to wait/limit.
     */
    public function search(Filter $filter, ?callable $rateLimiter = null): LicenseCollection
    {
        $payload = $filter->toUpstreamPayload();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this->doWithRetry(
            fn () => $this->http->request(
                'POST',
                $this->upstreamBase . '/api/v1/licenses/search',
                ['Origin' => $this->upstreamBase],
                $body,
                'application/json'
            ),
            'POST /api/v1/licenses/search',
            $rateLimiter
        );

        if ($response === null || !$response->isSuccess()) {
            $this->logger->warning('Search upstream failed', [
                'filter' => $filter->describe(),
                'status' => $response?->status,
            ]);
            return new LicenseCollection([], $filter->page, $filter->pageSize, 0);
        }

        $parsed = $this->parser->parseSearch($response->body);
        $items = [];
        foreach ($parsed['licenses'] as $row) {
            $items[] = License::fromUpstream($row);
        }

        // Total: the upstream search response does NOT include a total count
        // (verified in HAR). So we must derive it:
        //   - If the returned page is not full (fewer than pageSize), this is the
        //     last page and total = items already seen on this page + items from
        //     previous pages. For page 1 that's just count(items).
        //   - If the returned page IS full (== pageSize), there are likely more
        //     results, so issue a /count request to get the accurate total.
        // The previous heuristic (page*pageSize+1) produced a wildly wrong
        // total_pages that broke pagination ("page 2 of 1.5" etc.).
        $total = $parsed['total'];
        if ($total === null && count($items) >= $filter->pageSize) {
            $total = $this->count($filter, $rateLimiter);
        }
        if ($total === null) {
            $total = count($items);
        }

        $this->logger->info('Search upstream success', [
            'filter' => $filter->describe(),
            'page' => $filter->page,
            'returned' => count($items),
            'total' => $total,
            'total_source' => $parsed['total'] !== null ? 'embedded' : 'heuristic',
            'elapsed' => round($response->elapsedSeconds, 3),
        ]);

        return new LicenseCollection($items, $filter->page, $filter->pageSize, $total);
    }

    /**
     * Count licenses matching the filter (without page).
     */
    public function count(Filter $filter, ?callable $rateLimiter = null): ?int
    {
        // Count payload = search payload without 'page'
        $payload = $filter->toUpstreamPayload();
        unset($payload['page']);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this->doWithRetry(
            fn () => $this->http->request(
                'POST',
                $this->upstreamBase . '/api/v1/licenses/count',
                ['Origin' => $this->upstreamBase],
                $body,
                'application/json'
            ),
            'POST /api/v1/licenses/count',
            $rateLimiter
        );

        if ($response === null || !$response->isSuccess()) {
            return null;
        }

        return $this->parser->parseCount($response->body);
    }

    /**
     * Fetch provinces list.
     *
     * @return list<array{id:string,title:string}>
     */
    public function provinces(?callable $rateLimiter = null): array
    {
        $response = $this->doWithRetry(
            fn () => $this->http->request(
                'GET',
                $this->upstreamBase . '/api/v1/lookups/provinces',
                ['Referer' => $this->upstreamBase . '/']
            ),
            'GET /api/v1/lookups/provinces',
            $rateLimiter
        );
        if ($response === null || !$response->isSuccess()) {
            return [];
        }
        return $this->parser->parseProvinces($response->body);
    }

    /**
     * Fetch townships for a specific province.
     *
     * @return list<array{id:string,title:string}>
     */
    public function townships(int $provinceId, ?callable $rateLimiter = null): array
    {
        $response = $this->doWithRetry(
            fn () => $this->http->request(
                'GET',
                $this->upstreamBase . '/api/v1/lookups/provinces/' . $provinceId . '/townships',
                ['Referer' => $this->upstreamBase . '/']
            ),
            'GET /api/v1/lookups/provinces/' . $provinceId . '/townships',
            $rateLimiter
        );
        if ($response === null || !$response->isSuccess()) {
            return [];
        }
        return $this->parser->parseTownships($response->body);
    }

    /**
     * Fetch organizations list.
     *
     * @return array{main:list<array{id:string,title:string}>,sub:list<array{id:string,title:string}>}
     */
    public function organizations(?callable $rateLimiter = null): array
    {
        $response = $this->doWithRetry(
            fn () => $this->http->request(
                'GET',
                $this->upstreamBase . '/api/v1/lookups/organizations',
                ['Referer' => $this->upstreamBase . '/']
            ),
            'GET /api/v1/lookups/organizations',
            $rateLimiter
        );
        if ($response === null || !$response->isSuccess()) {
            return ['main' => [], 'sub' => []];
        }
        return $this->parser->parseOrganizations($response->body);
    }

    /**
     * Fetch sub-organizations for a specific main organization.
     *
     * @return list<array{id:string,title:string}>
     */
    public function subOrganizations(string $mainOrgCode, ?callable $rateLimiter = null): array
    {
        $response = $this->doWithRetry(
            fn () => $this->http->request(
                'GET',
                $this->upstreamBase . '/api/v1/lookups/organizations/' . $mainOrgCode . '/subs',
                ['Referer' => $this->upstreamBase . '/']
            ),
            'GET /api/v1/lookups/organizations/' . $mainOrgCode . '/subs',
            $rateLimiter
        );
        if ($response === null || !$response->isSuccess()) {
            return [];
        }
        return $this->parser->parseSubOrganizations($response->body);
    }

    public function upstreamBase(): string
    {
        return $this->upstreamBase;
    }

    /**
     * Execute a request with retry + rate-limit wait.
     *
     * @param callable():HttpResponse $fn
     * @return HttpResponse|null null if all attempts fail
     */
    private function doWithRetry(callable $fn, string $label, ?callable $rateLimiter): ?HttpResponse
    {
        // Defaults kept in sync with config/anti-detection.php. These are fallbacks
        // in case the AntiDetectionMiddleware isn't wired up; for full config-driven
        // behavior the values in config/anti-detection.php are authoritative.
        // Fail fast: 2 attempts, 500ms backoff — never make the user wait 90s+.
        $maxAttempts = 2;
        $backoffBase = 500;
        $backoffFactor = 2;
        $retryStatuses = [429, 502, 503, 504];

        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $attempt++;
            if ($rateLimiter !== null) {
                $rateLimiter();
            }
            $response = $fn();
            if ($response->isSuccess()) {
                return $response;
            }
            if (!in_array($response->status, $retryStatuses, true)) {
                // Non-retryable error
                $this->logger->warning('Upstream non-retryable error', [
                    'label' => $label,
                    'status' => $response->status,
                    'attempt' => $attempt,
                ]);
                return $response;
            }
            if ($attempt < $maxAttempts) {
                $delay = (int)($backoffBase * pow($backoffFactor, $attempt - 1));
                $this->logger->warning('Upstream retryable error, retrying', [
                    'label' => $label,
                    'status' => $response->status,
                    'attempt' => $attempt,
                    'next_delay_ms' => $delay,
                ]);
                usleep($delay * 1000);
            } else {
                $this->logger->error('Upstream max retries exhausted', [
                    'label' => $label,
                    'status' => $response->status,
                    'attempts' => $attempt,
                ]);
                return $response;
            }
        }
        return null;
    }
}
