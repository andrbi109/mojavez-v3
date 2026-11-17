<?php
declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Environment loader — reads a simple .env file (KEY=VALUE per line)
 * and merges with $_ENV / $_SERVER. No Composer, no framework.
 *
 * Inherited and adapted from gateway's Environment class for v3:
 *  - readonly immutable value object.
 *  - No SQLite path (v3 is MySQL-only).
 *  - Strict typed properties.
 *  - Quoted values + inline comments + boolean coercion.
 *
 * All secrets (CRON_SECRET, SYNC_SECRET, GATEWAY_API_KEY) start as empty
 * strings; production deployment must run `openssl rand -hex 32` and
 * fill them in. `install.php` does this automatically during one-shot
 * interactive install.
 */
final readonly class Environment
{
    public function __construct(
        public string $appEnv,
        public bool $debug,
        public string $appUrl,
        public string $baseUrl,

        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPass,

        public int $sessionLifetime,
        public int $publicRateLimitPerMin,
        public bool $trackingRequireMobile,
        public string $adminIpAllowlist,
        public string $ipBlocklist,

        public string $cronSecret,
        public string $syncSecret,
        public string $gatewayApiKey,
        public string $errorLogPath,

        public string $upstreamBase,
        public int $upstreamRateLimitPerMin,
        public int $upstreamMinDelayMs,
        public int $upstreamMaxDelayMs,
        public int $upstreamTimeoutConnect,
        public int $upstreamTimeoutResponse,

        public int $syncCycleIntervalMin,
        public int $syncAutoConfirmThreshold,
        public int $syncOperatorFlagThreshold,

        public int $cacheTtlSearch,
        public int $cacheTtlLookups,
        public int $cacheTtlCount,
    ) {}

    public static function load(string $envFile): self
    {
        $vars = self::parseFile($envFile);
        // Precedence: real $_ENV / $_SERVER (set by the host) > .env file.
        // This lets ops override any value via systemd, Docker -e, etc.
        $vars = array_merge($vars, self::readPhpEnv());

        $appEnv = $vars['APP_ENV'] ?? 'production';
        $debug = self::asBool($vars['APP_DEBUG'] ?? 'false') || $appEnv === 'local';
        return new self(
            appEnv: $appEnv,
            debug: $debug,
            appUrl: rtrim($vars['APP_URL'] ?? 'http://localhost:8000', '/'),
            baseUrl: $vars['BASE_URL'] ?? '/',

            dbHost: $vars['DB_HOST'] ?? 'localhost',
            dbPort: (int)($vars['DB_PORT'] ?? 3306),
            dbName: $vars['DB_NAME'] ?? 'mojavez_v3',
            dbUser: $vars['DB_USER'] ?? 'root',
            dbPass: $vars['DB_PASS'] ?? '',

            sessionLifetime: (int)($vars['SESSION_LIFETIME'] ?? 86400),
            publicRateLimitPerMin: (int)($vars['PUBLIC_RATE_LIMIT_PER_MIN'] ?? 30),
            trackingRequireMobile: self::asBool($vars['TRACKING_REQUIRE_MOBILE'] ?? 'true'),
            adminIpAllowlist: $vars['ADMIN_IP_ALLOWLIST'] ?? '',
            ipBlocklist: $vars['IP_BLOCKLIST'] ?? '',

            cronSecret: $vars['CRON_SECRET'] ?? '',
            syncSecret: $vars['SYNC_SECRET'] ?? '',
            gatewayApiKey: $vars['GATEWAY_API_KEY'] ?? '',
            errorLogPath: $vars['ERROR_LOG_PATH'] ?? '',

            upstreamBase: rtrim($vars['UPSTREAM_BASE'] ?? 'https://qr.mojavez.ir', '/'),
            upstreamRateLimitPerMin: (int)($vars['UPSTREAM_RATE_LIMIT_PER_MIN'] ?? 30),
            upstreamMinDelayMs: (int)($vars['UPSTREAM_MIN_DELAY_MS'] ?? 2000),
            upstreamMaxDelayMs: (int)($vars['UPSTREAM_MAX_DELAY_MS'] ?? 5000),
            upstreamTimeoutConnect: (int)($vars['UPSTREAM_TIMEOUT_CONNECT'] ?? 5),
            upstreamTimeoutResponse: (int)($vars['UPSTREAM_TIMEOUT_RESPONSE'] ?? 8),

            syncCycleIntervalMin: (int)($vars['SYNC_CYCLE_INTERVAL_MIN'] ?? 60),
            syncAutoConfirmThreshold: (int)($vars['SYNC_AUTO_CONFIRM_THRESHOLD'] ?? 90),
            syncOperatorFlagThreshold: (int)($vars['SYNC_OPERATOR_FLAG_THRESHOLD'] ?? 50),

            cacheTtlSearch: (int)($vars['CACHE_TTL_SEARCH_SECONDS'] ?? 1800),
            cacheTtlLookups: (int)($vars['CACHE_TTL_LOOKUPS_SECONDS'] ?? 86400),
            cacheTtlCount: (int)($vars['CACHE_TTL_COUNT_SECONDS'] ?? 3600),
        );
    }

    public function isProduction(): bool
    {
        return $this->appEnv === 'production';
    }

    public function isDevelopment(): bool
    {
        return in_array($this->appEnv, ['development', 'local'], true);
    }

    /**
     * @return array<string,string>
     */
    private static function parseFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $vars = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            // Strip inline comment if value is unquoted
            if ($v !== '' && $v[0] !== '"' && $v[0] !== "'" && str_contains($v, ' #')) {
                $v = substr($v, 0, strpos($v, ' #'));
                $v = trim($v);
            }
            // Strip surrounding quotes
            if (strlen($v) >= 2 && $v[0] === '"' && $v[-1] === '"') {
                $v = substr($v, 1, -1);
                $v = str_replace('\\n', "\n", $v);
            } elseif (strlen($v) >= 2 && $v[0] === "'" && $v[-1] === "'") {
                $v = substr($v, 1, -1);
            }
            $vars[$k] = $v;
        }
        return $vars;
    }

    /**
     * Read real env vars from the PHP runtime.
     *
     * @return array<string,string>
     */
    private static function readPhpEnv(): array
    {
        $out = [];
        foreach ($_ENV as $k => $v) {
            if (is_string($v)) {
                $out[$k] = $v;
            }
        }
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && !array_key_exists($k, $out)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function asBool(string $value): bool
    {
        $lower = strtolower(trim($value));
        return in_array($lower, ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Return the application version (read from the VERSION file at the
     * project root). Used by views to show the version in the footer.
     */
    public static function version(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        // Walk up from this file (app/Shared/Support/) to find VERSION.
        $dir = dirname(__DIR__, 3);
        $versionFile = $dir . '/VERSION';
        $cached = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : '0.0.0-dev';
        return $cached;
    }
}
