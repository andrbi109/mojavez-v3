<?php
declare(strict_types=1);

namespace App\Events;

/**
 * Minimal EventBus — Phase 1 placeholder.
 *
 * This class exists so ModuleLoader's type-hint (`App\Events\EventBus`) is
 * satisfied. Phase 4 will replace the in-memory implementation with a real
 * event bus backed by the `events` table for cross-process pub/sub.
 *
 * Per docs/00-MASTER.md §13.3 and docs/integration-contract.md §5 (Contract D),
 * the real Phase 4 EventBus will:
 *  - Dispatch events to in-process subscribers (synchronous).
 *  - Optionally publish to the `events` table for the Worker to drain
 *    (async subscribers — useful for slow handlers like SMS / email).
 *
 * For Phase 1+2 the EventBus is a no-op: subscribe() registers handlers
 * but publish() does nothing. This lets the kernel boot + ModuleLoader
 * run its full discovery/register/boot pipeline without crashing.
 */
final class EventBus
{
    /** @var array<string, array<int, callable|array{string,string}>> */
    private array $subscribers = [];

    /**
     * Register a subscriber for an event name.
     *
     * @param callable|array{string,string} $handler
     */
    public function subscribe(string $eventName, callable|array $handler): void
    {
        $this->subscribers[$eventName][] = $handler;
    }

    /**
     * Publish an event to all subscribers.
     *
     * Phase 1: no-op (no real subscribers registered yet — Core::subscribers()
     * returns []). Phase 4 will make this actually invoke subscribers + persist
     * to the events table.
     *
     * @param array<string,mixed> $payload
     */
    public function publish(string $eventName, array $payload = []): void
    {
        foreach ($this->subscribers[$eventName] ?? [] as $handler) {
            if (is_array($handler)) {
                [$class, $method] = $handler;
                // Resolve via container in Phase 4 — for now, just skip.
                continue;
            }
            $handler($payload);
        }
    }

    /**
     * Count subscribers (test + diagnostic helper).
     */
    public function subscriberCount(string $eventName = ''): int
    {
        if ($eventName === '') {
            return array_sum(array_map('count', $this->subscribers));
        }
        return count($this->subscribers[$eventName] ?? []);
    }
}
