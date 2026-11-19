<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Queue;

use App\Modules\NationalGateway\Services\LicenseService;
use App\Queue\JobInterface;
use App\Shared\Support\StructuredLogger;

/**
 * TrackFetchJobHandler — the runtime handler for `track_fetch` jobs.
 *
 * Phase 62 (v2.2.8) — the Worker's `handlerFor($type)` returns null
 * for every job type when no handler is registered (which was the
 * case before this commit: every queued track_fetch job failed with
 * "No handler registered for type 'track_fetch'"). This handler is
 * registered in NationalGateway\Module::boot() so that:
 *
 *   - The /api/v1/licenses/{id}/track endpoint (which enqueues a
 *     track_fetch job) actually has a downstream consumer.
 *   - The "search upstream for unmatched" button on the batch page
 *     (which enqueues a track_fetch per unmatched request_number)
 *     has a downstream consumer.
 *
 * When the Worker dequeues a track_fetch job, it calls execute()
 * with the deserialized payload {request_number: string}. The
 * handler delegates to LicenseService::fetchAndCacheByTrackNumber()
 * which scrapes the upstream /track/{rn} page, parses the RSC
 * payload, and persists the license's golden-link anchor fields
 * to national_licenses.
 *
 * If the scrape fails (network error, license not found, etc.),
 * the LicenseService returns null — we throw a RuntimeException
 * so the Worker marks the job as failed (it will retry up to
 * max_attempts via the queue's exponential backoff).
 */
final class TrackFetchJobHandler implements JobInterface
{
    public function __construct(
        private readonly LicenseService $licenseService,
        private readonly StructuredLogger $logger
    ) {}

    public function type(): string
    {
        return 'track_fetch';
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $context
     */
    public function execute(array $payload, array $context): void
    {
        $requestNumber = (string)($payload['request_number'] ?? '');
        if ($requestNumber === '') {
            throw new \RuntimeException('track_fetch job missing request_number');
        }

        $this->logger->info('TrackFetchJobHandler: scraping /track/{rn}', [
            'job_id' => $context['job_id'] ?? 0,
            'request_number' => $requestNumber,
        ]);

        $license = $this->licenseService->fetchAndCacheByTrackNumber($requestNumber);
        if ($license === null) {
            // The LicenseService returns null when the upstream track
            // page is unreachable OR the license is not found. We
            // throw so the Worker marks the job as failed (it will
            // retry per the queue's max_attempts + backoff
            // schedule). If the upstream is permanently unreachable
            // (geofence), the job will eventually exhaust its
            // attempts and remain in the 'failed' state — the admin
            // can see this in the audit trail.
            throw new \RuntimeException(
                'track_fetch: license not found on upstream (request_number=' . $requestNumber . ')'
            );
        }

        $this->logger->info('TrackFetchJobHandler: scraped + cached', [
            'job_id' => $context['job_id'] ?? 0,
            'request_number' => $requestNumber,
            'license_title' => $license->licenseTitle,
        ]);
    }
}
