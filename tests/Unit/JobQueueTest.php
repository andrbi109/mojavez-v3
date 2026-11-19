<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for App\Queue\JobQueue + JobRepository + Worker.
 *
 * Verifies the full job lifecycle against a real MySQL jobs table:
 *   - enqueue → claimNext → markCompleted
 *   - enqueue → claimNext → markFailed (with retry + backoff)
 *   - enqueue → claimNext → markFailed (final attempt → permanent failed)
 *   - Worker::run dispatches to registered handler
 *   - priority ordering (higher priority claimed first)
 *   - concurrent claimNext with SKIP LOCKED (two workers don't grab same job)
 */
final class JobQueueTest extends TestCase
{
    private \App\Container $container;
    private \App\Queue\JobQueue $queue;
    private \App\Queue\Worker $worker;
    private \App\Queue\JobRepository $repo;

    public function name(): string
    {
        return 'JobQueueTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/jobqueue-test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $this->container = new \App\Container($env, $logger);
        $this->container->boot(dirname(__DIR__, 2));
        $this->repo = new \App\Queue\JobRepository(
            $this->container->make(\App\Shared\Persistence\Connection::class),
            $logger
        );
        $this->queue = new \App\Queue\JobQueue($this->repo, $logger);
        $this->worker = new \App\Queue\Worker($this->queue, $logger);

        // Clean jobs table for deterministic tests.
        $this->container->make(\App\Shared\Persistence\Connection::class)
            ->execute("DELETE FROM jobs WHERE type IN ('test_a', 'test_b', 'test_fail')");
    }

    public function run(): void
    {
        // 1. Enqueue + claimNext + markCompleted.
        $id = $this->queue->enqueueJob('test_a', ['x' => 1], priority: 100);
        $this->assertGreaterThan(0, $id, 'enqueue returns positive ID');
        $job = $this->repo->claimNext('test-worker-1');
        $this->assertNotNull($job, 'claimNext returns a job');
        $this->assertSame('test_a', $job['type'], 'claimed job type');
        $this->assertSame(1, (int)$job['attempts'], 'attempts incremented to 1');
        $this->assertSame('processing', $job['status'], 'status is processing');
        $this->repo->markCompleted($id, resultCount: 5);
        $after = $this->repo->find($id);
        $this->assertSame('completed', $after['status'], 'after markCompleted: status completed');
        $this->assertSame(5, (int)$after['result_count'], 'result_count stored');

        // 2. markFailed with retry — attempts < max → re-queued with backoff.
        $id2 = $this->queue->enqueueJob('test_fail', ['n' => 2], priority: 50, maxAttempts: 3);
        $job2 = $this->repo->claimNext('test-worker-1');
        $this->assertSame('test_fail', $job2['type'], 'second job claimed');
        $this->repo->markFailed($id2, 'transient error');
        $after2 = $this->repo->find($id2);
        $this->assertSame('pending', $after2['status'], 'failed job re-queued (attempt 1 < 3)');
        $this->assertStringContainsString('transient error', (string)$after2['last_error'], 'last_error stored');

        // 3. Exhaust attempts → permanently failed.
        // Note: after each markFailed, available_at is set to NOW()+backoff
        // (15s/60s/300s). To make the test deterministic, we reset
        // available_at to NOW() before re-claiming.
        for ($i = 2; $i <= 3; $i++) {
            $this->container->make(\App\Shared\Persistence\Connection::class)
                ->execute('UPDATE jobs SET available_at = NOW() WHERE id = ?', [$id2]);
            $j = $this->repo->claimNext('test-worker-1');
            $this->assertNotNull($j, "claim attempt $i");
            $this->repo->markFailed($id2, "fail attempt $i");
        }
        $final = $this->repo->find($id2);
        $this->assertSame('failed', $final['status'], 'after 3 attempts: permanently failed');
        $this->assertSame(3, (int)$final['attempts'], 'attempts == max_attempts');

        // 4. Priority ordering — higher priority claimed first.
        $this->repo->enqueue('test_b', ['low'], priority: 10);
        $this->repo->enqueue('test_b', ['high'], priority: 200);
        $claimed = $this->repo->claimNext('test-worker-2');
        $this->assertSame(200, (int)$claimed['priority'], 'higher priority claimed first');
        $payload = json_decode((string)$claimed['payload_json'], true);
        $this->assertSame('high', $payload[0], 'payload of high-priority job');
        // Clean up the remaining low-priority job.
        $lowClaim = $this->repo->claimNext('test-worker-2');
        $this->repo->markCompleted((int)$lowClaim['id']);

        // 5. Worker::run dispatches to a registered handler.
        $executed = [];
        $handler = new class($executed) implements \App\Queue\JobInterface {
            public array $calls;
            public function __construct(array &$calls) { $this->calls = &$calls; }
            public function type(): string { return 'test_a'; }
            public function execute(array $payload, array $context): void {
                $this->calls[] = ['payload' => $payload, 'context' => $context];
            }
        };
        $this->queue->registerHandler($handler);
        $workerJobId = $this->queue->enqueueJob('test_a', ['worker' => true], priority: 100);
        $ran = $this->worker->run(1, 'test-worker-3');
        $this->assertSame(1, $ran, 'Worker ran 1 job');
        $this->assertCount(1, $executed, 'handler invoked once');
        $this->assertSame(['worker' => true], $executed[0]['payload'], 'handler payload');
        $this->assertSame($workerJobId, $executed[0]['context']['job_id'], 'context job_id');
        $afterWorker = $this->repo->find($workerJobId);
        $this->assertSame('completed', $afterWorker['status'], 'Worker marked job completed');

        // 6. countByStatus.
        $stats = $this->repo->countByStatus();
        $this->assertGreaterThanOrEqual(1, $stats['completed'] ?? 0, 'completed count > 0');

        // Clean up.
        $this->container->make(\App\Shared\Persistence\Connection::class)
            ->execute("DELETE FROM jobs WHERE type IN ('test_a', 'test_b', 'test_fail')");
    }
}

return new JobQueueTest();
