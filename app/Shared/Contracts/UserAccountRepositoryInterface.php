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

    /**
     * Look up an account by username (case-sensitive — usernames are
     * normalised to lower-case by ProfileUpdateValidator, so callers
     * should pass an already-lowercased value).
     *
     * @return array<string,mixed>|null
     */
    public function findByUsername(string $username): ?array;

    public function findByPersonId(int $personId): ?array;

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int;

    /**
     * Update a subset of the safe-to-edit profile fields on the account.
     *
     * The $fields array is a map of column-name => value. Only the
     * following columns are accepted (whitelist-enforced in the PDO
     * implementation):
     *   - username     (string|null)
     *   - mobile       (string)
     *   - email        (string|null)  — Phase 13 (v1.4.0), migration 008
     *   - display_name (string|null)   — Phase 13 (v1.4.0), migration 008
     *
     * Phase 13 (v1.4.0) extended the whitelist from `['username', 'mobile']`
     * to include `email` and `display_name`, paying D-12-1 (those fields
     * were validated by `ProfileUpdateValidator` since Phase 12 but
     * silently dropped because the `user_accounts` table had no such
     * columns).
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): void;

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
