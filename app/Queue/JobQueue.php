<?php
declare(strict_types=1);

namespace App\Queue;

use App\Shared\Support\StructuredLogger;

/**
 * Queue facade — wraps JobRepository with a clean enqueue API and maintains
 * a registry of job type → JobInterface handler.
 *
 * Per docs/00-MASTER.md §13.2 — JobQueue is the public face of the queue.
 * Modules enqueue jobs via this class (or via subscribers like SyncSubscriber);
 * the Worker polls JobRepository::claimNext() and dispatches to the registered
 * handler.
 *
 * Why a registry (not a switch statement): each module registers its own
 * Job handlers in Module::register() (via $container->make(JobQueue::class)
 * → registerHandler()). This keeps job definitions co-located with the module
 * that owns them, and avoids a central switch statement that would grow with
 * every new job type.
 */
final class JobQueue
{
    /** @var array<string,JobInterface> */
    private array $handlers = [];

    public function __construct(
        private readonly JobRepository $repository,
        private readonly StructuredLogger $logger
    ) {}

    /**
     * Register a job handler for its type.
     */
    public function registerHandler(JobInterface $handler): void
    {
        $this->handlers[$handler->type()] = $handler;
    }

    /**
     * Enqueue a job by type + payload. Returns the new job ID.
     *
     * @param array<string,mixed> $payload
     */
    public function enqueueJob(string $type, array $payload, int $priority = 100, int $maxAttempts = 3): int
    {
        return $this->repository->enqueue($type, $payload, $priority, $maxAttempts);
    }

    /**
     * Claim the next available job (delegates to JobRepository::claimNext).
     *
     * @return array<string,mixed>|null
     */
    public function dequeue(?string $reservedBy = null): ?array
    {
        return $this->repository->claimNext($reservedBy);
    }

    /**
     * Look up the registered handler for a job type.
     */
    public function handlerFor(string $type): ?JobInterface
    {
        return $this->handlers[$type] ?? null;
    }

    /**
     * Convenience enqueue helpers for the 5 known job types.
     */

    /**
     * @param array<string,mixed> $filterArray
     */
    public function enqueueSearch(array $filterArray, int $priority = 100): int
    {
        return $this->repository->enqueue('search', $filterArray, $priority);
    }

    public function enqueueBatch(int $batchId, array $row, int $priority = 50): int
    {
        return $this->repository->enqueue(
            'batch',
            ['row' => $row, 'batch_id' => $batchId],
            $priority
        );
    }

    /**
     * @param array<string,mixed> $filterArray
     */
    public function enqueueCount(array $filterArray, int $priority = 90): int
    {
        return $this->repository->enqueue('count', $filterArray, $priority);
    }

    public function enqueueSync(string $requestNumber, array $extra = [], int $priority = 80): int
    {
        return $this->repository->enqueue(
            'sync',
            array_merge(['request_number' => $requestNumber], $extra),
            $priority
        );
    }

    public function enqueueTrackFetch(string $requestNumber, int $priority = 70): int
    {
        return $this->repository->enqueue(
            'track_fetch',
            ['request_number' => $requestNumber],
            $priority
        );
    }

    public function repository(): JobRepository
    {
        return $this->repository;
    }
}
