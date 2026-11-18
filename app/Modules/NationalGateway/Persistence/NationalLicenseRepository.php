<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Persistence;

use App\Modules\NationalGateway\Domain\License;
use App\Modules\NationalGateway\Domain\LicenseCollection;
use App\Modules\NationalGateway\Domain\Enums\LicenseStatus;
use App\Shared\Contracts\NationalLicenseRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\StructuredLogger;

/**
 * PDO implementation of NationalLicenseRepositoryInterface.
 *
 * Persists upstream national licenses to the `national_licenses` table.
 * Each license row carries:
 *   - the basic search-API fields (request_number, applicant_name,
 *     license_title, organization_title, province_title, township_title,
 *     responded_at, status_*)
 *   - the golden-link anchor fields scraped from /track/{rn}
 *     (masked_national_code, father_name, business_address,
 *     postal_code_masked, isic_*, issue_type, expires_at,
 *     supervisory_authorities)
 *   - the golden-link match result (matched_applicant_id, match_confidence,
 *     matched_by) — populated by the NationalSyncOrchestrator
 *   - the filter_hash for cache invalidation + is_saved export tracking.
 *
 * Per docs/SCHEMA_OWNERSHIP.md §3 — only NationalGateway writes here;
 * Core reads via the interface (never directly).
 */
final class NationalLicenseRepository implements NationalLicenseRepositoryInterface
{
    public function __construct(
        private readonly Connection $conn,
        private readonly StructuredLogger $logger
    ) {}

    public function save(License $license, ?array $rawRow = null, ?array $filterContext = null): void
    {
        $filterHash = $filterContext['filter_hash'] ?? null;
        $rn = $license->requestNumber;

        // INSERT ... ON DUPLICATE KEY UPDATE — MariaDB-safe (uses request_number UNIQUE).
        // Per docs/00-MASTER.md §20.5 MariaDB trap: INSERT ... ON DUPLICATE KEY
        // with UNIQUE may error on some MariaDB versions, but for a single
        // UNIQUE column (request_number) it's safe.
        $sql = <<<'SQL'
        INSERT INTO national_licenses (
            request_number, applicant_name, user_image, license_title,
            organization_title, province_title, township_title, responded_at,
            status_slug, status_id, status_title,
            masked_national_code, father_name, business_address, postal_code_masked,
            isic_code, isic_title, issue_type, expires_at, supervisory_authorities,
            filter_hash, last_seen_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, NOW()
        )
        ON DUPLICATE KEY UPDATE
            applicant_name = VALUES(applicant_name),
            user_image = COALESCE(VALUES(user_image), user_image),
            license_title = VALUES(license_title),
            organization_title = VALUES(organization_title),
            province_title = VALUES(province_title),
            township_title = VALUES(township_title),
            responded_at = VALUES(responded_at),
            status_slug = VALUES(status_slug),
            status_id = VALUES(status_id),
            status_title = VALUES(status_title),
            masked_national_code = COALESCE(VALUES(masked_national_code), masked_national_code),
            father_name = COALESCE(VALUES(father_name), father_name),
            business_address = COALESCE(VALUES(business_address), business_address),
            postal_code_masked = COALESCE(VALUES(postal_code_masked), postal_code_masked),
            isic_code = COALESCE(VALUES(isic_code), isic_code),
            isic_title = COALESCE(VALUES(isic_title), isic_title),
            issue_type = COALESCE(VALUES(issue_type), issue_type),
            expires_at = COALESCE(VALUES(expires_at), expires_at),
            supervisory_authorities = COALESCE(VALUES(supervisory_authorities), supervisory_authorities),
            filter_hash = COALESCE(VALUES(filter_hash), filter_hash),
            last_seen_at = NOW()
        SQL;
        $this->conn->execute($sql, [
            $rn,
            $license->applicantName,
            $license->userImage,
            $license->licenseTitle,
            $license->organizationTitle,
            $license->provinceTitle,
            $license->townshipTitle,
            $license->respondedAt,
            $license->status->value,
            $license->statusId,
            $license->statusTitle,
            $license->maskedNationalCode,
            $license->fatherName,
            $license->businessAddress,
            $license->postalCodeMasked,
            $license->isicCode,
            $license->isicTitle,
            $license->issueType,
            $license->expiresAt,
            $license->supervisoryAuthorities,
            $filterHash,
        ]);
    }

    public function saveMany(array $licenses, ?array $rawRows = null, ?array $filterContext = null): int
    {
        $count = 0;
        foreach ($licenses as $license) {
            $this->save($license, null, $filterContext);
            $count++;
        }
        return $count;
    }

