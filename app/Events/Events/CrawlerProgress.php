<?php
declare(strict_types=1);

namespace App\Events\Events;

/**
 * Event: CrawlerProgress.
 * Fired during crawl to report progress (page N of M, items found so far).
 */
final class CrawlerProgress extends AbstractEvent
{
    public const TYPE = 'crawler_progress';
}
