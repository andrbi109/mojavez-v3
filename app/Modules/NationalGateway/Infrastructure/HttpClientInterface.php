<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Infrastructure;

/**
 * Contract for HTTP clients used by the NationalGateway module.
 *
 * The real implementation (HttpClient) uses cURL + cookie jar. Tests inject
 * a stub implementation that returns fixture content instead of hitting the
 * network — this lets us test MojavecClient and TrackScraper without cURL
 * or network access.
 */
interface HttpClientInterface
{
    /**
     * Execute an HTTP request.
     *
     * @param string $method  GET, POST, PUT, DELETE, etc.
     * @param string $url     Full URL.
     * @param array<string,string> $headers  Additional headers (merged with anti-detection defaults).
     * @param string|null $body  Request body (for POST/PUT).
     * @param string|null $contentType  Override Content-Type.
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $contentType = null
    ): HttpResponse;
}
