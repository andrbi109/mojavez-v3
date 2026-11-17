<?php
declare(strict_types=1);

namespace App\Events\Events;

/**
 * Event: OperatorFlag (NEW in Phase 14 / v1.5.0).
 *
 * Fired when the PatternMatcher finds a national license ↔ applicant
 * match with confidence 50-89 (OperatorFlag tier — 1-2 fields match but
 * no full national code match). The match is logged to sync_audit_log
 * pending manual review (the applicant's status is NOT changed).
 *
 * Phase 14 (v1.5.0) — pays D-4-7: NotificationSubscriber listens for
 * this event and inserts an in-app notification for each admin user
 * (role='admin') so they know to review the pending match. SMS is NOT
 * sent (the match isn't confirmed yet — admins review, applicants are
 * not bothered with potentially-false notifications).
 *
 * Previously (Phase 4-13) the OperatorFlag path only wrote to
 * sync_audit_log. The audit log is the source of truth for the
 * match decision, but admins had no in-band signal that a new flag
 * needed review — they had to actively poll the sync_audit_log admin
 * page. The new in-app notification closes that gap.
 *
 * Payload keys (set by ApplicantSyncAdapter::actOperatorFlagged):
 *   - applicant_id:           int    the applicant that was a partial match
 *   - national_request_number: string the upstream license RN
 *   - license_title:          string license title (for the notification body)
 *   - confidence:             int    the matcher's total score (50..89)
 *   - fields_matched:         array<string> the field names that matched
 *   - reason:                 string short reason phrase
 */
final class OperatorFlag extends AbstractEvent
{
    public const TYPE = 'operator_flag';
}
