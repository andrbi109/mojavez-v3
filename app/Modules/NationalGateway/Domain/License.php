<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Domain;

use App\Modules\NationalGateway\Domain\Enums\LicenseStatus;

/**
 * License entity — represents one row from qr.mojavez.ir /api/v1/licenses/search.
 *
 * Readonly: once constructed, fields cannot be mutated. To "update", construct a new instance.
 */
final readonly class License
{
    public function __construct(
        public string $requestNumber,
        public string $applicantName,
        public ?string $userImage,
        public string $licenseTitle,
        public string $organizationTitle,
        public string $provinceTitle,
        public string $townshipTitle,
        public string $respondedAt,
        public LicenseStatus $status,
        public ?string $statusId = null,
        public ?string $statusTitle = null,
        // Golden-link anchor fields — populated by TrackScraper (from the
        // /track/{rn} page RSC payload) and persisted in national_licenses.
        // See docs/00-MASTER.md §9.1: masked values are confirmation anchors
        // for the PatternMatcher.
        public ?string $maskedNationalCode = null,
        public ?string $fatherName = null,
        public ?string $businessAddress = null,
        public ?string $postalCodeMasked = null,
        public ?string $isicCode = null,
        public ?string $isicTitle = null,
        public ?string $issueType = null,
        public ?string $expiresAt = null,
        public ?string $supervisoryAuthorities = null,
        public ?int $matchedApplicantId = null,
        public ?int $matchConfidence = null,
        public ?string $matchedBy = null,
    ) {}

    /**
     * Build from upstream API row (as decoded from JSON).
     *
     * @param array<string,mixed> $row
     */
    public static function fromUpstream(array $row): self
    {
        $status = $row['status'] ?? [];
        return new self(
            requestNumber: (string)($row['request_number'] ?? ''),
            applicantName: (string)($row['applicant_name'] ?? ''),
            userImage: isset($row['user_image']) && is_string($row['user_image']) && $row['user_image'] !== ''
                ? $row['user_image'] : null,
            licenseTitle: (string)($row['license_title'] ?? ''),
            organizationTitle: (string)($row['organization_title'] ?? ''),
            provinceTitle: (string)($row['province_title'] ?? ''),
            townshipTitle: (string)($row['township_title'] ?? ''),
            respondedAt: (string)($row['responded_at'] ?? ''),
            status: LicenseStatus::fromUpstream(
                isset($status['status_slug']) ? (string)$status['status_slug'] : null,
                isset($status['status_id']) ? (string)$status['status_id'] : null
            ),
            statusId: isset($status['status_id']) ? (string)$status['status_id'] : null,
            statusTitle: isset($status['status_title']) ? (string)$status['status_title'] : null,
        );
    }

    /**
     * Build from a toArray() snapshot (e.g. restored from cache) or a DB
     * row (from NationalLicenseRepository::queryCached / findByRequestNumber).
     *
     * Phase 14 (v1.5.0) — pays D-4-6: `fromArray` previously only populated
     * the basic fields (request_number, applicant_name, license_title, etc.)
     * and silently dropped the golden-link anchors (`masked_national_code`,
     * `father_name`). When `LicenseService::hydrateLicense()` called
     * `fromArray` on a DB row, the resulting License had `null` anchors
     * even though the DB row had them — so `searchLocal()` results always
     * lost the anchors (only `findByRequestNumber`, which uses the
     * repository's private `hydrate()`, kept them).
     *
     * Now `fromArray` reads `masked_national_code` and `father_name` from
     * the input array with null-safe defaults — `null` if missing. This
     * keeps it backward-compatible with the cache-restoration path (which
     * calls `toArray()` first; that snapshot doesn't include the anchors,
     * so they default to `null` there, same as before). The DB-row path
     * (`hydrateLicense`) now passes the anchors through correctly.
     *
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $status = $row['status'] ?? [];
        $statusSlug = is_array($status) ? (string)($status['slug'] ?? $status['value'] ?? '') : (string)$status;
        $statusId = is_array($status) ? (string)($status['id'] ?? '') : null;
        $statusTitle = is_array($status) ? (string)($status['title'] ?? '') : null;

        return new self(
            requestNumber: (string)($row['request_number'] ?? ''),
            applicantName: (string)($row['applicant_name'] ?? ''),
            userImage: isset($row['user_image']) && is_string($row['user_image']) && $row['user_image'] !== ''
                ? $row['user_image'] : null,
            licenseTitle: (string)($row['license_title'] ?? ''),
            organizationTitle: (string)($row['organization_title'] ?? ''),
            provinceTitle: (string)($row['province_title'] ?? ''),
            townshipTitle: (string)($row['township_title'] ?? ''),
            respondedAt: (string)($row['responded_at'] ?? ''),
            status: LicenseStatus::fromUpstream($statusSlug !== '' ? $statusSlug : null, $statusId),
            statusId: $statusId,
            statusTitle: $statusTitle,
            // Golden-link anchors (Phase 14 / D-4-6). Read with null-safe
            // defaults so missing keys (e.g. cache-restored snapshots that
            // don't include them) default to null. Empty strings are
            // normalized to null — the DB stores NULL but some code paths
            // may pass ''.
            maskedNationalCode: self::nullableString($row['masked_national_code'] ?? null),
            fatherName: self::nullableString($row['father_name'] ?? null),
        );
    }

    /**
     * Build from a TrackScraper result array (the scraped /track/{rn} page).
     *
     * The track page exposes license_title, issuing_authority (organization),
     * business_province/township, issue_date (responded_at), tracking_code
     * (= the request number) and the status object — but NOT the applicant
     * name or photo (the public verification page hides them), so those are
     * left empty/null. Used by the batch processor, which looks licenses up by
     * number via the track page because the search API does not filter by
     * license_id.
     *
     * @param array<string,mixed> $t
     */
    public static function fromTrackPage(array $t): self
    {
        $statusArr = isset($t['status']) && is_array($t['status']) ? $t['status'] : [];
        $slug = isset($statusArr['status_slug']) ? (string)$statusArr['status_slug'] : null;
        $id = isset($statusArr['status_id']) ? (string)$statusArr['status_id'] : null;
        $title = isset($statusArr['status_title']) ? (string)$statusArr['status_title'] : null;

        return new self(
            requestNumber: (string)($t['tracking_code'] ?? $t['request_number'] ?? ''),
            applicantName: '',
            userImage: null,
            licenseTitle: (string)($t['license_title'] ?? ''),
            organizationTitle: (string)($t['issuing_authority'] ?? $t['organization_title'] ?? ''),
            provinceTitle: (string)($t['business_province'] ?? ''),
            townshipTitle: (string)($t['business_township'] ?? ''),
            respondedAt: (string)($t['issue_date'] ?? $t['responded_at'] ?? ''),
            status: LicenseStatus::fromUpstream($slug, $id),
            statusId: $id,
            statusTitle: $title,
            // Golden-link anchors from the RSC payload.
            maskedNationalCode: self::nullableString($t['national_id'] ?? null),
            fatherName: self::nullableString($t['father_name'] ?? null),
            businessAddress: self::nullableString($t['business_address'] ?? null),
            postalCodeMasked: self::nullableString($t['postal_code'] ?? null),
            isicCode: self::nullableString($t['isic_code'] ?? null),
            isicTitle: self::nullableString($t['isic_title'] ?? null),
            issueType: self::nullableString($t['issuance_type'] ?? $t['issue_type'] ?? null),
            expiresAt: self::nullableString($t['expires_at'] ?? null),
            supervisoryAuthorities: self::nullableString($t['supervisory_authorities'] ?? null),
        );
    }

    /**
     * Normalize a value to a nullable string ('' → null, else trimmed).
     */
    private static function nullableString(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    /**
     * Convert to associative array for JSON serialization.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'request_number' => $this->requestNumber,
            'applicant_name' => $this->applicantName,
            'user_image' => $this->userImage,
            'license_title' => $this->licenseTitle,
            'organization_title' => $this->organizationTitle,
            'province_title' => $this->provinceTitle,
            'township_title' => $this->townshipTitle,
            'responded_at' => $this->respondedAt,
            'status' => [
                'id' => $this->statusId,
                'title' => $this->statusTitle,
                'slug' => $this->status->value,
                'label' => $this->status->titleFa(),
            ],
        ];
    }

    /**
     * Avatar URL on upstream — full URL or null.
     */
    public function avatarUrl(?string $upstreamBase = null): ?string
    {
        if ($this->userImage === null) return null;
        if (str_starts_with($this->userImage, 'http')) return $this->userImage;
        return $upstreamBase !== null ? rtrim($upstreamBase, '/') . '/' . ltrim($this->userImage, '/') : null;
    }

    /**
     * Initials from applicant_name (for fallback avatar).
     */
    public function initials(): string
    {
        $name = trim($this->applicantName);
        if ($name === '') return '?';
        $parts = preg_split('/\s+/u', $name) ?: [];
        if (count($parts) >= 2) {
            return mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1);
        }
        return mb_substr($name, 0, 1);
    }
}
