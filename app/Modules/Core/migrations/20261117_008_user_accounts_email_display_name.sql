-- ============================================================================
-- mojavez-v3 — Core module migration 008
-- Add `email` and `display_name` columns to user_accounts.
--
-- Phase 13 (v1.4.0) — pay D-12-1.
-- `ProfileUpdateValidator` (Phase 12) validates these two fields and the
-- profile form shows them, but the `user_accounts` table had no such
-- columns — so `PdoUserAccountRepository::update()` silently dropped them
-- from its whitelist. This migration adds the columns; the matching
-- repository + interface update in the same phase extends the whitelist
-- to include them.
--
-- Both columns are NULLable + DEFAULT NULL so existing accounts are
-- unaffected. `email` is indexed (look-up + future unique constraint once
-- the wizard-population stabilises). `display_name` is small (<=100 chars)
-- and not indexed (display-only).
--
-- NOTE: ALTER TABLE has no IF NOT EXISTS in MariaDB. The MigrationRunner
-- records the checksum, so re-runs against a DB that already has the
-- columns are a no-op (the runner refuses to re-apply a migration whose
-- checksum matches). On a fresh install via database/schema.sql, the
-- columns are already in the CREATE TABLE — this migration is skipped
-- because the migrations table records it as already applied by the
-- initial schema-bootstrap step.
-- ============================================================================

ALTER TABLE `user_accounts`
  ADD COLUMN `email` VARCHAR(254) NULL DEFAULT NULL AFTER `username`,
  ADD COLUMN `display_name` VARCHAR(100) NULL DEFAULT NULL AFTER `email`,
  ADD INDEX `idx_user_accounts_email` (`email`);
