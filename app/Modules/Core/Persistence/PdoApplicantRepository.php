<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Modules\Core\Domain\Applicant;
use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Modules\Core\Domain\Enums\LicenseState;
use App\Shared\Contracts\ApplicantRepositoryInterface;

/**
 * PDO implementation of ApplicantRepositoryInterface.
 *
 * Memoized singleton via the Container (one instance per request, shares
 * the Connection singleton).
 *
 * All queries use real prepared statements (EMULATE_PREPARES=false). For
 * repeated named params (e.g. WHERE x = :v OR y = :v), we use distinct
 * names (:v, :v2) — fix inherited from v2's `Setting::set` workaround
 * for MariaDB's named-param limitation.
 */
final class PdoApplicantRepository extends PdoRepository implements ApplicantRepositoryInterface
{
    public function findByTrackingCode(string $trackingCode): ?Applicant
    {
        $row = $this->fetchOne(
            'SELECT * FROM applicants WHERE tracking_code = ? AND deleted_at IS NULL LIMIT 1',
            [$trackingCode]
        );
        return $row === null ? null : Applicant::fromArray($row);
    }

    public function findById(int $id): ?Applicant
    {
        $row = $this->fetchOne(
            'SELECT * FROM applicants WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$id]
        );
        return $row === null ? null : Applicant::fromArray($row);
    }

    public function findByNationalCode(string $nationalCode): array
    {
        $rows = $this->fetchAll(
            'SELECT * FROM applicants WHERE national_code = ? AND deleted_at IS NULL ORDER BY created_at DESC',
            [$nationalCode]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = Applicant::fromArray($row);
        }
        return $out;
    }

    public function findExpiredApplications(int $days): array
    {
        // Applicants in pre-issue statuses whose IssueRequest was more than
        // $days ago. The cutoff is taken from status_log (when they entered
        // 'ثبت درخواست صدور').
        $sql = <<<SQL
        SELECT a.* FROM applicants a
        INNER JOIN (
            SELECT applicant_id, MAX(created_at) AS issue_at
            FROM status_log
            WHERE status_name = ?
            GROUP BY applicant_id
        ) s ON s.applicant_id = a.id
        WHERE a.deleted_at IS NULL
          AND a.current_status IN ('ثبت درخواست صدور','بارگذاری مدارک به سازمان','درخواست اصلاح مدارک','تأیید اولیه')
          AND s.issue_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY s.issue_at ASC
        SQL;
        $rows = $this->fetchAll($sql, ['ثبت درخواست صدور', $days]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = Applicant::fromArray($row);
        }
        return $out;
    }

    public function findAutoSyncEligible(): array
    {
        // Applicants in pre-issue statuses that may be auto-transitioned
        // by the golden-link orchestrator and have NOT yet been matched.
        $sql = <<<SQL
        SELECT * FROM applicants
        WHERE deleted_at IS NULL
          AND national_license_request_number IS NULL
          AND current_status IN ('ثبت درخواست صدور','بارگذاری مدارک به سازمان','تأیید اولیه','تأیید نهایی')
        ORDER BY updated_at ASC
        SQL;
        $rows = $this->fetchAll($sql);
        $out = [];
        foreach ($rows as $row) {
            $out[] = Applicant::fromArray($row);
        }
        return $out;
    }

