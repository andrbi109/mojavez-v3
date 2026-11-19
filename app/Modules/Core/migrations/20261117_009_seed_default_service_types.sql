-- ============================================================================
-- mojavez-v3 — Core module migration 009
-- Seed the 5 default service_types + 8 license_titles (2..1 per service).
--
-- Phase 14 (v1.5.0): pays D-2-9. The wizard registration flow and the
-- admin panel both expect the 5 default service types (صنایع دستی،
-- مشاغل خانگی، صنایع غذایی خانگی، خدمات فنی، فرش و قالی) to exist with
-- stable primary-key ids (1..5) — the wizard's service-type dropdown and
-- the SettingsController's `default_service_type_id` select both hard-
-- code these ids. A fresh install previously left the lookup tables
-- empty (the admin had to populate them manually before the wizard
-- worked). This migration seeds them at install time.
--
-- The 8 license_titles span the 5 service_types (service_type 1 has 3
-- categories: سفال/چوب/فلز; service_type 2 has 2; service_type 3 has 1;
-- service_type 4 has 1; service_type 5 has 1). These are the same
-- reference rows the admin would create by hand — the seed just makes
-- the wizard's license-title dropdown non-empty out of the box.
--
-- Idempotency:
--   - service_types: INSERT ... ON DUPLICATE KEY UPDATE keeps the
--     migration idempotent on the UNIQUE(title) constraint. Re-running
--     against a DB that already has the rows is a no-op (the title is
--     re-applied to itself, which is a zero-cost UPDATE).
--   - license_titles: same pattern on the UNIQUE(service_type_id, title)
--     constraint.
--
-- Both INSERTs use explicit id values for the service_types so the
-- foreign-key references from license_titles.service_type_id remain
-- stable. The license_titles' id values are NOT pinned (AUTO_INCREMENT)
-- — re-running against a DB that already has rows is still a no-op
-- because the UNIQUE(service_type_id, title) constraint catches the
-- duplicate before the AUTO_INCREMENT advances.
-- ============================================================================

INSERT INTO `service_types` (`id`, `title`, `authority`, `description`, `is_active`, `sort_order`) VALUES
  (1, 'صنایع دستی', 'سازمان میراث فرهنگی و گردشگری', 'صدور مجوز برای تولیدکنندگان صنایع دستی', 1, 1),
  (2, 'مشاغل خانگی', 'سازمان امور اجتماعی', 'صدور مجوز برای کسب‌وکارهای خانگی', 1, 2),
  (3, 'صنایع غذایی خانگی', 'سازمان غذا و دارو', 'تولید مواد غذایی در خانه', 1, 3),
  (4, 'خدمات فنی', 'سازمان فنی و حرفه‌ای', 'مشاغل فنی و تعمیراتی', 1, 4),
  (5, 'فرش و قالی', 'سازمان میراث فرهنگی', 'بافندگان فرش دستباف', 1, 5)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- License titles per service (idempotent on UNIQUE(service_type_id, title)).
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
