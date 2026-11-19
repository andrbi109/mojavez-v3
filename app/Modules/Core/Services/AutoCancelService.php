<?php
declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\StatusLogRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Config;
use App\Shared\Support\StructuredLogger;

/**
 * AutoCancelService — auto-cancels expired applications + recomputes priority
 * flags.
 *
 * Inherited from v2's AutoCancelService, refactored to use the
 * ApplicantRepositoryInterface (instead of `Database::getInstance()`
 * directly) so it's testable + the golden-link orchestrator can reuse it.
 *
 * Trigger points (per docs/00-MASTER.md §13.1):
 *  - GET /check-cancel (cron-style, CRON_SECRET protected)
 *  - Every admin dashboard view (poor man's cron)
 *  - Every /tracking/status/{code} view
 *
 * Two responsibilities:
 *   cancelExpired() — auto-cancel applications whose IssueRequest is older
 *     than AUTO_CANCEL_DAYS (14) and still in a pre-issue status.
 *   updatePriorities() — set priority=1 for applications whose
 *     registration_completed_at is older than PRIORITY_DAYS (12) and that
 *     are still in pre-issue (so they get processed first by experts).
 *
 * Phase 45 (v2.1.1) — when the system changes an applicant's status
 * (either via cancelExpired() or autoProgress()), a user_notification
 * row is inserted for the applicant's user_account_id so the user sees
 * the change in their /panel/notifications surface. The notification
 * insert is best-effort: a DB error doesn't abort the status change.
 */
final class AutoCancelService
{
    public function __construct(
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly StatusLogRepositoryInterface $statusLog,
        private readonly StructuredLogger $logger,
        private readonly ?Connection $conn = null,
    ) {}

    /**
     * Auto-cancel expired applications. Returns the count of cancelled.
     *
     * Phase 45 (v2.1.1) — for each cancelled applicant, inserts a
     * user_notifications row (type='status_change') so the user is
     * informed that their application was auto-cancelled. The notification
     * is best-effort: a failure to insert is logged but does not abort
     * the cancellation loop.
     */
    public function cancelExpired(): int
    {
        $days = (int)Config::get('app.auto_cancel_days', 14);
        $expired = $this->applicants->findExpiredApplications($days);
        $count = 0;
        foreach ($expired as $applicant) {
            $this->applicants->changeStatus(
                $applicant->id,
                ApplicantStatus::Cancelled,
                'system',
                "لغو خودکار به دلیل عدم تکمیل پس از {$days} روز از ثبت درخواست صدور"
            );
            $this->applicants->updateLicenseState($applicant->id, \App\Modules\Core\Domain\Enums\LicenseState::Current);
            $this->logger->info('Application auto-cancelled', [
                '_module'        => 'core',
                'applicant_id'   => $applicant->id,
                'tracking_code'  => $applicant->trackingCode,
                'expired_days'   => $days,
            ]);

            // Phase 45 (v2.1.1) — notify the user that their application
            // was auto-cancelled by the system.
            $this->notifyStatusChange(
                $applicant->id,
                ApplicantStatus::Cancelled,
                "لغو خودکار به دلیل عدم تکمیل پس از {$days} روز از ثبت درخواست صدور"
            );

            $count++;
        }
        return $count;
    }

    /**
     * Recompute priority flags. Returns the count of newly-prioritised.
     *
     * Phase 13 (v1.4.0): pays D-2-5. Rewritten as a single SQL UPDATE
     * (via ApplicantRepositoryInterface::bulkSetPriorityForPreIssueOlderThan)
     * instead of the previous `paginate()` + per-row `updatePriority()`
     * PHP loop. The new approach is O(1) round-trips regardless of the
     * number of eligible rows; the old approach was O(N) UPDATE
     * statements (slow for large volumes — capped at 1000 per call by
     * the paginate() default page size).
     *
     * Behaviour is identical to the previous PHP-loop version:
     *   - eligibility: registration_completed_at < NOW() - $days days
     *     AND current_status IN (pre-issue statuses)
     *     AND priority = 0
     *     AND deleted_at IS NULL
     *   - action: SET priority = 1, updated_at = NOW()
     *   - return: count of newly-prioritised rows.
     *
     * The pre-issue status list is mirrored from
     * ApplicantStatus::isPreIssue() inside the SQL UPDATE (kept as a
     * literal because MariaDB can't call into PHP). The list must stay
     * in sync with the enum.
     */
    public function updatePriorities(): int
    {
        $days = (int)Config::get('app.priority_days', 12);
        $count = $this->applicants->bulkSetPriorityForPreIssueOlderThan($days);
        if ($count > 0) {
            $this->logger->info('AutoCancelService bulk-prioritised applicants', [
                '_module'  => 'core',
                'count'    => $count,
                'days'     => $days,
            ]);
        }
        return $count;
    }

