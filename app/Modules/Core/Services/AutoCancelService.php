<?php
declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\StatusLogRepositoryInterface;
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
 */
final class AutoCancelService
{
    public function __construct(
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly StatusLogRepositoryInterface $statusLog,
        private readonly StructuredLogger $logger
    ) {}

    /**
     * Auto-cancel expired applications. Returns the count of cancelled.
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
}
