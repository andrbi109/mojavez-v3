<?php
declare(strict_types=1);

/**
 * Default search filter for the national gateway scraper.
 *
 * Inherited from gateway's seed-defaults, this is the fallback Filter
 * applied when an admin clicks "جستجو" without explicit criteria. It
 * restricts to heritage-related licenses in Khorasan Razavi issued in
 * 1400-1405 (Persian calendar). Operators can save & override their own
 * filters via the /api/v1/filters endpoints.
 *
 * Values here are Persian-calendar strings (yyyy/mm/dd). The Filter
 * Domain Object converts to Gregorian when calling upstream via
 * `toUpstreamPayload()` (see docs/00-MASTER.md §6.1).
 *
 * @return array<string,mixed>
 */
return [
    'default_filter' => [
        'name'                => null,
        'license_id'          => null,
        'issue_start_date'    => '1400/01/01',  // Persian
        'issue_end_date'      => '1405/12/29',
        'last_op_start_date'  => null,
        'last_op_end_date'    => null,
        'province_id'         => 7,             // Khorasan Razavi
        'township_id'         => null,
        'main_org_code'       => null,
        'sub_org_code'        => null,
        'page'                => 1,
        'page_size'           => 20,
        'label'               => 'فیلتر پیش‌فرض',
        // NEW in v3: golden-link specific filter
        'unmatched_only'      => false,
        'service_type_id'      => null,
    ],
];
