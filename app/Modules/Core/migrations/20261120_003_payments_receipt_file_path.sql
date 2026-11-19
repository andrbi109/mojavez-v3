-- ============================================================================
-- mojavez-v3 — Core module migration 018 (Phase 58 / v2.2.4)
--
-- Adds two nullable columns to the `payments` table so the user-side
-- receipt-upload flow (POST /panel/payments/{id}/receipt) can persist the
-- uploaded receipt file path and the user-supplied receipt description
-- alongside the existing payment row.
--
-- Until now the `payments` table stored the receipt only via the
-- `receipt_document_id` FK to `documents.id` (an applicant-bound row,
-- set by the LicenseController::pay flow). The new user-side upload
-- flow needs a simpler path: the receipt belongs to the payment, not
-- the applicant — so we record the on-disk path directly on the
-- `payments` row (mirroring the task spec wording: "set status = 'در
-- انتظار رسید', receipt_file_path = $path"). This keeps the upload
-- endpoint a single UPDATE instead of an INSERT-into-documents + UPDATE.
--
-- Both columns are nullable so existing rows stay valid.
--
-- Idempotent ALTER (information_schema guard) — MariaDB does not support
-- `ADD COLUMN IF NOT EXISTS`, so we guard each DDL with a prepared
-- statement switch (same pattern as migration 20261120_002).
-- ============================================================================

SET @col_path_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'receipt_file_path'
);
SET @ddl_path = IF(@col_path_exists = 0,
    'ALTER TABLE `payments` ADD COLUMN `receipt_file_path` VARCHAR(255) NULL DEFAULT NULL AFTER `reject_reason`',
    'SELECT 1'
);
PREPARE stmt_path FROM @ddl_path;
EXECUTE stmt_path;
DEALLOCATE PREPARE stmt_path;

SET @col_desc_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'receipt_description'
);
SET @ddl_desc = IF(@col_desc_exists = 0,
    'ALTER TABLE `payments` ADD COLUMN `receipt_description` VARCHAR(500) NULL DEFAULT NULL AFTER `receipt_file_path`',
    'SELECT 1'
);
PREPARE stmt_desc FROM @ddl_desc;
EXECUTE stmt_desc;
DEALLOCATE PREPARE stmt_desc;
