<?php
declare(strict_types=1);

namespace App\Http;

/**
 * HTTP response value object.
 *
 * Supports both HTML and JSON bodies (the front controller and controllers
 * build responses, the front controller emits them).
 *
 * Headers are an ordered map; setting the same header twice overwrites
 * (use addHeader for append-style).
 *
 * Robustness: send() guards against header injection by stripping CR/LF
 * from header values (fix inherited from v2 — CODING_CONVENTIONS §6).
 */
final class Response
{
    /**
     * Default security headers applied to every response. Inherited from v2.
     *
     * @var array<string,string>
     */
    public const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=(), interest-cohort=()',
        'X-Robots-Tag'           => 'noindex, nofollow, noarchive, nosnippet, noimageindex',
        'Cross-Origin-Opener-Policy'   => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-site',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma'                  => 'no-cache',
        'Expires'                 => '0',
    ];

    /** CSP for v3 — fixes gateway's CDN leak and v2's unsafe-eval. */
    public const CSP = "default-src 'self'; script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; font-src 'self' data:; "
        . "img-src 'self' data: blob:; connect-src 'self'; object-src 'self'; "
        . "frame-src 'self'; base-uri 'self'; frame-ancestors 'self'; "
        . "form-action 'self'";

    /** @var array<string,string> */
    private array $headers = [];

    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        // Always apply the global security headers + CSP.
        foreach (self::SECURITY_HEADERS as $k => $v) {
            $this->headers[$k] = $v;
        }
        $this->headers['Content-Security-Policy'] = self::CSP;
        // HSTS only on HTTPS (front controller decides).
        foreach ($headers as $k => $v) {
            $this->headers[$k] = $v;
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self((string)$body, $status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function noContent(int $status = 204): self
    {
        return new self('', $status);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Emit the response to the client.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                // Strip CR/LF to prevent header injection.
                $clean = str_replace(["\r", "\n"], '', $value);
                header($name . ': ' . $clean);
            }
        }
        if ($this->body !== '') {
            echo $this->body;
        }
    }
}
