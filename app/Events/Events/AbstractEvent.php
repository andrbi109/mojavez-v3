<?php
declare(strict_types=1);

namespace App\Events\Events;

use App\Events\EventInterface;

/**
 * Base class for all events — provides type() + payload() boilerplate.
 *
 * Subclasses declare TYPE constant and pass payload to the constructor.
 * Events are immutable: payload is set at construction and read-only after.
 */
abstract class AbstractEvent implements EventInterface
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        private readonly array $payload = []
    ) {}

    public function type(): string
    {
        // Each subclass overrides TYPE constant — declared as `final public const TYPE`
        // but PHP doesn't allow abstract constants, so we read it via (static)::TYPE
        // which resolves to the concrete subclass's value.
        /** @var string $type */
        $type = (string)(static::TYPE);
        return $type;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
