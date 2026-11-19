<?php
declare(strict_types=1);

namespace App\Events\Events;

/**
 * Event: BatchProgress.
 * Fired during CSV batch processing to report row progress.
 */
final class BatchProgress extends AbstractEvent
{
    public const TYPE = 'batch_progress';
}
