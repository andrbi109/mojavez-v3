<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Core\Domain\Applicant;
use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Modules\Core\Domain\Enums\LicenseState;

/**
 * Repository contract for Applicant entities.
 *
 * Other modules (NationalGateway via ACL, Api via scope-based auth) read
 * applicants through this interface, never through the concrete model.
 *
 * See docs/SCHEMA_OWNERSHIP.md §3 — cross-module access goes through
 * Repository interfaces, not direct Database::getInstance() calls.
 *
 * The PdoApplicantRepository implementation lives in
 * app/Modules/Core/Persistence/.
 */
interface ApplicantRepositoryInterface
{
    public function findByTrackingCode(string $trackingCode): ?Applicant;

    public function findById(int $id): ?Applicant;

    /**
     * Lookup by national code. Used by the golden-link orchestrator (Phase 4)
     * and the /api/v1/applicants/by-national/{code} API endpoint.
     */
    public function findByNationalCode(string $nationalCode): array;

    /**
     * Find applicants in pre-issue statuses (subject to 14-day auto-cancel).
     *
     * @return array<int,Applicant>
     */
    public function findExpiredApplications(int $days): array;

    /**
     * Find applicants in auto-sync-eligible statuses (IssueRequest,
     * DocUploadToOrg, InitialApproval, FinalApproval). Used by the
     * golden-link orchestrator.
     *
     * @return array<int,Applicant>
     */
    public function findAutoSyncEligible(): array;

    /**
     * Persist a new applicant. Returns the new ID.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int;

    /**
     * Update an existing applicant's mutable fields.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): void;

    /**
     * Change the applicant's current_status. Inserts a row in status_log
     * with the given triggered_by value.
     */
    public function changeStatus(
        int $id,
        ApplicantStatus $newStatus,
        string $triggeredBy = 'admin',
        ?string $description = null
    ): void;

    /**
     * Update the license_state + license_valid_until fields.
     * Used by the golden-link orchestrator on AutoConfirmed matches.
     */
    public function updateLicenseState(
        int $id,
        LicenseState $state,
        ?string $validUntil = null
    ): void;

    /**
     * Set the golden-link anchor fields (national_license_request_number,
     * matched_at, match_confidence). Idempotent — once set, only an
     * explicit operator action may change it.
     */
    public function markMatchedToNational(
        int $applicantId,
        string $nationalRequestNumber,
        int $confidence,
        string $matchedBy = 'auto_sync'
    ): void;

    /**
     * Filtered + paginated list of applicants (admin panel).
     *
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 20): array;

    /**
     * Update the priority flag (used by AutoCancelService).
     */
    public function updatePriority(int $id, bool $priority): void;

    /**
     * Soft-delete (set deleted_at = NOW()). Never hard-delete.
     */
    public function softDelete(int $id): void;

    /**
     * Bump the version column for optimistic concurrency (ACL §23.2).
     * Returns the new version number.
     */
    public function bumpVersion(int $id): int;
}
