<?php
declare(strict_types=1);

use App\Container;
use App\Shared\Support\Environment;
use App\Shared\Support\StructuredLogger;

require_once __DIR__ . '/../TestCase.php';

final class ContainerTest extends TestCase
{
    public function name(): string
    {
        return 'ContainerTest';
    }

    public function run(): void
    {
        // Build a Container with a real Environment (pointed at a non-existent
        // .env so all defaults apply).
        $envFile = sys_get_temp_dir() . '/mojavez-v3-container-test-.env';
        @unlink($envFile);
        $environment = Environment::load($envFile);
        $logger = new StructuredLogger(sys_get_temp_dir() . '/container-test.log', 'debug');

        $container = new Container($environment, $logger);
        // Don't call boot(ROOT_PATH) since that requires the full config/ dir;
        // we test the public API directly.

        // 1. Environment is a singleton via constructor.
        $this->assertSame($environment, $container->environment, 'environment exposed');
        $this->assertSame($logger, $container->logger, 'logger exposed');

        // 2. bind() a closure → make() returns its result, memoized.
        $callCount = 0;
        $container->bind('TestService', function () use (&$callCount) {
            $callCount++;
            return new \stdClass();
        });
        $instance1 = $container->make('TestService');
        $instance2 = $container->make('TestService');
        $this->assertSame(1, $callCount, 'factory called once (memoized)');
        $this->assertSame($instance1, $instance2, 'same instance returned on second make()');

        // 3. bind() a pre-built instance.
        $prebuilt = new \stdClass();
        $prebuilt->marker = 'prebuilt';
        $container->bind('PrebuiltService', $prebuilt);
        $this->assertSame($prebuilt, $container->make('PrebuiltService'), 'prebuilt returned');

        // 4. singleton() explicit registration.
        $obj = new \stdClass();
        $container->singleton('SingletonService', $obj);
        $this->assertSame($obj, $container->make('SingletonService'), 'singleton returned');

        // 5. Autowire — instantiate a class with no constructor.
        $autowired = $container->make(\App\Shared\Support\Hash::class);
        $this->assertInstanceOf(\App\Shared\Support\Hash::class, $autowired, 'autowired Hash::class');
        // Memoized on subsequent calls.
        $this->assertSame($autowired, $container->make(\App\Shared\Support\Hash::class), 'autowire memoized');

        // 6. Cycle detection.
        // Use anonymous classes that depend on each other via constructor
        // types — emulated by binding a closure that calls make('CycleB')
        // while resolving CycleA, and vice versa.
        // We can't easily declare named cycle classes here, so we test that
        // make() throws RuntimeException on a self-cycle by binding a closure
        // that calls make on the same key.
        $container->bind('SelfCycle', function () use ($container) {
            return $container->make('SelfCycle');
        });
        $threw = false;
        try {
            $container->make('SelfCycle');
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'cycle detection throws RuntimeException');

        // 7. callSetter() — invoke a method on an object.
        $setterTarget = new class {
            public ?object $injected = null;
            public function setDependency(object $d): void
            {
                $this->injected = $d;
            }
        };
        $dep = new \stdClass();
        $container->callSetter($setterTarget, 'setDependency', $dep);
        $this->assertSame($dep, $setterTarget->injected, 'callSetter invokes method');

        // 8. callSetter() throws on missing method.
        $threw = false;
        try {
            $container->callSetter($setterTarget, 'nonExistentMethod');
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'callSetter throws on missing method');

        // 9. flush() clears all bindings — after flush, a previously-bound
        // service is no longer memoized (a fresh make() returns a new instance).
        $marker = new \stdClass();
        $marker->id = 'before-flush';
        $container->bind('stdClass', $marker);
        $before = $container->make('stdClass');
        $this->assertSame($marker, $before, 'pre-flush make returns bound instance');
        $container->flush();
        // After flush, the binding is gone — make() autowires stdClass
        // (no constructor) and returns a new instance each call.
        $after = $container->make('stdClass');
        $this->assertFalse($after === $before, 'flush clears singletons — make returns new instance');
    }
}

return new ContainerTest();
