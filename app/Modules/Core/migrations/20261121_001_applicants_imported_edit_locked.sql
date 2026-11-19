-- ============================================================================
-- mojavez-v3 — Core module migration 019 (Phase 60 / v2.2.6)
--
-- Adds the `imported_edit_locked` column to the `applicants` table.
--
-- Background: applicants created via the admin bulk-import flow
-- (BulkImportController::executeRow) are auto-provisioned with a
-- `user_accounts` row (Phase 59b) so they can log in to the user panel.
-- They should be able to edit / complete their application exactly ONCE
-- from the user panel — after that first save, the case is locked and
-- only the admin can edit it (the regular /admin/edit/{id} form).
--
-- Semantics:
--   0 = not locked yet — the user MAY edit their imported application
--       via GET/POST /panel/requests/{id}/edit (one-time edit window).
--   1 = locked — the user has saved the one-time edit. Further edits
--       must go through the admin (the user only sees a "تکمیل شده"
--       badge in /panel/requests).
--
-- Default 0 so every existing row (self-signups via the wizard, manually
-- registered by the admin, older bulk-imported rows) is treated as
-- "not an imported applicant" — they don't get the one-time edit form
-- in /panel/requests (their `registration_completed_at` is already NOT
-- NULL, so the edit-route guard rejects them anyway).
--
-- Idempotent ALTER (information_schema guard) — MariaDB does not support
-- `ADD COLUMN IF NOT EXISTS`, so we guard the DDL with a prepared
-- statement switch (same pattern as migration 20261120_003).
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'applicants'
      AND COLUMN_NAME = 'imported_edit_locked'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `applicants` ADD COLUMN `imported_edit_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `registration_completed_at`',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
