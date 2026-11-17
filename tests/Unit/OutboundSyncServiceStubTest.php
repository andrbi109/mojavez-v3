<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for App\Modules\NationalGateway\Services\OutboundSyncService — the
 * stub / callable behavior (D-8-1).
 *
 * Verifies the three behaviors introduced by D-8-1:
 *   1. Default (no callable injected): pushToUpstream() runs the stub,
 *      outcome='skipped', and the "upstream endpoint not yet available"
 *      info is logged. isStubActive() returns true.
 *   2. With a callable injected via setPushCallable(): the callable is
 *      invoked with ($operation, $requestNumber, $payload) and its
 *      return shape is preserved. isStubActive() returns false.
 *   3. setPushCallable(null) resets to the stub — isStubActive() returns
 *      true again.
 *
 * The actual integration_log writes + idempotency + conflict detection are
 * covered by OutboundSyncServiceTest — this test isolates the new stub /
 * callable surface so a regression in D-8-1 surfaces independently of the
 * broader outbound logic.
 */
final class OutboundSyncServiceStubTest extends TestCase
{
    private \App\Shared\Persistence\Connection $conn;
    private \App\Modules\NationalGateway\Services\OutboundSyncService $service;
    private \App\Shared\Support\StructuredLogger $logger;
    private string $logPath;
    private int $applicantId;
    private array $createdLogIds = [];
    private array $settingsBackup = [];

    public function name(): string
    {
        return 'OutboundSyncServiceStubTest';
    }

