<?php
declare(strict_types=1);

namespace App\Events\Events;

/**
 * Event: JobFailed.
 * Fired when a queued job fails (after all retry attempts).
 */
final class JobFailed extends AbstractEvent
{
    public const TYPE = 'job_failed';
}
