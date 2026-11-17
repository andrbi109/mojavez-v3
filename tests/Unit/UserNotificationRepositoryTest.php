<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Unit test for PdoUserNotificationRepository (Phase 9).
 *
 * Verifies the three contract methods that back the user-panel notifications
 * surface: findByUser, unreadCount, markAllRead. Uses a real Connection
 * against the mojavez_v3 database and cleans up its own rows (mobile prefix
 * 0912999 so it can't collide with seed data).
 */
final class UserNotificationRepositoryTest extends TestCase
{
    private \App\Container $container;
    private \App\Shared\Persistence\Connection $conn;
    private \App\Shared\Contracts\UserNotificationRepositoryInterface $repo;
    private int $accountId = 0;

    public function name(): string
    {
        return 'UserNotificationRepositoryTest';
    }

    public function run(): void
    {
        $tests = [
            'testFindByUserReturnsNewestFirst',
            'testFindByUserRespectsLimit',
            'testUnreadCount',
            'testMarkAllReadReturnsAffectedCount',
            'testFindByUserIsScopedToAccount',
        ];
        foreach ($tests as $method) {
            $this->setUp();
            try {
                $this->$method();
            } finally {
                $this->tearDown();
            }
        }
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/unr-test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $this->container = new \App\Container($env, $logger);
        $this->container->boot(dirname(__DIR__, 2));
        $this->conn = $this->container->make(\App\Shared\Persistence\Connection::class);
        $this->repo = $this->container->make(\App\Shared\Contracts\UserNotificationRepositoryInterface::class);

        // Clean leftovers from any prior run.
        $this->conn->execute("DELETE FROM user_notifications WHERE user_account_id IN (SELECT id FROM user_accounts WHERE mobile = '09129990001')");
        $this->conn->execute("DELETE FROM user_accounts WHERE mobile = '09129990001'");

        $this->accountId = (int)$this->conn->insert(
            "INSERT INTO user_accounts (username, mobile, password, is_active) VALUES ('unr_test', '09129990001', 'x', 1)"
        );
    }

    public function tearDown(): void
    {
        $this->conn->execute("DELETE FROM user_notifications WHERE user_account_id = ?", [$this->accountId]);
        $this->conn->execute("DELETE FROM user_accounts WHERE id = ?", [$this->accountId]);
    }

    private function seedNotif(string $title, bool $read = false, ?int $applicantId = null): int
    {
        return (int)$this->conn->insert(
            'INSERT INTO user_notifications (user_account_id, applicant_id, type, title, body, is_read) VALUES (?, ?, ?, ?, ?, ?)',
            [$this->accountId, $applicantId, 'system', $title, 'body', $read ? 1 : 0]
        );
    }

    public function testFindByUserReturnsNewestFirst(): void
    {
        $id1 = $this->seedNotif('اول');
        sleep(1);
        $id2 = $this->seedNotif('دوم');

        $rows = $this->repo->findByUser($this->accountId);
        $this->assertTrue(count($rows) === 2, 'باید دو ردیف برگرداند');
        $this->assertTrue((int)$rows[0]['id'] === $id2, 'جدیدترین باید اول باشد');
        $this->assertTrue((int)$rows[1]['id'] === $id1, 'قدیمی‌تر باید دوم باشد');
    }

    public function testFindByUserRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedNotif("notif-$i");
        }
        $rows = $this->repo->findByUser($this->accountId, 3);
        $this->assertTrue(count($rows) === 3, 'باید محدود به ۳ باشد');
    }

    public function testUnreadCount(): void
    {
        $this->seedNotif('خوانده‌شده', true);
        $this->seedNotif('خوانده‌نشده ۱', false);
        $this->seedNotif('خوانده‌نشده ۲', false);
        $count = $this->repo->unreadCount($this->accountId);
        $this->assertTrue($count === 2, 'باید ۲ خوانده‌نشده باشد');
    }

    public function testMarkAllReadReturnsAffectedCount(): void
    {
        $this->seedNotif('n1', false);
        $this->seedNotif('n2', false);
        $this->seedNotif('n3', true); // already read — should not be re-touched

        $affected = $this->repo->markAllRead($this->accountId);
        $this->assertTrue($affected === 2, 'باید ۲ ردیف update شده باشد (نه ۳)');

        // After mark, unreadCount must be 0.
        $this->assertTrue($this->repo->unreadCount($this->accountId) === 0, 'بعد از mark باید ۰ باشد');
    }

    public function testFindByUserIsScopedToAccount(): void
    {
        // Seed for our account...
        $this->seedNotif('مال من');
        // ...and for another account (should NOT leak in).
        $otherId = (int)$this->conn->insert(
            "INSERT INTO user_accounts (username, mobile, password, is_active) VALUES ('unr_other', '09129990002', 'x', 1)"
        );
        try {
            $this->conn->execute(
                'INSERT INTO user_notifications (user_account_id, type, title) VALUES (?, ?, ?)',
                [$otherId, 'system', 'مال دیگری']
            );
            $rows = $this->repo->findByUser($this->accountId);
            $titles = array_column($rows, 'title');
            $this->assertTrue(in_array('مال من', $titles, true), 'ردیف خودمان باید باشد');
            $this->assertTrue(!in_array('مال دیگری', $titles, true), 'ردیف دیگران نباید نشت کند');
        } finally {
            $this->conn->execute("DELETE FROM user_notifications WHERE user_account_id = ?", [$otherId]);
            $this->conn->execute("DELETE FROM user_accounts WHERE id = ?", [$otherId]);
        }
    }
}

return new UserNotificationRepositoryTest();