    public function create(array $data): int
    {
        $fields = [
            'person_id', 'parent_application_id', 'service_type_id', 'user_account_id',
            'first_name', 'last_name', 'national_code', 'postal_code', 'mobile',
            'tracking_code', 'birth_date', 'father_name', 'current_status',
            'applicant_type', 'marital_status', 'children_count', 'physical_condition',
            'disability_details', 'income_decile', 'has_court_case', 'has_active_loan',
            'has_overdue_installments', 'priority', 'payment_required', 'payment_requested_at',
            'license_state', 'license_valid_until', 'registration_started_at',
            'registration_completed_at',
        ];
        $cols = [];
        $placeholders = [];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $cols[] = $f;
                $placeholders[] = '?';
                $params[] = $data[$f];
            }
        }
        if ($cols === []) {
            throw new \InvalidArgumentException('No fields provided for applicant create');
        }
        $sql = 'INSERT INTO applicants (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        return (int)$this->insert($sql, $params);
    }

    public function update(int $id, array $data): void
    {
        $updatable = [
            'first_name', 'last_name', 'postal_code', 'mobile', 'birth_date',
            'father_name', 'marital_status', 'children_count', 'physical_condition',
            'disability_details', 'income_decile', 'has_court_case', 'has_active_loan',
            'has_overdue_installments', 'priority', 'service_type_id',
            'parent_application_id', 'user_account_id', 'consent_external_share',
            'payment_required', 'payment_requested_at', 'registration_started_at',
            'registration_completed_at',
        ];
        $sets = [];
        $params = [];
        foreach ($updatable as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = NOW()';
        $params[] = $id;
        $sql = 'UPDATE applicants SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->execute($sql, $params);
    }

    public function changeStatus(
        int $id,
        ApplicantStatus $newStatus,
        string $triggeredBy = 'admin',
        ?string $description = null
    ): void {
        $this->transactional(function () use ($id, $newStatus, $triggeredBy, $description): void {
            $this->execute(
                'UPDATE applicants SET current_status = ?, updated_at = NOW() WHERE id = ?',
                [$newStatus->value, $id]
            );
            $this->insert(
                'INSERT INTO status_log (applicant_id, status_name, description, triggered_by) VALUES (?, ?, ?, ?)',
                [$id, $newStatus->value, $description, $triggeredBy]
            );
        });
    }

    public function updateLicenseState(int $id, LicenseState $state, ?string $validUntil = null): void
    {
        $this->execute(
            'UPDATE applicants SET license_state = ?, license_valid_until = ?, updated_at = NOW() WHERE id = ?',
            [$state->value, $validUntil, $id]
        );
    }

    public function markMatchedToNational(
        int $applicantId,
        string $nationalRequestNumber,
        int $confidence,
        string $matchedBy = 'auto_sync'
    ): void {
        // Idempotent: only set if not already matched. The UNIQUE constraint
        // on national_license_request_number guarantees one applicant per
        // national license.
        $this->execute(
            'UPDATE applicants
             SET national_license_request_number = ?,
                 matched_at = NOW(),
                 match_confidence = ?,
                 updated_at = NOW()
             WHERE id = ? AND national_license_request_number IS NULL',
            [$nationalRequestNumber, $confidence, $applicantId]
        );
    }

    public function paginate(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        [$where, $params] = $this->buildFilter($filters);
        $offset = max(0, ($page - 1) * $pageSize);
        $sql = "SELECT SQL_CALC_FOUND_ROWS a.* FROM applicants a WHERE $where AND a.deleted_at IS NULL ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $pageSize;
        $params[] = $offset;
        $rows = $this->fetchAll($sql, $params);
        $total = (int)$this->fetchScalar('SELECT FOUND_ROWS()');
        return ['rows' => $rows, 'total' => $total];
    }

    public function updatePriority(int $id, bool $priority): void
    {
        $this->execute(
            'UPDATE applicants SET priority = ?, updated_at = NOW() WHERE id = ?',
            [(int)$priority, $id]
        );
    }

    /**
     * {@inheritdoc}
     *
     * Phase 13 (v1.4.0): pays D-2-5. Single SQL UPDATE that mirrors the
     * previous PHP-loop semantics in AutoCancelService::updatePriorities.
     *
     * The pre-issue status list is mirrored from
     * ApplicantStatus::isPreIssue(). Kept as a SQL literal (not as a
     * sub-call to isPreIssue) because MariaDB can't peek into PHP. If
     * the enum changes, this list must be kept in sync — same coupling
     * as findExpiredApplications/findAutoSyncEligible which hard-code
     * their own status lists.
     */
    public function bulkSetPriorityForPreIssueOlderThan(int $days): int
    {
        // The pre-issue statuses (mirror of ApplicantStatus::isPreIssue()
        // — 10 statuses). If the enum changes, update this list too.
        $preIssueStatuses = [
            'پیش‌نویس',                       // Draft
            'تکمیل اطلاعات',                  // InfoComplete
            'احراز نشانی',                    // AddressVerification
            'بررسی کارشناسی رشته',            // FieldExpertReview
            'ثبت درخواست صدور',               // IssueRequest
            'بارگذاری مدارک به سازمان',       // DocUploadToOrg
            'درخواست اصلاح مدارک',            // DocCorrectionRequest
            'تأیید اولیه',                    // InitialApproval
            'تأیید نهایی',                    // FinalApproval
            'تطبیق آدرس با ثبت احوال',         // AddressSabtAhvalMatch
        ];
        $placeholders = implode(', ', array_fill(0, count($preIssueStatuses), '?'));
        $sql = "UPDATE applicants
                   SET priority = 1, updated_at = NOW()
                 WHERE deleted_at IS NULL
                   AND priority = 0
                   AND registration_completed_at IS NOT NULL
                   AND registration_completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND current_status IN ({$placeholders})";
        $params = array_merge([$days], $preIssueStatuses);
        return $this->execute($sql, $params);
    }

    public function softDelete(int $id): void
    {
        $this->execute(
            'UPDATE applicants SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    public function bumpVersion(int $id): int
    {
        // Atomically increment version and return the new value.
        $this->execute(
            'UPDATE applicants SET version = version + 1 WHERE id = ?',
            [$id]
        );
        $new = $this->fetchScalar('SELECT version FROM applicants WHERE id = ?', [$id]);
        return (int)$new;
    }

    /**
     * Build a WHERE clause + params array from filter inputs.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string, 1:array<int,mixed>}
     */
    private function buildFilter(array $filters): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['tracking_code'])) {
            $where[] = 'a.tracking_code = ?';
            $params[] = $filters['tracking_code'];
        }
        if (!empty($filters['national_code'])) {
            $where[] = 'a.national_code = ?';
            $params[] = $filters['national_code'];
        }
        if (!empty($filters['current_status'])) {
            $where[] = 'a.current_status = ?';
            $params[] = $filters['current_status'];
        }
        if (!empty($filters['license_state'])) {
            $where[] = 'a.license_state = ?';
            $params[] = $filters['license_state'];
        }
        if (!empty($filters['service_type_id'])) {
            $where[] = 'a.service_type_id = ?';
            $params[] = (int)$filters['service_type_id'];
        }
        if (!empty($filters['mobile'])) {
            $where[] = 'a.mobile = ?';
            $params[] = $filters['mobile'];
        }
        // NEW in v3: filter for golden-link unmatched applicants.
        if (!empty($filters['unmatched_only'])) {
            $where[] = 'a.national_license_request_number IS NULL';
        }
        return [implode(' AND ', $where), $params];
    }
}
