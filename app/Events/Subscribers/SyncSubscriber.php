<?php
declare(strict_types=1);

namespace App\Events\Subscribers;

use App\Events\EventInterface;
use App\Events\Events\LicenseIssuedOnNational;
use App\Events\SubscriberInterface;
use App\Queue\JobQueue;
use App\Queue\Jobs\SyncJob;
use App\Shared\Support\StructuredLogger;

/**
 * Subscriber that bridges the event bus to the job queue.
 *
 * Listens for LicenseIssuedOnNational — when the scraper discovers a license
 * has been issued upstream, this subscriber enqueues a SyncJob so the
 * Worker (or poor man's cron) will run the golden-link matcher for that
 * license against all pending applicants.
 *
 * Why a job (not synchronous): the matcher may need to compare against
 * hundreds of applicants, each requiring PatternMatcher::matchQuorum across
 * 9 weighted fields. Doing that inline in the event-dispatch path would
 * block the request that discovered the license. Enqueuing lets the work
 * happen in a Worker tick (or the next poor man's cron poll).
 */
final class SyncSubscriber implements SubscriberInterface
{
    public function __construct(
        private readonly JobQueue $queue,
        private readonly StructuredLogger $logger
    ) {}

    public function subscribedTo(): array
    {
        return [LicenseIssuedOnNational::TYPE];
    }

    public function handle(EventInterface $event): void
    {
        if (!$event instanceof LicenseIssuedOnNational) {
            return;
        }
        $payload = $event->payload();
        $requestNumber = (string)($payload['request_number'] ?? '');

        if ($requestNumber === '') {
            $this->logger->warning('SyncSubscriber: LicenseIssuedOnNational missing request_number', $payload);
            return;
        }

        // Enqueue a SyncJob — the Worker/poor-man's-cron will pick it up and
        // run PatternMatcher::matchQuorum(national_license, applicant) for
        // every applicant in a pre-issue status.
        $job = new SyncJob($requestNumber, $payload);
        $this->queue->enqueue($job);

        $this->logger->info('SyncSubscriber enqueued SyncJob', [
            'request_number' => $requestNumber,
        ]);
    }
}
