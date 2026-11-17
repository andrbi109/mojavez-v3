<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Minimal HTTP request wrapper.
 *
 * Aggregates PHP's $_SERVER, $_GET, $_POST, $_FILES, $_COOKIE into a single
 * typed value object. Reading from superglobals directly in controllers is
 * forbidden (CODING_CONVENTIONS §2): always go through Request.
 *
 * Path normalization: trims the BASE_URL prefix and any trailing slash
 * (so /admin/login/ and /admin/login route identically).
 */
final class Request
{
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly array $cookies,
        private readonly array $files,
        private readonly string $ip,
        private readonly bool $isAjax,
    ) {}

    public static function fromGlobals(string $baseUrl = '/'): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = self::normalizePath($uri, $baseUrl);
        $isAjax = self::isAjaxRequest();

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            body: $_POST,
            headers: self::readHeaders(),
            cookies: $_COOKIE,
            files: $_FILES,
            ip: self::clientIp(),
            isAjax: $isAjax,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string,mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function queryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        // Case-insensitive lookup.
        $name = strtolower($name);
        foreach ($this->headers as $k => $v) {
            if (strtolower($k) === $name) {
                return $v;
            }
        }
        return $default;
    }

    /**
     * @return array<string,string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function isAjax(): bool
    {
        return $this->isAjax;
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isMutating(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * Read the JSON body of a request (for /api/v1/* endpoints).
     *
     * @return array<string,mixed>
     */
    public function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    private static function normalizePath(string $uri, string $baseUrl): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        // Strip BASE_URL prefix if present.
        if ($baseUrl !== '/' && $baseUrl !== '' && str_starts_with($path, $baseUrl)) {
            $path = substr($path, strlen($baseUrl));
        }
        // Guarantee a leading slash.
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        // Trim trailing slash unless this is the root.
        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    private static function isAjaxRequest(): bool
    {
        $xRequestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strtolower($xRequestedWith) === 'xmlhttprequest') {
            return true;
        }
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }

    /**
     * @return array<string,string>
     */
    private static function readHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && str_starts_with($k, 'HTTP_')) {
                $name = str_replace('_', '-', substr($k, 5));
                $headers[$name] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }
        return $headers;
    }

    /**
     * Client IP — trusts X-Forwarded-For only when a reverse proxy is
     * configured (config('app.trust_proxy')). Direct REMOTE_ADDR otherwise.
     */
    private static function clientIp(): string
    {
        // Direct remote address is always the safest default.
        $direct = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        // Trust forwarded headers only if explicitly enabled. The Container
        // sets a static flag based on config('app.trust_proxy').
        if (self::$trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $first = trim($forwarded[0]);
            if ($first !== '') {
                return $first;
            }
        }
        return $direct;
    }

    /**
     * @internal Set by the front controller when a trusted reverse proxy is
     *           configured via ADMIN_IP_ALLOWLIST or app.trust_proxy.
     */
    public static bool $trustProxy = false;
}
