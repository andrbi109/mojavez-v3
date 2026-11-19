<?php
declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\PersonRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\StructuredLogger;

/**
 * NotificationService — sends SMS + in-app notifications.
 *
 * Per docs/00-MASTER.md §9.5 step 5 — the NotificationSubscriber calls this
 * when an ApplicantMatched event fires (AutoConfirmed only).
 *
 * Two delivery channels:
 *   1. SMS — via the sms_templates + user_sms_entries tables. In production,
 *      this would call a real SMS gateway (Kavenegar/Farapayamak). In dev,
 *      the SMS is just persisted to user_sms_entries with status='در انتظار'
 *      (no real send) so the admin panel can preview what would be sent.
 *   2. In-app notification — via the user_notifications table. The applicant
 *      sees a banner on next login.
 *
 * Both channels are idempotent: if a notification with the same
 * (applicant_id, type) already exists, no duplicate is created.
 *
 * Schema notes (v3 inherits from v2):
 *   - user_sms_entries uses sms_template_id (FK) + sms_text + status (Persian).
 *     No mobile/body columns — we look up the template by target_status and
 *     store the rendered text in sms_text.
 *   - user_notifications has no reference_id column; we dedupe on
 *     (applicant_id, type) alone.
 *   - sms_templates uses title + sample_text + target_status (no key_name).
 */
final class NotificationService
{
    public function __construct(
        private readonly ApplicantRepositoryInterface $applicantRepo,
        private readonly PersonRepositoryInterface $personRepo,
        private readonly Connection $conn,
        private readonly StructuredLogger $logger
    ) {}

    /**
     * Notify an applicant that their license has been issued on the national
     * gateway (the golden-link match succeeded).
     *
     * @param int $applicantId  The matched applicant.
     * @param string $nationalRequestNumber  The upstream license request number.
     */
    public function notifyLicenseIssued(int $applicantId, string $nationalRequestNumber): void
    {
        $applicant = $this->applicantRepo->findById($applicantId);
        if ($applicant === null) {
            $this->logger->warning('notifyLicenseIssued: applicant not found', ['applicant_id' => $applicantId]);
            return;
        }

        // Build the SMS body (the template's sample_text with substitutions).
        $templateId = $this->findTemplateId('صدور مجوز');
        $body = "کاربر گرامی، مجوز کسب‌وکار شما در درگاه ملی صادر شد. کد رهگیری ملی: {$nationalRequestNumber}. کد رهگیری داخلی: {$applicant->trackingCode}";

        // 1. SMS — persist to user_sms_entries (real send deferred to Phase 6).
        if ($templateId !== null) {
            $this->queueSms($templateId, $body, $applicantId);
        }

        // 2. In-app notification — persist to user_notifications.
        $this->createNotification(
            userId: $applicant->userAccountId,
            applicantId: $applicantId,
            type: 'national_sync_issued',
            title: 'صدور مجوز در درگاه ملی',
            body: "مجوز کسب‌وکار شما در درگاه ملی صادر شد. کد رهگیری ملی: {$nationalRequestNumber}"
        );

        // 3. Audit log entry (action='notification_sent').
        $this->conn->execute(
            'INSERT INTO sync_audit_log (applicant_id, national_request_number, action, outcome, confidence, context) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
            [
                $applicantId,
                $nationalRequestNumber,
                'notification_sent',
                'notification_sent',
                90,
                json_encode(['channels' => ['sms', 'in_app'], 'mobile' => $applicant->mobile], JSON_UNESCAPED_UNICODE),
            ]
        );

        $this->logger->info('License-issued notification sent', [
            'applicant_id' => $applicantId,
            'rn' => $nationalRequestNumber,
            'mobile' => $applicant->mobile,
        ]);
    }

