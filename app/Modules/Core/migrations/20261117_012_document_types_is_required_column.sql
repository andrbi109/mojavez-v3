-- ============================================================================
-- mojavez-v3 — Core module migration 012
-- Add a real `is_required` column to the document_types lookup table.
--
-- Phase 22 (v1.9.4): pays D-16-1 (carried since Phase 16). Until now,
-- `RegistrationController::requiredDocs()` computed `is_required` from
-- the `doc_type_key` string with a hard-coded CASE list
-- (national_id, personal_photo → required; everything else → optional).
-- This worked but blocked any future admin UI from toggling requiredness
-- per document-type. The real column makes the requirement a first-class
-- attribute of the row and lets the controller read it directly.
--
-- Idempotency:
--   1. ALTER TABLE ADD COLUMN — wrapped in an information_schema check so
--      re-running against a DB that already has the column is a no-op
--      (MariaDB does not support IF NOT EXISTS on ADD COLUMN).
--   2. UPDATE — sets is_required=1 for the two seeded required types
--      (national_id, personal_photo). Re-running is a no-op (UPDATE
--      writes the same value back).
--
-- Schema: TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active. Tinyint(1) is
-- the standard MariaDB BOOLEAN alias — PDO returns it as int 0/1 which
-- PHP truthy/falsy handles directly.
-- ============================================================================

-- 1. Add the column if it does not exist (information_schema guard).
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'document_types'
      AND COLUMN_NAME = 'is_required'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `document_types` ADD COLUMN `is_required` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Mark the two seeded required document types. Idempotent — writes
--    the same value back on re-run.
UPDATE `document_types`
   SET `is_required` = 1
 WHERE `doc_type_key` IN ('national_id', 'personal_photo');
