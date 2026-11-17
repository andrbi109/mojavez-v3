<?php
declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\StatusLogRepositoryInterface;
use App\Support\Config;
use App\Support\StructuredLogger;

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
     */
    public function updatePriorities(): int
    {
        $days = (int)Config::get('app.priority_days', 12);
        // Find applicants who:
        //  - have registration_completed_at older than $days days
        //  - are in a pre-issue status
        //  - do NOT yet have priority=1
        $eligible = $this->applicants->paginate([
            // Filter by status is done in SQL via current_status IN pre_issue
            // We approximate by paginating and filtering in PHP.
        ], 1, 1000);
        $count = 0;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        foreach ($eligible['rows'] as $row) {
            $completedAt = $row['registration_completed_at'] ?? null;
            $priority = (int)($row['priority'] ?? 0);
            $statusSlug = (string)$row['current_status'];
            $status = ApplicantStatus::fromSlug($statusSlug);
            if ($status === null || !$status->isPreIssue()) {
                continue;
            }
            if ($priority === 1) {
                continue;  // already prioritised
            }
            if ($completedAt === null || $completedAt > $cutoff) {
                continue;  // not old enough
            }
            $this->applicants->updatePriority((int)$row['id'], true);
            $count++;
        }
        return $count;
    }
}
