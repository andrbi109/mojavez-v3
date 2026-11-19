<?php
declare(strict_types=1);

namespace App\Queue\Jobs;

use App\Modules\NationalGateway\Domain\Filter;
use App\Queue\JobInterface;

/**
 * Job: search.
 * Runs an upstream license search with the given filter.
 *
 * The Worker (not this class) holds the LicenseService reference and calls
 * searchHybrid(). This class exists for type-safety + as a factory for the
 * Filter from the deserialized payload.
 */
final class SearchJob implements JobInterface
{
    public const TYPE = 'search';

    public function type(): string
    {
        return self::TYPE;
    }

    public function execute(array $payload, array $context): void
    {
        // Worker::executeSearchJob() handles the actual execution by calling
        // LicenseService::searchHybrid() — this class is a type marker.
        throw new \RuntimeException('SearchJob::execute should not be called directly — Worker dispatches it');
    }

    /**
     * Build a Filter from the job payload.
     *
     * @param array<string,mixed> $payload
     */
    public static function toFilter(array $payload): Filter
    {
        return Filter::fromArray($payload);
    }
}
