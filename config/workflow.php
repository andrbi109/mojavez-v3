<?php
declare(strict_types=1);

/**
 * Workflow transition matrix — which role may move the applicant from
 * status X to status Y. Inherited from Mojavez-v2, extended for v3:
 *
 *  - `auto_sync` is allowed to transition a pre-issue applicant to
 *    "صدور مجوز" when MatchConfidence = AutoConfirmed.
 *  - `sync_operator` is a new role that may confirm/reject operator-flagged
 *    matches (handled in the sync admin panel, not via status transitions).
 *
 * `admin` may always transition (empty from/to means "all").
 *
 * @return array<string,mixed>
 */
return [
    'transitions' => [
        'expert' => [
            'from' => ['تکمیل اطلاعات', 'احراز نشانی', 'بررسی کارشناسی رشته', 'درخواست اصلاح مدارک', 'تأیید اولیه'],
            'to'   => ['احراز نشانی', 'بررسی کارشناسی رشته', 'ثبت درخواست صدور', 'درخواست اصلاح مدارک',
                        'تأیید اولیه', 'تأیید نهایی', 'رد درخواست', 'لغو'],
        ],
        'document_uploader' => [
            'from' => ['ثبت درخواست صدور', 'بارگذاری مدارک به سازمان'],
            'to'   => ['بارگذاری مدارک به سازمان', 'درخواست اصلاح مدارک', 'تأیید اولیه', 'صدور مجوز', 'لغو'],
        ],
        'admin' => [
            'from' => [],  // admin can transition from any status
            'to'   => [],  // ... to any status
        ],
        // NEW in v3: golden-link sync orchestrator. May only move a pre-issue
        // applicant to "صدور مجوز" (license issued on national gateway).
        'auto_sync' => [
            'from' => [
                'ثبت درخواست صدور', 'بارگذاری مدارک به سازمان',
                'تأیید اولیه', 'تأیید نهایی',
            ],
            'to'   => ['صدور مجوز'],
        ],
    ],

    // Per-role "tasks" view: statuses for which this role has work to do.
    // Used by the admin dashboard "وظایف من" widget.
    'task_lists' => [
        'expert'            => ['تکمیل اطلاعات', 'احراز نشانی', 'بررسی کارشناسی رشته', 'درخواست اصلاح مدارک', 'تأیید اولیه'],
        'document_uploader' => ['ثبت درخواست صدور', 'بارگذاری مدارک به سازمان'],
        'sync_operator'     => [],  // sync_operator tasks come from sync_audit_log, not status_log
        'admin'             => [],  // admin sees everything
    ],
];
