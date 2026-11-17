-- ============================================================================
-- mojavez-v3 — Core module migration 010
-- Seed the 6 default document_types so the registration wizard's step 4
-- has real document-type definitions to render against.
--
-- Phase 16 (v1.7.0): pays part of D-7-3. The wizard's step 4 view
-- (registration/step4.php) iterates a `requiredDocs` array to render one
-- file input per document type. Until now, `RegistrationController::
-- requiredDocs()` returned a hard-coded 3-row array, so the wizard had
-- no way to require docs dynamically. This migration seeds the lookup
-- table with the 6 default document types (national_id, personal_photo,
-- ownership_deed, skill_cert, business_license, avatar); the controller
-- now reads from this table, and the view marks `national_id` and
-- `personal_photo` as required (HTML5 `required` attribute) while the
-- rest are optional.
--
-- The `avatar` row is the same key Phase 12 (v1.3.0) seeded via
-- migration 007 (with sort_order=100). This migration re-asserts it
-- with sort_order=99 — the ON DUPLICATE KEY UPDATE rewrites the
-- sort_order value so the seed here is the single source of truth for
-- the default ordering, and re-running migration 007 + 010 in order
-- still ends at sort_order=99 (010 runs after 007).
--
-- Note: the table does NOT have an `is_required` column. For Phase 16,
-- `RegistrationController::requiredDocs()` computes `is_required` from
-- `doc_type_key` (national_id and personal_photo are required; the
-- others are optional). A real `is_required` column may be added in a
-- later phase if the admin UI needs per-doctor toggling.
--
-- Idempotency: INSERT ... ON DUPLICATE KEY UPDATE on the UNIQUE
-- `doc_type_key` constraint — re-running against a DB that already has
-- the rows is a no-op (label, category, is_active, sort_order are
-- re-applied to themselves).
-- ============================================================================

INSERT INTO `document_types`
    (`doc_type_key`, `label`, `category`, `is_active`, `sort_order`)
VALUES
    ('national_id',     'تصویر کارت ملی',         'user_upload', 1, 1),
    ('personal_photo',  'عکس پرسنلی',             'user_upload', 1, 2),
    ('ownership_deed',  'سند مالکیت محل کسب',     'user_upload', 1, 3),
    ('skill_cert',      'گواهی مهارت',           'user_upload', 1, 4),
    ('business_license','مجوز کسب',              'user_upload', 1, 5),
    ('avatar',          'تصویر پروفایل',          'user_upload', 1, 99)
ON DUPLICATE KEY UPDATE
    `label`      = VALUES(`label`),
    `category`   = VALUES(`category`),
    `is_active`  = VALUES(`is_active`),
    `sort_order` = VALUES(`sort_order`);
