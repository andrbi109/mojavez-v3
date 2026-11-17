<?php
declare(strict_types=1);

namespace App\Modules\Core\Domain;

use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Modules\Core\Domain\Enums\LicenseState;
use App\Modules\Core\Domain\Enums\PersonType;
use App\Shared\Support\Normalizer;

/**
 * Applicant — the heart of the local v3 system (the case file).
 *
 * Inherited from v2's `applicants` table, extended for v3 with:
 *  - readonly immutable Value Object.
 *  - national_license_request_number (NEW, nullable, UNIQUE) — the golden-link
 *    anchor that stores the upstream national gateway's request number once
 *    a match is auto-confirmed.
 *  - matched_at, match_confidence (NEW) — when and with what confidence the
 *    golden-link orchestrator matched this applicant to a national license.
 *  - version (NEW, optimistic concurrency) — for ACL conflict resolution.
 *  - deleted_at (NEW, soft-delete) — never hard-delete an applicant.
 *  - consent_external_share (NEW) — applicant's consent to share PII with
 *    external systems (default false; needed for /api/v1/applicants/* with
 *    scope read:applicants:full — see integration-contract.md §3.4).
 *
 * Storage: `applicants` table.
 */
final readonly class Applicant
{
    public function __construct(
        public int $id,
        public ?int $personId,
        public string $trackingCode,                       // HG-XXXXXXXX (CSPRNG)
        public string $nationalCode,                       // CHAR(10), validated
        public ApplicantStatus $currentStatus,             // one of 18 statuses
        public LicenseState $licenseState,                // جاری|صادر شده|باطل شده|منقضی
        public ?string $licenseValidUntil,                // DATE (Gregorian in DB, Persian in UI)
        public ?int $serviceTypeId,
        public ?int $parentApplicationId,                  // multi-license (self-ref)
        public bool $priority,
        public ?string $registrationStartedAt,
        public ?string $registrationCompletedAt,
        public string $firstName,
        public string $lastName,
        public string $mobile,
        public string $createdAt,
        public string $updatedAt,
        // NEW in v3 — golden-link anchor + optimistic concurrency + soft-delete:
        public ?string $nationalLicenseRequestNumber = null,
        public ?int $matchConfidence = null,
        public ?string $matchedAt = null,
        public int $version = 1,
        public ?string $deletedAt = null,
        public bool $consentExternalShare = false,
        public ?int $userAccountId = null,
        public bool $paymentRequired = false,
        public ?string $paymentRequestedAt = null,
    ) {}

    /**
     * Build an Applicant from a raw DB row (FETCH_ASSOC).
     *
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $statusSlug = (string)($row['current_status'] ?? 'پیش‌نویس');
        $licenseStateSlug = (string)($row['license_state'] ?? 'جاری');

        return new self(
            id: (int)($row['id'] ?? 0),
            personId: isset($row['person_id']) ? (int)$row['person_id'] : null,
            trackingCode: (string)($row['tracking_code'] ?? ''),
            nationalCode: (string)($row['national_code'] ?? ''),
            currentStatus: ApplicantStatus::fromSlug($statusSlug) ?? ApplicantStatus::Draft,
            licenseState: LicenseState::fromSlug($licenseStateSlug) ?? LicenseState::Current,
            licenseValidUntil: isset($row['license_valid_until']) ? (string)$row['license_valid_until'] : null,
            serviceTypeId: isset($row['service_type_id']) ? (int)$row['service_type_id'] : null,
            parentApplicationId: isset($row['parent_application_id']) ? (int)$row['parent_application_id'] : null,
            priority: (bool)($row['priority'] ?? false),
            registrationStartedAt: isset($row['registration_started_at']) ? (string)$row['registration_started_at'] : null,
            registrationCompletedAt: isset($row['registration_completed_at']) ? (string)$row['registration_completed_at'] : null,
            firstName: (string)($row['first_name'] ?? ''),
            lastName: (string)($row['last_name'] ?? ''),
            mobile: (string)($row['mobile'] ?? ''),
            createdAt: (string)($row['created_at'] ?? ''),
            updatedAt: (string)($row['updated_at'] ?? ''),
            nationalLicenseRequestNumber: isset($row['national_license_request_number']) ? (string)$row['national_license_request_number'] : null,
            matchConfidence: isset($row['match_confidence']) ? (int)$row['match_confidence'] : null,
            matchedAt: isset($row['matched_at']) ? (string)$row['matched_at'] : null,
            version: (int)($row['version'] ?? 1),
            deletedAt: isset($row['deleted_at']) ? (string)$row['deleted_at'] : null,
            consentExternalShare: (bool)($row['consent_external_share'] ?? false),
            userAccountId: isset($row['user_account_id']) ? (int)$row['user_account_id'] : null,
            paymentRequired: (bool)($row['payment_required'] ?? false),
            paymentRequestedAt: isset($row['payment_requested_at']) ? (string)$row['payment_requested_at'] : null,
        );
    }

    /**
     * Full name for display.
     */
    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /**
     * Whether the case is in a terminal state (closed).
     */
    public function isTerminal(): bool
    {
        return $this->currentStatus->isTerminal();
    }

    /**
     * Whether this applicant has an issued license (license_state=Issued +
     * terminal status). Used by the public tracking page to display the
     * license section.
     */
    public function isIssuedLicense(): bool
    {
        return $this->licenseState === LicenseState::Issued
            && $this->currentStatus->isTerminal();
    }

    /**
     * Whether this applicant has been matched to a national license
     * (golden-link anchor set).
     */
    public function isMatchedToNational(): bool
    {
        return $this->nationalLicenseRequestNumber !== null
            && $this->matchedAt !== null;
    }

    /**
     * Whether this applicant is soft-deleted.
     */
    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * Build a masked national-code pattern for this applicant.
     * Used by PatternMatcher in the golden-link orchestrator.
     */
    public function nationalCodeMaskPattern(): string
    {
        return Normalizer::nationalCodeMask($this->nationalCode);
    }

    /**
     * Whether this applicant is in a pre-issue status (subject to 14-day
     * auto-cancel from "ثبت درخواست صدور").
     */
    public function isPreIssue(): bool
    {
        return $this->currentStatus->isPreIssue();
    }

    /**
     * Whether the auto_sync role may transition this applicant (i.e. they
     * are in a pre-issue status that allows golden-link auto-transition).
     */
    public function isAutoSyncEligible(): bool
    {
        return !$this->isMatchedToNational()
            && $this->currentStatus->isAutoSyncTransitionableFrom();
    }

    /**
     * Check if the license is currently valid (issued + valid_until >= today).
     */
    public function hasValidLicense(): bool
    {
        if ($this->licenseState !== LicenseState::Issued) {
            return false;
        }
        if ($this->licenseValidUntil === null) {
            return true;  // no expiry = permanent
        }
        return strtotime($this->licenseValidUntil) >= strtotime(date('Y-m-d'));
    }
}
