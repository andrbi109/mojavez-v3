<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Shared\Contracts\UserNotificationRepositoryInterface;

/**
 * PDO implementation of UserNotificationRepositoryInterface.
 *
 * Phase 9: backs the user-panel notifications surface. Uses the renamed
 * fetchAll/fetchOne/execute helpers from PdoRepository (see ISSUE-001).
 */
final class PdoUserNotificationRepository extends PdoRepository implements UserNotificationRepositoryInterface
{
    public function findByUser(int $accountId, ?int $limit = null): array
    {
        $sql = 'SELECT id, user_account_id, applicant_id, type, title, body, is_read, created_at
                FROM user_notifications
                WHERE user_account_id = ?
                ORDER BY created_at DESC';
        $params = [$accountId];
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        return $this->fetchAll($sql, $params);
    }

    public function unreadCount(int $accountId): int
    {
        return (int)$this->fetchScalar(
            'SELECT COUNT(*) FROM user_notifications WHERE user_account_id = ? AND is_read = 0',
            [$accountId]
        );
    }

    public function markAllRead(int $accountId): int
    {
        return $this->execute(
            'UPDATE user_notifications SET is_read = 1 WHERE user_account_id = ? AND is_read = 0',
            [$accountId]
        );
    }
}
