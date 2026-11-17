<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for NationalSyncOrchestrator Stage 2 push dedup set (D-8-3).
 *
 * Verifies the per-orchestrator-instance dedup set:
 *   - Calling pushStage2ForRows() with the same applicant_id twice in one
 *     cycle skips the second push + logs "already pushed in this cycle"
 *     (dedup_skipped increments).
 *   - Distinct applicant_ids are pushed normally.
 *
 * The dedup set is per-instance and reset by runCycle() — testing via the
 * extracted public pushStage2ForRows() method isolates the dedup behavior
 * from the SQL window (which can never return duplicate IDs since
 * applicants.id is the PK).
 */
final class NationalSyncOrchestratorDedupTest extends TestCase
{
    private \App\Container $container;
    private \App\Shared\Persistence\Connection $conn;
    private \App\Modules\NationalGateway\Services\NationalSyncOrchestrator $orchestrator;
    private \App\Modules\NationalGateway\Services\OutboundSyncService $outbound;
    private int $applicantIdA = 0;
    private int $applicantIdB = 0;
    private array $createdLogIds = [];
    private array $settingsBackup = [];

    public function name(): string
    {
        return 'NationalSyncOrchestratorDedupTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(
            dirname(__DIR__, 2) . '/storage/logs/test.log',
            'debug'
        );
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $this->container = new \App\Container($env, $logger);
        $this->container->boot(dirname(__DIR__, 2));
        $this->conn = $this->container->make(\App\Shared\Persistence\Connection::class);

        $this->outbound = $this->container->make(
            \App\Modules\NationalGateway\Services\OutboundSyncService::class
        );
        // Build the orchestrator with the same outbound service so the
        // pushStage2ForRows() path actually executes pushes.
        $this->orchestrator = new \App\Modules\NationalGateway\Services\NationalSyncOrchestrator(
            $this->container->make(\App\Modules\NationalGateway\Services\LicenseService::class),
            $this->container->make(\App\Modules\NationalGateway\Services\ApplicantSyncAdapter::class),
            $this->container->make(\App\Shared\Contracts\NationalLicenseRepositoryInterface::class),
            $this->container->make(\App\Shared\Contracts\SyncAuditLogRepositoryInterface::class),
            $this->container->make(\App\Events\EventBus::class),
            $this->container->make(\App\Shared\Support\StructuredLogger::class),
            $this->conn,
            $this->outbound,
        );

        $this->settingsBackup['sync_two_way_enabled'] = $this->readSetting('sync_two_way_enabled');
        $this->settingsBackup['sync_direction'] = $this->readSetting('sync_direction');
        $this->settingsBackup['outbound_failure_streak'] = $this->readSetting('outbound_failure_streak');
        $this->upsertSetting('sync_two_way_enabled', '1');
        $this->upsertSetting('sync_direction', 'two_way');
        $this->upsertSetting('outbound_failure_streak', '0');

        // Clean up any leftover rows from a previous failed run (different
        // test runs may have used the same tracking codes / request numbers
        // with the old single-license setup). This is idempotent.
        $this->conn->execute("DELETE FROM integration_log WHERE applicant_id IN (SELECT id FROM applicants WHERE tracking_code IN ('HG-DEDUP-A', 'HG-DEDUP-B'))");
        $this->conn->execute("DELETE FROM applicants WHERE tracking_code IN ('HG-DEDUP-A', 'HG-DEDUP-B')");
        $this->conn->execute("DELETE FROM national_licenses WHERE request_number IN ('TEST-DEDUP-A', 'TEST-DEDUP-B', 'TEST-DEDUP-001')");

        // Ensure each applicant is matched to a DISTINCT national license
        // (there is a UNIQUE constraint on
        // applicants.national_license_request_number — both applicants
        // can't share the same request_number).
        $this->ensureNationalLicense('TEST-DEDUP-A');
        $this->ensureNationalLicense('TEST-DEDUP-B');

