<?php
declare(strict_types=1);

namespace App\Http\Exceptions;

use RuntimeException;

/**
 * Thrown when the path matches a route but the HTTP method does not.
 * Carries the list of allowed methods so the front controller can emit
 * an `Allow` header (HTTP 405).
 */
final class MethodNotAllowedException extends RuntimeException
{
    /** @var array<int,string> */
    private array $allowedMethods;

    /**
     * @param array<int,string> $allowedMethods
     */
    public function __construct(string $path, string $method, array $allowedMethods)
    {
        $this->allowedMethods = $allowedMethods;
        parent::__construct("Method $method not allowed for $path", 405);
    }

    /**
     * @return array<int,string>
     */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
