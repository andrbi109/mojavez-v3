<?php
declare(strict_types=1);

namespace App\Events;

/**
 * Subscriber interface — observers that react to events.
 */
interface SubscriberInterface
{
    /**
     * @return list<string>  Event types this subscriber cares about. Use '*' for wildcard.
     */
    public function subscribedTo(): array;

    public function handle(EventInterface $event): void;
}