        $this->applicantIdA = $this->ensureTestApplicant('HG-DEDUP-A', '0000000042', 'TEST-DEDUP-A');
        $this->applicantIdB = $this->ensureTestApplicant('HG-DEDUP-B', '0000000043', 'TEST-DEDUP-B');
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
        if ($this->applicantIdA > 0) {
            $this->conn->execute('DELETE FROM integration_log WHERE applicant_id = ?', [$this->applicantIdA]);
            $this->conn->execute('DELETE FROM applicants WHERE id = ?', [$this->applicantIdA]);
        }
        if ($this->applicantIdB > 0) {
            $this->conn->execute('DELETE FROM integration_log WHERE applicant_id = ?', [$this->applicantIdB]);
            $this->conn->execute('DELETE FROM applicants WHERE id = ?', [$this->applicantIdB]);
        }
        $this->conn->execute("DELETE FROM national_licenses WHERE request_number IN ('TEST-DEDUP-A', 'TEST-DEDUP-B')");
    }

    public function run(): void
    {
        // 1. Two DISTINCT applicants → both pushed, dedup_skipped=0.
        $rows = [
            ['id' => $this->applicantIdA, 'current_status' => 'تأیید اولیه'],
            ['id' => $this->applicantIdB, 'current_status' => 'تأیید اولیه'],
        ];
        $summary = $this->orchestrator->pushStage2ForRows($rows);
        $this->assertGreaterThanOrEqual(
            2,
            $summary['pushed'] + $summary['skipped'] + $summary['conflicts'],
            'both distinct applicants attempted (pushed/skipped/conflicts)'
        );
        $this->assertSame(0, $summary['dedup_skipped'], 'no dedup skipped on distinct IDs');

        // Collect log IDs for cleanup. Both applicants should have a fresh
        // integration_log entry from this push attempt.
        foreach ([$this->applicantIdA, $this->applicantIdB] as $appId) {
            $logRow = $this->conn->one(
                'SELECT id FROM integration_log WHERE applicant_id = ? ORDER BY id DESC LIMIT 1',
                [$appId]
            );
            if ($logRow !== null) {
                $this->createdLogIds[] = (int)$logRow['id'];
            }
        }

        // 2. SAME applicant repeated twice in one call → dedup_skipped=1,
        //    pushed+skipped+conflicts should reflect only ONE push for that
        //    applicant. Build rows on a fresh orchestrator instance so the
        //    dedup set starts empty (production runCycle() resets it).
        $freshOrchestrator = new \App\Modules\NationalGateway\Services\NationalSyncOrchestrator(
            $this->container->make(\App\Modules\NationalGateway\Services\LicenseService::class),
            $this->container->make(\App\Modules\NationalGateway\Services\ApplicantSyncAdapter::class),
            $this->container->make(\App\Shared\Contracts\NationalLicenseRepositoryInterface::class),
            $this->container->make(\App\Shared\Contracts\SyncAuditLogRepositoryInterface::class),
            $this->container->make(\App\Events\EventBus::class),
            $this->container->make(\App\Shared\Support\StructuredLogger::class),
            $this->conn,
            $this->outbound,
        );
        // Use applicantIdB with a NEW status (different idempotency key) so
        // the second push would normally trigger a fresh log entry — except
        // the dedup set catches it before OutboundSyncService is reached.
        $rowsDup = [
            ['id' => $this->applicantIdB, 'current_status' => 'تأیید نهایی'],
            ['id' => $this->applicantIdB, 'current_status' => 'تأیید نهایی'],
        ];
        $summaryDup = $freshOrchestrator->pushStage2ForRows($rowsDup);
        $this->assertSame(1, $summaryDup['dedup_skipped'], 'duplicate applicant deduped to 1 skip');
        // Only one push for the duplicated applicant. The push could land
        // in any of the three buckets (pushed / skipped / conflicts) —
        // dedup means the second iteration was skipped, so the sum of
        // (pushed + skipped + conflicts) is at most 1.
        $attemptedOnce = $summaryDup['pushed'] + $summaryDup['skipped'] + $summaryDup['conflicts'];
        $this->assertTrue(
            $attemptedOnce <= 1,
            'only one push for the duplicated applicant (got: '
            . $summaryDup['pushed'] . ' pushed, '
            . $summaryDup['skipped'] . ' skipped, '
            . $summaryDup['conflicts'] . ' conflicts)'
        );

        // Collect the log id for the one push that did happen.
        $logRow = $this->conn->one(
            'SELECT id FROM integration_log WHERE applicant_id = ? ORDER BY id DESC LIMIT 1',
            [$this->applicantIdB]
        );
        if ($logRow !== null) {
            $this->createdLogIds[] = (int)$logRow['id'];
        }

        // 3. Mix: one new applicant + one already-pushed (in this same
        //    pushStage2ForRows call) — the new one is pushed, the duplicate
        //    is deduped. We re-use the SAME orchestrator instance ($fresh)
        //    so the dedup set carries the entry from step 2.
        $rowsMixed = [
            ['id' => $this->applicantIdA, 'current_status' => 'تأیید نهایی'],
            ['id' => $this->applicantIdB, 'current_status' => 'تأیید نهایی'],
        ];
        $summaryMixed = $freshOrchestrator->pushStage2ForRows($rowsMixed);
        // applicantIdB is already in the dedup set from step 2 → dedup_skipped=1.
        // applicantIdA is new (in this fresh orchestrator) → 1 push attempt.
        $this->assertSame(1, $summaryMixed['dedup_skipped'], 'second-row applicant deduped');
        $this->assertGreaterThanOrEqual(
            1,
            $summaryMixed['pushed'] + $summaryMixed['skipped'] + $summaryMixed['conflicts'],
            'first-row applicant pushed (or skipped/conflicted)'
        );
        $logRow = $this->conn->one(
            'SELECT id FROM integration_log WHERE applicant_id = ? ORDER BY id DESC LIMIT 1',
            [$this->applicantIdA]
        );
        if ($logRow !== null) {
            $this->createdLogIds[] = (int)$logRow['id'];
        }
    }

    private function ensureNationalLicense(string $requestNumber): void
    {
        $exists = $this->conn->one(
            'SELECT id FROM national_licenses WHERE request_number = ?',
            [$requestNumber]
        );
        if ($exists === null) {
            $this->conn->execute(
                "INSERT INTO national_licenses (request_number, applicant_name, license_title,
                    organization_title, province_title, status_slug, masked_national_code)
                 VALUES (?, 'تست ددپ', 'صنایع دستی', 'سازمان میراث', 'تهران', 'active', '00******4242')",
                [$requestNumber]
            );
        }
    }

    private function ensureTestApplicant(string $trackingCode, string $nationalCode, string $requestNumber): int
    {
        $row = $this->conn->one(
            'SELECT id FROM applicants WHERE tracking_code = ? LIMIT 1',
            [$trackingCode]
        );
        if ($row !== null) {
            return (int)$row['id'];
        }
        $this->conn->execute(
            "INSERT INTO applicants (first_name, last_name, national_code, postal_code, mobile,
                tracking_code, current_status, license_state, national_license_request_number, matched_at, match_confidence)
             VALUES ('تست', 'ددپ', ?, '0000000000', '09120000999',
                ?, 'صدور مجوز', 'جاری', ?, NOW(), 95)",
            [$nationalCode, $trackingCode, $requestNumber]
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

return new NationalSyncOrchestratorDedupTest();
