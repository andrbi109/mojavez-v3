<?php
declare(strict_types=1);

namespace App\Queue;

use App\Shared\Support\StructuredLogger;

/**
 * Worker — drains the job queue.
 *
 * Per docs/00-MASTER.md §13.3 — the Worker is the poor man's cron pattern:
 * rather than running a long-lived daemon process (impossible on shared
 * hosting), Worker::run($maxJobs) is called from:
 *   - SseController::stream (every poll)
 *   - /admin/sync/status (every poll)
 *   - cron (if available): `php scripts/run_worker.php --max-jobs=5` per minute
 *
 * Each call to run() claims up to $maxJobs jobs from the queue (via
 * JobRepository::claimNext with SELECT ... FOR UPDATE SKIP LOCKED), executes
 * each via the registered JobInterface handler, and marks the result.
 *
 * Execution flow per job:
 *   1. claimNext() — atomically mark 'processing', increment attempts.
 *   2. Dispatch to the handler (via JobQueue::handlerFor($type)).
 *   3. On success: markCompleted(job_id).
 *   4. On failure: markFailed(job_id, error) — respects max_attempts:
 *      if attempts < max: re-queue with exponential backoff (15s/60s/300s)
 *      else: permanently 'failed', dispatch JobFailed event.
 *
 * Resilience: any Throwable from the handler is caught — the Worker never
 * crashes on a bad job. The job is marked failed + the Worker continues to
 * the next job.
 */
final class Worker
{
    public function __construct(
        private readonly JobQueue $queue,
        private readonly StructuredLogger $logger
    ) {}

    /**
     * Run up to $maxJobs. Returns the number of jobs actually executed.
     *
     * @param int $maxJobs  Maximum jobs to process in this tick.
     * @param string $reservedBy  Identifier for this worker (for diagnostics).
     */
    public function run(int $maxJobs = 1, string $reservedBy = 'web'): int
    {
        $executed = 0;
        for ($i = 0; $i < $maxJobs; $i++) {
            $job = $this->queue->dequeue($reservedBy);
            if ($job === null) {
                break; // queue empty
            }
            $this->executeOne($job);
            $executed++;
        }
        return $executed;
    }

    /**
     * Execute a single claimed job.
     *
     * @param array<string,mixed> $job  Row from the jobs table.
     */
    public function executeOne(array $job): void
    {
        $jobId = (int)$job['id'];
        $type = (string)$job['type'];
        $attempts = (int)$job['attempts'];

        $this->logger->info('Worker executing job', [
            'job_id' => $jobId,
            'type' => $type,
            'attempt' => $attempts,
        ]);

        $handler = $this->queue->handlerFor($type);
        if ($handler === null) {
            $this->queue->repository()->markFailed($jobId, "No handler registered for type '$type'");
            $this->logger->error('Worker: no handler for job type', ['job_id' => $jobId, 'type' => $type]);
            return;
        }

        $payload = json_decode((string)($job['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $context = [
            'job_id' => $jobId,
            'type' => $type,
            'attempts' => $attempts,
            'max_attempts' => (int)($job['max_attempts'] ?? 3),
        ];

        try {
            $handler->execute($payload, $context);
            $this->queue->repository()->markCompleted($jobId);
            $this->logger->info('Worker: job completed', ['job_id' => $jobId, 'type' => $type]);
        } catch (\Throwable $e) {
            $this->queue->repository()->markFailed($jobId, $e->getMessage());
            $this->logger->error('Worker: job failed', [
                'job_id' => $jobId,
                'type' => $type,
                'attempt' => $attempts,
                'error' => $e->getMessage(),
            ]);
            // Note: if this was the final attempt, the JobFailed event is
            // dispatched by markFailed via the repository. For now, the
            // repository just sets status='failed' — the event dispatch
            // is a TODO until the SyncSubscriber wires the EventBus.
        }
    }

    /**
     * Drain the entire queue (used by scripts/run_worker.php).
     * Returns the number of jobs processed.
     */
    public function drain(string $reservedBy = 'cli'): int
    {
        $total = 0;
        while (true) {
            $job = $this->queue->dequeue($reservedBy);
            if ($job === null) {
                break;
            }
            $this->executeOne($job);
            $total++;
        }
        return $total;
    }
}
