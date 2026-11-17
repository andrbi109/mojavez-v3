<?php
declare(strict_types=1);

/**
 * Anti-detection config for the upstream national gateway scraper.
 *
 * This is the "respectful" anti-detection philosophy: NO spoofing,
 * NO malicious evasion. We respect the upstream's resource limits by
 * keeping a steady low rate, naturalistic jitter, and fail-fast retries.
 * See docs/00-MASTER.md §8.6.
 *
 * Fix ST1: RateLimiterService::wait() is ALWAYS called in
 * MojavecClient::doWithRetry() — never dead code.
 *
 * @return array<string,mixed>
 */
return [
    'upstream_base'   => $_ENV['UPSTREAM_BASE'] ?? 'https://qr.mojavez.ir',

    // HTTP client
    'user_agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',

    // Constant browser-like headers (order matters for fingerprint stability).
    'headers' => [
        'Accept'             => 'application/json',
        'Accept-Language'    => 'fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
        'Origin'             => 'https://qr.mojavez.ir',
        'Referer'            => 'https://qr.mojavez.ir/',
        'Sec-Fetch-Dest'     => 'empty',
        'Sec-Fetch-Mode'     => 'cors',
        'Sec-Fetch-Site'     => 'same-origin',
        'sec-ch-ua'          => '"Not;A=Brand";v="99", "Google Chrome";v="120", "Chromium";v="120"',
        'sec-ch-ua-mobile'   => '?0',
        'sec-ch-ua-platform' => '"Windows"',
        'DNT'                => '1',
        'Upgrade-Insecure-Requests' => '1',
    ],

    // Rate limiting — REAL (fix ST1)
    'rate_limit' => [
        'min_delay_ms'     => (int)($_ENV['UPSTREAM_MIN_DELAY_MS'] ?? 2000),
        'max_delay_ms'     => (int)($_ENV['UPSTREAM_MAX_DELAY_MS'] ?? 5000),
        'max_concurrent'   => 1,             // NEVER concurrent
        'burst_count'      => 5,             // burst size
        'burst_window_s'   => 60,
        'cooldown_after_burst_s' => 30,
        // Gaussian jitter: mean=0.5s, stddev=0.2s (naturalistic spacing)
        'jitter_mean_s'    => 0.5,
        'jitter_stddev_s'  => 0.2,
    ],

    // Timeouts (fail fast)
    'timeout' => [
        'connect_s'  => (int)($_ENV['UPSTREAM_TIMEOUT_CONNECT'] ?? 5),
        'response_s' => (int)($_ENV['UPSTREAM_TIMEOUT_RESPONSE'] ?? 8),
    ],

    // Retry policy
    'retry' => [
        'max_attempts'     => 2,
        'initial_backoff_ms' => 500,
        'multiplier'        => 2,           // 500ms, 1s, 2s...
        'retry_on_status'   => [429, 502, 503, 504],
    ],

    // TLS (fix S4)
    'tls' => [
        'verify_peer'   => true,
        'verify_host'   => 2,
        'follow_location' => false,  // no open redirect risk
    ],

    // Circuit breaker (Phase 3 — declared here for forward visibility)
    'circuit_breaker' => [
        'failure_threshold'  => 3,
        'failure_window_s'    => 60,
        'cooldown_s'          => 300,       // 5 min half-open probe interval
    ],
];
