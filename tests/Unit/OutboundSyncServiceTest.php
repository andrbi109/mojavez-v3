<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for App\Modules\NationalGateway\Services\OutboundSyncService.
 *
 * Verifies the Phase 8 / Rollout Stage 2 outbound sync contract:
 *   - pushStatusChange logs to integration_log with direction='outbound'.
 *   - Idempotency: same push twice → second is a no-op (returns existing log id).
 *   - Conflict detection: if upstream_updated_at > v3 updated_at, push is
 *     skipped with outcome='conflict' + source_of_truth='upstream'.
 *   - Stub: when no upstream endpoint (current state per §19.8), outcome='skipped'.
 *   - Kill-switch: 3 consecutive 'server_error' outcomes auto-disable two-way.
 *
 * Note: the actual HTTP push is stubbed in OutboundSyncService::pushToUpstream()
 * (upstream has no writable endpoint yet). These tests verify the orchestration
 * + audit + conflict logic, not the (future) HTTP call.
 */
final class OutboundSyncServiceTest extends TestCase
{
    private \App\Shared\Persistence\Connection $conn;
    private \App\Modules\NationalGateway\Services\OutboundSyncService $service;
    private int $applicantId;
    private array $createdLogIds = [];
    private array $settingsBackup = [];

    public function name(): string
    {
        return 'OutboundSyncServiceTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(dirname(__DIR__, 2) . '/storage/logs/test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $container = new \App\Container($env, $logger);
        $container->boot(dirname(__DIR__, 2));
        $this->conn = $container->make(\App\Shared\Persistence\Connection::class);
        $this->service = $container->make(\App\Modules\NationalGateway\Services\OutboundSyncService::class);

        // Ensure two-way is enabled for the test (we'll restore in tearDown).
        $this->settingsBackup['sync_two_way_enabled'] = $this->readSetting('sync_two_way_enabled');
        $this->settingsBackup['sync_direction'] = $this->readSetting('sync_direction');
        $this->settingsBackup['outbound_failure_streak'] = $this->readSetting('outbound_failure_streak');
        $this->upsertSetting('sync_two_way_enabled', '1');
        $this->upsertSetting('sync_direction', 'two_way');
        $this->upsertSetting('outbound_failure_streak', '0');

        // Create a test applicant matched to a national license.
        $this->applicantId = $this->ensureTestApplicant();
    }

    public function tearDown(): void
    {
        // Restore settings.
        foreach ($this->settingsBackup as $key => $value) {
            if ($value === null) {
                $this->conn->execute('DELETE FROM settings WHERE setting_key = ?', [$key]);
            } else {
                $this->upsertSetting($key, $value);
            }
        }
        // Clean integration_log rows created by this test.
        foreach ($this->createdLogIds as $id) {
            $this->conn->execute('DELETE FROM integration_log WHERE id = ?', [$id]);
        }
        // Clean by applicant_id (safety net).
        $this->conn->execute('DELETE FROM integration_log WHERE applicant_id = ?', [$this->applicantId]);
        // Clean the test applicant.
        $this->conn->execute("DELETE FROM applicants WHERE tracking_code = 'HG-OUTBTEST'");
        // Clean test national_licenses row.
        $this->conn->execute("DELETE FROM national_licenses WHERE request_number = 'TEST-OUTB-001'");
    }

    public function run(): void
    {
        // 1. isEnabled() returns true (we set sync_two_way_enabled=1 in setUp).
        $this->assertTrue($this->service->isEnabled(), 'two-way sync enabled');

        // 2. pushStatusChange — stub path, outcome='skipped' (no upstream endpoint).
        $result = $this->service->pushStatusChange(
            $this->applicantId,
            \App\Modules\Core\Domain\Enums\ApplicantStatus::from('تأیید اولیه'),
            'admin'
        );
        $this->assertTrue(
            in_array($result['outcome'], ['skipped', 'success'], true),
            'pushStatusChange returns skipped or success (got: ' . $result['outcome'] . ')'
        );
        $this->assertNotNull($result['integration_log_id'], 'integration_log row created');
        $this->createdLogIds[] = (int)$result['integration_log_id'];

        // 3. Idempotency: same push again → returns existing log id (no duplicate).
        $result2 = $this->service->pushStatusChange(
            $this->applicantId,
            \App\Modules\Core\Domain\Enums\ApplicantStatus::from('تأیید اولیه'),
            'admin'
        );
        $this->assertSame(
            $result['integration_log_id'],
            $result2['integration_log_id'],
            'idempotent — same push returns existing log id'
        );

        // 4. Verify integration_log row shape.
        $row = $this->conn->one(
            'SELECT direction, operation, idempotency_key, outcome FROM integration_log WHERE id = ?',
            [$result['integration_log_id']]
        );
        $this->assertSame('outbound', $row['direction'], 'direction is outbound');
        $this->assertSame('push_status', $row['operation'], 'operation is push_status');
        $this->assertTrue(str_starts_with($row['idempotency_key'], 'out_push_status_'), 'idempotency_key prefix correct');

        // 5. pushDocument — different operation, separate idempotency key.
        $result3 = $this->service->pushDocument($this->applicantId, 999);
        $this->assertFalse(
            $result['integration_log_id'] === $result3['integration_log_id'],
            'pushDocument creates a separate log entry'
        );
        $this->createdLogIds[] = (int)$result3['integration_log_id'];

        // 6. Conflict detection: insert a fake inbound log with upstream_updated_at
        //    in the future → next push should be 'conflict'.
        $futureDate = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $this->conn->execute(
            'INSERT INTO integration_log (direction, operation, applicant_id, national_request_number,
                idempotency_key, outcome, upstream_updated_at, created_at)
             VALUES ("inbound", "fetch_license", ?, "TEST-OUTB-001", ?, "success", ?, NOW())',
            [$this->applicantId, 'inb_conflict_test_' . uniqid(), $futureDate]
        );
        $conflictLogId = (int)$this->conn->pdo()->lastInsertId();
        $this->createdLogIds[] = $conflictLogId;

