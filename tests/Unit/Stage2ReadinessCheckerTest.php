<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for App\Modules\NationalGateway\Services\Stage2ReadinessChecker.
 *
 * Verifies the 4 §29.2 conditions are correctly evaluated:
 *   1. Stage 1 stable ≥ 90 days (from first integration_log inbound entry).
 *   2. AutoConfirmed false-positive rate < 0.1% (from sync_audit_log).
 *   3. Circuit-breaker open rate < 5% in last 30 days (from integration_log).
 *   4. Contract tests exist in tests/Contract/.
 *
 * The checker never auto-enables Stage 2 — it only reports. This test
 * verifies the report structure + the pass/fail logic for each condition.
 */
final class Stage2ReadinessCheckerTest extends TestCase
{
    private \App\Shared\Persistence\Connection $conn;
    private \App\Modules\NationalGateway\Services\Stage2ReadinessChecker $checker;
    private array $createdIntegrationLogIds = [];
    private array $createdAuditLogIds = [];

    public function name(): string
    {
        return 'Stage2ReadinessCheckerTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(dirname(__DIR__, 2) . '/storage/logs/test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $container = new \App\Container($env, $logger);
        $container->boot(dirname(__DIR__, 2));
        $this->conn = $container->make(\App\Shared\Persistence\Connection::class);
        $this->checker = $container->make(\App\Modules\NationalGateway\Services\Stage2ReadinessChecker::class);
    }

    public function tearDown(): void
    {
        foreach ($this->createdIntegrationLogIds as $id) {
            $this->conn->execute('DELETE FROM integration_log WHERE id = ?', [$id]);
        }
        foreach ($this->createdAuditLogIds as $id) {
            $this->conn->execute('DELETE FROM sync_audit_log WHERE id = ?', [$id]);
        }
    }

