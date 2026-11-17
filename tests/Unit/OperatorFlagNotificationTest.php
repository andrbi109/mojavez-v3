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
 *   3. notifyAdmins() with an admin that has NO user_account skips
 *      silently (no notification row created; no crash).
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

        // 3. Admin without a user_account is skipped (no crash, no row).
        //    Add an admin-only user with no matching user_account.
        $orphanAdminUser = 'opflag_orphan_' . substr(md5((string)uniqid('', true)), 0, 8);
        $orphanId = (int)$this->conn->insert(
            'INSERT INTO users (username, password, role) VALUES (?, ?, ?)',
            [$orphanAdminUser, 'x', 'admin']
        );
        try {
            // Different title so it doesn't dedupe with the first.
            $this->service->notifyAdmins('نیازمند بررسی اپراتور (orphan)', 'orphan body');

            // The orphan admin has no user_account — no row should be
            // created pointing to a non-existent user_account.
            $orphanRows = (int)$this->conn->scalar(
                'SELECT COUNT(*) FROM user_notifications WHERE type = ? AND title = ?',
                ['operator_flag', 'نیازمند بررسی اپراتور (orphan)']
            );
            $this->assertSame(0, $orphanRows, 'orphan admin (no user_account) → no notification row');
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
        //    subscriber directly with a fresh title:
        $title2 = 'نیازمند بررسی اپراتور (subscriber-direct)';
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
            [$this->userAccountId, 'operator_flag', $title2]
        );
        $this->assertNotNull($notif2, 'subscriber.handle(OperatorFlag) created a notification');
        $this->assertStringContainsString('I-SUB-DIRECT', (string)$notif2['body'], 'subscriber notification body has the RN');
        $this->assertStringContainsString('سفالگری', (string)$notif2['body'], 'subscriber notification body has the license title');
    }
}

return new OperatorFlagNotificationTest();
