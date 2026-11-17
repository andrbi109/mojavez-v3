<?php
declare(strict_types=1);

namespace App\Http;

use App\Http\Exceptions\MethodNotAllowedException;
use App\Http\Exceptions\RouteNotFoundException;
use App\Shared\Support\StructuredLogger;
use Closure;

/**
 * Regex-based first-match router.
 *
 * Routes are registered as:
 *   $router->add('GET', '/api/licenses/{id}', ClassName::class, 'methodName')
 *
 * Placeholders:
 *   {name}       matches [^/]+
 *   {name:\d+}   matches the given regex
 *
 * Trailing slash is optional on every pattern: `/?` is appended to every
 * compiled regex (fix 004-06 from gateway — the bare `/` route failed).
 *
 * First-match wins: route order in config/routes.php and module route
 * collectors matters. Always register specific routes (`/saved`, `/export`,
 * `/{id}/track`) before the generic catch-all (`/{id}`).
 */
final class Router
{
    /**
     * @var array<int, array{method:string,pattern:string,handler:string,action:string}>
     */
    private array $routes = [];

    /** @var array<string, Closure> */
    private array $inlineHandlers = [];

    public function __construct(
        private readonly StructuredLogger $logger
    ) {}

    public function add(string $method, string $pattern, string $handler, string $action = '__invoke'): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'action'  => $action,
        ];
    }

    /**
     * Register an inline closure route (no controller class needed).
     *
     * @param Closure(Request, array<string,string>):Response $handler
     */
    public function inline(string $method, string $pattern, Closure $handler): void
    {
        $key = $method . ' ' . $pattern;
        $this->inlineHandlers[$key] = $handler;
        // Use a placeholder handler string with the same key so dispatch
        // can find the closure later.
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'handler' => '@inline:' . $key,
            'action'  => '__invoke',
        ];
    }

    public function get(string $p, string $h, string $a = '__invoke'): void
    {
        $this->add('GET', $p, $h, $a);
    }

    public function post(string $p, string $h, string $a = '__invoke'): void
    {
        $this->add('POST', $p, $h, $a);
    }

    public function put(string $p, string $h, string $a = '__invoke'): void
    {
        $this->add('PUT', $p, $h, $a);
    }

    public function delete(string $p, string $h, string $a = '__invoke'): void
    {
        $this->add('DELETE', $p, $h, $a);
    }

    public function any(string $p, string $h, string $a = '__invoke'): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
            $this->add($m, $p, $h, $a);
        }
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->routes);
    }

    /**
     * @return array<int, array{method:string,pattern:string,handler:string,action:string}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Match a path against all registered routes.
     *
     * @return array{handler:string,action:string,params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $allowedMethods = [];
        foreach ($this->routes as $route) {
            $params = $this->matchPattern($route['pattern'], $path);
            if ($params === null) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowedMethods[] = $route['method'];
                continue;
            }
            return [
                'handler' => $route['handler'],
                'action'  => $route['action'],
                'params'  => $params,
            ];
        }
        if ($allowedMethods !== []) {
            throw new MethodNotAllowedException($path, $method, array_unique($allowedMethods));
        }
        throw new RouteNotFoundException($path, $method);
    }

    /**
     * Compile a route pattern to a regex and test the path against it.
     *
     * @return array<string,string>|null
     */
    private function matchPattern(string $pattern, string $path): ?array
    {
        // Convert {name} → (?P<name>[^/]+), and {name:\d+} → (?P<name>\d+).
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#',
            static function (array $m): string {
                $name = $m[1];
                $constraint = $m[2] ?? '[^/]+';
                return '(?P<' . $name . '>' . $constraint . ')';
            },
            $pattern
        );
        $regex = '#^' . $regex . '/?$#u';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }
        $params = [];
        foreach ($matches as $k => $v) {
            if (is_string($k)) {
                $params[$k] = $v;
            }
        }
        return $params;
    }

    /**
     * Look up an inline handler (registered via `inline()`).
     */
    public function inlineHandler(string $key): ?Closure
    {
        return $this->inlineHandlers[$key] ?? null;
    }
}
