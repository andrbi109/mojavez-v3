<?php
declare(strict_types=1);

namespace App\Events\Events;

/**
 * Event: SearchRequested.
 * Fired when a user initiates an upstream license search.
 */
final class SearchRequested extends AbstractEvent
{
    public const TYPE = 'search_requested';
}
