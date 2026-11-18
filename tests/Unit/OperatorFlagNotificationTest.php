<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for NotificationService::notifyAdmins + NotificationSubscriber
 * OperatorFlag handler (Phase 14 / D-4-7).
 *
 * Verifies:
 *   1. notifyAdmins() with an admin that has a matching user_account
 *      inserts a user_notifications row (type='operator_flag').
 *   2. notifyAdmins() idempotency — re-calling with the same title
 *      doesn't insert a duplicate.
 *   3. notifyAdmins() with an admin that has NO user_account inserts
 *      a notification row with user_account_id = NULL (Phase 27 /
 *      D-14-2 fix — previously the row was skipped entirely; the
 *      sync_audit_log row was the only admin-facing surface).
 *   4. NotificationSubscriber::handleOperatorFlag builds the expected
 *      Persian title + body and calls notifyAdmins (indirect via
 *      notifyAdmins assertions above).
 *   5. subscribedTo() returns both ApplicantMatched::TYPE and
 *      OperatorFlag::TYPE.
 */
final class OperatorFlagNotificationTest extends TestCase
{
    private \App\Container $container;
    private \App\Modules\Core\Services\NotificationService $service;
    private \App\Shared\Persistence\Connection $conn;
    private int $adminUserId = 0;
    private int $userAccountId = 0;
    private string $testUsername = '';
    private string $testMobile = '';

    public function name(): string
    {
        return 'OperatorFlagNotificationTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/opflag-test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $this->container = new \App\Container($env, $logger);
        $this->container->boot(dirname(__DIR__, 2));
        $this->conn = $this->container->make(\App\Shared\Persistence\Connection::class);
        $this->service = $this->container->make(\App\Modules\Core\Services\NotificationService::class);

        // Pick a unique username + mobile so the test can't collide with
        // seed data or other tests.
        $this->testUsername = 'opflag_test_' . substr(md5((string)uniqid('', true)), 0, 8);
        $this->testMobile   = '09129' . random_int(100000, 999999);

        // Clean leftovers (idempotent across re-runs).
        $this->conn->execute(
            'DELETE FROM user_notifications WHERE type = ? AND title = ?',
            ['operator_flag', 'نیازمند بررسی اپراتور']
        );
        $this->conn->execute(
            'DELETE FROM user_accounts WHERE username = ?',
            [$this->testUsername]
        );
        $this->conn->execute(
            'DELETE FROM users WHERE username = ?',
            [$this->testUsername]
        );

        // Create an admin user (in the `users` table).
        $this->adminUserId = (int)$this->conn->insert(
            'INSERT INTO users (username, password, role) VALUES (?, ?, ?)',
            [$this->testUsername, 'x', 'admin']
        );

        // Create a matching user_account (in the `user_accounts` table)
        // so the username-bridge in notifyAdmins finds it.
        $this->userAccountId = (int)$this->conn->insert(
            'INSERT INTO user_accounts (username, mobile, password, is_active) VALUES (?, ?, ?, 1)',
            [$this->testUsername, $this->testMobile, 'x']
        );
    }

    public function tearDown(): void
    {
        // Clean up the rows we created.
        $this->conn->execute(
            'DELETE FROM user_notifications WHERE user_account_id = ?',
            [$this->userAccountId]
        );
        if ($this->userAccountId > 0) {
            $this->conn->execute(
                'DELETE FROM user_accounts WHERE id = ?',
                [$this->userAccountId]
            );
        }
        if ($this->adminUserId > 0) {
            $this->conn->execute(
                'DELETE FROM users WHERE id = ?',
                [$this->adminUserId]
            );
        }
        // Clean up any orphaned notifications with the test title (defensive).
        $this->conn->execute(
            'DELETE FROM user_notifications WHERE type = ? AND title = ?',
            ['operator_flag', 'نیازمند بررسی اپراتور']
        );
    }

