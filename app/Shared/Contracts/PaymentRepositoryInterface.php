<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Core\Domain\Payment;

/**
 * Repository contract for Payment entities.
 */
interface PaymentRepositoryInterface
{
    public function findById(int $id): ?Payment;

    /**
     * @return array<int,Payment>
     */
    public function findByApplicantId(int $applicantId): array;

    public function findLatestByApplicant(int $applicantId, string $purpose = 'هزینهٔ ثبت‌نام'): ?Payment;

    public function hasApproved(int $applicantId, string $purpose = 'هزینهٔ ثبت‌نام'): bool;

    /**
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public function paginateWithFilters(array $filters = [], int $page = 1, int $pageSize = 20): array;

    /**
     * @return array<string,int> {approved_total, approved_month, pending_count, pending_sum, rejected_count}
     */
    public function stats(): array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function sumByChannel(): array;

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int;

    public function review(int $id, string $status, int $reviewerId, ?string $rejectReason = null): void;
}
