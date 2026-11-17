<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for scripts/migrate_from_v2.php.
 *
 * Verifies the migration script correctly copies data from a v2 source
 * (simulated via the v2_src_ prefixed tables in the same database) into
 * the v3 tables, including:
 *   - persons with father_name (golden-link anchor)
 *   - applicants with current_status (Persian enum values preserved)
 *   - status_log with triggered_by default ('admin')
 *   - idempotency (re-run doesn't duplicate)
 *   - migration marker in sync_audit_log
 *
 * This test runs the actual migrate_from_v2.php script via shell_exec,
 * against the test database with the v2_src_ prefix.
 */
final class MigrateFromV2Test extends TestCase
{
    private \App\Shared\Persistence\Connection $conn;
    private string $php = '/home/z/my-project/bin/php';
    private string $script;
    private string $dsn;
    private string $user = 'mojavez';
    private string $pass = 'mojavez_pass';
    /** @var list<int> */
    private array $createdApplicantIds = [];
    /** @var list<int> */
    private array $createdPersonIds = [];

    public function name(): string
    {
        return 'MigrateFromV2Test';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/migrate-test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $container = new \App\Container($env, $logger);
        $container->boot(dirname(__DIR__, 2));
        $this->conn = $container->make(\App\Shared\Persistence\Connection::class);
        $this->script = dirname(__DIR__, 2) . '/scripts/migrate_from_v2.php';
        $this->dsn = 'mysql:unix_socket=/home/z/my-project/opt/mariadb/mysql.sock;dbname=' . $env->dbName;

        // Ensure v2_src_ tables exist (created by tests/setup_v2_source.php).
        $this->ensureV2SourceSchema();
    }

    public function tearDown(): void
    {
        // Clean up the migrated rows so the test is repeatable.
        $this->conn->execute('SET FOREIGN_KEY_CHECKS = 0');
        if ($this->createdApplicantIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->createdApplicantIds), '?'));
            $this->conn->execute(
                "DELETE FROM status_log WHERE applicant_id IN ($placeholders)",
                $this->createdApplicantIds
            );
            $this->conn->execute(
                "DELETE FROM applicants WHERE id IN ($placeholders)",
                $this->createdApplicantIds
            );
        }
        if ($this->createdPersonIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->createdPersonIds), '?'));
            $this->conn->execute(
                "DELETE FROM persons WHERE id IN ($placeholders)",
                $this->createdPersonIds
            );
        }
        $this->conn->execute("DELETE FROM sync_audit_log WHERE action='migrated'");
        // Clean v2_src_ test data.
        $this->conn->execute("DELETE FROM v2_src_status_log WHERE applicant_id IN (9001, 9002)");
        $this->conn->execute("DELETE FROM v2_src_applicants WHERE id IN (9001, 9002)");
        $this->conn->execute("DELETE FROM v2_src_persons WHERE id IN (9001, 9002)");
        $this->conn->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function run(): void
    {
        // 1. Insert test data into v2_src_ tables.
        $this->conn->execute('SET FOREIGN_KEY_CHECKS = 0');
        $this->conn->execute("DELETE FROM v2_src_status_log WHERE applicant_id IN (9001, 9002)");
        $this->conn->execute("DELETE FROM v2_src_applicants WHERE id IN (9001, 9002)");
        $this->conn->execute("DELETE FROM v2_src_persons WHERE id IN (9001, 9002)");
        $this->conn->execute("DELETE FROM persons WHERE id IN (9001, 9002)");
        $this->conn->execute("DELETE FROM applicants WHERE id IN (9001, 9002)");
        $this->conn->execute("DELETE FROM status_log WHERE applicant_id IN (9001, 9002)");

        $this->conn->execute(
            "INSERT INTO v2_src_persons (id, national_code, first_name, last_name, father_name, mobile, person_type, postal_code, created_at) "
            . "VALUES (9001, '0720599991', 'تست', 'مهاجرت', 'پدرتست', '09150000091', 'حقیقی', '9571968191', NOW()), "
            . "(9002, '0720599992', 'تست2', 'مهاجرت2', 'پدرتست2', '09150000092', 'حقیقی', '9571968192', NOW())"
        );

        $this->conn->execute(
            "INSERT INTO v2_src_applicants (id, person_id, first_name, last_name, national_code, postal_code, mobile, tracking_code, father_name, current_status, license_state, priority, registration_started_at, created_at, updated_at) "
            . "VALUES (9001, 9001, 'تست', 'مهاجرت', '0720599991', '9571968191', '09150000091', 'HG-MIGR001', 'پدرتست', 'ثبت درخواست صدور', 'جاری', 0, NOW(), NOW(), NOW()), "
            . "(9002, 9002, 'تست2', 'مهاجرت2', '0720599992', '9571968192', '09150000092', 'HG-MIGR002', 'پدرتست2', 'تأیید نهایی', 'صادر شده', 1, NOW(), NOW(), NOW())"
        );

        $this->conn->execute(
            "INSERT INTO v2_src_status_log (applicant_id, status_name, description, created_at) VALUES "
            . "(9001, 'پیش‌نویس', 'ایجاد', NOW()), "
            . "(9001, 'ثبت درخواست صدور', 'ارسال', NOW()), "
            . "(9002, 'تأیید نهایی', 'تأیید', NOW())"
        );
        $this->conn->execute('SET FOREIGN_KEY_CHECKS = 1');

        // 2. Run the migration script.
        $cmd = sprintf(
            '%s %s --source-dsn=%s --source-user=%s --source-pass=%s --source-prefix=v2_src_ --table=persons 2>&1',
            escapeshellarg($this->php),
            escapeshellarg($this->script),
            escapeshellarg($this->dsn),
            escapeshellarg($this->user),
            escapeshellarg($this->pass)
        );
        $out = shell_exec($cmd);
        $this->assertStringContainsString('inserted:', (string)$out, 'persons migration output has inserted count');

        $cmd = str_replace('--table=persons', '--table=applicants', $cmd);
        $out = shell_exec($cmd);
        $this->assertStringContainsString('inserted:', (string)$out, 'applicants migration output has inserted count');

        $cmd = str_replace('--table=applicants', '--table=status_log', $cmd);
        $out = shell_exec($cmd);
        $this->assertStringContainsString('inserted:', (string)$out, 'status_log migration output has inserted count');

        $this->createdPersonIds = [9001, 9002];
        $this->createdApplicantIds = [9001, 9002];

        // 3. Verify persons migrated with father_name.
        $p1 = $this->conn->one('SELECT * FROM persons WHERE id = 9001');
        $this->assertNotNull($p1, 'person 9001 migrated');
        $this->assertSame('0720599991', (string)$p1['national_code'], 'person national_code');
        $this->assertSame('پدرتست', (string)$p1['father_name'], 'person father_name (golden-link anchor)');

        // 4. Verify applicants migrated with Persian status preserved.
        $a1 = $this->conn->one('SELECT * FROM applicants WHERE id = 9001');
        $this->assertNotNull($a1, 'applicant 9001 migrated');
        $this->assertSame('HG-MIGR001', (string)$a1['tracking_code'], 'applicant tracking_code');
        $this->assertSame('ثبت درخواست صدور', (string)$a1['current_status'], 'applicant current_status (Persian preserved)');
        $this->assertSame('پدرتست', (string)$a1['father_name'], 'applicant father_name (denormalized)');

        $a2 = $this->conn->one('SELECT * FROM applicants WHERE id = 9002');
        $this->assertSame('تأیید نهایی', (string)$a2['current_status'], 'applicant 2 status');

        // 5. Verify status_log migrated with triggered_by default.
        $logs = $this->conn->all('SELECT * FROM status_log WHERE applicant_id = 9001 ORDER BY id');
        $this->assertCount(2, $logs, '2 status_log entries for applicant 9001');
        foreach ($logs as $log) {
            $this->assertSame('admin', (string)$log['triggered_by'], 'status_log triggered_by defaults to admin');
        }

        // 6. Verify migration marker in sync_audit_log.
        $marker = $this->conn->one("SELECT * FROM sync_audit_log WHERE action='migrated' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($marker, 'migration marker inserted');
        $this->assertSame('v2_import', (string)$marker['outcome'], 'marker outcome=v2_import');

        // 7. Idempotency — re-run should insert 0 (INSERT IGNORE).
        $cmd = sprintf(
            '%s %s --source-dsn=%s --source-user=%s --source-pass=%s --source-prefix=v2_src_ --table=persons 2>&1',
            escapeshellarg($this->php),
            escapeshellarg($this->script),
            escapeshellarg($this->dsn),
            escapeshellarg($this->user),
            escapeshellarg($this->pass)
        );
        $out = shell_exec($cmd);
        $this->assertStringContainsString('inserted: 0', (string)$out, 'idempotent: re-run inserts 0');

        // 8. dry-run mode doesn't write.
        $beforeCount = (int)$this->conn->scalar("SELECT COUNT(*) FROM sync_audit_log WHERE action='migrated'");
        $cmd = sprintf(
            '%s %s --source-dsn=%s --source-user=%s --source-pass=%s --source-prefix=v2_src_ --table=persons --dry-run 2>&1',
            escapeshellarg($this->php),
            escapeshellarg($this->script),
            escapeshellarg($this->dsn),
            escapeshellarg($this->user),
            escapeshellarg($this->pass)
        );
        $out = shell_exec($cmd);
        $this->assertStringContainsString('dry-run', (string)$out, 'dry-run output');
        $afterCount = (int)$this->conn->scalar("SELECT COUNT(*) FROM sync_audit_log WHERE action='migrated'");
        $this->assertSame($beforeCount, $afterCount, 'dry-run did not insert marker');
    }

    /**
     * Ensure the v2_src_ source tables exist (created once by the test setup).
     * If they don't exist, skip the test.
     */
    private function ensureV2SourceSchema(): void
    {
        $exists = $this->conn->one("SHOW TABLES LIKE 'v2_src_persons'");
        if ($exists === null) {
            $this->markTestSkipped('v2_src_ source tables not present (run tests/setup_v2_source.php first)');
        }
    }
}

return new MigrateFromV2Test();
