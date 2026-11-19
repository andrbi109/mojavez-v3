<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Infrastructure;

use App\Shared\Support\StructuredLogger;

/**
 * HTTP client — cURL-based, no Guzzle.
 *
 * Features:
 *   - applies anti-detection headers (fixed UA + browser headers)
 *   - cookie jar (persists session between requests)
 *   - timeout enforcement (connect + response)
 *   - gzip/deflate/brotli support (built into cURL)
 *   - returns immutable HttpResponse DTO
 *
 * NO retry logic here — retry is the caller's responsibility (MojavecClient)
 * because retries interact with the rate limiter.
 */
final class HttpClient implements HttpClientInterface
{
    private string $cookieJarFile;

    public function __construct(
        private readonly AntiDetectionMiddleware $antiDetection,
        private readonly StructuredLogger $logger
    ) {
        $this->cookieJarFile = sys_get_temp_dir() . '/mojavez_cookies_' . md5(__FILE__) . '.txt';
    }

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
    ): HttpResponse {
        $referer = parse_url($url, PHP_URL_HOST) !== null
            ? (parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/')
            : null;

        $finalHeaders = $this->antiDetection->apply($headers, $referer);
        if ($contentType !== null) {
            $finalHeaders['Content-Type'] = $contentType;
        }

        $timeouts = $this->antiDetection->timeouts();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $this->formatHeaders($finalHeaders),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            // Phase 58 (v2.2.4): hard 10s connect + 30s total timeout
            // so a dead upstream fails fast instead of pinning the sync
            // worker for the full 45s default. The anti-detection
            // middleware provides the same defaults via timeouts() but
            // we re-assert them here as a safety net (cheap, explicit).
            CURLOPT_CONNECTTIMEOUT => $timeouts['connect'],
            CURLOPT_TIMEOUT => $timeouts['response'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '', // accept all encodings; let cURL decode
            CURLOPT_COOKIEJAR => $this->cookieJarFile,
            CURLOPT_COOKIEFILE => $this->cookieJarFile,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        if ($body !== null && $method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        // Capture response headers
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        });

        $start = microtime(true);
        $bodyResp = curl_exec($ch);
        $elapsed = microtime(true) - $start;

        if ($bodyResp === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            // Phase 58 (v2.2.4): @-suppress the PHP 8.5 deprecation
            // notice for curl_close() (no-op since 8.0, auto-closed by
            // GC). Same pattern as the imagedestroy() fix in phase 56.
            @curl_close($ch);
            $this->logger->error('HTTP request failed', [
                'url' => $url,
                'method' => $method,
                'error' => $error,
                'errno' => $errno,
                'elapsed' => round($elapsed, 3),
            ]);
            // Surface a clear, actionable error string to the caller.
            // The previous "degraded" sentinel was ambiguous; with the
            // connect-timeout fix above, curl_error() now carries either
            // a DNS/connection failure or a "maximum X redirects reached"
            // message that the sync worker can render verbatim.
            return new HttpResponse(0, '', [], $elapsed, $error !== '' ? $error : 'curl_exec failed');
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // Phase 58 (v2.2.4): same @-suppression for the success path.
        @curl_close($ch);

        $this->logger->debug('HTTP request', [
            'method' => $method,
            'url' => $url,
            'status' => $status,
            'elapsed' => round($elapsed, 3),
            'body_size' => strlen($bodyResp),
        ]);

        return new HttpResponse($status, $bodyResp, $responseHeaders, $elapsed);
    }

    /**
     * @param array<string,string> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[] = $name . ': ' . $value;
        }
        return $out;
    }
}
