-- ============================================================================
-- mojavez-v3 — Core module migration 016 (Phase 53, v2.1.9)
--
-- Adds a `closed_at` DATETIME NULL column to the `applicants` table so
-- that AdminController::changeStatus() can stamp the moment a case is
-- closed (when its status transitions to a terminal state — رد درخواست /
-- لغو / تحویل مجوز / تأیید نهایی دریافت کد رهگیری).
--
-- `closed_at` is intentionally distinct from `registration_completed_at`
-- (which marks when the user finished the registration wizard) and
-- `deleted_at` (which marks a soft-delete). Closed cases remain
-- visible in lists + reports but are frozen — no further status
-- changes are accepted by changeStatus() (isTerminal() check).
--
-- The column is nullable and defaults to NULL so existing rows (and
-- any applicants created via the registration wizard, which never
-- sets closed_at) are unaffected. The admin's changeStatus() code
-- only writes to it when transitioning to a terminal status.
--
-- Idempotent ALTER (information_schema guard on the column name) so
-- re-running is safe — MariaDB does not support IF NOT EXISTS on
-- ADD COLUMN, so we guard the DDL with a prepared-statement switch.
-- ============================================================================

-- 1. Add the column if it does not exist (information_schema guard).
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'applicants'
      AND COLUMN_NAME = 'closed_at'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `applicants` ADD COLUMN `closed_at` DATETIME NULL DEFAULT NULL AFTER `registration_completed_at`',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
