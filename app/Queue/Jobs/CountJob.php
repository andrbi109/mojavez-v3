<?php
declare(strict_types=1);

namespace App\Queue\Jobs;

use App\Queue\JobInterface;

/**
 * Job: count.
 * Counts the upstream total for a filter (without paging) — cached for 1h
 * in the CacheService so paginated search UIs know the real total without
 * re-issuing the count request.
 *
 * Payload: {filter: {...}}
 */
final class CountJob implements JobInterface
{
    public const TYPE = 'count';

    public function type(): string
    {
        return self::TYPE;
    }

    public function execute(array $payload, array $context): void
    {
        throw new \RuntimeException('CountJob::execute should not be called directly — Worker dispatches it');
    }
}
