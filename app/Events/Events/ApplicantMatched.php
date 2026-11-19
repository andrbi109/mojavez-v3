<?php
declare(strict_types=1);

namespace App\Events\Events;

/**
 * Event: ApplicantMatched (NEW in v3).
 *
 * Fired when the PatternMatcher successfully links a national license to a
 * local applicant (AutoConfirmed confidence >= 90). NotificationSubscriber
 * listens for this and sends SMS + in-app notification to the applicant.
 *
 * For OperatorFlag (confidence 50), the event is NOT fired — the match is
 * only logged to sync_audit_log pending manual review.
 */
final class ApplicantMatched extends AbstractEvent
{
    public const TYPE = 'applicant_matched';
}
