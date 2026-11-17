<?php
declare(strict_types=1);

use App\Http\Exceptions\MethodNotAllowedException;
use App\Http\Exceptions\RouteNotFoundException;
use App\Http\Router;
use App\Support\StructuredLogger;

require_once __DIR__ . '/../TestCase.php';

final class RouterTest extends TestCase
{
    public function name(): string
    {
        return 'RouterTest';
    }

    public function run(): void
    {
        $logger = new StructuredLogger(sys_get_temp_dir() . '/router-test.log', 'debug');
        $router = new Router($logger);

        // 1. Simple literal match.
        $router->get('/health', 'HealthController', 'health');
        $matched = $router->match('GET', '/health');
        $this->assertSame('HealthController', $matched['handler'], 'literal route matched');
        $this->assertSame('health', $matched['action'], 'action is correct');
        $this->assertSame([], $matched['params'], 'no params');

        // 2. Trailing slash is optional (fix 004-06).
        $matched = $router->match('GET', '/health/');
        $this->assertSame('HealthController', $matched['handler'], 'trailing-slash variant matched');

        // 3. Parametric route — {name} → [^/]+.
        $router->get('/api/v1/applicants/{code}', 'ApplicantController', 'show');
        $matched = $router->match('GET', '/api/v1/applicants/HG-ABC12345');
        $this->assertSame('ApplicantController', $matched['handler'], 'param route matched');
        $this->assertSame('HG-ABC12345', $matched['params']['code'], 'param value captured');

        // 4. Constrained parametric route — {id:\d+} → digits only.
        $router->get('/api/v1/licenses/{id:\d+}', 'LicenseController', 'show');
        $matched = $router->match('GET', '/api/v1/licenses/42');
        $this->assertSame('LicenseController', $matched['handler'], 'constrained route matched');
        $this->assertSame('42', $matched['params']['id'], 'constrained param value');
        // Non-digit should NOT match this route (would throw RouteNotFoundException).
        $threw = false;
        try {
            $router->match('GET', '/api/v1/licenses/abc');
        } catch (RouteNotFoundException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'constrained route rejects non-matching param');

        // 5. First-match wins — earlier routes take precedence over later catch-alls.
        $router->get('/saved', 'SavedController');
        $router->get('/{id}', 'CatchAllController');
        $matched = $router->match('GET', '/saved');
        $this->assertSame('SavedController', $matched['handler'], 'first-match wins over catch-all');
        $matched = $router->match('GET', '/anything-else');
        $this->assertSame('CatchAllController', $matched['handler'], 'catch-all matches unknown paths');

        // 6. Method-not-allowed when path matches but method doesn't.
        $router->post('/login', 'AuthController', 'login');
        $threw = false;
        $allowed = [];
        try {
            $router->match('GET', '/login');
        } catch (MethodNotAllowedException $e) {
            $threw = true;
            $allowed = $e->allowedMethods();
        }
        $this->assertTrue($threw, 'wrong method throws MethodNotAllowedException');
        $this->assertContains('POST', $allowed, 'allowed methods list contains POST');

        // 7. Route-not-found when no route matches.
        $threw = false;
        try {
            $router->match('GET', '/totally/unknown/path');
        } catch (RouteNotFoundException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'unknown path throws RouteNotFoundException');

        // 8. count() and routes() accessors.
        $this->assertTrue($router->count() >= 5, 'count() returns at least the routes we registered');
        $this->assertNotNull($router->routes(), 'routes() returns the array');
    }

    /**
     * @param array<int,string> $haystack
     */
    private function assertContains(string $needle, array $haystack, string $message = ''): void
    {
        $this->assertTrue(in_array($needle, $haystack, true), $message ?: "$needle not found in array");
    }
}

return new RouterTest();
