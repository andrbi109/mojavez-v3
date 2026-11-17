<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Repository contract for the `user_notifications` table (Phase 9).
 *
 * Backs the user-panel notifications surface — list + unread count + bulk
 * mark-as-read. Inspired by v2's UserPanelController::notifications() and
 * markAllRead() but written against v3's PdoRepository base (renamed
 * fetchAll/fetchOne helpers — see docs/ISSUES.md ISSUE-001).
 *
 * Notifications are addressed by user_account_id (the storefront account, not
 * the admin user_id), so they survive applicants being re-linked.
 */
interface UserNotificationRepositoryInterface
{
    /**
     * Newest-first list of notifications for an account, optionally limited.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByUser(int $accountId, ?int $limit = null): array;

    /**
     * Count of unread notifications for an account (used in the panel badge).
     */
    public function unreadCount(int $accountId): int;

    /**
     * Mark every unread notification for an account as read.
     * Returns the number of rows affected.
     */
    public function markAllRead(int $accountId): int;
}
