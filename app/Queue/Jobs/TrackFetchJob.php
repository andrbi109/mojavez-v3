<?php
declare(strict_types=1);

namespace App\Queue\Jobs;

use App\Queue\JobInterface;

/**
 * Job: track_fetch (NEW in v3).
 * Scrapes the upstream /track/{rn} page for a single license and caches
 * the extended detail (father name, masked national code, business address,
 * history table, etc.) in national_licenses.
 *
 * Payload: {request_number: string}
 */
final class TrackFetchJob implements JobInterface
{
    public const TYPE = 'track_fetch';

    public function type(): string
    {
        return self::TYPE;
    }

    public function execute(array $payload, array $context): void
    {
        throw new \RuntimeException('TrackFetchJob::execute should not be called directly — Worker dispatches it');
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function extractRequestNumber(array $payload): string
    {
        return (string)($payload['request_number'] ?? '');
    }
}
