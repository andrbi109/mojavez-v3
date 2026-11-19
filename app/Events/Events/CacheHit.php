<?php
declare(strict_types=1);

namespace App\Events\Events;

/**
 * Event: CacheHit.
 * Fired when a search request is served from the local cache (no upstream call).
 */
final class CacheHit extends AbstractEvent
{
    public const TYPE = 'cache_hit';
}
