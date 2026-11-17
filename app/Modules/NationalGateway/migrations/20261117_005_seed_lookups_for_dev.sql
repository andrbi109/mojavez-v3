-- Phase 15 (v1.6.0) — seed national_lookups with 8 provinces + 28 townships + 4 main organizations.
-- Idempotent INSERT ... ON DUPLICATE KEY UPDATE so it can be re-run safely.
--
-- In a real deployment, these rows would be fetched from the upstream
-- national gateway (qr.mojavez.ir) via LicenseService::refreshLookups(). For
-- development and test environments without access to upstream, this seed
-- provides enough data for the registration wizard's province/township
-- selects to work and for the golden-link matcher to find matches.

INSERT INTO `national_lookups` (`kind`, `code`, `title`, `parent_code`, `is_active`) VALUES
  -- 8 provinces (استان)
  ('province', '01', 'تهران', NULL, 1),
  ('province', '02', 'اصفهان', NULL, 1),
  ('province', '03', 'فارس', NULL, 1),
  ('province', '04', 'خراسان رضوی', NULL, 1),
  ('province', '05', 'آذربایجان شرقی', NULL, 1),
  ('province', '06', 'گیلان', NULL, 1),
  ('province', '07', 'مازندران', NULL, 1),
  ('province', '08', 'خوزستان', NULL, 1),
  -- 28 townships (شهرستان) — 3-5 per province
  ('township', '0101', 'تهران', '01', 1),
  ('township', '0102', 'اسلام‌شهر', '01', 1),
  ('township', '0103', 'رباط‌کریم', '01', 1),
  ('township', '0104', 'ورامین', '01', 1),
  ('township', '0105', 'شهریار', '01', 1),
  ('township', '0201', 'اصفهان', '02', 1),
  ('township', '0202', 'کاشان', '02', 1),
  ('township', '0203', 'نجف‌آباد', '02', 1),
  ('township', '0204', 'خمینی‌شهر', '02', 1),
  ('township', '0301', 'شیراز', '03', 1),
  ('township', '0302', 'مرودشت', '03', 1),
  ('township', '0303', 'کازرون', '03', 1),
  ('township', '0304', 'جهرم', '03', 1),
  ('township', '0401', 'مشهد', '04', 1),
  ('township', '0402', 'نیشابور', '04', 1),
  ('township', '0403', 'سبزوار', '04', 1),
  ('township', '0501', 'تبریز', '05', 1),
  ('township', '0502', 'مراغه', '05', 1),
  ('township', '0503', 'میانه', '05', 1),
  ('township', '0601', 'رشت', '06', 1),
  ('township', '0602', 'بندر انزلی', '06', 1),
  ('township', '0603', 'لاهیجان', '06', 1),
  ('township', '0701', 'ساری', '07', 1),
  ('township', '0702', 'بابل', '07', 1),
  ('township', '0703', 'آمل', '07', 1),
  ('township', '0801', 'اهواز', '08', 1),
  ('township', '0802', 'آبادان', '08', 1),
  ('township', '0803', 'خرمشهر', '08', 1),
  -- 4 main organizations
  ('main_organization', 'ORG1', 'سازمان میراث فرهنگی و گردشگری', NULL, 1),
  ('main_organization', 'ORG2', 'سازمان امور اجتماعی', NULL, 1),
  ('main_organization', 'ORG3', 'سازمان غذا و دارو', NULL, 1),
  ('main_organization', 'ORG4', 'سازمان فنی و حرفه‌ای', NULL, 1)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);
