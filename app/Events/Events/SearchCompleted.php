<?php
declare(strict_types=1);

namespace App\Events\Events;

/**
 * Event: SearchCompleted.
 * Fired when an upstream license search finishes successfully.
 */
final class SearchCompleted extends AbstractEvent
{
    public const TYPE = 'search_completed';
}
