<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Shared\Contracts\UserAccountRepositoryInterface;

final class PdoUserAccountRepository extends PdoRepository implements UserAccountRepositoryInterface
{
    public function findById(int $id): ?array
    {
        return $this->one('SELECT * FROM user_accounts WHERE id = ? LIMIT 1', [$id]);
    }

    public function findByMobile(string $mobile): ?array
    {
        return $this->one('SELECT * FROM user_accounts WHERE mobile = ? LIMIT 1', [$mobile]);
    }

    public function findByPersonId(int $personId): ?array
    {
        return $this->one('SELECT * FROM user_accounts WHERE person_id = ? LIMIT 1', [$personId]);
    }

    public function create(array $data): int
    {
        return (int)$this->insert(
            'INSERT INTO user_accounts (person_id, username, mobile, password, is_active) VALUES (?, ?, ?, ?, ?)',
            [
                $data['person_id'] ?? null,
                $data['username'] ?? null,
                $data['mobile'],
                $data['password'],
                $data['is_active'] ?? 1,
            ]
        );
    }

    public function verifyPassword(int $accountId, string $password): bool
    {
        $row = $this->one('SELECT password FROM user_accounts WHERE id = ? LIMIT 1', [$accountId]);
        if ($row === null) {
            // Run a dummy verify to keep timing uniform.
            password_verify($password, password_hash('dummy', PASSWORD_DEFAULT));
            return false;
        }
        return password_verify($password, $row['password']);
    }

    public function updatePassword(int $accountId, string $newPassword): void
    {
        $this->execute(
            'UPDATE user_accounts SET password = ? WHERE id = ?',
            [$newPassword, $accountId]
        );
    }

    public function updateLastLogin(int $accountId): void
    {
        $this->execute(
            'UPDATE user_accounts SET last_login_at = NOW(), failed_login_count = 0 WHERE id = ?',
            [$accountId]
        );
    }

    public function recordFailedLogin(int $accountId): void
    {
        // Increment failed_login_count; lock for 5 minutes if this attempt
        // brings the count to 5 or more (matching v2's pattern).
        $this->execute(
            'UPDATE user_accounts
             SET failed_login_count = failed_login_count + 1,
                 locked_until = CASE
                     WHEN failed_login_count + 1 >= 5 THEN DATE_ADD(NOW(), INTERVAL 5 MINUTE)
                     ELSE locked_until
                 END
             WHERE id = ?',
            [$accountId]
        );
    }

    public function isLocked(int $accountId): bool
    {
        $row = $this->one(
            'SELECT locked_until FROM user_accounts WHERE id = ? LIMIT 1',
            [$accountId]
        );
        if ($row === null || $row['locked_until'] === null) {
            return false;
        }
        return strtotime($row['locked_until']) > time();
    }

    public function getApplications(int $accountId): array
    {
        return $this->all(
            'SELECT * FROM applicants WHERE user_account_id = ? AND deleted_at IS NULL ORDER BY created_at DESC',
            [$accountId]
        );
    }

    public function getPayments(int $accountId): array
    {
        return $this->all(
            'SELECT * FROM payments WHERE user_account_id = ? ORDER BY created_at DESC',
            [$accountId]
        );
    }

    public function linkApplication(int $accountId, int $applicantId): void
    {
        $this->execute(
            'UPDATE applicants SET user_account_id = ? WHERE id = ?',
            [$accountId, $applicantId]
        );
    }

    public function linkPayment(int $accountId, int $paymentId): void
    {
        $this->execute(
            'UPDATE payments SET user_account_id = ? WHERE id = ?',
            [$accountId, $paymentId]
        );
    }
}
