<?php
declare(strict_types=1);

namespace App\Events\Events;

/**
 * Event: LicenseSaved.
 * Fired when a national license has been saved to the local cache.
 */
final class LicenseSaved extends AbstractEvent
{
    public const TYPE = 'license_saved';
}
