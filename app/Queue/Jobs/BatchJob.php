<?php
declare(strict_types=1);

namespace App\Queue\Jobs;

use App\Queue\JobInterface;

/**
 * Job: batch.
 * Processes one row of a CSV batch import (look up one license request
 * number via the track page, cache it, update batch progress).
 *
 * Payload: {row: {...}, batch_id: int}
 */
final class BatchJob implements JobInterface
{
    public const TYPE = 'batch';

    public function type(): string
    {
        return self::TYPE;
    }

    public function execute(array $payload, array $context): void
    {
        throw new \RuntimeException('BatchJob::execute should not be called directly — Worker dispatches it');
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function extractBatchId(array $payload): int
    {
        return (int)($payload['batch_id'] ?? 0);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function extractRow(array $payload): array
    {
        return $payload['row'] ?? [];
    }
}
