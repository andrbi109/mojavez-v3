<?php
declare(strict_types=1);

namespace App\Events\Subscribers;

use App\Events\EventInterface;
use App\Events\Events\ApplicantMatched;
use App\Events\Events\OperatorFlag;
use App\Events\SubscriberInterface;
use App\Modules\Core\Services\NotificationService;
use App\Shared\Support\StructuredLogger;

/**
 * Subscriber that sends notifications when a golden-link match succeeds
 * OR when an operator-flagged match needs admin review.
 *
 * Listens for two event types:
 *
 *   1. ApplicantMatched (fired only on AutoConfirmed, confidence >= 90):
 *      - Sends an SMS to the applicant's mobile.
 *      - Creates an in-app UserNotification for the applicant
 *        (type='national_sync_issued').
 *
 *   2. OperatorFlag (fired on OperatorFlag, confidence 50-89):
 *      - Creates an in-app UserNotification for each admin user
 *        (role='admin' in `users`) so they know to review the pending
 *        match in sync_audit_log. Title: "نیازمند بررسی اپراتور".
 *      - NO SMS / NO email to admins (the match isn't confirmed yet;
 *        admins review via the admin panel, not via personal channels).
 *      - Phase 14 (v1.5.0): pays D-4-7.
 *
 * For AutoRejected (confidence 0), NO event is fired — only the
 * sync_audit_log row (written by ApplicantSyncAdapter::act).
 */
final class NotificationSubscriber implements SubscriberInterface
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly StructuredLogger $logger
    ) {}

    public function subscribedTo(): array
    {
        // Phase 14 (v1.5.0) — adds OperatorFlag (D-4-7).
        return [ApplicantMatched::TYPE, OperatorFlag::TYPE];
    }

    public function handle(EventInterface $event): void
    {
        if ($event instanceof ApplicantMatched) {
            $this->handleApplicantMatched($event);
            return;
        }
        if ($event instanceof OperatorFlag) {
            $this->handleOperatorFlag($event);
            return;
        }
    }

    /**
     * ApplicantMatched handler — AutoConfirmed (confidence >= 90).
     * Sends SMS + in-app notification to the matched applicant.
     */
    private function handleApplicantMatched(ApplicantMatched $event): void
    {
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

    /**
     * OperatorFlag handler — confidence 50-89, pending manual review.
     * Sends an in-app notification to admins (no SMS, no email).
     *
     * Phase 14 (v1.5.0) — pays D-4-7.
     */
    private function handleOperatorFlag(OperatorFlag $event): void
    {
        $payload = $event->payload();
        $applicantId = (int)($payload['applicant_id'] ?? 0);
        $nationalRequestNumber = (string)($payload['national_request_number'] ?? '');
        $licenseTitle = (string)($payload['license_title'] ?? '');
        $confidence = (int)($payload['confidence'] ?? 0);
        $fieldsMatched = is_array($payload['fields_matched'] ?? null)
            ? implode('، ', array_map('strval', $payload['fields_matched']))
            : '';

        if ($applicantId === 0 || $nationalRequestNumber === '') {
            $this->logger->warning('NotificationSubscriber: OperatorFlag missing fields', $payload);
            return;
        }

        $title = 'نیازمند بررسی اپراتور';
        $body = sprintf(
            'تطابق جزئی (اطمینان %d) برای متقاضی %d با مجوز %s%s%s نیاز به بررسی دارد.',
            $confidence,
            $applicantId,
            $nationalRequestNumber,
            $licenseTitle !== '' ? ' (' . $licenseTitle . ')' : '',
            $fieldsMatched !== '' ? ' — فیلدهای منطبق: ' . $fieldsMatched : ''
        );

        $this->notifications->notifyAdmins($title, $body);

        $this->logger->info('NotificationSubscriber dispatched admin notification', [
            'applicant_id' => $applicantId,
            'national_request_number' => $nationalRequestNumber,
            'confidence' => $confidence,
        ]);
    }
}
