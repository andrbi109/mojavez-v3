<?php
declare(strict_types=1);

namespace App;

use App\Http\Router;
use App\Http\Session;
use App\Http\Csrf;
use App\Http\IpRateLimiter;
use App\Modules\ModuleLoader;
use App\Shared\Persistence\Connection;
use App\Shared\Persistence\MigrationRunner;
use App\Shared\Support\Config;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\Normalizer;
use App\Shared\Support\PersianDate;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;
use App\Shared\Support\CsvReader;
use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Minimal DI container — manual, no Composer.
 *
 * Capabilities:
 *  - bind(class, closure|instance) — register a factory or pre-built instance.
 *  - singleton(class, instance)    — register a memoized singleton.
 *  - make(class)                    — resolve a class:
 *      1. Check the singleton cache.
 *      2. Check the factory map → invoke closure.
 *      3. Autowire via ReflectionClass (constructor injection).
 *  - callSetter(object, methodName, dependency) — setter injection for
 *    cyclic dependency resolution (fix 003-01 from gateway — gateway used
 *    this exact pattern to break Container → Router → Container cycles).
 *
 * Singletons memoized in this Container:
 *  - Environment, Logger (passed in constructor)
 *  - Config, Hash, PersianDate, Validator, Normalizer, CsvReader
 *  - Session, Csrf
 *  - Connection (via Connection::instance() — true singleton via static)
 *  - MigrationRunner, Router, ModuleLoader
 *
 * This Container is the single source of truth for object construction;
 * no class instantiates its own dependencies through `new` outside of tests.
 */
final class Container
{
    /** Root project path (set by boot()) — used to find app/Modules/. */
    private string $rootPath = '';

    /** @var array<string, Closure|object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $singletons = [];

    /** @var array<string, true> */
    private array $resolving = [];  // cycle detection

    public function __construct(
        public readonly Environment $environment,
        public readonly StructuredLogger $logger
    ) {
        // The constructor params are themselves singletons.
        $this->singletons[Environment::class]     = $environment;
        $this->singletons[StructuredLogger::class] = $logger;
        // Self-register so autowired controllers (and any other class that
        // asks the Container for a Container) get THIS instance — with all
        // its module bindings — instead of a fresh empty one. Without this,
        // a controller's `$this->container->make(Connection::class)` (and
        // similar late-bound lookups) would throw "Cannot autowire
        // Connection: parameter $host has no type" because the new
        // Container has no factory for Connection. Phase 10 fix.
        $this->singletons[self::class] = $this;
    }

    /**
     * Boot the container: register all core bindings.
     *
     * Module-specific bindings are registered later by each module's
     * `register()` method (invoked by ModuleLoader).
     */
    public function boot(string $rootPath): void
    {
        $this->rootPath = $rootPath;
        $this->registerPaths($rootPath);
        $this->registerSupport();
        $this->registerPersistence();
        $this->registerHttp();
        $this->registerModules();
    }

    private function registerPaths(string $rootPath): void
    {
        // Initialise the Config singleton from config/.
        Config::init($rootPath . '/config');
    }

    private function registerSupport(): void
    {
        $this->singletons[Hash::class]        = new Hash();
        $this->singletons[PersianDate::class]  = new PersianDate();
        $this->singletons[Validator::class]    = new Validator();
        $this->singletons[Normalizer::class]   = new Normalizer();  // static-only class; instance for symmetry
        $this->singletons[CsvReader::class]    = new CsvReader();
    }

    private function registerPersistence(): void
    {
        // Connection is a TRUE singleton via its own static cache (fix 004-05).
        $this->factories[Connection::class] = fn(): Connection => Connection::instance(
            host: $this->environment->dbHost,
            port: $this->environment->dbPort,
            name: $this->environment->dbName,
            user: $this->environment->dbUser,
            pass: $this->environment->dbPass,
            charset: Config::get('database.charset', 'utf8mb4'),
            timezone: Config::get('database.timezone', '+00:00'),
            logger: $this->logger,
        );

        $this->factories[MigrationRunner::class] = fn(): MigrationRunner => new MigrationRunner(
            $this->make(Connection::class),
            $this->logger
        );

        // EventBus — persisted to the events table for SSE replay.
        $this->factories[\App\Events\EventBus::class] = fn(): \App\Events\EventBus => new \App\Events\EventBus(
            $this->logger,
            $this->make(Connection::class)
        );

        // Queue — JobRepository + JobQueue + Worker.
        $this->factories[\App\Queue\JobRepository::class] = fn(): \App\Queue\JobRepository => new \App\Queue\JobRepository(
            $this->make(Connection::class),
            $this->logger
        );
        $this->factories[\App\Queue\JobQueue::class] = fn(): \App\Queue\JobQueue => new \App\Queue\JobQueue(
            $this->make(\App\Queue\JobRepository::class),
            $this->logger
        );
        $this->factories[\App\Queue\Worker::class] = fn(): \App\Queue\Worker => new \App\Queue\Worker(
            $this->make(\App\Queue\JobQueue::class),
            $this->logger
        );
    }

