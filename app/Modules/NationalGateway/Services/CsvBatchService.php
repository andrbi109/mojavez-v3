<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Queue\JobQueue;
use App\Shared\Contracts\NationalBatchRepositoryInterface;
use App\Shared\Support\CsvReader;
use App\Shared\Support\StructuredLogger;

/**
 * CsvBatchService — CSV batch upload + per-row job enqueue.
 *
 * Phase 62 (v2.2.8) — rewritten imports: the previous version
 * imported the non-existent `App\Persistence\BatchRepository`
 * class, so even though it was never instantiated by the Container,
 * the class was unreachable from v3's autoloader. The imports are
 * now `App\Shared\Contracts\NationalBatchRepositoryInterface`
 * (the v3 contract that the NationalBatchRepository implements)
 * + the standard JobQueue / CsvReader / StructuredLogger imports.
 *
 * The class is NOT wired into the Container (the gateway admin's
 * batch upload goes through GatewayAdminController::batchUpload,
 * which inlines the CSV parsing + local-cache lookup). It is kept
 * here as a future-ready service for when the batch flow needs to
 * enqueue per-row track_fetch jobs in the background (e.g. for a
 * cron-driven batch processor that runs outside the admin UI).
 */
final class CsvBatchService
{
    public function __construct(
        private readonly CsvReader $csvReader,
        private readonly JobQueue $queue,
        private readonly FilterService $filterService,
        private readonly StructuredLogger $logger,
        private ?NationalBatchRepositoryInterface $batchRepo = null
    ) {}

    public function setBatchRepository(NationalBatchRepositoryInterface $repo): void
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
        $destDir = dirname(__DIR__, 4) . '/storage/uploads';
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
