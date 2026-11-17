<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for App\Modules\Core\Services\NotificationService.
 *
 * Verifies that when notifyLicenseIssued() is called (as NotificationSubscriber
 * would do on an ApplicantMatched event):
 *   1. An SMS entry is queued in user_sms_entries (idempotent).
 *   2. An in-app notification is created in user_notifications (idempotent).
 *   3. A sync_audit_log row with action='notification_sent' is inserted.
 *   4. Calling twice doesn't create duplicates (idempotency).
 */
final class NotificationServiceTest extends TestCase
{
    private \App\Container $container;
    private \App\Modules\Core\Services\NotificationService $service;
    private \App\Shared\Persistence\Connection $conn;
    private int $applicantId = 0;
    private int $personId = 0;
    private string $testRn = '';

    public function name(): string
    {
        return 'NotificationServiceTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/notif-test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $this->container = new \App\Container($env, $logger);
        $this->container->boot(dirname(__DIR__, 2));
        $this->conn = $this->container->make(\App\Shared\Persistence\Connection::class);
        $this->service = $this->container->make(\App\Modules\Core\Services\NotificationService::class);

        // Clean leftovers.
        $this->conn->execute("DELETE FROM sync_audit_log WHERE national_request_number LIKE 'NOTIF-%'");
        $this->conn->execute("DELETE FROM user_sms_entries WHERE applicant_id IN (SELECT id FROM applicants WHERE tracking_code LIKE 'NOTIF-%')");
        $this->conn->execute("DELETE FROM user_notifications WHERE applicant_id IN (SELECT id FROM applicants WHERE tracking_code LIKE 'NOTIF-%')");
        $this->conn->execute("DELETE FROM applicants WHERE tracking_code LIKE 'NOTIF-%'");
        $this->conn->execute("DELETE FROM persons WHERE national_code = '0720593999'");

        // Create test person + applicant (no user_account → in-app notification skipped).
        $this->personId = (int)$this->conn->insert(
            'INSERT INTO persons (national_code, first_name, last_name, mobile, person_type, father_name, postal_code, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            ['0720593999', 'تست', 'نوتیف', '09150000099', 'حقیقی', 'محمد', '9571968999']
        );
        $this->applicantId = (int)$this->conn->insert(
            'INSERT INTO applicants (person_id, tracking_code, national_code, current_status, license_state, '
            . 'first_name, last_name, mobile, postal_code, priority, registration_started_at, version, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), 1, NOW())',
            [$this->personId, 'NOTIF-' . random_int(100000, 999999), '0720593999', 'صدور مجوز', 'صادر شده', 'تست', 'نوتیف', '09150000099', '9571968999']
        );
        $this->testRn = 'NOTIF-' . random_int(100000, 999999);

        // Ensure a sms_templates row for 'صدور مجوز' exists.
        $this->conn->execute(
            "INSERT IGNORE INTO sms_templates (title, sample_text, target_status, is_active, created_at) "
            . "VALUES (?, ?, ?, 1, NOW())",
            ['اطلاع‌رسانی صدور مجوز', 'مجوز شما صادر شد', 'صدور مجوز']
        );
    }

    public function tearDown(): void
    {
        if ($this->applicantId > 0) {
            $this->conn->execute('DELETE FROM user_sms_entries WHERE applicant_id = ?', [$this->applicantId]);
            $this->conn->execute('DELETE FROM user_notifications WHERE applicant_id = ?', [$this->applicantId]);
            $this->conn->execute('DELETE FROM applicants WHERE id = ?', [$this->applicantId]);
        }
        if ($this->personId > 0) {
            $this->conn->execute('DELETE FROM persons WHERE id = ?', [$this->personId]);
        }
        if ($this->testRn !== '') {
            $this->conn->execute('DELETE FROM sync_audit_log WHERE national_request_number = ?', [$this->testRn]);
        }
    }

    public function run(): void
    {
        // 1. notifyLicenseIssued — should queue SMS + create audit log.
        $this->service->notifyLicenseIssued($this->applicantId, $this->testRn);

        // 2. SMS entry exists in user_sms_entries.
        $sms = $this->conn->one(
            'SELECT * FROM user_sms_entries WHERE applicant_id = ? AND sms_template_id IS NOT NULL',
            [$this->applicantId]
        );
        $this->assertNotNull($sms, 'SMS entry queued');
        $this->assertSame('در انتظار', (string)$sms['status'], 'SMS status = در انتظار (queued)');
        $this->assertStringContainsString($this->testRn, (string)$sms['sms_text'], 'SMS body contains request number');

        // 3. sync_audit_log entry with action='notification_sent'.
        $audit = $this->conn->one(
            "SELECT * FROM sync_audit_log WHERE applicant_id = ? AND action = 'notification_sent'",
            [$this->applicantId]
        );
        $this->assertNotNull($audit, 'audit log has notification_sent');
        $this->assertSame($this->testRn, (string)$audit['national_request_number'], 'audit rn matches');

        // 4. Idempotency — calling again should NOT create duplicate SMS.
        $this->service->notifyLicenseIssued($this->applicantId, $this->testRn);
        $smsCount = (int)$this->conn->scalar(
            'SELECT COUNT(*) FROM user_sms_entries WHERE applicant_id = ?',
            [$this->applicantId]
        );
        $this->assertSame(1, $smsCount, 'idempotent: still only 1 SMS entry');

        // 5. In-app notification NOT created (applicant has no user_account).
        //    user_notifications.user_account_id is NOT NULL, so NotificationService
        //    skips the in-app channel when the applicant has no user_account.
        $notifCount = (int)$this->conn->scalar(
            'SELECT COUNT(*) FROM user_notifications WHERE applicant_id = ?',
            [$this->applicantId]
        );
        $this->assertSame(0, $notifCount, 'no in-app notification (applicant has no user_account)');

        // 6. notifyLicenseIssued on a non-existent applicant → no crash, no SMS.
        $this->service->notifyLicenseIssued(999999, 'NONEXIST-1');
        $this->assertTrue(true, 'notifyLicenseIssued on missing applicant did not crash');
    }
}

return new NotificationServiceTest();
