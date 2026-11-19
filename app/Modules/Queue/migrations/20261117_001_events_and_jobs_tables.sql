-- ============================================================================
-- mojavez-v3 — Core module migration 006
-- events table — audit log for the EventBus + SSE replay source.
--
-- Per docs/00-MASTER.md §13.1: every dispatched event is persisted here so
-- the SseController can replay events newer than a client's last-seen ID
-- (long-polling pattern). The table is append-only — events are never
-- updated or deleted.
-- ============================================================================

CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(64) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_events_type_created (type, created_at),
    INDEX idx_events_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- jobs table — MySQL-backed job queue for the Worker + poor man's cron.
-- Per docs/00-MASTER.md §13.2. Jobs are picked atomically by
-- JobRepository::pickNext() (SELECT ... FOR UPDATE SKIP LOCKED) so
-- multiple Worker processes don't grab the same job.
CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(64) NOT NULL,
    payload_json JSON NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    reserved_by VARCHAR(64) NULL,
    reserved_at DATETIME NULL,
    available_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_error TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_jobs_status_available (status, available_at),
    INDEX idx_jobs_type_status (type, status),
    INDEX idx_jobs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
