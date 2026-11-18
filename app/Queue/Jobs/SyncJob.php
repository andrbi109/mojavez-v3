<?php
declare(strict_types=1);

namespace App\Queue\Jobs;

use App\Queue\JobInterface;

/**
 * Job: sync (NEW in v3).
 * Runs one golden-link sync cycle for a single national license — i.e. the
 * Worker calls NationalSyncOrchestrator::matchOne($requestNumber) which
 * runs PatternMatcher::matchQuorum() against all applicants in pre-issue
 * statuses.
 *
 * Payload: {request_number: string, ...extra}
 */
final class SyncJob implements JobInterface
{
    public const TYPE = 'sync';

    public function type(): string
    {
        return self::TYPE;
    }

    public function execute(array $payload, array $context): void
    {
        throw new \RuntimeException('SyncJob::execute should not be called directly — Worker dispatches it');
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function extractRequestNumber(array $payload): string
    {
        return (string)($payload['request_number'] ?? '');
    }
}
