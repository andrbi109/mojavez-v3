<?php
declare(strict_types=1);

namespace App\Queue;

/**
 * Contract for queueable jobs.
 *
 * Each job type declares a type() string (matches the `jobs.type` column) and
 * an execute() method that receives the deserialized payload + context.
 *
 * Per docs/00-MASTER.md §13.2 — 5 job types:
 *   - search      — run an upstream license search
 *   - batch       — process one CSV batch row
 *   - count       — count upstream total (1h TTL)
 *   - sync        — run one golden-link sync cycle
 *   - track_fetch — scrape the track page for one license
 */
interface JobInterface
{
    /**
     * The job type identifier (matches jobs.type column).
     */
    public function type(): string;

    /**
     * Execute the job. Throw on failure — Worker will catch, log, and
     * call JobRepository::markFailed (which respects max_attempts).
     *
     * @param array<string,mixed> $payload  Deserialized payload from jobs.payload_json.
     * @param array<string,mixed> $context  Job metadata (job_id, attempts, max_attempts).
     */
    public function execute(array $payload, array $context): void;
}
