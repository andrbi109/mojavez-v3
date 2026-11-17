<?php
declare(strict_types=1);

namespace App\Events\Events;

/**
 * Event: LicenseIssuedOnNational (NEW in v3).
 *
 * Fired when the scraper discovers a license has been issued on the national
 * gateway (qr.mojavez.ir) — i.e. the upstream track page shows status=active.
 * This is the trigger for the golden-link matcher: SyncSubscriber enqueues a
 * SyncJob on receipt, which runs PatternMatcher::matchQuorum against all
 * pending applicants.
 */
final class LicenseIssuedOnNational extends AbstractEvent
{
    public const TYPE = 'license_issued_on_national';
}
