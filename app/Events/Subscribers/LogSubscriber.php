<?php
declare(strict_types=1);

namespace App\Events\Subscribers;

use App\Events\EventInterface;
use App\Events\SubscriberInterface;
use App\Shared\Support\StructuredLogger;

/**
 * Logs every event for audit purposes.
 *
 * Subscribes to '*' (wildcard) — receives all events. Writes an info-level
 * log line per event with the event type + payload, so the audit trail in
 * storage/logs/ captures every state transition in the system.
 */
final class LogSubscriber implements SubscriberInterface
{
    public function __construct(private readonly StructuredLogger $logger) {}

    public function subscribedTo(): array
    {
        return ['*'];
    }

    public function handle(EventInterface $event): void
    {
        $this->logger->info('Event: ' . $event->type(), $event->payload());
    }
}
