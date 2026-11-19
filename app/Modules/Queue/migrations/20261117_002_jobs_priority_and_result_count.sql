-- ============================================================================
-- mojavez-v3 — Queue module migration 002
-- Add priority + result_count columns to jobs (used by JobRepository).
--
-- The original 001 migration created the jobs table without these columns
-- because the column set wasn't finalized. This migration adds them
-- idempotently (ADD COLUMN IF NOT EXISTS — MariaDB supports this syntax).
-- ============================================================================

ALTER TABLE jobs ADD COLUMN IF NOT EXISTS priority INT NOT NULL DEFAULT 100;
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS result_count INT NOT NULL DEFAULT 0;

-- Index for the priority-based picking (priority DESC, created_at ASC).
ALTER TABLE jobs ADD INDEX IF NOT EXISTS idx_jobs_priority_created (priority, created_at);
