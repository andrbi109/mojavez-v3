<?php
declare(strict_types=1);

namespace App\Shared\Support;

use RuntimeException;

/**
 * Config — lazy-cached access to the config/ directory.
 *
 * Reads each subordinate file under config/ (app.php, database.php,
 * anti-detection.php, filters.php, statuses.php, workflow.php) once,
 * caches the merged array, and exposes dot-notation getters:
 *
 *   Config::get('app.version')
 *   Config::get('database.charset')
 *   Config::get('anti_detection.retry.max_attempts')
 *
 * Replaces the global `define()` pattern forbidden by
 * CODING_CONVENTIONS §2. Single instance, memoized via the Container.
 *
 * Why not constants: define() makes a global symbol; testability and
 * override-ability suffer. Config arrays let tests swap values and ops
 * override via .env (Environment is merged into 'app' at load time).
 */
final class Config
{
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    private static ?string $configPath = null;

    /**
     * Initialise the config from a path (called once by Container::boot()).
     * Subsequent calls to get() will use this path's data.
     */
    public static function init(string $configPath): void
    {
        self::$configPath = rtrim($configPath, '/');
        self::$cache = null; // force reload on next get()
    }

    /**
     * Bootstrap the config cache (called lazily on first get()).
     */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        if (self::$configPath === null) {
            throw new RuntimeException('Config::init() was not called');
        }
        $file = self::$configPath . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException("Config file not found: $file");
        }
        /** @var array<string,mixed> $config */
        $config = require $file;
        if (!is_array($config)) {
            throw new RuntimeException("Config file did not return an array: $file");
        }
        self::$cache = $config;
        return self::$cache;
    }

    /**
     * Get a config value by dot-notation key.
     *
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $config = self::load();
        $keys = explode('.', $key);
        $value = $config;
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value;
    }

    /**
     * Check if a config key exists.
     */
    public static function has(string $key): bool
    {
        $sentinel = new \stdClass();
        return self::get($key, $sentinel) !== $sentinel;
    }

    /**
     * Reload from disk (mostly for tests).
     */
    public static function reload(): void
    {
        self::$cache = null;
    }

    /**
     * Inject values directly (test helper). Merges recursively.
     *
     * @internal
     * @param array<string,mixed> $overrides
     */
    public static function overrideForTests(array $overrides): void
    {
        self::load();
        self::$cache = array_merge(self::$cache ?? [], $overrides);
    }
}
