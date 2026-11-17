<?php
declare(strict_types=1);

namespace App\Modules;

use App\Container;
use App\Events\EventBus;
use App\Http\Router;
use App\Shared\Persistence\MigrationRunner;
use App\Shared\Support\StructuredLogger;
use RuntimeException;

/**
 * Discovers and boots modules under app/Modules/<Name>/Module.php.
 *
 * Boot order:
 *   1. discover()       — scan app/Modules/*/Module.php for ModuleInterface
 *                         instances, instantiate them (no constructor args
 *                         by contract).
 *   2. registerAll()    — call register() on each (declare services).
 *   3. bootAll()        — call boot() on each (cross-module wiring).
 *   4. collectRoutes()  — gather routes() from each, register on Router.
 *   5. collectMigrations() — gather migrations() from each, hand to
 *                             MigrationRunner.
 *   6. collectSubscribers() — gather subscribers() from each, attach to
 *                              EventBus.
 *
 * Module dependency order is fixed at the moment by the order of the
 * $MODULE_BOOT_ORDER constant. Core always boots first (it owns the
 * fundamental entities that every other module reads through ACLs).
 *
 * Why a loader (not lazy loading by namespace): the existence of a
 * Module.php file at a fixed path is the discovery contract — no
 * reflection scanning of namespaces (which would be slow and ambiguous
 * without an autoloader registry).
 */
final class ModuleLoader
{
    /**
     * Boot order — Core first, then Queue (so events have a bus), then
     * NationalGateway (depends on Core + Queue), then Api (depends on
     * Core + NationalGateway read), then Reporting (depends on Core).
     */
    private const MODULE_BOOT_ORDER = [
        'Core',
        'Queue',
        'NationalGateway',
        'Api',
        'Reporting',
    ];

    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly EventBus $eventBus,
        private readonly MigrationRunner $migrationRunner,
        private readonly StructuredLogger $logger
    ) {}

    /**
     * Scan app/Modules/*/Module.php and instantiate each module.
     */
    public function discover(string $modulesRoot): void
    {
        $this->logger->debug('Discovering modules', ['root' => $modulesRoot]);

        foreach (self::MODULE_BOOT_ORDER as $name) {
            $moduleFile = $modulesRoot . '/' . $name . '/Module.php';
            if (!is_file($moduleFile)) {
                // Module not implemented yet (e.g. Phase 1 ships only Core).
                $this->logger->debug('Module not implemented yet, skipping', ['module' => $name]);
                continue;
            }
            $class = 'App\\Modules\\' . $name . '\\Module';
            if (!class_exists($class)) {
                throw new RuntimeException("Module class $class not autoloadable");
            }
            $instance = new $class();
            if (!$instance instanceof ModuleInterface) {
                throw new RuntimeException("$class does not implement ModuleInterface");
            }
            $this->modules[$instance->name()] = $instance;
            $this->logger->info('Module discovered', [
                '_module' => $instance->name(),
                'version' => $instance->version(),
            ]);
        }
    }

    /**
     * @return array<string, ModuleInterface>
     */
    public function modules(): array
    {
        return $this->modules;
    }

    /**
     * Call register() on each discovered module in boot order.
     */
    public function registerAll(): void
    {
        foreach ($this->modules as $module) {
            $module->register($this);
            $this->logger->debug('Module registered', ['_module' => $module->name()]);
        }
    }

    /**
     * Call boot() on each discovered module in boot order.
     */
    public function bootAll(): void
    {
        foreach ($this->modules as $module) {
            $module->boot($this);
            $this->logger->debug('Module booted', ['_module' => $module->name()]);
        }
    }

    /**
     * Gather routes() from each module and register them on the Router.
     */
    public function collectRoutes(): void
    {
        foreach ($this->modules as $module) {
            foreach ($module->routes() as $route) {
                $this->router->add(
                    $route['method'],
                    $route['path'],
                    $route['handler'],
                    $route['action']
                );
            }
            $this->logger->debug('Module routes collected', [
                '_module' => $module->name(),
                'count' => count($module->routes()),
            ]);
        }
    }

    /**
     * Gather migrations() from each module and feed them to MigrationRunner.
     */
    public function collectMigrations(string $modulesRoot): void
    {
        foreach ($this->modules as $module) {
            foreach ($module->migrations() as $relativePath) {
                $absolute = $modulesRoot . '/' . ucfirst($module->name())
                          . '/migrations/' . $relativePath;
                $this->migrationRunner->enqueue($absolute, $module->name());
            }
        }
    }

    /**
     * Gather subscribers() from each module and attach them to the EventBus.
     */
    public function collectSubscribers(): void
    {
        foreach ($this->modules as $module) {
            foreach ($module->subscribers() as $eventName => $handlers) {
                foreach ($handlers as $handler) {
                    $this->eventBus->subscribe($eventName, $handler);
                }
            }
        }
    }

    /**
     * The DI container — exposed for modules' register() to bind services.
     */
    public function container(): Container
    {
        return $this->container;
    }
}
