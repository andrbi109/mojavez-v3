<?php
declare(strict_types=1);

namespace App\Events\Subscribers;

use App\Events\EventInterface;
use App\Events\Events\ApplicantMatched;
use App\Events\SubscriberInterface;
use App\Modules\Core\Services\NotificationService;
use App\Shared\Support\StructuredLogger;

/**
 * Subscriber that sends notifications when a golden-link match succeeds.
 *
 * Listens for ApplicantMatched (fired only on AutoConfirmed, confidence >= 90).
 * On receipt:
 *   1. Sends an SMS to the applicant's mobile: "مجوز کسب‌وکار شما در درگاه ملی
 *      صادر شد. کد رهگیری: {national_request_number}."
 *   2. Creates an in-app UserNotification of type 'national_sync_issued' so
 *      the applicant sees a banner on next login.
 *
 * For OperatorFlag (confidence 50) matches, NO ApplicantMatched event is
 * fired — the match goes to sync_audit_log pending operator review, and the
 * applicant is NOT notified (to avoid false hope).
 */
final class NotificationSubscriber implements SubscriberInterface
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly StructuredLogger $logger
    ) {}

    public function subscribedTo(): array
    {
        return [ApplicantMatched::TYPE];
    }

    public function handle(EventInterface $event): void
    {
        if (!$event instanceof ApplicantMatched) {
            return;
        }
        $payload = $event->payload();
        $applicantId = (int)($payload['applicant_id'] ?? 0);
        $nationalRequestNumber = (string)($payload['national_request_number'] ?? '');

        if ($applicantId === 0 || $nationalRequestNumber === '') {
            $this->logger->warning('NotificationSubscriber: ApplicantMatched missing fields', $payload);
            return;
        }

        // Send SMS + in-app notification. NotificationService handles lookup
        // of the applicant's mobile from applicants.person_id → persons.mobile.
        $this->notifications->notifyLicenseIssued($applicantId, $nationalRequestNumber);

        $this->logger->info('NotificationSubscriber sent notification', [
            'applicant_id' => $applicantId,
            'national_request_number' => $nationalRequestNumber,
        ]);
    }
}
