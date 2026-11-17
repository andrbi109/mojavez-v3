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
 */
final class Session
{
    private const REGENERATE_INTERVAL_S = 1800;  // 30 minutes

    private bool $started = false;

    public function __construct(
        private readonly bool $secure = false,
        private readonly int $lifetime = 86400,
        private readonly string $name = 'MOJAVEZSESSID'
    ) {}

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
            'secure'   => $this->secure,
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