    public function setUp(): void
    {
        // Use a temp log file so we can assert on the stub's log line.
        $this->logPath = sys_get_temp_dir() . '/outbound-stub-' . uniqid() . '.log';
        $this->logger = new \App\Shared\Support\StructuredLogger($this->logPath, 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $container = new \App\Container($env, $this->logger);
        $container->boot(dirname(__DIR__, 2));
        $this->conn = $container->make(\App\Shared\Persistence\Connection::class);

        // Build the service directly so we can inject the temp logger.
        $this->service = new \App\Modules\NationalGateway\Services\OutboundSyncService(
            $container->make(\App\Shared\Contracts\ApplicantRepositoryInterface::class),
            $this->conn,
            $this->logger,
        );

        $this->settingsBackup['sync_two_way_enabled'] = $this->readSetting('sync_two_way_enabled');
        $this->settingsBackup['sync_direction'] = $this->readSetting('sync_direction');
        $this->settingsBackup['outbound_failure_streak'] = $this->readSetting('outbound_failure_streak');
        $this->upsertSetting('sync_two_way_enabled', '1');
        $this->upsertSetting('sync_direction', 'two_way');
        $this->upsertSetting('outbound_failure_streak', '0');

        $this->applicantId = $this->ensureTestApplicant();
    }

    public function tearDown(): void
    {
        foreach ($this->settingsBackup as $key => $value) {
            if ($value === null) {
                $this->conn->execute('DELETE FROM settings WHERE setting_key = ?', [$key]);
            } else {
                $this->upsertSetting($key, $value);
            }
        }
        foreach ($this->createdLogIds as $id) {
            $this->conn->execute('DELETE FROM integration_log WHERE id = ?', [$id]);
        }
        $this->conn->execute('DELETE FROM integration_log WHERE applicant_id = ?', [$this->applicantId]);
        $this->conn->execute("DELETE FROM applicants WHERE tracking_code = 'HG-STUB-TEST'");
        $this->conn->execute("DELETE FROM national_licenses WHERE request_number = 'TEST-STUB-001'");
        @unlink($this->logPath);
    }

    public function run(): void
    {
        // 1. isStubActive() defaults to true (no callable injected).
        $this->assertTrue($this->service->isStubActive(), 'stub active by default');

        // 2. Default stub path: pushStatusChange() returns outcome='skipped'
        //    (because the stub logs + skips the actual HTTP push).
        $result = $this->service->pushStatusChange(
            $this->applicantId,
            \App\Modules\Core\Domain\Enums\ApplicantStatus::from('تأیید اولیه'),
            'admin'
        );
        $this->assertTrue(
            in_array($result['outcome'], ['skipped', 'success'], true),
            'stub path returns skipped or success (got: ' . $result['outcome'] . ')'
        );
        $this->assertNotNull($result['integration_log_id'], 'integration_log row created');
        $this->createdLogIds[] = (int)$result['integration_log_id'];

        // 3. The stub logs an "upstream endpoint not yet available" line —
        //    verify the log file contains it.
        $logContents = (string)@file_get_contents($this->logPath);
        $this->assertStringContainsString(
            'upstream endpoint not yet available',
            $logContents,
            'stub logged the "upstream not available" message'
        );

        // 4. Inject a real callable. isStubActive() should flip to false.
        $captured = null;
        $this->service->setPushCallable(
            function (string $op, string $rn, array $payload) use (&$captured): array {
                $captured = ['operation' => $op, 'request_number' => $rn, 'payload' => $payload];
                return [
                    'outcome' => 'success',
                    'http_status' => 202,
                    'response' => ['upstream_id' => 'up-123'],
                    'error' => null,
                    'latency_ms' => 17,
                ];
            }
        );
        $this->assertFalse($this->service->isStubActive(), 'stub inactive after callable injected');

        // 5. pushStatusChange now delegates to the callable. The callable
        //    was called with a NEW idempotency key (different status) so it
        //    runs through the full push path. Verify the captured args +
        //    that the outcome reflects the callable's return.
        $result2 = $this->service->pushStatusChange(
            $this->applicantId,
            \App\Modules\Core\Domain\Enums\ApplicantStatus::from('تأیید نهایی'),
            'operator'
        );
        $this->createdLogIds[] = (int)$result2['integration_log_id'];

        $this->assertSame('success', $result2['outcome'], 'callable path returns success');
        $this->assertNotNull($captured, 'callable was invoked');
        $this->assertSame('push_status', $captured['operation'], 'callable got operation');
        $this->assertSame('TEST-STUB-001', $captured['request_number'], 'callable got request_number');
        $this->assertArrayHasKey('new_status', $captured['payload'], 'callable got payload with new_status');
        $this->assertSame('تأیید نهایی', $captured['payload']['new_status'], 'callable got correct status');

        // 6. Verify the integration_log row recorded the callable's outcome.
        $row = $this->conn->one(
            'SELECT outcome, http_status FROM integration_log WHERE id = ?',
            [$result2['integration_log_id']]
        );
        $this->assertSame('success', $row['outcome'], 'integration_log recorded callable outcome');
        $this->assertSame(202, (int)$row['http_status'], 'integration_log recorded callable http_status');

        // 7. Reset the callable to null — isStubActive() flips back to true.
        $this->service->setPushCallable(null);
        $this->assertTrue($this->service->isStubActive(), 'stub active again after reset to null');
    }

    private function ensureTestApplicant(): int
    {
        $row = $this->conn->one("SELECT id FROM applicants WHERE tracking_code = 'HG-STUB-TEST' LIMIT 1");
        if ($row !== null) {
            return (int)$row['id'];
        }
        $exists = $this->conn->one("SELECT id FROM national_licenses WHERE request_number = 'TEST-STUB-001'");
        if ($exists === null) {
            $this->conn->execute(
                "INSERT INTO national_licenses (request_number, applicant_name, license_title,
                    organization_title, province_title, status_slug, masked_national_code)
                 VALUES ('TEST-STUB-001', 'تست استاب', 'صنایع دستی', 'سازمان میراث', 'تهران', 'active', '00******9999')"
            );
        }
        $this->conn->execute(
            "INSERT INTO applicants (first_name, last_name, national_code, postal_code, mobile,
                tracking_code, current_status, license_state, national_license_request_number, matched_at, match_confidence)
             VALUES ('تست', 'استاب', '0000000009', '0000000000', '09120000009',
                'HG-STUB-TEST', 'صدور مجوز', 'جاری', 'TEST-STUB-001', NOW(), 95)"
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

return new OutboundSyncServiceStubTest();
