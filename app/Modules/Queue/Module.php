<?php
declare(strict_types=1);

namespace App\Modules\Queue;

use App\Modules\ModuleInterface;
use App\Modules\ModuleLoader;

/**
 * Queue module — owner of the async infrastructure tables.
 *
 * Per docs/SCHEMA_OWNERSHIP.md §2 — Queue owns 4 meta tables:
 *   events, jobs, batches (CSV batch jobs), worker_state.
 *
 * This module exists primarily to scope table ownership correctly. The
 * actual EventBus, JobQueue, and Worker classes live in app/Events/ and
 * app/Queue/ (flat, not under the module dir) because they're shared
 * infrastructure used by every other module.
 *
 * Phase 4 wires:
 *   - app/Events/EventBus (persists to events table)
 *   - app/Queue/JobQueue + JobRepository (persists to jobs table)
 *   - app/Queue/Worker (drains jobs table via poor man's cron)
 */
final class Module implements ModuleInterface
{
    public function name(): string
    {
        return 'queue';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleLoader $loader): void
    {
        // No bindings here — EventBus and JobQueue are wired in
        // public/index.php and scripts/migrate.php because they need
        // the Connection (which is built after the Container boots).
    }

    public function boot(ModuleLoader $loader): void
    {
        // No cross-module wiring.
    }

    public function routes(): array
    {
        return [];
    }

    public function migrations(): array
    {
        return [
            '20261117_001_events_and_jobs_tables.sql',
        ];
    }

    public function subscribers(): array
    {
        return [];
    }

    public function ownedTables(): array
    {
        // Per docs/SCHEMA_OWNERSHIP.md §2 — Queue owns these meta tables.
        return [
            'events',
            'jobs',
            'batches',
            'worker_state',
        ];
    }
}