    /**
     * Auto-progress applicants that have been stuck in their current
     * pre-issue status for more than N days. Moves each one forward by
     * exactly one step in the workflow order. The `triggered_by` of the
     * status_log entry is `'system'` so the manual-role guard in
     * Controller::validateStatusTransition() does not apply.
     *
     * Phase 22 (v1.9.4) — completes the 18-status workflow cycle by
     * adding the *automatic* forward progression that pairs with the
     * existing automatic backward-to-terminal (`cancelExpired()`) and
     * the manual forward/backward (AdminController::changeStatus()).
     *
     * Eligibility per applicant:
     *   - deleted_at IS NULL (paginate() already filters this)
     *   - current_status is a pre-issue status present in $nextStatus
     *   - the latest status_log row matching current_status was created
     *     more than $days days ago
     *
     * The "next status" map is hard-coded here (kept as a literal
     * because the workflow matrix in config/workflow.php is role-based,
     * not next-status-based). It mirrors the `order` field in
     * config/statuses.php. Terminal and non-pre-issue statuses
     * (صدور مجوز, تحویل مجوز, مشاهده و چاپ, رد درخواست, لغو,
     * دریافت تصویر مجوز, تأیید اولیه در سامانه مشاغل خانگی,
     * تأیید نهایی دریافت کد رهگیری) are intentionally NOT in the
     * map — autoProgress only fires on pre-issue statuses, and
     * advancing to a non-pre-issue status moves the applicant out of
     * the auto_cancel + auto_progress pipeline.
     *
     * Threshold: `app.auto_progress_days` (default 7). Must be less
     * than `app.auto_cancel_days` (default 14) so an application gets
     * at least one auto_progress nudge before the 14-day auto_cancel
     * kicks in. The threshold is enforced per-status (we look at the
     * status_log timestamp for when they ENTERED the current status,
     * not registration_completed_at).
     *
     * Implementation: we iterate the pre-issue statuses and call
     * `paginate(['current_status' => $slug], 1, 1000)` for each. This
     * bounds the PHP-side work to a max of 1000 applicants per status,
     * which is acceptable because stale pre-issue applicants get
     * auto-cancelled after 14 days. The status-log age check uses
     * `StatusLogRepositoryInterface::findByApplicantId()` and
     * reverse-iterates to find the latest entry matching the current
     * status. The filter cost is O(stuck_applicants × log_length) —
     * bounded by auto-cancel, so small in practice.
     *
     * @return int  The number of applicants auto-progressed.
     */
    public function autoProgress(): int
    {
        $days = (int)Config::get('app.auto_progress_days', 7);
        if ($days <= 0) {
            return 0;  // disabled
        }

        // The "next status" map. Mirror of the `order` field in
        // config/statuses.php. Only pre-issue statuses appear here.
        $nextStatus = [
            'پیش‌نویس'                  => ApplicantStatus::InfoComplete,
            'تکمیل اطلاعات'             => ApplicantStatus::AddressVerification,
            'احراز نشانی'               => ApplicantStatus::FieldExpertReview,
            'بررسی کارشناسی رشته'        => ApplicantStatus::IssueRequest,
            'ثبت درخواست صدور'           => ApplicantStatus::DocUploadToOrg,
            'بارگذاری مدارک به سازمان'     => ApplicantStatus::InitialApproval,
            'درخواست اصلاح مدارک'        => ApplicantStatus::DocUploadToOrg,
            'تأیید اولیه'                => ApplicantStatus::FinalApproval,
            'تأیید نهایی'                => ApplicantStatus::LicenseIssuance,
            'تطبیق آدرس با ثبت احوال'         => ApplicantStatus::LicenseImageReceipt,
        ];

        $count = 0;
        foreach (array_keys($nextStatus) as $slug) {
            $result = $this->applicants->paginate(
                ['current_status' => $slug],
                1,
                1000
            );
            foreach ($result['rows'] as $row) {
                $applicantId = (int)($row['id'] ?? 0);
                $trackingCode = (string)($row['tracking_code'] ?? '');
                if ($applicantId === 0) {
                    continue;
                }
                // Latest status_log entry for this applicant's current
                // status — if it's older than $days days, advance.
                $logs = $this->statusLog->findByApplicantId($applicantId);
                $enteredAt = null;
                foreach ($logs as $log) {
                    if ($log->statusName === $slug) {
                        $enteredAt = $log->createdAt;
                        break;  // logs are ordered newest-first
                    }
                }
                if ($enteredAt === null) {
                    continue;
                }
                $ts = strtotime((string)$enteredAt);
                if ($ts === false) {
                    continue;
                }
                $ageDays = (time() - $ts) / 86400;
                if ($ageDays < $days) {
                    continue;
                }

                $target = $nextStatus[$slug];
                $this->applicants->changeStatus(
                    $applicantId,
                    $target,
                    'system',
                    "پیشرفت خودکار به دلیل توقف بیش از {$days} روز در وضعیت «{$slug}»"
                );
                $this->logger->info('Application auto-progressed', [
                    '_module'        => 'core',
                    'applicant_id'   => $applicantId,
                    'tracking_code'  => $trackingCode,
                    'from'           => $slug,
                    'to'             => $target->value,
                    'stuck_days'     => $days,
                ]);

                // Phase 45 (v2.1.1) — notify the user that the system
                // auto-progressed their application to the next status.
                $this->notifyStatusChange(
                    $applicantId,
                    $target,
                    "پیشرفت خودکار به دلیل توقف بیش از {$days} روز در وضعیت «{$slug}»"
                );

                $count++;
            }
        }
        return $count;
    }

