<?php
declare(strict_types=1);

/**
 * Application config.
 *
 * All values default to safe production values. Override per-environment via
 * .env (read by App\Shared\Support\Environment).
 *
 * @return array<string,mixed>
 */
return [
    // Single source of truth for version — mirrors VERSION file & CHANGELOG.
    // Fix for v2's version three-way split (see docs/03-strategy-and-risk.md §2.3).
    'version'      => trim((string)file_get_contents(__DIR__ . '/../VERSION') ?: '0.0.0'),
    'version_name' => 'بازنویسی یکپارچه از صفر — v3',
    'name'         => 'mojavez-v3',

    // Defaults; overridden by Environment at runtime.
    'env'          => $_ENV['APP_ENV']   ?? 'production',
    'debug'        => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
    'timezone'     => 'Asia/Tehran',
    'locale'       => 'fa_IR',
    'base_url'     => $_ENV['BASE_URL']  ?? '/',

    // Pagination
    'default_page_size' => 20,
    'max_page_size'      => 100,

    // Uploads — sizes in bytes.
    'max_upload_size'  => 2 * 1024 * 1024,     // 2 MiB — documents
    'max_csv_size'     => 5 * 1024 * 1024,     // 5 MiB — CSV imports
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
    'upload_dir'        => dirname(__DIR__) . '/uploads',
    'max_image_dim'     => 1920,                // re-encode width/height cap
    'jpeg_quality'      => 85,
    'png_compression'   => 9,

    // Workflow timings
    'auto_cancel_days'  => 14,   // from "ثبت درخواست صدور"
    'priority_days'     => 12,   // priority for registrations older than this

    // License image access token
    'license_access_token_hours' => 72,

    // Cache TTLs (seconds)
    'cache_ttl_search'   => 1800,
    'cache_ttl_lookups'  => 86400,
    'cache_ttl_count'    => 3600,

    // Sync (golden link) thresholds
    'sync_cycle_interval_min'   => 60,
    'sync_auto_confirm_threshold'    => 90,
    'sync_operator_flag_threshold'   => 50,

    // Session
    'session_lifetime'      => 86400,
    'session_sid_length'    => 40,        // not 48 (PHP 8.5 compat from v2)
    'session_sid_bits'      => 5,         // not 6 (PHP 8.5 warning fix)

    // Security flags (default true in v3; fixes v2 weaknesses).
    'tracking_require_mobile'  => true,
    'use_cdn'                   => false,   // never; self-host mandatory

    // Public rate limit
    'public_rate_limit_per_min' => 30,
];
