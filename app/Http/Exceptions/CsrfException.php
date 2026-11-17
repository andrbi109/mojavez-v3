<?php
declare(strict_types=1);

namespace App\Http\Exceptions;

use RuntimeException;

/**
 * Thrown when CSRF verification fails on a mutating request.
 * Maps to HTTP 419 (the Laravel convention; matches CODING_CONVENTIONS §6.2).
 *
 * Fix S1: gateway had no CSRF at all; v3 throws this on every mutating
 * request that lacks a valid `_csrf` token (form) or `X-CSRF-TOKEN` header.
 */
final class CsrfException extends RuntimeException
{
    public function __construct(string $message = 'CSRF token validation failed')
    {
        parent::__construct($message, 419);
    }
}
