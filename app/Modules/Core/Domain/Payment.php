<?php
declare(strict_types=1);

namespace App\Modules\Core\Domain;

/**
 * Payment — a payment record for an applicant.
 *
 * Inherited from v2's `payments` table. Amount is stored as BIGINT UNSIGNED
 * (Rial, no decimals). Has a circular FK with `license_access_requests`
 * (license_access_requests.payment_id → payments, payments.linked_request_id
 * → license_access_requests, both ON DELETE SET NULL).
 *
 * Storage: `payments` table.
 */
final readonly class Payment
{
    public function __construct(
        public int $id,
        public int $applicantId,
        public string $purpose,                  // 'هزینهٔ ثبت‌نام'|'هزینهٔ دریافت مجدد تصویر مجوز'
        public int $amount,                       // Rial
        public ?int $originalAmount = null,
        public ?int $finalAmount = null,
        public ?string $discountReason = null,
        public ?int $feeGroupId = null,
        public bool $isRuralExempt = false,
        public bool $fallbackUsed = false,
        public ?string $suspicionFlags = null,
        public ?string $referenceCode = null,
        public ?string $paidAt = null,
        public ?int $receiptDocumentId = null,
        public ?int $linkedRequestId = null,
        public string $status = 'در انتظار رسید',  // 'در انتظار رسید'|'در انتظار بررسی'|'تأیید شده'|'رد شده'
        public ?int $reviewedBy = null,
        public ?string $reviewedAt = null,
        public ?string $rejectReason = null,
        public ?int $userAccountId = null,
        public ?int $paymentChannelId = null,
        public string $createdAt = '',
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (int)($row['id'] ?? 0),
            applicantId: (int)($row['applicant_id'] ?? 0),
            purpose: (string)($row['purpose'] ?? 'هزینهٔ ثبت‌نام'),
            amount: (int)($row['amount'] ?? 0),
            originalAmount: isset($row['original_amount']) ? (int)$row['original_amount'] : null,
            finalAmount: isset($row['final_amount']) ? (int)$row['final_amount'] : null,
            discountReason: isset($row['discount_reason']) ? (string)$row['discount_reason'] : null,
            feeGroupId: isset($row['fee_group_id']) ? (int)$row['fee_group_id'] : null,
            isRuralExempt: (bool)($row['is_rural_exempt'] ?? false),
            fallbackUsed: (bool)($row['fallback_used'] ?? false),
            suspicionFlags: isset($row['suspicion_flags']) ? (string)$row['suspicion_flags'] : null,
            referenceCode: isset($row['reference_code']) ? (string)$row['reference_code'] : null,
            paidAt: isset($row['paid_at']) ? (string)$row['paid_at'] : null,
            receiptDocumentId: isset($row['receipt_document_id']) ? (int)$row['receipt_document_id'] : null,
            linkedRequestId: isset($row['linked_request_id']) ? (int)$row['linked_request_id'] : null,
            status: (string)($row['status'] ?? 'در انتظار رسید'),
            reviewedBy: isset($row['reviewed_by']) ? (int)$row['reviewed_by'] : null,
            reviewedAt: isset($row['reviewed_at']) ? (string)$row['reviewed_at'] : null,
            rejectReason: isset($row['reject_reason']) ? (string)$row['reject_reason'] : null,
            userAccountId: isset($row['user_account_id']) ? (int)$row['user_account_id'] : null,
            paymentChannelId: isset($row['payment_channel_id']) ? (int)$row['payment_channel_id'] : null,
            createdAt: (string)($row['created_at'] ?? ''),
        );
    }

    public function isApproved(): bool
    {
        return $this->status === 'تأیید شده';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['در انتظار رسید', 'در انتظار بررسی'], true);
    }

    public function isRejected(): bool
    {
        return $this->status === 'رد شده';
    }

    /**
     * Amount formatted as Rial with thousand separators (Persian digits).
     */
    public function amountFormatted(): string
    {
        return number_format($this->amount, 0, '.', ',');
    }
}
