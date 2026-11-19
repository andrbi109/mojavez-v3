-- ============================================================================
-- mojavez-v3 — Core module migration 014
-- Make `user_notifications.user_account_id` nullable (Phase 27 / D-14-2).
--
-- Background: the `user_notifications` table was created (migration 005)
-- with `user_account_id INT NOT NULL` and a FK to `user_accounts(id)`
-- ON DELETE CASCADE. That makes sense for user-facing notifications
-- (every user-panel notification must belong to a real user_account).
--
-- But `NotificationService::notifyAdmins()` walks the `users` table
-- (role='admin') and bridges to `user_accounts` by username. Admins who
-- don't have a matching user_account were silently skipped (D-14-1,
-- Phase 22 added a per-admin warning log; the structural fix was
-- deferred). Phase 27 finishes the structural fix: insert a
-- notification row with `user_account_id = NULL` so the notification
-- exists for a future admin-notifications view (the sync_audit_log row
-- remains the primary admin-facing channel today, but a row in
-- user_notifications makes the notification queryable + dismissible
-- once that view is built).
--
-- The FK constraint allows NULL values (InnoDB skips the FK check when
-- the column is NULL), so we only need to:
--   1. ALTER the column from NOT NULL to NULL DEFAULT NULL.
--   2. Keep the existing FK (no change needed — FKs work with NULL).
--
-- Idempotency:
--   1. ALTER COLUMN ... DROP NOT NULL — wrapped in an information_schema
--      check (IS_NULLABLE) so re-running against a DB where the column
--      is already nullable is a no-op.
-- ============================================================================

-- 1. Check current nullability of user_notifications.user_account_id.
SET @is_not_null = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_notifications'
      AND COLUMN_NAME = 'user_account_id'
      AND IS_NULLABLE = 'NO'
);
SET @ddl = IF(@is_not_null = 1,
    'ALTER TABLE `user_notifications` MODIFY COLUMN `user_account_id` INT(11) NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
