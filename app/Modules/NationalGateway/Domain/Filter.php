<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Domain;

use App\Shared\Support\PersianDate;

/**
 * Immutable filter value object.
 *
 * Mirrors qr.mojavez.ir /api/v1/licenses/search payload structure:
 *   {
 *     "license_id": null, "name": "",
 *     "issue_start_date": "", "issue_end_date": "",
 *     "last_op_start_date": "", "last_op_end_date": "",
 *     "province_id": null, "township_id": null,
 *     "main_org_code": null, "sub_org_code": null,
 *     "page": "1"
 *   }
 *
 * IMPORTANT — date format:
 *   The user supplies Jalali dates (e.g. "1400/01/01") in the filter form.
 *   The upstream API and the local DB both store `responded_at` as a GREGORIAN
 *   datetime ("2026-11-11 22:32:22", verified from HAR). So:
 *     - toUpstreamPayload(): converts Jalali → Gregorian ("1400/01/01" → "2021-03-21")
 *       before sending to upstream. If conversion fails, the date is sent empty
 *       (upstream treats empty as "no date filter").
 *     - toLocalFilterArray(): keeps the ORIGINAL Jalali string; the LicenseRepository
 *       is responsible for converting it to Gregorian when building the WHERE clause
 *       (so it can compare against the Gregorian responded_at column).
 */