    private function registerHttp(): void
    {
        // Session depends on environment (production → secure cookies).
        $this->factories[Session::class] = fn(): Session => new Session(
            secure: $this->environment->isProduction(),
            lifetime: $this->environment->sessionLifetime,
            name: 'MOJAVEZSESSID',
        );

        // Csrf depends on Session.
        $this->factories[Csrf::class] = fn(): Csrf => new Csrf(
            $this->make(Session::class)
        );

        // IpRateLimiter — IP + session based HTTP rate limiter (§12.6).
        // Depends on Connection, Session, and Logger.
        $this->factories[IpRateLimiter::class] = fn(): IpRateLimiter => new IpRateLimiter(
            $this->make(Connection::class),
            $this->make(Session::class),
            $this->logger
        );

        // Router depends on Logger.
        $this->factories[Router::class] = fn(): Router => new Router($this->logger);
    }

    private function registerModules(): void
    {
        // Build the ModuleLoader and let each module register its bindings.
        // Modules are discovered from app/Modules/<Name>/Module.php (PascalCase
        // directories) in the fixed boot order declared in ModuleLoader.
        $router = $this->make(Router::class);
        $eventBus = $this->make(\App\Events\EventBus::class);
        $migrationRunner = $this->make(MigrationRunner::class);

        $moduleLoader = new ModuleLoader(
            $this,
            $router,
            $eventBus,
            $migrationRunner,
            $this->logger
        );
        $modulesRoot = $this->rootPath . '/app/Modules';
        $moduleLoader->discover($modulesRoot);

        // register() each module — this binds factories into the Container.
        $moduleLoader->registerAll();

        // boot() each module — cross-module wiring (e.g. subscribers on EventBus).
        $moduleLoader->bootAll();

        // Keep the loader accessible for index.php to collect routes/subscribers.
        $this->singletons[ModuleLoader::class] = $moduleLoader;
    }

    /**
     * Bind a factory closure or pre-built instance to a class name.
     *
     * Accepts either a Closure (factory) or a pre-built object instance.
     * Closure is a subtype of object, so PHP 8.2 rejects the explicit
     * union `Closure|object` as redundant — we accept a plain object
     * parameter and check for Closure at runtime.
     *
     * @param Closure|object $factory
     */
    public function bind(string $class, object $factory): void
    {
        $this->factories[$class] = $factory;
    }

    /**
     * Bind a pre-built instance as a singleton.
     */
    public function singleton(string $class, object $instance): void
    {
        $this->singletons[$class] = $instance;
    }

    /**
     * Resolve a class out of the container.
     *
     * Resolution order:
     *  1. Already a singleton in the cache → return it.
     *  2. Factory closure → invoke, cache as singleton, return.
     *  3. Autowire via ReflectionClass — read constructor params, recursively
     *     make each one.
     */
    public function make(string $class): object
    {
        // 1. Memoized singleton.
        if (isset($this->singletons[$class])) {
            return $this->singletons[$class];
        }

        // Cycle detection.
        if (isset($this->resolving[$class])) {
            throw new RuntimeException("Circular dependency while resolving $class");
        }

        // 2. Factory closure.
        if (isset($this->factories[$class]) && $this->factories[$class] instanceof Closure) {
            $this->resolving[$class] = true;
            try {
                /** @var Closure $factory */
                $factory = $this->factories[$class];
                $instance = $factory($this);
                $this->singletons[$class] = $instance;  // memoize
                return $instance;
            } finally {
                unset($this->resolving[$class]);
            }
        }
        if (isset($this->factories[$class]) && is_object($this->factories[$class])) {
            // Pre-built instance bound as factory.
            $this->singletons[$class] = $this->factories[$class];
            return $this->factories[$class];
        }

        // 3. Autowire.
        $this->resolving[$class] = true;
        try {
            $reflection = new ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                throw new RuntimeException("Cannot instantiate $class — not instantiable");
            }
            $constructor = $reflection->getConstructor();
            if ($constructor === null) {
                $instance = new $class();
                $this->singletons[$class] = $instance;
                return $instance;
            }
            $args = [];
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    if ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                        continue;
                    }
                    if ($param->allowsNull()) {
                        $args[] = null;
                        continue;
                    }
                    throw new RuntimeException(
                        "Cannot autowire $class: parameter \${$param->getName()} has no type"
                    );
                }
                $depClass = $type->getName();
                $args[] = $this->make($depClass);
            }
            $instance = $reflection->newInstanceArgs($args);
            $this->singletons[$class] = $instance;
            return $instance;
        } finally {
            unset($this->resolving[$class]);
        }
    }

    /**
     * Setter injection — break cyclic dependencies by deferring the
     * resolution to after both sides are instantiated.
     *
     * Pattern (fix 003-01 from gateway):
     *   $container->callSetter($router, 'setContainer', $container);
     *
     * @param array<int,mixed> $args
     */
    public function callSetter(object $target, string $method, mixed ...$args): void
    {
        if (!method_exists($target, $method)) {
            throw new RuntimeException(
                get_class($target) . "::$method does not exist for setter injection"
            );
        }
        $target->$method(...$args);
    }

    /**
     * Does this Container have a binding for $class?
     */
    public function has(string $class): bool
    {
        return isset($this->singletons[$class]) || isset($this->factories[$class]) || class_exists($class);
    }

    /**
     * Flush all singletons — test helper.
     */
    public function flush(): void
    {
        $this->singletons = [];
        $this->factories = [];
        $this->resolving = [];
    }
}
