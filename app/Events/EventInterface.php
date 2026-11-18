<?php
declare(strict_types=1);

namespace App\Events;

/**
 * Marker interface for events.
 * Events are immutable DTOs carrying data about something that happened.
 */
interface EventInterface
{
    /**
     * Event type name — used for routing in subscribers and SSE.
     */
    public function type(): string;

    /**
     * Payload as array — for JSON serialization (logs, SSE, etc.).
     *
     * @return array<string,mixed>
     */
    public function payload(): array;
}
