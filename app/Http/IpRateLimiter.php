<?php
declare(strict_types=1);

namespace App\Http;

use App\Shared\Persistence\Connection;
use App\Shared\Support\StructuredLogger;

/**
 * IpRateLimiter — IP + session based HTTP rate limiter.
 *
 * Per docs/00-MASTER.md §12.6 — two mechanisms:
 *
 *   1. Session-based: tracks attempts in $_SESSION['_rate_limit'][$key].
 *      Used for admin login (5/300s), tracking search (10/60s), etc.
 *      Pros: no DB write per hit. Cons: cleared when session expires.
 *
 *   2. IP-based: persists to the `rate_limit_buckets` table (survives logout,
 *      shared across sessions, survives restarts). Used for user login,
 *      /tracking/status/{code}, /license/view/{token}, API endpoints.
 *
 * This class implements BOTH. The caller picks the mechanism by passing
 * 'session' or 'ip' as the bucket type.
 *
 * Buckets are sliding-window: each hit appends a timestamp; old timestamps
 * beyond the window are pruned on read. The table is cleaned up lazily (no
 * separate cron) — prune() is called on every hit() and occasionally drops
 * stale buckets (created_at older than window × 2).
 *
 * DO NOT use this for the upstream national-gateway scraper — that uses
 * RateLimiterService (min delay + burst + cooldown + jitter) which is a
 * different mechanism (anti-detection, not anti-abuse).
 */
final class IpRateLimiter
{
    public function __construct(
        private readonly Connection $conn,
        private readonly Session $session,
        private readonly StructuredLogger $logger,
    ) {}

    /**
     * Check + record a hit. Returns true if the request is allowed, false
     * if the bucket is full (rate-limited).
     *
     * @param string $key      Bucket identifier (e.g. 'admin_login', 'tracking_search').
     * @param int    $max      Max hits allowed in the window.
     * @param int    $window   Window size in seconds.
     * @param string $type     'session' (per-session) or 'ip' (per-IP, DB-backed).
     */
    public function hit(string $key, int $max, int $window, string $type = 'ip'): bool
    {
        if ($type === 'session') {
            return $this->hitSession($key, $max, $window);
        }
        return $this->hitIp($key, $max, $window);
    }

    /**
     * Remaining hits in the current window (for X-RateLimit-Remaining header).
     */
    public function remaining(string $key, int $max, int $window, string $type = 'ip'): int
    {
        $count = $type === 'session'
            ? $this->countSession($key, $window)
            : $this->countIp($key, $window);
        return max(0, $max - $count);
    }

    // ---- Session-based (in $_SESSION, no DB) ----

    private function hitSession(string $key, int $max, int $window): bool
    {
        $bucket = $this->session->get('_rate_limit', []);
        if (!is_array($bucket)) {
            $bucket = [];
        }
        $now = time();
        $hits = array_values(array_filter(
            $bucket[$key] ?? [],
            static fn (int $ts): bool => $ts > $now - $window
        ));
        if (count($hits) >= $max) {
            $this->logger->info('rate_limit: session bucket full', [
                'key' => $key, 'hits' => count($hits), 'max' => $max,
            ]);
            return false;
        }
        $hits[] = $now;
        $bucket[$key] = $hits;
        $this->session->set('_rate_limit', $bucket);
        return true;
    }

    private function countSession(string $key, int $window): int
    {
        $bucket = $this->session->get('_rate_limit', []);
        if (!is_array($bucket) || !isset($bucket[$key])) {
            return 0;
        }
        $now = time();
        return count(array_filter(
            $bucket[$key],
            static fn (int $ts): bool => $ts > $now - $window
        ));
    }

    // ---- IP-based (in DB, survives logout) ----

    private function hitIp(string $key, int $max, int $window): bool
    {
        $ip = $this->clientIp();
        $now = time();
        $cutoff = $now - $window;

        // Read current bucket.
        $row = $this->conn->one(
            'SELECT * FROM rate_limit_buckets WHERE bucket_key = ? AND ip = ? LIMIT 1',
            [$key, $ip]
        );
        $hits = [];
        if ($row !== null) {
            $decoded = json_decode((string)($row['hits_json'] ?? '[]'), true);
            if (is_array($decoded)) {
                $hits = array_values(array_filter(
                    $decoded,
                    static fn ($ts): bool => (int)$ts > $cutoff
                ));
            }
        }

        if (count($hits) >= $max) {
            $this->logger->info('rate_limit: ip bucket full', [
                'key' => $key, 'ip' => $ip, 'hits' => count($hits), 'max' => $max,
            ]);
            return false;
        }
        $hits[] = $now;

        if ($row !== null) {
            $this->conn->execute(
                'UPDATE rate_limit_buckets SET hits_json = ?, updated_at = NOW() WHERE bucket_key = ? AND ip = ?',
                [json_encode($hits), $key, $ip]
            );
        } else {
            $this->conn->execute(
                'INSERT INTO rate_limit_buckets (bucket_key, ip, hits_json, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
                [$key, $ip, json_encode($hits)]
            );
        }

        // Lazy prune: drop buckets older than 2× window (housekeeping).
        if (random_int(1, 50) === 1) {
            $this->pruneStale($cutoff * 2);
        }
        return true;
    }

    private function countIp(string $key, int $window): int
    {
        $ip = $this->clientIp();
        $row = $this->conn->one(
            'SELECT hits_json FROM rate_limit_buckets WHERE bucket_key = ? AND ip = ? LIMIT 1',
            [$key, $ip]
        );
        if ($row === null) {
            return 0;
        }
        $decoded = json_decode((string)($row['hits_json'] ?? '[]'), true);
        if (!is_array($decoded)) {
            return 0;
        }
        $cutoff = time() - $window;
        return count(array_filter($decoded, static fn ($ts): bool => (int)$ts > $cutoff));
    }

    private function pruneStale(int $cutoff): void
    {
        try {
            $this->conn->execute(
                'DELETE FROM rate_limit_buckets WHERE updated_at < FROM_UNIXTIME(?)',
                [$cutoff]
            );
        } catch (\Throwable $e) {
            // Non-fatal — pruning is best-effort.
        }
    }

    private function clientIp(): string
    {
        // Trust X-Forwarded-For only if behind a known proxy (set via env).
        // For shared hosting without a proxy, REMOTE_ADDR is authoritative.
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '' && ($this->trustProxy())) {
            $parts = explode(',', $xff);
            return trim($parts[0]);
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function trustProxy(): bool
    {
        // Simple env flag — production should set TRUST_PROXY=1 behind nginx.
        return (getenv('TRUST_PROXY') ?: '0') === '1';
    }
}
