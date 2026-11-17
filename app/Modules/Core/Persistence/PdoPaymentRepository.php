<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Modules\Core\Domain\Payment;
use App\Shared\Contracts\PaymentRepositoryInterface;

final class PdoPaymentRepository extends PdoRepository implements PaymentRepositoryInterface
{
    public function findById(int $id): ?Payment
    {
        $row = $this->fetchOne('SELECT * FROM payments WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : Payment::fromArray($row);
    }

    public function findByApplicantId(int $applicantId): array
    {
        $rows = $this->fetchAll(
            'SELECT * FROM payments WHERE applicant_id = ? ORDER BY created_at DESC',
            [$applicantId]
        );
        return array_map(static fn (array $r): Payment => Payment::fromArray($r), $rows);
    }

    public function findLatestByApplicant(int $applicantId, string $purpose = 'هزینهٔ ثبت‌نام'): ?Payment
    {
        $row = $this->fetchOne(
            'SELECT * FROM payments WHERE applicant_id = ? AND purpose = ? ORDER BY created_at DESC LIMIT 1',
            [$applicantId, $purpose]
        );
        return $row === null ? null : Payment::fromArray($row);
    }

    public function hasApproved(int $applicantId, string $purpose = 'هزینهٔ ثبت‌نام'): bool
    {
        $count = (int)$this->fetchScalar(
            'SELECT COUNT(*) FROM payments WHERE applicant_id = ? AND purpose = ? AND status = ?',
            [$applicantId, $purpose, 'تأیید شده']
        );
        return $count > 0;
    }

    public function paginateWithFilters(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['applicant_id'])) {
            $where[] = 'p.applicant_id = ?';
            $params[] = (int)$filters['applicant_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['purpose'])) {
            $where[] = 'p.purpose = ?';
            $params[] = $filters['purpose'];
        }
        if (!empty($filters['from_date'])) {
            $where[] = 'p.created_at >= ?';
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $where[] = 'p.created_at <= ?';
            $params[] = $filters['to_date'];
        }

        $offset = max(0, ($page - 1) * $pageSize);
        $whereSql = implode(' AND ', $where);
        $sql = "SELECT SQL_CALC_FOUND_ROWS p.*, a.tracking_code, a.first_name, a.last_name
                FROM payments p
                LEFT JOIN applicants a ON a.id = p.applicant_id
                WHERE $whereSql
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?";
        $params[] = $pageSize;
        $params[] = $offset;
        $rows = $this->fetchAll($sql, $params);
        $total = (int)$this->fetchScalar('SELECT FOUND_ROWS()');
        return ['rows' => $rows, 'total' => $total];
    }

    public function stats(): array
    {
        $approvedTotal = (int)$this->fetchScalar("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'تأیید شده'");
        $approvedMonth = (int)$this->fetchScalar(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE status = 'تأیید شده' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $pendingCount = (int)$this->fetchScalar("SELECT COUNT(*) FROM payments WHERE status IN ('در انتظار رسید','در انتظار بررسی')");
        $pendingSum = (int)$this->fetchScalar("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status IN ('در انتظار رسید','در انتظار بررسی')");
        $rejectedCount = (int)$this->fetchScalar("SELECT COUNT(*) FROM payments WHERE status = 'رد شده'");

        return [
            'approved_total'  => $approvedTotal,
            'approved_month'  => $approvedMonth,
            'pending_count'   => $pendingCount,
            'pending_sum'     => $pendingSum,
            'rejected_count'  => $rejectedCount,
        ];
    }

    public function sumByChannel(): array
    {
        return $this->fetchAll(
            "SELECT pc.title AS channel_title, COUNT(p.id) AS count, COALESCE(SUM(p.amount), 0) AS total
             FROM payments p
             LEFT JOIN payment_channels pc ON pc.id = p.payment_channel_id
             WHERE p.status = 'تأیید شده'
             GROUP BY pc.id, pc.title
             ORDER BY total DESC"
        );
    }

    public function create(array $data): int
    {
        return (int)$this->insert(
            'INSERT INTO payments
                (applicant_id, user_account_id, payment_channel_id, purpose, amount,
                 original_amount, final_amount, discount_reason, fee_group_id,
                 is_rural_exempt, fallback_used, suspicion_flags, reference_code,
                 paid_at, receipt_document_id, linked_request_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['applicant_id'],
                $data['user_account_id'] ?? null,
                $data['payment_channel_id'] ?? null,
                $data['purpose'] ?? 'هزینهٔ ثبت‌نام',
                $data['amount'] ?? 0,
                $data['original_amount'] ?? null,
                $data['final_amount'] ?? null,
                $data['discount_reason'] ?? null,
                $data['fee_group_id'] ?? null,
                $data['is_rural_exempt'] ?? 0,
                $data['fallback_used'] ?? 0,
                $data['suspicion_flags'] ?? null,
                $data['reference_code'] ?? null,
                $data['paid_at'] ?? null,
                $data['receipt_document_id'] ?? null,
                $data['linked_request_id'] ?? null,
                $data['status'] ?? 'در انتظار رسید',
            ]
        );
    }

    public function review(int $id, string $status, int $reviewerId, ?string $rejectReason = null): void
    {
        $this->execute(
            'UPDATE payments SET status = ?, reviewed_by = ?, reviewed_at = NOW(), reject_reason = ? WHERE id = ?',
            [$status, $reviewerId, $rejectReason, $id]
        );
    }
}
