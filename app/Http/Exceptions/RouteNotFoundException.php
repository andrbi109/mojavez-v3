<?php
declare(strict_types=1);

namespace App\Http\Exceptions;

use RuntimeException;

/**
 * Thrown when no route matches the request path/method.
 */
final class RouteNotFoundException extends RuntimeException
{
    public function __construct(string $path, string $method)
    {
        parent::__construct("No route matches $method $path", 404);
    }
}