final readonly class Filter
{
    public function __construct(
        public ?string $licenseId = null,
        public ?string $name = null,
        public ?string $issueStartDate = null,
        public ?string $issueEndDate = null,
        public ?string $lastOpStartDate = null,
        public ?string $lastOpEndDate = null,
        public ?int $provinceId = null,
        public ?int $townshipId = null,
        public ?string $mainOrgCode = null,
        public ?string $subOrgCode = null,
        public int $page = 1,
        public int $pageSize = 20,
        /** Label for display in admin UI */
        public ?string $label = null,
        /** Optional saved filter ID */
        public ?int $savedId = null,
    ) {}

    /**
     * Build from user input (HTTP request body or query string).
     * All inputs are validated and normalized.
     *
     * @param array<string,mixed> $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            licenseId: self::strOrNull($input['license_id'] ?? null),
            name: self::strOrNull($input['name'] ?? null),
            issueStartDate: self::strOrNull($input['issue_start_date'] ?? null),
            issueEndDate: self::strOrNull($input['issue_end_date'] ?? null),
            lastOpStartDate: self::strOrNull($input['last_op_start_date'] ?? null),
            lastOpEndDate: self::strOrNull($input['last_op_end_date'] ?? null),
            provinceId: self::intOrNull($input['province_id'] ?? null),
            townshipId: self::intOrNull($input['township_id'] ?? null),
            mainOrgCode: self::strOrNull($input['main_org_code'] ?? null),
            subOrgCode: self::strOrNull($input['sub_org_code'] ?? null),
            page: max(1, self::intOrNull($input['page'] ?? null) ?? 1),
            pageSize: self::clamp(self::intOrNull($input['page_size'] ?? null) ?? 20, 1, 100),
            label: self::strOrNull($input['label'] ?? null),
            savedId: self::intOrNull($input['saved_filter_id'] ?? null),
        );
    }

    private static function strOrNull(mixed $v): ?string
    {
        if (!is_string($v) || trim($v) === '') return null;
        return trim($v);
    }

    private static function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_int($v)) return $v;
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) return (int)$v;
        return null;
    }

    private static function clamp(int $v, int $min, int $max): int
    {
        return max($min, min($max, $v));
    }

    /**
     * Build the JSON payload to send to upstream API.
     * Format matches HAR exactly (note: page is a STRING).
     *
     * Date conversion: the user enters Jalali dates (e.g. "1400/01/01"), but
     * upstream stores/responds with Gregorian datetimes ("2026-11-11 ...").
     * We convert Jalali → Gregorian ("1400/01/01" → "2021-03-21") here.
     * If conversion fails (malformed date), we send "" (no date filter) rather
     * than the raw Jalali string — sending a Jalali date to upstream makes it
     * match nothing (the bug this fixes: combined filters with dates returned 0).
     */
    public function toUpstreamPayload(): array
    {
        $persian = new PersianDate();
        return [
            'license_id' => $this->licenseId,
            'name' => $this->name ?? '',
            'issue_start_date' => $this->toGregorianOrEmpty($persian, $this->issueStartDate),
            'issue_end_date' => $this->toGregorianOrEmpty($persian, $this->issueEndDate),
            'last_op_start_date' => $this->lastOpStartDate ?? '',
            'last_op_end_date' => $this->lastOpEndDate ?? '',
            'province_id' => $this->provinceId,
            'township_id' => $this->townshipId,
            'main_org_code' => $this->mainOrgCode,
            'sub_org_code' => $this->subOrgCode,
            'page' => (string)$this->page,
        ];
    }

    /**
     * Convert a Jalali date string to Gregorian ("1400/01/01" → "2021-03-21").
     * Returns "" (empty) if the input is null/empty or the conversion fails —
     * upstream treats empty as "no date filter", which is safer than sending
     * a Jalali string upstream can't parse.
     */
    private function toGregorianOrEmpty(PersianDate $persian, ?string $jalali): string
    {
        if ($jalali === null || $jalali === '') {
            return '';
        }
        $gregorian = $persian->jalaliStringToGregorian($jalali);
        return $gregorian ?? '';
    }

    /**
     * Stable hash for cache key (excludes page/pageSize/label/savedId).
     */
    public function cacheKey(): string
    {
        $parts = [
            $this->licenseId ?? '',
            $this->name ?? '',
            $this->issueStartDate ?? '',
            $this->issueEndDate ?? '',
            $this->lastOpStartDate ?? '',
            $this->lastOpEndDate ?? '',
            $this->provinceId ?? '',
            $this->townshipId ?? '',
            $this->mainOrgCode ?? '',
            $this->subOrgCode ?? '',
        ];
        return hash('sha256', implode('|', $parts));
    }

    /**
     * True if no filter criteria are set (empty search would hit upstream
     * with all-null fields, which is wasteful — caller should short-circuit).
     */
    public function isEmpty(): bool
    {
        return $this->licenseId === null
            && $this->name === null
            && $this->issueStartDate === null
            && $this->issueEndDate === null
            && $this->lastOpStartDate === null
            && $this->lastOpEndDate === null
            && $this->provinceId === null
            && $this->townshipId === null
            && $this->mainOrgCode === null
            && $this->subOrgCode === null;
    }

    /**
     * Short human-readable description of the filter (for logs/admin).
     */
    public function describe(): string
    {
        $bits = [];
        if ($this->mainOrgCode) $bits[] = "org={$this->mainOrgCode}";
        if ($this->subOrgCode) $bits[] = "sub={$this->subOrgCode}";
        if ($this->provinceId) $bits[] = "prov={$this->provinceId}";
        if ($this->townshipId) $bits[] = "twn={$this->townshipId}";
        if ($this->issueStartDate) $bits[] = "from={$this->issueStartDate}";
        if ($this->issueEndDate) $bits[] = "to={$this->issueEndDate}";
        if ($this->name) $bits[] = "name=" . mb_substr($this->name, 0, 20);
        if ($this->licenseId) $bits[] = "id={$this->licenseId}";
        return $bits === [] ? 'all' : implode(',', $bits);
    }

    /**
     * Create a new filter with page modified.
     */
    public function withPage(int $page): self
    {
        return new self(
            licenseId: $this->licenseId,
            name: $this->name,
            issueStartDate: $this->issueStartDate,
            issueEndDate: $this->issueEndDate,
            lastOpStartDate: $this->lastOpStartDate,
            lastOpEndDate: $this->lastOpEndDate,
            provinceId: $this->provinceId,
            townshipId: $this->townshipId,
            mainOrgCode: $this->mainOrgCode,
            subOrgCode: $this->subOrgCode,
            page: $page,
            pageSize: $this->pageSize,
            label: $this->label,
            savedId: $this->savedId,
        );
    }

    /**
     * Create a new filter with page size modified (used by saveAllResults
     * to walk upstream pages with a larger page size for fewer round-trips).
     */
    public function withPageSize(int $pageSize): self
    {
        return new self(
            licenseId: $this->licenseId,
            name: $this->name,
            issueStartDate: $this->issueStartDate,
            issueEndDate: $this->issueEndDate,
            lastOpStartDate: $this->lastOpStartDate,
            lastOpEndDate: $this->lastOpEndDate,
            provinceId: $this->provinceId,
            townshipId: $this->townshipId,
            mainOrgCode: $this->mainOrgCode,
            subOrgCode: $this->subOrgCode,
            page: $this->page,
            pageSize: $pageSize,
            label: $this->label,
            savedId: $this->savedId,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'license_id' => $this->licenseId,
            'name' => $this->name,
            'issue_start_date' => $this->issueStartDate,
            'issue_end_date' => $this->issueEndDate,
            'last_op_start_date' => $this->lastOpStartDate,
            'last_op_end_date' => $this->lastOpEndDate,
            'province_id' => $this->provinceId,
            'township_id' => $this->townshipId,
            'main_org_code' => $this->mainOrgCode,
            'sub_org_code' => $this->subOrgCode,
            'page' => $this->page,
            'page_size' => $this->pageSize,
            'label' => $this->label,
            'saved_filter_id' => $this->savedId,
            'description' => $this->describe(),
        ];
    }

    /**
     * Build the filter array used to query the LOCAL cache (LicenseRepository::queryCached).
     *
     * This is a subset of toArray() that the repository's WHERE clauses
     * actually understand. It excludes page/page_size (handled separately)
     * and metadata like label/savedId.
     *
     * IMPORTANT — license_id IS included here. The previous version dropped it
     * (with a comment saying "the repository does not filter by it"), which
     * broke local searches by license_id: after a search-and-cache, re-querying
     * the local cache with the same license_id filter returned 0 results
     * because the WHERE clause never included it. The repository now supports
     * filtering by request_number (= license_id), so we pass it through.
     *
     * Note: status is NOT included because the upstream Filter doesn't carry it
     * (status is applied client-side / via ExportService). The repository DOES
     * support status_slug filtering when the filter array contains a 'status' key
     * (set by GET /api/licenses, not by the upstream Filter).
     *
     * Dates are kept as-is (Jalali strings). The repository converts them to
     * Gregorian before comparing against the responded_at column.
     *
     * @return array<string,mixed>
     */
    public function toLocalFilterArray(): array
    {
        return array_filter([
            'license_id' => $this->licenseId,
            'name' => $this->name,
            'province_id' => $this->provinceId,
            'township_id' => $this->townshipId,
            'main_org_code' => $this->mainOrgCode,
            'sub_org_code' => $this->subOrgCode,
            'issue_start_date' => $this->issueStartDate,
            'issue_end_date' => $this->issueEndDate,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
