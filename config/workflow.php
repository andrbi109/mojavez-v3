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
 * Phase 22 (v1.9.4) — 18-status cycle completion:
 *  - `expert` and `document_uploader` now also cover the 4 household-only
 *    statuses (service_type_id=2) so experts handling a household-business
 *    applicant can move it through the household chain (order 15 → 25 → 35
 *    → 36). The backward-order guard in
 *    Controller::validateStatusTransition() still forbids going backwards
 *    in order (except to Cancelled/Rejected).
 *  - `auto_sync.to` now includes "تحویل مجوز" in addition to "صدور مجوز"
 *    so the golden-link orchestrator can fast-track to delivery when the
 *    national gateway already has the license image ready.
 *  - `auto_cancel` is intentionally NOT a row here — it goes through
 *    AutoCancelService::cancelExpired(), which calls
 *    `changeStatus($id, Cancelled, 'system', ...)` directly (no
 *    role-guard check). The matrix is for *manual* role-guarded transitions
 *    only; system triggers bypass it.
 *
 * @return array<string,mixed>
 */
return [
    'transitions' => [
        'expert' => [
            'from' => [
                'تکمیل اطلاعات', 'احراز نشانی', 'بررسی کارشناسی رشته',
                'درخواست اصلاح مدارک', 'تأیید اولیه',
                // Phase 22 — household-only statuses (service_type_id=2):
                'تطبیق آدرس با ثبت احوال', 'تأیید اولیه در سامانه مشاغل خانگی',
            ],
            'to'   => [
                'احراز نشانی', 'بررسی کارشناسی رشته', 'ثبت درخواست صدور',
                'درخواست اصلاح مدارک', 'تأیید اولیه', 'تأیید نهایی',
                'رد درخواست', 'لغو',
                // Phase 22 — household-only (service_type_id=2):
                'تطبیق آدرس با ثبت احوال', 'تأیید اولیه در سامانه مشاغل خانگی',
            ],
        ],
        'document_uploader' => [
            'from' => ['ثبت درخواست صدور', 'بارگذاری مدارک به سازمان'],
            'to'   => [
                'بارگذاری مدارک به سازمان', 'درخواست اصلاح مدارک',
                'تأیید اولیه', 'صدور مجوز', 'لغو',
                // Phase 22 — household-only image-receipt step:
                'دریافت تصویر مجوز',
            ],
        ],
        'admin' => [
            'from' => [],  // admin can transition from any status
            'to'   => [],  // ... to any status (all 18 statuses)
        ],
        // NEW in v3: golden-link sync orchestrator. May only move a pre-issue
        // applicant to "صدور مجوز" (license issued on national gateway) or
        // — when the national gateway already has the license image ready —
        // directly to "تحویل مجوز" (Phase 22 extension).
        'auto_sync' => [
            'from' => [
                'ثبت درخواست صدور', 'بارگذاری مدارک به سازمان',
                'تأیید اولیه', 'تأیید نهایی',
            ],
            'to'   => ['صدور مجوز', 'تحویل مجوز'],
        ],
    ],

    // Per-role "tasks" view: statuses for which this role has work to do.
    // Used by the admin dashboard "وظایف من" widget.
    // Phase 22: expert + document_uploader task lists now include the
    // 4 household-only statuses so household-business applicants appear
    // in the role's work queue.
    'task_lists' => [
        'expert'            => [
            'تکمیل اطلاعات', 'احراز نشانی', 'بررسی کارشناسی رشته',
            'درخواست اصلاح مدارک', 'تأیید اولیه',
            'تطبیق آدرس با ثبت احوال', 'تأیید اولیه در سامانه مشاغل خانگی',
        ],
        'document_uploader' => [
            'ثبت درخواست صدور', 'بارگذاری مدارک به سازمان',
            'دریافت تصویر مجوز',
        ],
        'sync_operator'     => [],  // sync_operator tasks come from sync_audit_log, not status_log
        'admin'             => [],  // admin sees everything
    ],
];
