<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Hash utilities — SHA-256, short cache keys, secure random tokens,
 * constant-time comparison helpers, and password hashing.
 *
 * Inherited from gateway's Hash class, extended for v3 with:
 *  - passwordHash() / verifyPassword() wrappers around password_hash()
 *    (fixes v2's hardcoded admin password — see S3).
 *  - timingSafeCompare() for secrets (CRON_SECRET, SYNC_SECRET, GATEWAY_API_KEY,
 *    API keys — all compared with hash_equals).
 *
 * CODING_CONVENTIONS §6.4: never hardcode credentials; everything in .env.
 */
final class Hash
{
    public const PASSWORD_COST = 10;

    public function sha256(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Stable short hash (12 hex chars) — good enough for cache keys.
     */
    public function short(string $value): string
    {
        return substr(hash('sha256', $value), 0, 12);
    }

    /**
     * Secure random token. Default 32 bytes → 64 hex chars (256 bits entropy).
     * Used for: CSRF tokens, license access tokens, CRON/SYNC secrets,
     * API keys, idempotency keys.
     */
    public function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Hash an API key (or any credential) before storing in DB.
     * We store only the SHA-256 hash — never the raw key (fix S3 + §28).
     */
    public function apiKeyHash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    /**
     * Filter hash — used as cache key for filter-based queries.
     *
     * @param array<string,mixed> $filter
     */
    public function filterKey(array $filter): string
    {
        ksort($filter);
        $json = json_encode($filter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->short((string)$json);
    }

    /**
     * Hash a password with PASSWORD_BCRYPT (cost 10).
     * Used by User, UserAccount create/update.
     */
    public function passwordHash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::PASSWORD_COST]);
    }

    /**
     * Constant-time password verification.
     * Always runs even if $user is null (anti-timing for login — fix v2).
     */
    public function verifyPassword(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            // Run a dummy hash to keep timing uniform.
            password_verify($password, $this->passwordHash('dummy'));
            return false;
        }
        return password_verify($password, $hash);
    }

    /**
     * Constant-time string comparison.
     * Used for CSRF token, CRON_SECRET, SYNC_SECRET, GATEWAY_API_KEY,
     * and API keys (fix v2's hash_equals discipline).
     */
    public function timingSafeCompare(string $known, string $user): bool
    {
        if (strlen($known) !== strlen($user)) {
            // Still run hash_equals to keep timing uniform.
            hash_equals($known, $user);
            return false;
        }
        return hash_equals($known, $user);
    }

    /**
     * Generate a tracking code in the v2 format HG-XXXXXXXX
     * where X is [A-Z0-9] (32 chars, 36^8 ≈ 2.8 trillion combinations).
     *
     * Uses CSPRNG. The 'HG-' prefix is a permanent project identifier.
     */
    public function generateTrackingCode(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $len = 8;
        $code = '';
        for ($i = 0; $i < $len; $i++) {
            $code .= $alphabet[random_int(0, 35)];
        }
        return 'HG-' . $code;
    }

    /**
     * Generate an idempotency key (UUID v4 style, 36 chars).
     */
    public function generateIdempotencyKey(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
