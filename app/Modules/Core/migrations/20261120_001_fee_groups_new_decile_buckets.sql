-- ============================================================================
-- mojavez-v3 — Core module migration 016
-- Phase 54 (v2.2.0) — update fee_groups to the new decile buckets.
--
-- Old seed (from migration 20261117_015) used:
--   A=1-3, B=4-6, C=7-9, D=روستایی (1-10)
-- The old buckets had two issues:
--   1. Decile 10 was in no group (the C bucket stopped at 9).
--   2. The D bucket ("روستایی") overlapped all the others (1-10), which
--      made the "which group does this applicant fall into?" lookup
--      ambiguous (an applicant in decile 5 matched BOTH B and D).
--
-- New buckets are non-overlapping and cover 1..10 with no gaps:
--   A = deciles 1-2  (low income)
--   B = deciles 3-4  (medium income)
--   C = deciles 5-6  (high income)
--   D = deciles 7-10 (very high income)
--
-- This migration is idempotent and uses the UNIQUE(code) constraint to
-- update each existing row in place (preserving fee_group_items FK rows
-- via the existing fee_group_id values). The label text is updated to
-- match the new decile ranges so the admin UI shows consistent labels.
-- The D bucket's label changes from "روستایی (مهمان)" to a decile
-- range label; the rural exemption is now controlled by the
-- `fee_rural_auto_eligible` setting (Phase 20, see SettingsController).
--
-- Re-running migration 015 (with its new seed) is intentionally skipped
-- by MigrationRunner because the file's SHA-256 checksum changed —
-- this migration is the canonical upgrade path for existing installs.
-- ============================================================================

-- A: deciles 1-2
UPDATE `fee_groups`
   SET `label` = 'دهک ۱-۲ (پایین)', `decile_min` = 1, `decile_max` = 2, `sort_order` = 10
 WHERE `code` = 'A';

-- B: deciles 3-4
UPDATE `fee_groups`
   SET `label` = 'دهک ۳-۴ (متوسط)', `decile_min` = 3, `decile_max` = 4, `sort_order` = 20
 WHERE `code` = 'B';

-- C: deciles 5-6
UPDATE `fee_groups`
   SET `label` = 'دهک ۵-۶ (بالا)', `decile_min` = 5, `decile_max` = 6, `sort_order` = 30
 WHERE `code` = 'C';

-- D: deciles 7-10 (formerly "روستایی (مهمان)" 1-10)
UPDATE `fee_groups`
   SET `label` = 'دهک ۷-۱۰ (بسیار بالا)', `decile_min` = 7, `decile_max` = 10, `sort_order` = 40
 WHERE `code` = 'D';

-- Defensive: if the D row didn't exist (some installs deleted it),
-- re-create it with the new decile range. INSERT IGNORE keeps this
-- idempotent.
INSERT IGNORE INTO `fee_groups` (`code`, `label`, `decile_min`, `decile_max`, `total_amount`, `is_active`, `sort_order`)
VALUES ('D', 'دهک ۷-۱۰ (بسیار بالا)', 7, 10, 50000, 1, 40);