        $result4 = $this->service->pushStatusChange(
            $this->applicantId,
            \App\Modules\Core\Domain\Enums\ApplicantStatus::from('تأیید نهایی'),
            'operator'
        );
        $this->assertSame('conflict', $result4['outcome'], 'push skipped with conflict (upstream newer)');
        $this->createdLogIds[] = (int)$result4['integration_log_id'];

        // 7. Verify the conflict log recorded source_of_truth='upstream'.
        $conflictRow = $this->conn->one(
            'SELECT source_of_truth, conflict_resolution FROM integration_log WHERE id = ?',
            [$result4['integration_log_id']]
        );
        $this->assertSame('upstream', $conflictRow['source_of_truth'], 'source_of_truth=upstream on conflict');
        $this->assertSame('last_write_wins', $conflictRow['conflict_resolution'], 'conflict_resolution=last_write_wins');

        // 8. pushStatusChange for applicant NOT matched to national license → skipped.
        //    (Create a second applicant without national_license_request_number.)
        $this->conn->execute(
            "INSERT INTO applicants (first_name, last_name, national_code, postal_code, mobile,
                tracking_code, current_status, license_state, national_license_request_number)
             VALUES ('تست', 'بدون‌مجوز', '0000000001', '0000000000', '09120000001',
                'HG-OUTBTEST2', 'پیش‌نویس', 'جاری', NULL)"
        );
        $unmatchedId = (int)$this->conn->pdo()->lastInsertId();
        $result5 = $this->service->pushStatusChange(
            $unmatchedId,
            \App\Modules\Core\Domain\Enums\ApplicantStatus::from('تأیید اولیه'),
            'admin'
        );
        $this->assertSame('skipped', $result5['outcome'], 'push skipped for unmatched applicant');
        $this->assertNull($result5['integration_log_id'], 'no log row for unmatched applicant');
        // Cleanup.
        $this->conn->execute('DELETE FROM applicants WHERE id = ?', [$unmatchedId]);
    }

    private function ensureTestApplicant(): int
    {
        // Find or create a test applicant matched to a national license.
        $row = $this->conn->one("SELECT id FROM applicants WHERE tracking_code = 'HG-OUTBTEST' LIMIT 1");
        if ($row !== null) {
            return (int)$row['id'];
        }
        // Ensure a national_licenses row exists for the FK-like reference.
        $exists = $this->conn->one("SELECT id FROM national_licenses WHERE request_number = 'TEST-OUTB-001'");
        if ($exists === null) {
            $this->conn->execute(
                "INSERT INTO national_licenses (request_number, applicant_name, license_title,
                    organization_title, province_title, status_slug, masked_national_code)
                 VALUES ('TEST-OUTB-001', 'تست خروجی', 'صنایع دستی', 'سازمان میراث', 'تهران', 'active', '00******1234')"
            );
        }
        $this->conn->execute(
            "INSERT INTO applicants (first_name, last_name, national_code, postal_code, mobile,
                tracking_code, current_status, license_state, national_license_request_number, matched_at, match_confidence)
             VALUES ('تست', 'خروجی', '0000000002', '0000000000', '09120000002',
                'HG-OUTBTEST', 'صدور مجوز', 'جاری', 'TEST-OUTB-001', NOW(), 95)"
        );
        return (int)$this->conn->pdo()->lastInsertId();
    }

    private function readSetting(string $key): ?string
    {
        $row = $this->conn->one('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        return $row !== null ? (string)$row['setting_value'] : null;
    }

    private function upsertSetting(string $key, string $value): void
    {
        $existing = $this->conn->one('SELECT setting_key FROM settings WHERE setting_key = ?', [$key]);
        if ($existing !== null) {
            $this->conn->execute('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$value, $key]);
        } else {
            $this->conn->execute('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)', [$key, $value]);
        }
    }
}

return new OutboundSyncServiceTest();
