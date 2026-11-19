<?php
declare(strict_types=1);

namespace App\Events\Events;

/**
 * Event: SyncCycleCompleted (NEW in v3).
 *
 * Fired at the end of a full golden-link sync cycle (scrape → match → act
 * → notify). Carries a summary of what happened in the cycle so the
 * /admin/sync/status panel can show the latest cycle stats.
 */
final class SyncCycleCompleted extends AbstractEvent
{
    public const TYPE = 'sync_cycle_completed';
}
