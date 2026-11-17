<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

/**
 * Rate limiter — enforces minimum delay between upstream requests.
 *
 * Uses a file-based state (single JSON file) — sufficient for single-host
 * shared hosting deployment. For multi-host, would need Redis/DB.
 *
 * Behavior:
 *   - Tracks timestamps of last N requests
 *   - Before each request, checks if min_delay_ms has elapsed
 *   - If in burst (more than `burst` requests in `burst_window`), enforces cooldown
 *   - Adds Gaussian jitter to delay to look human
 */
final class RateLimiterService
{
    private const BURST_WINDOW_SECONDS = 60;

    /** @var array<int,float> */
    private array $timestamps = [];

    public function __construct(
        private readonly array $config,
        private readonly string $stateFile
    ) {
        $this->load();
    }

    /**
     * Wait until it's safe to send the next request.
     */
    public function wait(): void
    {
        $minDelay = ($this->config['min_delay_ms'] ?? 2000) / 1000;
        $maxDelay = ($this->config['max_delay_ms'] ?? 5000) / 1000;
        $burst = $this->config['burst'] ?? 5;
        $cooldown = ($this->config['cooldown_ms'] ?? 30000) / 1000;

        $now = microtime(true);

        // Prune old timestamps (older than burst window)
        $this->timestamps = array_values(array_filter(
            $this->timestamps,
            static fn ($t) => $now - $t < self::BURST_WINDOW_SECONDS
        ));

        // If in cooldown (just finished burst), wait
        if (count($this->timestamps) >= $burst) {
            $oldestInWindow = $this->timestamps[0];
            $windowRemaining = self::BURST_WINDOW_SECONDS - ($now - $oldestInWindow);
            if ($windowRemaining > 0 && count($this->timestamps) >= $burst) {
                // Need cooldown
                $sleep = $cooldown + $this->jitter($minDelay, $maxDelay);
                usleep((int)($sleep * 1_000_000));
                $this->timestamps = [];
            }
        }

        // Enforce min delay from last request
        $last = end($this->timestamps) ?: 0;
        $elapsed = $now - $last;
        $desired = $this->jitter($minDelay, $maxDelay);
        if ($elapsed < $desired) {
            $sleep = $desired - $elapsed;
            usleep((int)($sleep * 1_000_000));
        }

        // Record this request
        $this->timestamps[] = microtime(true);
        if (count($this->timestamps) > 50) {
            $this->timestamps = array_slice($this->timestamps, -50);
        }
        $this->save();
    }

    /**
     * Gaussian-like jitter around midpoint of [min, max].
     * Uses three uniform randoms averaged → approximates normal distribution.
     */
    private function jitter(float $min, float $max): float
    {
        $mid = ($min + $max) / 2;
        $range = ($max - $min) / 2;
        // Average of 3 uniform[0,1] → triangular distribution centered at 0.5
        $r = (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax()) / 3;
        return $mid + ($r - 0.5) * 2 * $range;
    }

    private function load(): void
    {
        if (!is_file($this->stateFile)) return;
        $data = file_get_contents($this->stateFile);
        if ($data === false) return;
        $decoded = json_decode($data, true);
        if (is_array($decoded) && isset($decoded['timestamps']) && is_array($decoded['timestamps'])) {
            $this->timestamps = array_map('floatval', $decoded['timestamps']);
        }
    }

    private function save(): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($this->stateFile, json_encode(['timestamps' => $this->timestamps]), LOCK_EX);
    }
}
