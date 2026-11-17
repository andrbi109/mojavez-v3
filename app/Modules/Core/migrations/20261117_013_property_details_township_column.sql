-- ============================================================================
-- mojavez-v3 — Core module migration 013
-- Add a `township` column to `property_details` (Phase 24 / D-5-5).
--
-- In v2/v3, the `property_details.county` column held the city/district
-- value the user picked in the wizard (the `township_id` select). The
-- golden-link matcher then routed `county` into the matcher's `township`
-- field, which worked because in v2 county === township for the
-- registration wizard.
--
-- In production with the upstream national gateway, county and township
-- can legitimately differ (the upstream exposes a finer-grained township
-- list than the gateway's old sub_organization data). To support that,
-- Phase 24 adds a separate `township` column so the wizard can store
-- the resolved township title distinctly from `county`. The matcher
-- (ApplicantSyncAdapter) then prefers `township` if set, falling back
-- to `county` for backward compat with rows written by older code.
--
-- Schema: VARCHAR(50) NULL DEFAULT NULL AFTER county. NULL is allowed
-- because existing rows don't have the value yet — they continue to
-- match via the `county` fallback. Future RegistrationController
-- writes always set both `county` (the v2 value) and `township` (the
-- resolved title).
--
-- Idempotency:
--   1. ALTER TABLE ADD COLUMN — wrapped in an information_schema check
--      so re-running against a DB that already has the column is a
--      no-op (MariaDB does not support IF NOT EXISTS on ADD COLUMN).
-- ============================================================================

-- 1. Add the column if it does not exist (information_schema guard).
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'property_details'
      AND COLUMN_NAME = 'township'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `property_details` ADD COLUMN `township` VARCHAR(50) NULL DEFAULT NULL AFTER `county`',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