    public function run(): void
    {
        // 1. Baseline: with no integration_log inbound entries, condition 1 fails.
        $report = $this->checker->check();
        $this->assertSame(false, $report['ready'], 'not ready with no Stage 1 history');
        $this->assertSame(false, $report['conditions']['stage1_stable']['passed'], 'cond1 fails: no inbound log');
        $this->assertSame(false, $report['conditions']['false_positive_rate']['passed'], 'cond2 fails: no auto_confirmed data');
        $this->assertSame(true, $report['conditions']['circuit_breaker_rate']['passed'], 'cond3 passes: 0 bad days < 5%');

        // 2. Insert a fake inbound log 95 days ago → condition 1 passes.
        $oldDate = date('Y-m-d H:i:s', strtotime('-95 days'));
        $id1 = $this->insertInboundLog($oldDate, 'success');
        $this->createdIntegrationLogIds[] = $id1;

        $report = $this->checker->check();
        $this->assertSame(true, $report['conditions']['stage1_stable']['passed'], 'cond1 passes: 95 days > 90');

        // 3. Insert 100 auto_confirmed + 1 operator_rejected (false positive).
        //    Rate = 1/100 = 1% > 0.1% → condition 2 fails.
        $appId = $this->ensureTestApplicant();
        for ($i = 0; $i < 100; $i++) {
            $this->createdAuditLogIds[] = $this->insertAuditLog($appId, 'auto_confirmed', 'auto_confirmed');
        }
        $this->createdAuditLogIds[] = $this->insertAuditLog($appId, 'operator_rejected', 'operator_rejected', '+1 hour');

        $report = $this->checker->check();
        $this->assertSame(false, $report['conditions']['false_positive_rate']['passed'], 'cond2 fails: 1% > 0.1%');
        $this->assertStringContainsString('1.0000%', $report['conditions']['false_positive_rate']['value'], 'rate value formatted');

        // 4. Insert 2 server_error days in last 30 days → 2/30 = 6.7% > 5% → fails.
        $id2 = $this->insertInboundLog(date('Y-m-d H:i:s', strtotime('-5 days')), 'server_error');
        $id3 = $this->insertInboundLog(date('Y-m-d H:i:s', strtotime('-10 days')), 'server_error');
        $this->createdIntegrationLogIds[] = $id2;
        $this->createdIntegrationLogIds[] = $id3;

        $report = $this->checker->check();
        $this->assertSame(false, $report['conditions']['circuit_breaker_rate']['passed'], 'cond3 fails: 6.7% > 5%');

        // 5. Clean the operator_rejected → rate drops to 0 → cond2 passes.
        $this->conn->execute('DELETE FROM sync_audit_log WHERE action = ?', ['operator_rejected']);
        $report = $this->checker->check();
        $this->assertSame(true, $report['conditions']['false_positive_rate']['passed'], 'cond2 passes after cleanup: 0% < 0.1%');

        // 6. Clean the server_error logs → cond3 passes (0 bad days).
        $this->conn->execute('DELETE FROM integration_log WHERE outcome = ? AND direction = ?', ['server_error', 'inbound']);
        $report = $this->checker->check();
        $this->assertSame(true, $report['conditions']['circuit_breaker_rate']['passed'], 'cond3 passes after cleanup: 0% < 5%');

        // 7. Verify the report structure.
        $this->assertArrayHasKey('ready', $report, 'report has ready key');
        $this->assertArrayHasKey('conditions', $report, 'report has conditions key');
        $this->assertArrayHasKey('summary', $report, 'report has summary key');
        $this->assertArrayHasKey('stage1_stable', $report['conditions'], 'cond1 present');
        $this->assertArrayHasKey('false_positive_rate', $report['conditions'], 'cond2 present');
        $this->assertArrayHasKey('circuit_breaker_rate', $report['conditions'], 'cond3 present');
        $this->assertArrayHasKey('contract_tests', $report['conditions'], 'cond4 present');

        // 8. Condition 4 (contract tests) — depends on whether tests/Contract/
        //    exists. Phase 8d creates it. For now, just verify the key exists
        //    and is a bool.
        $this->assertTrue(
            is_bool($report['conditions']['contract_tests']['passed']),
            'cond4 passed is a bool'
        );

        // 9. Clean up the test applicant + audit logs we created.
        $this->conn->execute('DELETE FROM sync_audit_log WHERE applicant_id = ?', [$appId]);
        $this->conn->execute("DELETE FROM applicants WHERE tracking_code = 'HG-READY-CHK'");
    }

    private function ensureTestApplicant(): int
    {
        $row = $this->conn->one("SELECT id FROM applicants WHERE tracking_code = 'HG-READY-CHK' LIMIT 1");
        if ($row !== null) {
            return (int)$row['id'];
        }
        $this->conn->execute(
            "INSERT INTO applicants (first_name, last_name, national_code, postal_code, mobile,
                tracking_code, current_status, license_state)
             VALUES ('تست', 'آمادگی', '0000000003', '0000000000', '09120000003',
                'HG-READY-CHK', 'صدور مجوز', 'جاری')"
        );
        return (int)$this->conn->pdo()->lastInsertId();
    }

    private function insertInboundLog(string $createdAt, string $outcome): int
    {
        $this->conn->execute(
            'INSERT INTO integration_log (direction, operation, idempotency_key, outcome, created_at)
             VALUES ("inbound", "fetch_license", ?, ?, ?)',
            ['inb_test_' . uniqid(), $outcome, $createdAt]
        );
        return (int)$this->conn->pdo()->lastInsertId();
    }

    private function insertAuditLog(int $applicantId, string $action, string $outcome, string $timeOffset = ''): int
    {
        $createdAt = $timeOffset !== '' ? date('Y-m-d H:i:s', strtotime($timeOffset)) : date('Y-m-d H:i:s');
        $this->conn->execute(
            'INSERT INTO sync_audit_log (applicant_id, action, outcome, confidence, created_at)
             VALUES (?, ?, ?, 95, ?)',
            [$applicantId, $action, $outcome, $createdAt]
        );
        return (int)$this->conn->pdo()->lastInsertId();
    }
}

return new Stage2ReadinessCheckerTest();
