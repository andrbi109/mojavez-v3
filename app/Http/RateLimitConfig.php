<?php
declare(strict_types=1);

namespace App\Http;

/**
 * RateLimitConfig — declarative rate-limit rules per route pattern.
 *
 * Per docs/00-MASTER.md §12.6. The front controller (public/index.php)
 * consults this config before dispatching: for each request, it finds the
 * first matching rule (by method + regex) and calls IpRateLimiter::hit().
 *
 * Rules are ordered most-specific-first. The first match wins.
 *
 * 'type' = 'session' → per-session bucket (cleared on logout/session expiry).
 *          Good for admin-side actions (admin is trusted, just anti-brute-force).
 * 'type' = 'ip'      → per-IP bucket persisted in rate_limit_buckets table.
 *          Good for public endpoints (survives logout, shared across sessions).
 *
 * DO NOT add the upstream scraper here — that uses RateLimiterService
 * (anti-detection jitter), a different mechanism.
 */
final class RateLimitConfig
{
    /**
     * @return list<array{method:string,pattern:string,key:string,max:int,window:int,type:string}>
     */
    public static function rules(): array
    {
        return [
            // Admin login — session-based (admin is a trusted realm, just
            // anti-brute-force within a single browser session).
            ['method' => 'POST', 'pattern' => '#^/admin/login$#', 'key' => 'admin_login', 'max' => 5, 'window' => 300, 'type' => 'session'],
            // User login — IP-based (public, survives session reset).
            ['method' => 'POST', 'pattern' => '#^/login$#', 'key' => 'user_login', 'max' => 5, 'window' => 300, 'type' => 'ip'],
            // Tracking status lookup — public, IP-based.
            ['method' => 'GET', 'pattern' => '#^/tracking/status/#', 'key' => 'tracking_status', 'max' => 30, 'window' => 60, 'type' => 'ip'],
            // License view (token-gated but still public) — IP-based.
            ['method' => 'GET', 'pattern' => '#^/license/view/#', 'key' => 'license_view', 'max' => 20, 'window' => 60, 'type' => 'ip'],
            // Print license — heavier (PDF gen), IP-based.
            ['method' => 'GET', 'pattern' => '#^/tracking/print/#', 'key' => 'tracking_print', 'max' => 10, 'window' => 60, 'type' => 'ip'],
            // Admin API: national license search — session-based (admin realm).
            ['method' => 'GET', 'pattern' => '#^/api/v1/licenses/search#', 'key' => 'api_licenses_search', 'max' => 10, 'window' => 60, 'type' => 'session'],
            // Public API: applicants — API-key based, but bucket by IP.
            ['method' => 'GET', 'pattern' => '#^/api/v1/applicants/#', 'key' => 'api_applicants', 'max' => 60, 'window' => 60, 'type' => 'ip'],
        ];
    }

    /**
     * Find the rule matching a method+path. Returns null if no rule matches.
     *
     * @return array{method:string,pattern:string,key:string,max:int,window:int,type:string}|null
     */
    public static function match(string $method, string $path): ?array
    {
        foreach (self::rules() as $rule) {
            if ($rule['method'] !== $method) {
                continue;
            }
            if (preg_match($rule['pattern'], $path) === 1) {
                return $rule;
            }
        }
        return null;
    }
}
