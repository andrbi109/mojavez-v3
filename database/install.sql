-- ============================================================================
-- mojavez-v3 — database/install.sql
-- ============================================================================
-- Fresh-install entry point. Creates the mojavez_v3 database (if missing)
-- and the mojavez user (if missing), then sources schema.sql.
--
-- Usage (as DB root or with CREATE privilege):
--   mariadb -u root -p < database/install.sql
-- Or from inside MariaDB:
--   SOURCE database/install.sql;
--
-- For shared hosting where you can't CREATE DATABASE, skip this file and
-- import schema.sql directly into your pre-created DB via phpMyAdmin.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS mojavez_v3
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Create the app user (no-op if it exists — adjust password as needed).
CREATE USER IF NOT EXISTS 'mojavez'@'localhost' IDENTIFIED BY 'mojavez_pass';
GRANT ALL PRIVILEGES ON mojavez_v3.* TO 'mojavez'@'localhost';
FLUSH PRIVILEGES;

USE mojavez_v3;
SOURCE schema.sql;

-- Phase 12 (v1.3.0) seed: the 'avatar' document_type row. Idempotent
-- (UNIQUE constraint on document_types.doc_type_key). The new
-- AvatarService stores avatar uploads as documents with doc_type='avatar';
-- this seed row is forward-compatibility for a future admin UI + tighter
-- FK constraint.
INSERT IGNORE INTO document_types
    (doc_type_key, label, category, is_active, sort_order)
VALUES
    ('avatar', 'تصویر پروفایل', 'user_upload', 1, 100);

-- Phase 14 (v1.5.0) seed: the 5 default service_types + 8 license_titles.
-- Pays D-2-9. Idempotent (INSERT ... ON DUPLICATE KEY UPDATE on the
-- UNIQUE(title) and UNIQUE(service_type_id, title) constraints). The
-- wizard's service-type dropdown and the SettingsController's
-- `default_service_type_id` both reference these ids 1..5 directly.
-- Mirrors the migration 20261117_009_seed_default_service_types.sql
-- (the schema.sql generator doesn't dump seed data — this is the
-- hand-added install.sql companion).
INSERT INTO `service_types` (`id`, `title`, `authority`, `description`, `is_active`, `sort_order`) VALUES
  (1, 'صنایع دستی', 'سازمان میراث فرهنگی و گردشگری', 'صدور مجوز برای تولیدکنندگان صنایع دستی', 1, 1),
  (2, 'مشاغل خانگی', 'سازمان امور اجتماعی', 'صدور مجوز برای کسب‌وکارهای خانگی', 1, 2),
  (3, 'صنایع غذایی خانگی', 'سازمان غذا و دارو', 'تولید مواد غذایی در خانه', 1, 3),
  (4, 'خدمات فنی', 'سازمان فنی و حرفه‌ای', 'مشاغل فنی و تعمیراتی', 1, 4),
  (5, 'فرش و قالی', 'سازمان میراث فرهنگی', 'بافندگان فرش دستباف', 1, 5)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT INTO `license_titles` (`service_type_id`, `category`, `title`, `is_active`, `sort_order`) VALUES
  (1, 'سفال و سرامیک', 'سفالگری', 1, 1),
  (1, 'چوب', 'معرق چوب', 1, 2),
  (1, 'فلز', 'زینت فلزی', 1, 3),
  (2, 'پوشاک', 'خیاطی خانگی', 1, 1),
  (2, 'غذایی', 'کنسرو خانگی', 1, 2),
  (3, 'غذایی', 'ترشیجات', 1, 1),
  (4, 'الکترونیک', 'تعمیرات موبایل', 1, 1),
  (5, 'بافت', 'گلیم بافی', 1, 1)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- Phase 16 (v1.7.0) seed: the 6 default document_types so the wizard's
-- step 4 has real document-type definitions. Pays part of D-7-3.
-- Idempotent (INSERT ... ON DUPLICATE KEY UPDATE on UNIQUE doc_type_key).
-- The avatar row is re-asserted at sort_order=99 (was 100 in the
-- Phase 12 seed above — the ON DUPLICATE KEY UPDATE rewrites the
-- sort_order so this seed is the single source of truth for the
-- default ordering). Mirrors migration
-- 20261117_010_seed_document_types_default.sql.
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

-- Phase 16 (v1.7.0) seed: the default admin user (username=admin,
-- password=admin123 — change on first login!) so a fresh install is
-- immediately usable. Idempotent (INSERT ... WHERE NOT EXISTS skips if
-- any row with username='admin' exists, so password rotation is
-- preserved). Mirrors migration 20261117_011_seed_admin_default.sql.
INSERT INTO `users` (`username`, `password`, `display_name`, `role`, `created_at`)
SELECT
    'admin',
    '$2y$12$Wgb0mwSnZKV/T7cAM4rvsepvrafAPzd8cg1hIGL5/deYfDpaIum6y',
    'مدیر کل',
    'admin',
    NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'admin');

