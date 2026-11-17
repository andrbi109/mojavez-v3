<?php
declare(strict_types=1);

/**
 * Statuses — Single Source of Truth for applicant workflow.
 *
 * Inherited from Mojavez-v2, extended for v3 with the new `triggered_by`
 * value `'auto_sync'` (used by the golden-link orchestrator).
 *
 * The 18 statuses cover:
 *  - 14 shared statuses (Draft → View/Print, Rejected, Cancelled).
 *  - 4 statuses specific to independent household businesses (service_type_id=2),
 *    sourced from smkh.mcls.gov.ir (Ministry of Cooperatives, Labour, and
 *    Social Welfare) — NOT qr.mojavez.ir (see docs/00-MASTER.md §20.8).
 *
 * This array must stay in sync with the ApplicantStatus enum
 * (app/Modules/Core/Domain/Enums/ApplicantStatus.php) and the
 * `applicants.current_status` column (VARCHAR(32), not MySQL ENUM — see
 * CODING_CONVENTIONS §5.2).
 *
 * @return array<string,mixed>
 */
return [
    'statuses' => [
        // 14 shared statuses — order is one-way review progression.
        'پیش‌نویس'                  => ['order' => 1,  'color' => 'secondary', 'manual' => true,  'pre_issue' => true],
        'تکمیل اطلاعات'             => ['order' => 2,  'color' => 'info',      'manual' => true,  'pre_issue' => true],
        'احراز نشانی'               => ['order' => 3,  'color' => 'primary',   'manual' => true,  'pre_issue' => true],
        'بررسی کارشناسی رشته'        => ['order' => 4,  'color' => 'primary',   'manual' => true,  'pre_issue' => true],
        'ثبت درخواست صدور'           => ['order' => 5,  'color' => 'primary',   'manual' => true,  'pre_issue' => true],
        'بارگذاری مدارک به سازمان'     => ['order' => 6,  'color' => 'warning',   'manual' => true,  'pre_issue' => true],
        'درخواست اصلاح مدارک'        => ['order' => 7,  'color' => 'warning',   'manual' => true,  'pre_issue' => true],
        'تأیید اولیه'                => ['order' => 8,  'color' => 'success',   'manual' => true,  'pre_issue' => true],
        'تأیید نهایی'                => ['order' => 9,  'color' => 'success',   'manual' => true,  'pre_issue' => true],
        'صدور مجوز'                  => ['order' => 10, 'color' => 'success',   'manual' => true,  'pre_issue' => false],
        'تحویل مجوز'                 => ['order' => 11, 'color' => 'success',   'manual' => true,  'pre_issue' => false],
        'مشاهده و چاپ'               => ['order' => 12, 'color' => 'dark',      'manual' => false, 'pre_issue' => false],
        'رد درخواست'                 => ['order' => 98, 'color' => 'danger',    'manual' => true,  'pre_issue' => false],
        'لغو'                       => ['order' => 99, 'color' => 'danger',    'manual' => true,  'pre_issue' => false],

        // 4 household-business-only statuses (service_type_id=2).
        // Source: smkh.mcls.gov.ir
        'تطبیق آدرس با ثبت احوال'         => ['order' => 15, 'color' => 'primary', 'manual' => true, 'pre_issue' => true,  'services' => [2]],
        'دریافت تصویر مجوز'               => ['order' => 25, 'color' => 'success', 'manual' => true, 'pre_issue' => false, 'services' => [2]],
        'تأیید اولیه در سامانه مشاغل خانگی'  => ['order' => 35, 'color' => 'success', 'manual' => true, 'pre_issue' => false, 'services' => [2]],
        'تأیید نهایی دریافت کد رهگیری'      => ['order' => 36, 'color' => 'success', 'manual' => true, 'pre_issue' => false, 'services' => [2]],
    ],

    // Terminal statuses — the case is closed.
    // Only Rejected, Cancelled, LicenseDelivery, and the final household
    // status are terminal. "صدور مجوز" and "دریافت تصویر مجوز" are NOT
    // terminal because they have follow-up steps.
    'terminal' => [
        'رد درخواست',
        'لغو',
        'تحویل مجوز',
        'تأیید نهایی دریافت کد رهگیری',
    ],

    // Pre-issuance statuses — subject to 14-day auto-cancel
    // from "ثبت درخواست صدور".
    'pre_issue' => [
        'پیش‌نویس', 'تکمیل اطلاعات', 'احراز نشانی', 'بررسی کارشناسی رشته',
        'ثبت درخواست صدور', 'بارگذاری مدارک به سازمان', 'درخواست اصلاح مدارک',
        'تأیید اولیه', 'تأیید نهایی', 'تطبیق آدرس با ثبت احوال',
    ],

    // Triggered-by values for status_log.triggered_by.
    // 'auto_sync' is new in v3 (golden-link orchestrator).
    'triggered_by' => [
        'admin',      // admin user action
        'user',       // applicant self-service
        'system',     // system/cron (e.g. auto-cancel)
        'auto_sync',  // golden-link sync orchestrator (NEW in v3)
    ],
];
