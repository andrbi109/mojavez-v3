<?php
declare(strict_types=1);

namespace App\Queue;

use App\Shared\Persistence\Connection;
use App\Shared\Support\StructuredLogger;

/**
 * MySQL-backed job repository for the `jobs` table.
 *
 * Per docs/00-MASTER.md §13.2 — job picking uses SELECT ... FOR UPDATE
 * SKIP LOCKED so multiple Worker processes (or the poor man's cron poller)
 * don't grab the same job. This is the MySQL equivalent of SQLite's
 * BEGIN...SELECT...UPDATE pattern (gateway used SQLite which doesn't support
 * SKIP LOCKED).
 *
 * Lifecycle:
 *   enqueue()         → status='pending', attempts=0, available_at=NOW()
 *   claimNext()       → status='processing', attempts++, reserved_at=NOW()
 *                       (atomic via FOR UPDATE SKIP LOCKED in a transaction)
 *   markCompleted()   → status='completed', completed_at=NOW()
 *   markFailed()      → if attempts >= max_attempts: status='failed'
 *                       else: status='pending', available_at=NOW()+backoff,
 *                             last_error set
 *   retry(id)         → manual retry: status='pending', attempts=0,
 *                       clears last_error (admin-triggered, fresh start)
 */
final class JobRepository
{
    /** @var array<string,string> */
    private const DEFAULT_BACKOFF = [
        1 => '15 SECOND',
        2 => '60 SECOND',
        3 => '300 SECOND',
    ];

    public function __construct(
        private readonly Connection $conn,
        private readonly StructuredLogger $logger
    ) {}

    /**
     * Enqueue a job.
     *
     * @param array<string,mixed> $payload
     */
    public function enqueue(string $type, array $payload, int $priority = 100, int $maxAttempts = 3): int
    {
        $id = $this->conn->insert(
            'INSERT INTO jobs (type, payload_json, status, priority, max_attempts, available_at) '
            . 'VALUES (?, ?, ?, ?, ?, NOW())',
            [$type, json_encode($payload, JSON_UNESCAPED_UNICODE), 'pending', $priority, $maxAttempts]
        );
        $this->logger->info('Job enqueued', ['job_id' => $id, 'type' => $type, 'priority' => $priority]);
        return (int)$id;
    }

    /**
     * Atomically claim the next available job.
     *
     * Uses SELECT ... FOR UPDATE SKIP LOCKED so concurrent Workers don't
     * grab the same job. The whole claim is in a single transaction.
     *
     * @return array<string,mixed>|null
     */
    public function claimNext(?string $reservedBy = null): ?array
    {
        return $this->conn->transactional(function () use ($reservedBy): ?array {
            $pdo = $this->conn->pdo();
            // SKIP LOCKED lets other workers skip past this row if it's
            // already locked by another transaction. Critical for multi-worker
            // correctness without single-threading.
            $stmt = $pdo->prepare(
                "SELECT id, type, payload_json, attempts, max_attempts, priority, created_at "
                . "FROM jobs "
                . "WHERE status = 'pending' AND available_at <= NOW() "
                . "ORDER BY priority DESC, created_at ASC "
                . "LIMIT 1 "
                . "FOR UPDATE SKIP LOCKED"
            );
            $stmt->execute();
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$job) {
                return null;
            }
            $jobId = (int)$job['id'];
            $attempts = (int)$job['attempts'] + 1;
            $update = $pdo->prepare(
                "UPDATE jobs SET status = 'processing', attempts = ?, "
                . "reserved_by = ?, reserved_at = NOW() WHERE id = ?"
            );
            $update->execute([$attempts, $reservedBy, $jobId]);
            $job['status'] = 'processing';
            $job['attempts'] = $attempts;
            $job['reserved_by'] = $reservedBy;
            return $job;
        });
    }

    public function markCompleted(int $id, int $resultCount = 0): void
    {
        $this->conn->execute(
            "UPDATE jobs SET status = 'completed', result_count = ?, completed_at = NOW() WHERE id = ?",
            [$resultCount, $id]
        );
    }

    /**
     * Mark a job as failed. If attempts < max_attempts, re-queue it with
     * exponential backoff (15s, 60s, 300s). Otherwise mark as permanently
     * failed.
     */
    public function markFailed(int $id, string $error): void
    {
        $job = $this->conn->one('SELECT attempts, max_attempts FROM jobs WHERE id = ?', [$id]);
        if ($job === null) {
            return;
        }
        $attempts = (int)$job['attempts'];
        $maxAttempts = (int)$job['max_attempts'];

        if ($attempts >= $maxAttempts) {
            $this->conn->execute(
                "UPDATE jobs SET status = 'failed', last_error = ? WHERE id = ?",
                [mb_substr($error, 0, 65535), $id]
            );
            $this->logger->warning('Job permanently failed', ['job_id' => $id, 'attempts' => $attempts, 'error' => $error]);
        } else {
            $backoff = self::DEFAULT_BACKOFF[$attempts] ?? '300 SECOND';
            $this->conn->execute(
                "UPDATE jobs SET status = 'pending', last_error = ?, available_at = DATE_ADD(NOW(), INTERVAL $backoff) WHERE id = ?",
                [mb_substr($error, 0, 65535), $id]
            );
            $this->logger->info('Job retrying with backoff', ['job_id' => $id, 'attempt' => $attempts, 'backoff' => $backoff]);
        }
    }

    /**
     * Manual retry — reset attempts to 0 (fresh start).
     */
    public function retry(int $id): void
    {
        $this->conn->execute(
            "UPDATE jobs SET status = 'pending', attempts = 0, last_error = NULL, "
            . "reserved_by = NULL, reserved_at = NULL, available_at = NOW() WHERE id = ?",
            [$id]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->conn->one('SELECT * FROM jobs WHERE id = ?', [$id]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 50, ?string $status = null): array
    {
        if ($status !== null) {
            return $this->conn->all(
                'SELECT * FROM jobs WHERE status = ? ORDER BY created_at DESC LIMIT ?',
                [$status, $limit]
            );
        }
        return $this->conn->all('SELECT * FROM jobs ORDER BY created_at DESC LIMIT ?', [$limit]);
    }

    /**
     * @return array<string,int>
     */
    public function countByStatus(): array
    {
        $rows = $this->conn->all('SELECT status, COUNT(*) AS c FROM jobs GROUP BY status');
        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['status']] = (int)$row['c'];
        }
        return $out;
    }

    /**
     * Clean up old completed/failed jobs (housekeeping for the admin panel).
     */
    public function purgeOlderThan(int $days): int
    {
        $count = (int)$this->conn->scalar(
            'SELECT COUNT(*) FROM jobs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND status IN (?, ?)',
            [$days, 'completed', 'failed']
        );
        if ($count > 0) {
            $this->conn->execute(
                'DELETE FROM jobs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND status IN (?, ?)',
                [$days, 'completed', 'failed']
            );
        }
        return $count;
    }
}
