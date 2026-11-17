<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Persistence\BatchRepository;
use App\Queue\JobQueue;
use App\Shared\Support\CsvReader;
use App\Shared\Support\StructuredLogger;

/**
 * CSV batch service — parses uploaded CSV, enqueues a job per row.
 */
final class CsvBatchService
{
    public function __construct(
        private readonly CsvReader $csvReader,
        private readonly JobQueue $queue,
        private readonly FilterService $filterService,
        private readonly StructuredStructuredLogger $logger,
        private ?BatchRepository $batchRepo = null
    ) {}

    public function setBatchRepository(BatchRepository $repo): void
    {
        $this->batchRepo = $repo;
    }

    /**
     * Process an uploaded CSV file.
     * Saves to storage/uploads/, creates a batch record, enqueues jobs per row.
     *
     * @return int The batch ID.
     */
    public function upload(string $tmpPath, string $originalName): int
    {
        if (!is_file($tmpPath)) {
            throw new \RuntimeException('Uploaded file not found');
        }

        // Save to storage/uploads/
        $destDir = STORAGE_PATH . '/uploads';
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $destPath = $destDir . '/batch_' . date('Y-m-d_His') . '_' . $safeName;
        if (!move_uploaded_file($tmpPath, $destPath) && !copy($tmpPath, $destPath)) {
            throw new \RuntimeException('Failed to save uploaded CSV');
        }

        // Count rows
        $rowCount = 0;
        foreach ($this->csvReader->read($destPath) as $_) {
            $rowCount++;
        }

        $this->logger->info('CSV uploaded', ['file' => $destPath, 'rows' => $rowCount]);

        if ($this->batchRepo === null) {
            throw new \RuntimeException('BatchRepository not set');
        }
        $batchId = $this->batchRepo->create($originalName, $rowCount, $destPath);

        // Enqueue one job per row
        foreach ($this->csvReader->read($destPath) as $row) {
            $this->queue->enqueueBatch($batchId, $row);
        }

        return $batchId;
    }

    /**
     * Get batch status.
     */
    public function batchStatus(int $batchId): ?array
    {
        return $this->batchRepo?->find($batchId);
    }

    /**
     * Recent batches.
     */
    public function recentBatches(int $limit = 20): array
    {
        return $this->batchRepo?->recent($limit) ?? [];
    }
}
