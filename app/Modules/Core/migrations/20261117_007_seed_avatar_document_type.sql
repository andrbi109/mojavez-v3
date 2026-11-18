-- ============================================================================
-- mojavez-v3 — Core module migration 007
-- Seed the `avatar` document_type row.
--
-- Phase 12 (v1.3.0): the new AvatarService stores avatar uploads as rows
-- in the `documents` table with `doc_type='avatar'`. The `documents`
-- schema uses a free-form VARCHAR `doc_type` column (NOT a FK to
-- `document_types.id`), so this seed row is forward-compatibility: it
-- documents the 'avatar' doctype as a first-class entry in the
-- `document_types` lookup table (for future admin UI + tighter FK
-- constraint in v1.4+).
--
-- INSERT IGNORE keeps the migration idempotent — re-running against a
-- DB that already has the row is a no-op (UNIQUE constraint on
-- `doc_type_key`).
-- ============================================================================

INSERT IGNORE INTO document_types
    (doc_type_key, label, category, is_active, sort_order)
VALUES
    ('avatar', 'تصویر پروفایل', 'user_upload', 1, 100);
