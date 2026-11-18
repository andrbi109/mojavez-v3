<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Infrastructure;

/**
 * Anti-detection middleware — applies a FIXED set of browser-like headers
 * to every outbound request, never changing them across requests.
 *
 * Per user requirement: UA must be fixed (not rotating) — to look like a
 * consistent browser, not a bot trying to evade detection.
 */
final readonly class AntiDetectionMiddleware
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        private array $config
    ) {}

    /**
     * The fixed User-Agent (Chrome 120 Windows).
     */
    public function userAgent(): string
    {
        return $this->config['user_agent']
            ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    }

    /**
     * Default fixed headers — applied to every request.
     *
     * @return array<string,string>
     */
    public function defaultHeaders(): array
    {
        return $this->config['headers'] ?? [
            'Accept' => 'application/json',
            'Accept-Language' => 'fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'sec-ch-ua' => '"Not;A=Brand";v="99", "Google Chrome";v="120", "Chromium";v="120"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"Windows"',
            'DNT' => '1',
            'Upgrade-Insecure-Requests' => '1',
        ];
    }

    /**
     * Apply anti-detection to a set of request headers.
     * Merges defaults with caller-provided headers (caller wins).
     *
     * @param array<string,string> $headers
     * @param string|null $referer Optional Referer URL.
     * @return array<string,string>
     */
    public function apply(array $headers, ?string $referer = null): array
    {
        $merged = $this->defaultHeaders();
        $merged['User-Agent'] = $this->userAgent();
        if ($referer !== null) {
            $merged['Referer'] = $referer;
        }
        foreach ($headers as $k => $v) {
            $merged[$k] = $v;
        }
        return $merged;
    }

    /**
     * Rate limit config (used by RateLimiterService).
     *
     * @return array{min_delay_ms:int,max_delay_ms:int,max_concurrent:int,burst:int,cooldown_ms:int}
     */
    public function rateLimit(): array
    {
        return $this->config['rate_limit'] ?? [
            'min_delay_ms' => 2000,
            'max_delay_ms' => 5000,
            'max_concurrent' => 1,
            'burst' => 5,
            'cooldown_ms' => 30000,
        ];
    }

    /**
     * Retry config.
     *
     * @return array{max_attempts:int,backoff_base_ms:int,backoff_factor:int,retry_on_status:array<int>}
     */
    public function retry(): array
    {
        return $this->config['retry'] ?? [
            'max_attempts' => 3,
            'backoff_base_ms' => 1000,
            'backoff_factor' => 2,
            'retry_on_status' => [429, 500, 502, 503, 504],
        ];
    }

    /**
     * HTTP timeouts (connect + response) in seconds.
     *
     * Phase 54 (v2.2.0) — normalize both the legacy key names (`connect`,
     * `response`) and the documented config key names (`connect_s`,
     * `response_s`) so HttpClient.php can always read `$timeouts['connect']`
     * and `$timeouts['response']` without an "Undefined array key" warning.
     * The config file (config/anti-detection.php) ships `connect_s` /
     * `response_s`, but the previous implementation returned the config
     * array as-is — which left `connect` and `response` undefined and
     * surfaced as "Undefined array key 'connect'" on the sync status page.
     *
     * @return array{connect:int,response:int}
     */
    public function timeouts(): array
    {
        $t = is_array($this->config['timeout'] ?? null) ? $this->config['timeout'] : [];
        return [
            'connect'  => (int)($t['connect']  ?? $t['connect_s']  ?? 15),
            'response' => (int)($t['response'] ?? $t['response_s'] ?? 30),
        ];
    }
}
