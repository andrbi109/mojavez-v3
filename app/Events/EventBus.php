<?php
declare(strict_types=1);

namespace App\Events;

use App\Shared\Persistence\Connection;
use App\Shared\Support\StructuredLogger;

/**
 * Event bus — in-process pub/sub with persistence.
 *
 * Persists every event to the `events` table (for audit + SSE replay)
 * and dispatches to in-memory subscribers synchronously.
 *
 * Cross-process delivery (to long-lived SSE connections in other PHP
 * processes) is handled by the SseController polling eventsSince(lastId)
 * for events newer than the last seen ID.
 *
 * Per docs/00-MASTER.md §13.1 — 9 event types, 3 subscribers:
 *   - LogSubscriber (wildcard '*')
 *   - SyncSubscriber (LicenseIssuedOnNational → enqueues SyncJob)
 *   - NotificationSubscriber (ApplicantMatched → SMS + in-app notification)
 *
 * Resilience note (004-04 carry-over): eventsSince() and lastEventId()
 * wrap their DB access in try-catch so a missing `events` table (before
 * migrations run) degrades gracefully to empty / 0 instead of crashing.
 */
final class EventBus
{
    /** @var array<string, list<SubscriberInterface>> */
    private array $subscribers = [];

    public function __construct(
        private readonly StructuredLogger $logger,
        private ?Connection $connection = null
    ) {}

    /**
     * Subscribe a SubscriberInterface instance to one or more event types.
     */
    public function subscribe(SubscriberInterface $subscriber): void
    {
        foreach ($subscriber->subscribedTo() as $type) {
            $this->subscribers[$type][] = $subscriber;
        }
    }

    /**
     * Convenience: subscribe a callable or [instance, method] tuple for a
     * single event type. Used by ModuleLoader::collectSubscribers() for
     * module-declared handlers that don't warrant a full Subscriber class.
     *
     * @param callable|array{string,string} $handler
     */
    public function on(string $eventType, callable|array $handler): void
    {
        $this->subscribers[$eventType][] = new class($handler) implements SubscriberInterface {
            public function __construct(private readonly mixed $handler) {}
            public function subscribedTo(): array
            {
                return []; // not used — subscribed directly via on()
            }
            public function handle(EventInterface $event): void
            {
                if (is_array($this->handler)) {
                    [$class, $method] = $this->handler;
                    if (is_object($class)) {
                        $class->$method($event);
                    } elseif (class_exists($class)) {
                        $class::$method($event);
                    }
                } else {
                    ($this->handler)($event);
                }
            }
        };
    }

    /**
     * Dispatch an event to all subscribers + persist for SSE replay.
     */
    public function dispatch(EventInterface $event): void
    {
        // Persist for audit + SSE replay (best-effort — table may not exist yet).
        $this->persist($event);

        // Dispatch to in-memory subscribers.
        $type = $event->type();
        foreach ($this->subscribers[$type] ?? [] as $subscriber) {
            try {
                $subscriber->handle($event);
            } catch (\Throwable $e) {
                $this->logger->error('Subscriber failed', [
                    'event_type' => $type,
                    'subscriber' => $subscriber::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        // Also dispatch to '*' (wildcard) subscribers — e.g. LogSubscriber.
        foreach ($this->subscribers['*'] ?? [] as $subscriber) {
            try {
                $subscriber->handle($event);
            } catch (\Throwable $e) {
                $this->logger->error('Wildcard subscriber failed', [
                    'event_type' => $type,
                    'subscriber' => $subscriber::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function persist(EventInterface $event): void
    {
        if ($this->connection === null) {
            return;
        }
        try {
            $this->connection->execute(
                'INSERT INTO events (type, payload_json) VALUES (?, ?)',
                [$event->type(), json_encode($event->payload(), JSON_UNESCAPED_UNICODE)]
            );
        } catch (\Throwable $e) {
            // Table may not exist yet (before migrations run) — degrade gracefully.
            $this->logger->warning('Event persist failed', [
                'type' => $event->type(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Read events newer than the given ID (for SSE replay).
     *
     * @return list<array{id:int,type:string,payload:array<string,mixed>,created_at:string}>
     */
    public function eventsSince(int $lastId, int $limit = 100): array
    {
        if ($this->connection === null) {
            return [];
        }
        try {
            $rows = $this->connection->all(
                'SELECT id, type, payload_json, created_at FROM events WHERE id > ? ORDER BY id ASC LIMIT ?',
                [$lastId, $limit]
            );
            $out = [];
            foreach ($rows as $row) {
                $payload = json_decode((string)$row['payload_json'], true);
                $out[] = [
                    'id' => (int)$row['id'],
                    'type' => (string)$row['type'],
                    'payload' => is_array($payload) ? $payload : [],
                    'created_at' => (string)$row['created_at'],
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            // Table may not exist yet — degrade gracefully.
            return [];
        }
    }

    public function lastEventId(): int
    {
        if ($this->connection === null) {
            return 0;
        }
        try {
            $v = $this->connection->scalar('SELECT MAX(id) FROM events');
            return $v === null || $v === false ? 0 : (int)$v;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Count subscribers (test + diagnostic helper).
     */
    public function subscriberCount(string $eventType = ''): int
    {
        if ($eventType === '') {
            return array_sum(array_map('count', $this->subscribers));
        }
        return count($this->subscribers[$eventType] ?? []);
    }

    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }
}