    public function run(): void
    {
        $title = 'نیازمند بررسی اپراتور';
        $body  = 'تطابق جزئی (اطمینان 65) برای متقاضی 9001 با مجوز I-TEST123 (سفالگری) نیاز به بررسی دارد.';

        // 1. notifyAdmins inserts a user_notifications row for the admin.
        $this->service->notifyAdmins($title, $body);

        $notif = $this->conn->one(
            'SELECT * FROM user_notifications WHERE user_account_id = ? AND type = ?',
            [$this->userAccountId, 'operator_flag']
        );
        $this->assertNotNull($notif, 'notification row created (D-4-7)');
        $this->assertSame($title, (string)$notif['title'], 'notification title');
        $this->assertStringContainsString('I-TEST123', (string)$notif['body'], 'notification body contains the request number');
        $this->assertSame(0, (int)$notif['is_read'], 'notification is unread');
        $this->assertNull($notif['applicant_id'], 'applicant_id is NULL for admin notifications');

        // 2. Idempotency — re-calling with the same title doesn't duplicate.
        $this->service->notifyAdmins($title, $body);
        $count = (int)$this->conn->scalar(
            'SELECT COUNT(*) FROM user_notifications WHERE user_account_id = ? AND type = ? AND title = ?',
            [$this->userAccountId, 'operator_flag', $title]
        );
        $this->assertSame(1, $count, 'idempotent: still only 1 notification for the same (user, type, title)');

        // 3. Admin without a user_account — Phase 27 (D-14-2):
        //    the notification is no longer skipped; a row is created
        //    with user_account_id = NULL so the notification exists for
        //    a future admin-notifications view.
        //    Add an admin-only user with no matching user_account.
        $orphanAdminUser = 'opflag_orphan_' . substr(md5((string)uniqid('', true)), 0, 8);
        $orphanId = (int)$this->conn->insert(
            'INSERT INTO users (username, password, role) VALUES (?, ?, ?)',
            [$orphanAdminUser, 'x', 'admin']
        );
        try {
            // Different title so it doesn't dedupe with the first.
            $orphanTitle = 'نیازمند بررسی اپراتور (orphan)';
            $this->service->notifyAdmins($orphanTitle, 'orphan body');

            // The orphan admin has no user_account — Phase 27 behavior:
            // a row IS created with user_account_id = NULL.
            $orphanRow = $this->conn->one(
                'SELECT * FROM user_notifications WHERE user_account_id IS NULL AND type = ? AND title = ?',
                ['operator_flag', $orphanTitle]
            );
            $this->assertNotNull($orphanRow, 'orphan admin (no user_account) → NULL-account notification row created (Phase 27 D-14-2)');
            $this->assertNull($orphanRow['user_account_id'], 'orphan notification user_account_id is NULL');
            $this->assertSame($orphanTitle, (string)$orphanRow['title'], 'orphan notification title');
            $this->assertSame('orphan body', (string)$orphanRow['body'], 'orphan notification body');

            // The bridged admin (still in the users table) ALSO got a
            // notification for the same title — via the user_account
            // bridge, not the NULL path.
            $bridgedOrphanRow = $this->conn->one(
                'SELECT * FROM user_notifications WHERE user_account_id = ? AND type = ? AND title = ?',
                [$this->userAccountId, 'operator_flag', $orphanTitle]
            );
            $this->assertNotNull($bridgedOrphanRow, 'bridged admin still gets its own row (separate from the NULL row)');

            // Idempotency: re-call notifyAdmins with the same title —
            // the NULL row is dedup'd (one shared NULL row per
            // (type, title) — orphan admins share the surface).
            $this->service->notifyAdmins($orphanTitle, 'orphan body 2');
            $nullCount = (int)$this->conn->scalar(
                'SELECT COUNT(*) FROM user_notifications WHERE user_account_id IS NULL AND type = ? AND title = ?',
                ['operator_flag', $orphanTitle]
            );
            $this->assertSame(1, $nullCount, 'orphan NULL-row idempotency: still only 1 NULL row for the same (type, title)');

            // Cleanup the orphan-title rows so step 5 starts fresh.
            $this->conn->execute(
                'DELETE FROM user_notifications WHERE type = ? AND title = ?',
                ['operator_flag', $orphanTitle]
            );
        } finally {
            $this->conn->execute('DELETE FROM users WHERE id = ?', [$orphanId]);
        }

        // 4. NotificationSubscriber.subscribedTo() returns both types.
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/sub-test.log', 'debug');
        $subscriber = new \App\Events\Subscribers\NotificationSubscriber(
            $this->service,
            $logger
        );
        $types = $subscriber->subscribedTo();
        $this->assertTrue(in_array(\App\Events\Events\ApplicantMatched::TYPE, $types, true), 'subscribedTo includes ApplicantMatched');
        $this->assertTrue(in_array(\App\Events\Events\OperatorFlag::TYPE, $types, true), 'subscribedTo includes OperatorFlag (D-4-7)');

        // 5. NotificationSubscriber.handle(OperatorFlag) calls notifyAdmins
        //    indirectly — verified by the row created in step 1+2 above
        //    (we tested the service directly; the subscriber delegates to
        //    the service). For completeness, dispatch through the
        //    subscriber directly.
        //
        //    The subscriber ALWAYS uses the fixed title
        //    'نیازمند بررسی اپراتور' (NotificationSubscriber line 107)
        //    — so the dedup check would silently skip if step 1's row
        //    for the bridged admin is still around. Delete that row
        //    first so the subscriber's call inserts a fresh row we can
        //    assert against.
        $this->conn->execute(
            'DELETE FROM user_notifications WHERE user_account_id = ? AND type = ?',
            [$this->userAccountId, 'operator_flag']
        );
        $event = new \App\Events\Events\OperatorFlag([
            'applicant_id'            => 9001,
            'national_request_number' => 'I-SUB-DIRECT',
            'license_title'           => 'سفالگری',
            'confidence'              => 70,
            'fields_matched'          => ['father_name', 'postal_code'],
            'reason'                  => 'partial match',
        ]);
        $subscriber->handle($event);

        $notif2 = $this->conn->one(
            'SELECT * FROM user_notifications WHERE user_account_id = ? AND type = ? AND title = ?',
            [$this->userAccountId, 'operator_flag', 'نیازمند بررسی اپراتور']
        );
        $this->assertNotNull($notif2, 'subscriber.handle(OperatorFlag) created a notification');
        $this->assertStringContainsString('I-SUB-DIRECT', (string)$notif2['body'], 'subscriber notification body has the RN');
        $this->assertStringContainsString('سفالگری', (string)$notif2['body'], 'subscriber notification body has the license title');
    }
}

return new OperatorFlagNotificationTest();