    public function findByRequestNumber(string $requestNumber): ?License
    {
        $row = $this->conn->one(
            'SELECT * FROM national_licenses WHERE request_number = ?',
            [$requestNumber]
        );
        if ($row === null) {
            return null;
        }
        return $this->hydrate($row);
    }

    public function queryCached(array $filter, int $page = 1, int $pageSize = 20): array
    {
        $where = $this->buildWhereClause($filter);
        $offset = max(0, ($page - 1) * $pageSize);

        $sql = "SELECT * FROM national_licenses WHERE {$where['sql']} ORDER BY responded_at DESC LIMIT ? OFFSET ?";
        $params = array_merge($where['params'], [$pageSize, $offset]);
        $rows = $this->conn->all($sql, $params);

        $total = $this->countCached($filter);

        return ['rows' => $rows, 'total' => $total];
    }

    public function countAll(): int
    {
        return (int)$this->conn->scalar('SELECT COUNT(*) FROM national_licenses');
    }

    public function countCached(array $filter): int
    {
        $where = $this->buildWhereClause($filter);
        return (int)$this->conn->scalar(
            "SELECT COUNT(*) FROM national_licenses WHERE {$where['sql']}",
            $where['params']
        );
    }

    public function countByStatus(): array
    {
        $rows = $this->conn->all(
            'SELECT status_slug, COUNT(*) AS c FROM national_licenses GROUP BY status_slug'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['status_slug']] = (int)$row['c'];
        }
        return $out;
    }

    public function lastSeenAt(): ?string
    {
        $v = $this->conn->scalar('SELECT MAX(last_seen_at) FROM national_licenses');
        return $v === null || $v === false ? null : (string)$v;
    }

    public function topProvinces(int $limit = 10): array
    {
        return $this->conn->all(
            'SELECT province_title AS province, COUNT(*) AS c FROM national_licenses '
            . 'WHERE province_title != "" GROUP BY province_title ORDER BY c DESC LIMIT ?',
            [$limit]
        );
    }

    public function topOrganizations(int $limit = 10): array
    {
        return $this->conn->all(
            'SELECT organization_title AS organization, COUNT(*) AS c FROM national_licenses '
            . 'WHERE organization_title != "" GROUP BY organization_title ORDER BY c DESC LIMIT ?',
            [$limit]
        );
    }

    public function countByMonth(int $limit = 12): array
    {
        return $this->conn->all(
            'SELECT DATE_FORMAT(responded_at, "%Y-%m") AS month, COUNT(*) AS c '
            . 'FROM national_licenses WHERE responded_at IS NOT NULL '
            . 'GROUP BY month ORDER BY month DESC LIMIT ?',
            [$limit]
        );
    }

    public function markSaved(string $requestNumber): bool
    {
        $n = $this->conn->execute(
            'UPDATE national_licenses SET is_saved = 1, saved_at = NOW() WHERE request_number = ?',
            [$requestNumber]
        );
        return $n > 0;
    }

    public function markUnsaved(string $requestNumber): bool
    {
        $n = $this->conn->execute(
            'UPDATE national_licenses SET is_saved = 0, saved_at = NULL WHERE request_number = ?',
            [$requestNumber]
        );
        return $n > 0;
    }

    public function markSavedByFilter(array $filter): int
    {
        $where = $this->buildWhereClause($filter);
        return $this->conn->execute(
            "UPDATE national_licenses SET is_saved = 1, saved_at = NOW() WHERE {$where['sql']}",
            $where['params']
        );
    }

    public function countSaved(): int
    {
        return (int)$this->conn->scalar('SELECT COUNT(*) FROM national_licenses WHERE is_saved = 1');
    }

    public function findSavedRequestNumbers(array $requestNumbers): array
    {
        if ($requestNumbers === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($requestNumbers), '?'));
        $rows = $this->conn->all(
            "SELECT request_number FROM national_licenses WHERE is_saved = 1 AND request_number IN ($placeholders)",
            $requestNumbers
        );
        return array_map(static fn (array $r): string => (string)$r['request_number'], $rows);
    }

    /**
     * Set the golden-link match result on a license row.
     * Called by NationalSyncOrchestrator after a successful match.
     */
    public function setMatch(
        string $requestNumber,
        int $applicantId,
        int $confidence,
        string $matchedBy
    ): void {
        $this->conn->execute(
            'UPDATE national_licenses SET matched_applicant_id = ?, '
            . 'match_confidence = ?, matched_by = ? WHERE request_number = ?',
            [$applicantId, $confidence, $matchedBy, $requestNumber]
        );
    }

    /**
     * Find licenses that haven't been matched to an applicant yet.
     * Used by NationalSyncOrchestrator::runCycle() to find candidates.
     *
     * @return list<License>
     */
    public function findUnmatched(int $limit = 100): array
    {
        $rows = $this->conn->all(
            'SELECT * FROM national_licenses WHERE matched_applicant_id IS NULL '
            . 'AND masked_national_code IS NOT NULL '
            . 'ORDER BY last_seen_at DESC LIMIT ?',
            [$limit]
        );
        return array_map(fn (array $r): License => $this->hydrate($r), $rows);
    }

    /**
     * @return array{sql:string,params:array<int,mixed>}
     */
    private function buildWhereClause(array $filter): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filter['license_id'])) {
            $clauses[] = 'request_number = ?';
            $params[] = $filter['license_id'];
        }
        if (!empty($filter['name'])) {
            $clauses[] = 'applicant_name LIKE ?';
            $params[] = '%' . $this->conn->escapeLike($filter['name']) . '%';
        }
        if (!empty($filter['province_title']) || !empty($filter['province'])) {
            $p = $filter['province_title'] ?? $filter['province'];
            $clauses[] = 'province_title = ?';
            $params[] = $p;
        }
        if (!empty($filter['township_title']) || !empty($filter['township'])) {
            $t = $filter['township_title'] ?? $filter['township'];
            $clauses[] = 'township_title = ?';
            $params[] = $t;
        }
        if (!empty($filter['organization_title']) || !empty($filter['main_org_code'])) {
            $o = $filter['organization_title'] ?? $filter['main_org_code'];
            $clauses[] = 'organization_title = ?';
            $params[] = $o;
        }
        if (!empty($filter['status'])) {
            $clauses[] = 'status_slug = ?';
            $params[] = $filter['status'];
        }
        $sql = $clauses === [] ? '1=1' : implode(' AND ', $clauses);
        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): License
    {
        $statusSlug = (string)($row['status_slug'] ?? '');
        return new License(
            requestNumber: (string)$row['request_number'],
            applicantName: (string)($row['applicant_name'] ?? ''),
            userImage: isset($row['user_image']) && $row['user_image'] !== '' ? (string)$row['user_image'] : null,
            licenseTitle: (string)($row['license_title'] ?? ''),
            organizationTitle: (string)($row['organization_title'] ?? ''),
            provinceTitle: (string)($row['province_title'] ?? ''),
            townshipTitle: (string)($row['township_title'] ?? ''),
            respondedAt: (string)($row['responded_at'] ?? ''),
            status: LicenseStatus::fromUpstream($statusSlug !== '' ? $statusSlug : null, null),
            statusId: isset($row['status_id']) ? (string)$row['status_id'] : null,
            statusTitle: isset($row['status_title']) ? (string)$row['status_title'] : null,
            maskedNationalCode: isset($row['masked_national_code']) && $row['masked_national_code'] !== '' ? (string)$row['masked_national_code'] : null,
            fatherName: isset($row['father_name']) && $row['father_name'] !== '' ? (string)$row['father_name'] : null,
            businessAddress: isset($row['business_address']) && $row['business_address'] !== '' ? (string)$row['business_address'] : null,
            postalCodeMasked: isset($row['postal_code_masked']) && $row['postal_code_masked'] !== '' ? (string)$row['postal_code_masked'] : null,
            isicCode: isset($row['isic_code']) && $row['isic_code'] !== '' ? (string)$row['isic_code'] : null,
            isicTitle: isset($row['isic_title']) && $row['isic_title'] !== '' ? (string)$row['isic_title'] : null,
            issueType: isset($row['issue_type']) && $row['issue_type'] !== '' ? (string)$row['issue_type'] : null,
            expiresAt: isset($row['expires_at']) && $row['expires_at'] !== '' ? (string)$row['expires_at'] : null,
            supervisoryAuthorities: isset($row['supervisory_authorities']) && $row['supervisory_authorities'] !== '' ? (string)$row['supervisory_authorities'] : null,
            matchedApplicantId: isset($row['matched_applicant_id']) ? (int)$row['matched_applicant_id'] : null,
            matchConfidence: isset($row['match_confidence']) ? (int)$row['match_confidence'] : null,
            matchedBy: isset($row['matched_by']) && $row['matched_by'] !== '' ? (string)$row['matched_by'] : null,
        );
    }
}
