<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Repository contract for CSV batch import jobs (the `national_batches`
 * table — tracks bulk license-number lookups via the track page).
 *
 * Each batch:
 *   1. Admin uploads a CSV of license request numbers.
 *   2. BatchRepository::create() inserts a row with total_rows + csv_path.
 *   3. CsvBatchService processes each row (TrackScraper fetch + cache).
 *   4. updateProgress / incrementProgress track processed/matched counts.
 *   5. complete() or fail() finalizes the batch.
 */
interface NationalBatchRepositoryInterface
{
    public function create(string $originalFilename, int $totalRows, string $csvPath): int;

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array;

    public function updateProgress(int $id, int $processed, int $matched): void;

    public function complete(int $id, int $processed, int $matched, ?string $resultsJson = null): void;

    public function fail(int $id, string $error): void;

    public function incrementProgress(int $id, int $matchedDelta): void;

    public function appendResult(int $id, array $result): void;

    public function markCompleted(int $id): void;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 20): array;
}