    /**
     * Best-effort: insert a user_notifications row (type='status_change')
     * for the given applicant's user_account_id.
     *
     * Phase 45 (v2.1.1) — used by cancelExpired() and autoProgress()
     * so users are notified when the SYSTEM changes their application
     * status (admin-triggered changes go through
     * AdminController::changeStatus() which has its own notify logic).
     *
     * Skips silently when:
     *   - the AutoCancelService has no Connection injected (e.g. unit
     *     tests that don't pass a real DB),
     *   - the applicant has no user_account_id (admin-only cases),
     *   - the INSERT fails (DB error) — the status change itself has
     *     already succeeded and we don't want to roll it back.
     *
     * @param int              $applicantId  Applicants row id.
     * @param ApplicantStatus  $newStatus    The new status (for the title).
     * @param string           $description  The status_log description
     *                                       (used as the notification body).
     */
    private function notifyStatusChange(int $applicantId, ApplicantStatus $newStatus, string $description): void
    {
        if ($this->conn === null) {
            return;
        }
        try {
            $userAccountId = $this->conn->scalar(
                'SELECT user_account_id FROM applicants WHERE id = ?',
                [$applicantId]
            );
            if ($userAccountId === null || (int)$userAccountId <= 0) {
                return;
            }
            $this->conn->execute(
                'INSERT INTO user_notifications (user_account_id, applicant_id, type, title, body, is_read, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, 0, NOW())',
                [
                    (int)$userAccountId,
                    $applicantId,
                    'status_change',
                    'تغییر وضعیت پرونده',
                    sprintf('وضعیت پروندهٔ شما به «%s» تغییر یافت. %s', $newStatus->titleFa(), $description),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('AutoCancel: failed to insert status-change notification', [
                '_module'        => 'core',
                'applicant_id'   => $applicantId,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
