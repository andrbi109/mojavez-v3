<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Repository contract for UserAccount entities (applicant storefront accounts).
 *
 * Two realms: applicants use this; admins use UserRepositoryInterface.
 *
 * Includes the account-lockout recordFailedLogin() that locks for 5 minutes
 * after 5 failed attempts (inherited from v2).
 */
interface UserAccountRepositoryInterface
{
    public function findById(int $id): ?array;

    public function findByMobile(string $mobile): ?array;

    public function findByPersonId(int $personId): ?array;

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int;

    public function verifyPassword(int $accountId, string $password): bool;

    public function updatePassword(int $accountId, string $newPassword): void;

    public function updateLastLogin(int $accountId): void;

    /**
     * Increment failed_login_count; lock for 5 minutes after 5 failures.
     */
    public function recordFailedLogin(int $accountId): void;

    public function isLocked(int $accountId): bool;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getApplications(int $accountId): array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getPayments(int $accountId): array;

    public function linkApplication(int $accountId, int $applicantId): void;

    public function linkPayment(int $accountId, int $paymentId): void;
}