    /**
     * Notify all admin users (role='admin' in the `users` table) of an
     * operator-flagged match that needs manual review.
     *
     * Phase 14 (v1.5.0) — pays D-4-7. Called by NotificationSubscriber
     * when an OperatorFlag event fires (golden-link match with confidence
     * 50-89 — pending manual review). The notification is IN-APP ONLY:
     * no SMS, no email (admins review via the admin panel's
     * sync_audit_log page; the SMS/email channels are reserved for
     * applicant-facing notifications on confirmed matches).
     *
     * Schema note: `user_notifications.user_account_id` is now nullable
     * (Phase 27, migration 014 — pays D-14-2). Admins live in the
     * separate `users` table; we bridge by looking up a matching
     * `user_accounts` row by `username` — admins who ALSO have a
     * user_account (e.g. a superuser who uses both the admin realm
     * `/admin/login` and the user realm `/login`) see the notification
     * in their user panel. Admins who don't have a user_account get a
     * notification row with `user_account_id = NULL` — the row exists
     * (queryable, dismissible) for a future admin-notifications view;
     * the sync_audit_log row remains the primary admin-facing channel
     * today. The previous behavior (Phase 22, D-14-1) was to skip +
     * log a warning — the row didn't exist, so a future admin view had
     * nothing to surface.
     *
     * Phase 22 (v1.9.4) paid D-14-1: per-admin warning log for skipped
     * admins. Phase 27 (v1.9.9) pays D-14-2: the row is now created
     * (with NULL user_account_id) instead of skipped.
     *
     * Idempotency: dedupes on (user_account_id, type, title) — note
     * that NULL user_account_id dedupes with a `user_account_id IS
     * NULL` predicate (so multiple orphan admins share one
     * notification row for the same title, which is the desired
     * behavior — the row is a "for-any-admin" notification, not
     * per-admin).
     *
     * @param string $title  Short Persian headline.
     * @param string $body   Short Persian body summarizing the match.
     */
    public function notifyAdmins(string $title, string $body): void
    {
        // Find admins (role='admin' in `users`).
        try {
            $admins = $this->conn->all("SELECT id, username FROM users WHERE role = 'admin'");
        } catch (\Throwable $e) {
            $this->logger->warning('notifyAdmins: cannot read users table', [
                'error' => $e->getMessage(),
            ]);
            return;
        }
        if ($admins === []) {
            $this->logger->info('notifyAdmins: no admin users found', []);
            return;
        }

        $notified = 0;
        $skipped = 0;
        $orphanNotified = false; // shared NULL-row dedup across all orphan admins
        foreach ($admins as $admin) {
            $username = (string)($admin['username'] ?? '');
            if ($username === '') {
                $skipped++;
                continue;
            }
            // Bridge: find a matching user_account by username.
            $account = $this->conn->one(
                'SELECT id FROM user_accounts WHERE username = ? AND is_active = 1 LIMIT 1',
                [$username]
            );
            if ($account === null) {
                // Admin has no user_account bridge. Phase 27 (D-14-2):
                // instead of skipping (Phase 22 behavior), insert a
                // notification row with user_account_id = NULL so the
                // notification exists for a future admin-notifications
                // view. The schema was made nullable in migration 014.
                //
                // Dedup: one shared NULL row per (type, title) — once
                // any orphan admin has triggered it for this title,
                // don't re-insert for other orphan admins (they share
                // the same admin-facing notification surface).
                if ($orphanNotified) {
                    $skipped++;
                    continue;
                }
                $existing = $this->conn->one(
                    'SELECT id FROM user_notifications WHERE user_account_id IS NULL AND type = ? AND title = ? LIMIT 1',
                    ['operator_flag', $title]
                );
                if ($existing !== null) {
                    $orphanNotified = true;
                    $skipped++;
                    continue;
                }
                $this->conn->execute(
                    'INSERT INTO user_notifications (user_account_id, applicant_id, type, title, body, is_read, created_at) '
                    . 'VALUES (NULL, NULL, ?, ?, ?, 0, NOW())',
                    ['operator_flag', $title, $body]
                );
                $orphanNotified = true;
                $notified++;
                $this->logger->info('notifyAdmins: admin has no user_account bridge, inserted NULL-account notification row', [
                    'admin_username' => $username,
                    'admin_id'       => (int)($admin['id'] ?? 0),
                    'title'          => $title,
                    'hint'           => 'Create a user_account with the same username to receive notifications in the user panel.',
                ]);
                continue;
            }
            $userAccountId = (int)$account['id'];

            // Idempotency: dedupe on (user_account_id, type, title).
            $existing = $this->conn->one(
                'SELECT id FROM user_notifications WHERE user_account_id = ? AND type = ? AND title = ? LIMIT 1',
                [$userAccountId, 'operator_flag', $title]
            );
            if ($existing !== null) {
                $skipped++;
                continue;
            }

            $this->conn->execute(
                'INSERT INTO user_notifications (user_account_id, applicant_id, type, title, body, is_read, created_at) '
                . 'VALUES (?, NULL, ?, ?, ?, 0, NOW())',
                [$userAccountId, 'operator_flag', $title, $body]
            );
            $notified++;
        }

        $this->logger->info('notifyAdmins dispatched', [
            'notified' => $notified,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Find the sms_templates.id for a given target_status.
     */
    private function findTemplateId(string $targetStatus): ?int
    {
        $row = $this->conn->one(
            'SELECT id FROM sms_templates WHERE target_status = ? AND is_active = 1 ORDER BY id ASC LIMIT 1',
            [$targetStatus]
        );
        return $row === null ? null : (int)$row['id'];
    }

    /**
     * Queue an SMS for sending. Idempotent on (applicant_id, sms_template_id, status).
     */
    private function queueSms(int $templateId, string $body, int $applicantId): void
    {
        // Idempotency: don't queue duplicate SMS for the same applicant+template.
        $existing = $this->conn->one(
            'SELECT id FROM user_sms_entries WHERE applicant_id = ? AND sms_template_id = ? AND status = ? LIMIT 1',
            [$applicantId, $templateId, 'در انتظار']
        );
        if ($existing !== null) {
            return;
        }

        // Note: user_sms_entries has applicant_id column (FK to applicants) —
        // verified via SHOW CREATE TABLE. The sms_text holds the rendered body.
        $this->conn->execute(
            'INSERT INTO user_sms_entries (applicant_id, sms_template_id, sms_text, status, created_at) '
            . 'VALUES (?, ?, ?, ?, NOW())',
            [$applicantId, $templateId, $body, 'در انتظار']
        );
    }

    /**
     * Create an in-app notification. Idempotent on (applicant_id, type).
     */
    private function createNotification(
        ?int $userId,
        int $applicantId,
        string $type,
        string $title,
        string $body
    ): void {
        // user_notifications.user_account_id is NOT NULL — if the applicant
        // has no user_account, we can't create an in-app notification. In
        // that case, the SMS + audit log are the only channels.
        if ($userId === null || $userId === 0) {
            $this->logger->info('createNotification: applicant has no user_account, skipping in-app', [
                'applicant_id' => $applicantId,
            ]);
            return;
        }

        $existing = $this->conn->one(
            'SELECT id FROM user_notifications WHERE applicant_id = ? AND type = ? LIMIT 1',
            [$applicantId, $type]
        );
        if ($existing !== null) {
            return;
        }

        $this->conn->execute(
            'INSERT INTO user_notifications (user_account_id, applicant_id, type, title, body, is_read, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, 0, NOW())',
            [$userId, $applicantId, $type, $title, $body]
        );
    }
}
