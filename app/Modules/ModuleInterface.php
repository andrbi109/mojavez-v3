<?php
declare(strict_types=1);

namespace App\Modules;

/**
 * Formal contract every module under app/Modules/<Name>/Module.php implements.
 *
 * A module is a bounded business context with:
 *  - controllers / services / domain / infrastructure / persistence
 *  - its own migration files (owned by the module)
 *  - its own routes (appended to the global router at boot)
 *  - its own event subscribers (attached to the EventBus at boot)
 *
 * ModuleLoader discovers modules by scanning app/Modules/<Name>/Module.php and
 * invoking them in dependency order:
 *   1. register()  — declare services & interfaces into the Container.
 *   2. boot()       — perform any cross-module wiring (after all modules registered).
 *   3. routes()     — return route definitions for the router.
 *   4. migrations() — return migration filenames relative to the module dir.
 *   5. subscribers() — return event => handler mappings for the EventBus.
 *
 * The ownedTables() method declares which DB tables this module owns. CI
 * (tools/check_schema_ownership.py) enforces this matrix before any merge.
 * See docs/SCHEMA_OWNERSHIP.md.
 *
 * Design rationale (fix from docs/03-strategy-and-risk.md §3.1 & §8.5):
 *   - Provides isolated loading of external modules (gateway as a module,
 *     not inlined into the flat structure of v2).
 *   - Standard contract prevents cultural drift between Core and
 *     NationalGateway development (§5.11).
 *   - Schema ownership matrix prevents drift schema in the new monorepo (§5.7).
 */
interface ModuleInterface
{
    /**
     * Unique module name (snake_case, e.g. 'core', 'national_gateway', 'api',
     * 'queue', 'reporting').
     */
    public function name(): string;

    /**
     * Module version (SemVer, e.g. '1.0.0').
     * Stored in the `migrations` table.module column for traceability.
     */
    public function version(): string;

    /**
     * Register services & dependencies into the Container.
     * Called BEFORE boot() so all modules have a chance to declare their
     * bindings before any module resolves a dependency from another module.
     */
    public function register(ModuleLoader $loader): void;

    /**
     * Boot the module — perform any cross-module wiring.
     * Called AFTER all modules have called register().
     */
    public function boot(ModuleLoader $loader): void;

    /**
     * Module route definitions, appended to the global Router.
     *
     * Each entry is an array: ['method' => 'GET'|'POST'|..., 'path' => '/...', 'handler' => ClassName::class, 'action' => 'methodName']
     *
     * @return array<int, array{method:string,path:string,handler:string,action:string}>
     */
    public function routes(): array;

    /**
     * Migration files relative to the module's directory.
     * MigrationRunner will execute them in array order, tracked by filename
     * + SHA-256 checksum in the `migrations` table.
     *
     * @return array<int, string>
     */
    public function migrations(): array;

    /**
     * Event subscribers: event name => [handler, ...].
     * ModuleLoader::collectSubscribers() attaches these to the EventBus.
     *
     * @return array<string, array<int, callable|array{string,string}>>
     */
    public function subscribers(): array;

    /**
     * Tables owned by this module. Used by the schema-ownership CI check.
     *
     * @return array<int, string>
     */
    public function ownedTables(): array;
}
