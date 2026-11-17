<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Shared\Contracts\UserAccountRepositoryInterface;

final class PdoUserAccountRepository extends PdoRepository implements UserAccountRepositoryInterface
{
    /**
     * Whitelist of columns that are user-editable on the user_accounts
     * profile form. Phase 12 added username+mobile only (the only
     * user-editable columns on the table at the time); Phase 13 (v1.4.0,
     * migration 008) added email+display_name. Kept as a constant so the
     * schema-side whitelist (migration 008) and this repository stay
     * in sync.
     */
    private const WHITELIST = ['username', 'mobile', 'email', 'display_name'];

    public function findById(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM user_accounts WHERE id = ? LIMIT 1', [$id]);
    }

    public function findByMobile(string $mobile): ?array
    {
        return $this->fetchOne('SELECT * FROM user_accounts WHERE mobile = ? LIMIT 1', [$mobile]);
    }

    public function findByUsername(string $username): ?array
    {
        return $this->fetchOne('SELECT * FROM user_accounts WHERE username = ? LIMIT 1', [$username]);
    }

    public function findByPersonId(int $personId): ?array
    {
        return $this->fetchOne('SELECT * FROM user_accounts WHERE person_id = ? LIMIT 1', [$personId]);
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

    /**
     * Update a subset of profile fields on the account. Whitelist-
     * enforced: only the columns listed in {@see WHITELIST} are
     * accepted (the user-editable columns on `user_accounts`).
     * Any other keys in $fields are silently dropped.
     *
     * Builds the SET clause dynamically from the keys present, with
     * positional `?` placeholders. The build is deterministic: the
     * whitelist order is fixed, so the generated SQL is identical for
     * the same input (helps statement cache hits on MariaDB).
     *
     * Phase 13 (v1.4.0): the whitelist was extended from
     * `['username', 'mobile']` to include `email` and `display_name`
     * (migration 008 added the columns; pays D-12-1).
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        // Filter to whitelisted keys that are actually present.
        $updates = [];
        $params = [];
        foreach (self::WHITELIST as $col) {
            if (array_key_exists($col, $fields)) {
                $updates[] = "{$col} = ?";
                $params[] = $fields[$col];
            }
        }
        if ($updates === []) {
            // Nothing to update — short-circuit (avoid sending an
            // `UPDATE ... SET  WHERE id = ?` SQL syntax error).
            return;
        }
        $params[] = $id;
        $sql = 'UPDATE user_accounts SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $this->execute($sql, $params);
    }

    public function verifyPassword(int $accountId, string $password): bool
    {
        $row = $this->fetchOne('SELECT password FROM user_accounts WHERE id = ? LIMIT 1', [$accountId]);
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
        $row = $this->fetchOne(
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
        return $this->fetchAll(
            'SELECT * FROM applicants WHERE user_account_id = ? AND deleted_at IS NULL ORDER BY created_at DESC',
            [$accountId]
        );
    }

    public function getPayments(int $accountId): array
    {
        return $this->fetchAll(
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
