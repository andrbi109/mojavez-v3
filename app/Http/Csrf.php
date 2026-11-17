<?php
declare(strict_types=1);

namespace App\Http;

/**
 * CSRF token manager — ALWAYS ON (fix S1 from gateway).
 *
 * Lifecycle:
 *  - token() generates and persists a 64-hex-char token in the session
 *    (bin2hex(random_bytes(32)) = 256 bits entropy).
 *  - verify() compares the provided token with hash_equals (constant-time).
 *  - AJAX callers may send the token via the X-CSRF-TOKEN header instead
 *    of a hidden form field.
 *
 * CODING_CONVENTIONS §6.2: every mutating form (`<form method=post>`) must
 * include `<?= Csrf::field() ?>`, and every controller handling a mutating
 * request must call `Csrf::verify()` before doing any work.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public function __construct(
        private readonly Session $session
    ) {}

    /**
     * Get (or lazily create) the CSRF token for this session.
     */
    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    /**
     * Verify the provided token against the session token (constant-time).
     */
    public function verify(?string $provided): bool
    {
        $known = $this->session->get(self::SESSION_KEY);
        if (!is_string($known) || $known === '' || $provided === null || $provided === '') {
            // Run a dummy comparison to keep timing uniform.
            hash_equals('x', 'y');
            return false;
        }
        return hash_equals($known, $provided);
    }

    /**
     * Render the hidden `<input>` field for use inside a `<form>`.
     */
    public function field(): string
    {
        $token = $this->token();
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Read the token from a request body or X-CSRF-TOKEN header.
     *
     * @param array<string,mixed>|null $body
     */
    public function readProvided(?array $body, ?string $header): ?string
    {
        if ($body !== null && isset($body['_csrf']) && is_string($body['_csrf'])) {
            return $body['_csrf'];
        }
        return $header;
    }
}
