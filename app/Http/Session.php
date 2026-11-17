<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Session wrapper with secure defaults.
 *
 * Inherited from gateway, hardened for v3:
 *  - cookie flags: HttpOnly, SameSite=Lax, Secure=auto.
 *  - use_strict_mode=1 (prevent session adoption).
 *  - sid_bits_per_character=5 (fix PHP 8.5 warning seen in v2 — see §20.3).
 *  - sid_length=40 (not 48).
 *  - cookie name MOJAVEZSESSID (distinct from gateway's name).
 *  - regenerate every 30 minutes (keep data).
 *
 * Two realms (admin / user) use distinct session key prefixes — both can
 * be active in the same browser simultaneously without interfering.
 *
 * Phase 22 (v1.9.4) — CRITICAL session-persistence fix:
 * The `secure` cookie flag is no longer bound to `APP_ENV=production`.
 * Browsers refuse to persist `secure` cookies over HTTP — so the previous
 * `secure: isProduction()` default dropped the session on every navigation
 * when the dev server ran on HTTP (which it always does in development).
 * The fix: `secure` is now nullable. When null, the start() method
 * auto-detects from `$_SERVER['HTTPS']` at runtime — true only when the
 * current request is actually over HTTPS. This makes the cookie persist
 * over HTTP dev AND HTTPS prod without configuration.
 */
final class Session
{
    private const REGENERATE_INTERVAL_S = 1800;  // 30 minutes

    private bool $started = false;

    /**
     * @param bool|null $secure  Null (default) = auto-detect from $_SERVER['HTTPS']
     *                           at start() time. Pass an explicit bool to force.
     * @param int       $lifetime Cookie lifetime in seconds.
     * @param string    $name    Cookie name (MOJAVEZSESSID by default).
     */
    public function __construct(
        private readonly ?bool $secure = null,
        private readonly int $lifetime = 86400,
        private readonly string $name = 'MOJAVEZSESSID'
    ) {}

    /**
     * Resolve the `secure` flag for the current request.
     *
     * Returns the explicitly-supplied value if non-null; otherwise
     * auto-detects from `$_SERVER['HTTPS']` and `SERVER_PORT`. Mirrors
     * the standard `is_https()` heuristic used by Symfony and others:
     *   - `$_SERVER['HTTPS']` set to a non-empty, non-'off' value, OR
     *   - `$_SERVER['SERVER_PORT']` == 443.
     *
     * This is intentionally NOT gated by `APP_ENV` — a production app
     * behind a TLS-terminating proxy that talks HTTP to PHP-FPM should
     * also get `secure: false`, because the *browser* sees HTTPS but
     * PHP sees HTTP. (Reverse-proxy trust is a separate concern handled
     * by `Request::$trustProxy`.)
     */
    private function resolveSecure(): bool
    {
        if ($this->secure !== null) {
            return $this->secure;
        }
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        $port = $_SERVER['SERVER_PORT'] ?? '';
        return (string)$port === '443';
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        // Custom save path under storage/sessions (Windows/XAMPP fix from gateway).
        $projectRoot = dirname(__DIR__, 2);
        $sessionPath = $projectRoot . '/storage/sessions';
        if (!is_dir($sessionPath)) {
            @mkdir($sessionPath, 0775, true);
        }
        if (is_dir($sessionPath) && is_writable($sessionPath)) {
            @session_save_path($sessionPath);
        }

        @session_set_cookie_params([
            'lifetime' => $this->lifetime,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $this->resolveSecure(),
            'samesite' => 'Lax',
        ]);
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.sid_bits_per_character', '5');
        @ini_set('session.sid_length', '40');
        @session_name($this->name);

        if (!@session_start()) {
            // Cannot start session (e.g. headers already sent). The app
            // should still work in degraded mode; subsequent get/set
            // operate on an empty in-memory array.
            return;
        }
        $this->started = true;

        // Regenerate every 30 minutes (preserve data).
        $lastRegen = $_SESSION['_session_regenerated_at'] ?? 0;
        if (time() - $lastRegen > self::REGENERATE_INTERVAL_S) {
            $this->regenerate();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();
        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $this->ensureStarted();
        return $_SESSION;
    }

    public function regenerate(): void
    {
        $this->ensureStarted();
        session_regenerate_id(true);
        $_SESSION['_session_regenerated_at'] = time();
    }

    public function destroy(): void
    {
        $this->ensureStarted();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                $this->name,
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        @session_destroy();
        $this->started = false;
    }

    public function id(): string
    {
        $this->ensureStarted();
        return session_id() ?: '';
    }

    private function ensureStarted(): void
    {
        if (!$this->started && session_status() !== PHP_SESSION_ACTIVE) {
            $this->start();
        }
        $this->started = true;
    }
}
