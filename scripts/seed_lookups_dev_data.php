<?php
declare(strict_types=1);

/**
 * mojavez-v3 — built-in dev seed for `national_lookups`.
 *
 * Phase 24 (v1.9.6): pays D-5-1. In sandboxes behind a geofence (no
 * access to qr.mojavez.ir), `scripts/seed_lookups.php` cannot fetch
 * real lookups from upstream. The previous `--from-gateway` fallback
 * only built townships from the gateway-imported sub_organization
 * rows (no provinces / no main_organization), which left the
 * registration wizard's province select empty and the golden-link
 * matcher unable to ever match province + township.
 *
 * This file is the built-in static seed: 8 provinces + 28 townships +
 * 4 main organizations — the same rows as migration 005 (the dev
 * seed migration). The script imports this array directly into
 * national_lookups (idempotent INSERT ... ON DUPLICATE KEY UPDATE)
 * so a sandbox install gets a working lookup set with zero network
 * access.
 *
 * In production with upstream access, `seed_lookups.php` (no flags)
 * still calls `LicenseService::refreshLookups()` to fetch the real
 * authoritative list. This file is only the fallback.
 *
 * @return array<int, array{kind:string, code:string, title:string, parent_code:?string, is_active:int}>
 */

return [
    // ---- 8 provinces (استان) ----
    ['kind' => 'province', 'code' => '01', 'title' => 'تهران',         'parent_code' => null, 'is_active' => 1],
    ['kind' => 'province', 'code' => '02', 'title' => 'اصفهان',         'parent_code' => null, 'is_active' => 1],
    ['kind' => 'province', 'code' => '03', 'title' => 'فارس',           'parent_code' => null, 'is_active' => 1],
    ['kind' => 'province', 'code' => '04', 'title' => 'خراسان رضوی',     'parent_code' => null, 'is_active' => 1],
    ['kind' => 'province', 'code' => '05', 'title' => 'آذربایجان شرقی', 'parent_code' => null, 'is_active' => 1],
    ['kind' => 'province', 'code' => '06', 'title' => 'گیلان',          'parent_code' => null, 'is_active' => 1],
    ['kind' => 'province', 'code' => '07', 'title' => 'مازندران',       'parent_code' => null, 'is_active' => 1],
    ['kind' => 'province', 'code' => '08', 'title' => 'خوزستان',        'parent_code' => null, 'is_active' => 1],

    // ---- 28 townships (شهرستان) — 3-5 per province ----
    ['kind' => 'township', 'code' => '0101', 'title' => 'تهران',       'parent_code' => '01', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0102', 'title' => 'اسلام‌شهر',  'parent_code' => '01', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0103', 'title' => 'رباط‌کریم',  'parent_code' => '01', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0104', 'title' => 'ورامین',      'parent_code' => '01', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0105', 'title' => 'شهریار',      'parent_code' => '01', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0201', 'title' => 'اصفهان',      'parent_code' => '02', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0202', 'title' => 'کاشان',       'parent_code' => '02', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0203', 'title' => 'نجف‌آباد',   'parent_code' => '02', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0204', 'title' => 'خمینی‌شهر',  'parent_code' => '02', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0301', 'title' => 'شیراز',       'parent_code' => '03', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0302', 'title' => 'مرودشت',      'parent_code' => '03', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0303', 'title' => 'کازرون',      'parent_code' => '03', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0304', 'title' => 'جهرم',        'parent_code' => '03', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0401', 'title' => 'مشهد',        'parent_code' => '04', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0402', 'title' => 'نیشابور',     'parent_code' => '04', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0403', 'title' => 'سبزوار',      'parent_code' => '04', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0501', 'title' => 'تبریز',       'parent_code' => '05', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0502', 'title' => 'مراغه',       'parent_code' => '05', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0503', 'title' => 'میانه',       'parent_code' => '05', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0601', 'title' => 'رشت',         'parent_code' => '06', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0602', 'title' => 'بندر انزلی',  'parent_code' => '06', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0603', 'title' => 'لاهیجان',     'parent_code' => '06', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0701', 'title' => 'ساری',         'parent_code' => '07', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0702', 'title' => 'بابل',         'parent_code' => '07', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0703', 'title' => 'آمل',          'parent_code' => '07', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0801', 'title' => 'اهواز',        'parent_code' => '08', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0802', 'title' => 'آبادان',      'parent_code' => '08', 'is_active' => 1],
    ['kind' => 'township', 'code' => '0803', 'title' => 'خرمشهر',       'parent_code' => '08', 'is_active' => 1],

    // ---- 4 main organizations ----
    ['kind' => 'main_organization', 'code' => 'ORG1', 'title' => 'سازمان میراث فرهنگی و گردشگری', 'parent_code' => null, 'is_active' => 1],
    ['kind' => 'main_organization', 'code' => 'ORG2', 'title' => 'سازمان امور اجتماعی',          'parent_code' => null, 'is_active' => 1],
    ['kind' => 'main_organization', 'code' => 'ORG3', 'title' => 'سازمان غذا و دارو',              'parent_code' => null, 'is_active' => 1],
    ['kind' => 'main_organization', 'code' => 'ORG4', 'title' => 'سازمان فنی و حرفه‌ای',           'parent_code' => null, 'is_active' => 1],
];
