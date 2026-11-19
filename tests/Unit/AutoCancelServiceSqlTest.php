<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Unit test for AutoCancelService::updatePriorities — Phase 13 (v1.4.0).
 *
 * Verifies the new SQL-based implementation
 * (`ApplicantRepositoryInterface::bulkSetPriorityForPreIssueOlderThan`)
 * correctly sets `priority = 1` only for applicants that match ALL of:
 *   - registration_completed_at < NOW() - PRIORITY_DAYS (12) days
 *   - current_status IN (pre-issue statuses)
 *   - priority = 0 (skip already-prioritised)
 *   - deleted_at IS NULL
 *   - registration_completed_at IS NOT NULL
 *
 * Test setup creates 5 applicants covering the boundary cases:
 *   A. registration_completed_at 13 days ago + pre-issue + priority=0
 *      → SHOULD be prioritised.
 *   B. registration_completed_at 11 days ago + pre-issue + priority=0
 *      → should NOT (not yet 12 days old).
 *   C. registration_completed_at 13 days ago + post-issue + priority=0
 *      → should NOT (not pre-issue).
 *   D. registration_completed_at 13 days ago + pre-issue + priority=1
 *      → should NOT (already prioritised).
 *   E. registration_completed_at IS NULL + pre-issue + priority=0
 *      → should NOT (never completed registration).
 *
 * Before Phase 13, this test would have produced the same assertions
 * (the old PHP-loop version had the same semantics), but the loop was
 * capped at 1000 rows via paginate() and ran O(N) UPDATE statements.
 * Phase 13 rewrites it as a single SQL UPDATE — same semantics, faster.
 *
 * Test environment: requires a real DB-backed Container. If the DB or
 * Container is unavailable, the test is skipped (same pattern as
 * NotificationServiceTest).
 */
final class AutoCancelServiceSqlTest extends TestCase
{
    private \App\Container $container;
    private \App\Shared\Persistence\Connection $conn;
    private \App\Modules\Core\Services\AutoCancelService $service;
    /** @var array<int,int> */
    private array $applicantIds = [];
    private int $personId = 0;

    /** Test tracking-code prefix — used to clean up leftovers in setUp/tearDown. */
    private const TC_PREFIX = 'ACS-P13-';

    public function name(): string
    {
        return 'AutoCancelServiceSqlTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(
            sys_get_temp_dir() . '/autocancel-sql-test.log',
            'debug'
        );
        try {
            $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
            $this->container = new \App\Container($env, $logger);
            $this->container->boot(dirname(__DIR__, 2));
            $this->conn = $this->container->make(\App\Shared\Persistence\Connection::class);
            $this->service = $this->container->make(\App\Modules\Core\Services\AutoCancelService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB or container unavailable: ' . $e->getMessage());
            return;
        }

        // Clean leftovers from prior runs.
        try {
            $this->conn->execute(
                "DELETE FROM applicants WHERE tracking_code LIKE ?",
                [self::TC_PREFIX . '%']
            );
            $this->conn->execute(
                "DELETE FROM persons WHERE national_code LIKE ?",
                ['ACS-P13-%']
            );
        } catch (\Throwable) {
            // ignore — best-effort
        }

        // Create a person row to satisfy the FK on applicants.person_id.
        // (person_id is nullable, but we set one to exercise the FK path.)
        $this->personId = (int)$this->conn->insert(
            'INSERT INTO persons (national_code, first_name, last_name, mobile, person_type, father_name, postal_code, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            ['ACS-P13-0', 'تست', 'اتوکانسل', '09129990200', 'حقیقی', 'محمد', '9571968999']
        );

        // 5 applicants covering the boundary cases.
        // registration_completed_at values are anchored to "now" so the
        // test is robust to when CI runs it.
        $now = date('Y-m-d H:i:s');
        $oldCutoff = date('Y-m-d H:i:s', strtotime('-13 days'));   // older than 12-day window
        $recentCutoff = date('Y-m-d H:i:s', strtotime('-11 days')); // newer than 12-day window

        // A. 13 days old + pre-issue (IssueRequest) + priority=0 → WILL be prioritised.
        $this->applicantIds['A_eligible'] = (int)$this->conn->insert(
            'INSERT INTO applicants (person_id, tracking_code, national_code, current_status, license_state, '
            . 'first_name, last_name, mobile, postal_code, priority, registration_completed_at, registration_started_at, version, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 1, ?)',
            [$this->personId, self::TC_PREFIX . 'A', 'ACS-P13-A', 'ثبت درخواست صدور', 'جاری', 'A', 'تست', '09129990201', '9571968999', $oldCutoff, $oldCutoff, $now]
        );

        // B. 11 days old + pre-issue + priority=0 → NOT prioritised (not yet 12 days).
        $this->applicantIds['B_too_recent'] = (int)$this->conn->insert(
            'INSERT INTO applicants (person_id, tracking_code, national_code, current_status, license_state, '
            . 'first_name, last_name, mobile, postal_code, priority, registration_completed_at, registration_started_at, version, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 1, ?)',
            [$this->personId, self::TC_PREFIX . 'B', 'ACS-P13-B', 'ثبت درخواست صدور', 'جاری', 'B', 'تست', '09129990202', '9571968999', $recentCutoff, $recentCutoff, $now]
        );

        // C. 13 days old + post-issue (LicenseIssuance) + priority=0 → NOT prioritised (not pre-issue).
        $this->applicantIds['C_post_issue'] = (int)$this->conn->insert(
            'INSERT INTO applicants (person_id, tracking_code, national_code, current_status, license_state, '
            . 'first_name, last_name, mobile, postal_code, priority, registration_completed_at, registration_started_at, version, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 1, ?)',
            [$this->personId, self::TC_PREFIX . 'C', 'ACS-P13-C', 'صدور مجوز', 'جاری', 'C', 'تست', '09129990203', '9571968999', $oldCutoff, $oldCutoff, $now]
        );

        // D. 13 days old + pre-issue + priority=1 → NOT prioritised (already).
        $this->applicantIds['D_already'] = (int)$this->conn->insert(
            'INSERT INTO applicants (person_id, tracking_code, national_code, current_status, license_state, '
            . 'first_name, last_name, mobile, postal_code, priority, registration_completed_at, registration_started_at, version, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 1, ?)',
            [$this->personId, self::TC_PREFIX . 'D', 'ACS-P13-D', 'ثبت درخواست صدور', 'جاری', 'D', 'تست', '09129990204', '9571968999', $oldCutoff, $oldCutoff, $now]
        );

        // E. registration_completed_at NULL + pre-issue + priority=0 → NOT prioritised (never completed).
        $this->applicantIds['E_null_completed'] = (int)$this->conn->insert(
            'INSERT INTO applicants (person_id, tracking_code, national_code, current_status, license_state, '
            . 'first_name, last_name, mobile, postal_code, priority, registration_started_at, version, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 1, ?)',
            [$this->personId, self::TC_PREFIX . 'E', 'ACS-P13-E', 'ثبت درخواست صدور', 'جاری', 'E', 'تست', '09129990205', '9571968999', $oldCutoff, $now]
        );
    }

    public function tearDown(): void
    {
        try {
            $this->conn->execute(
                "DELETE FROM applicants WHERE tracking_code LIKE ?",
                [self::TC_PREFIX . '%']
            );
            $this->conn->execute(
                "DELETE FROM persons WHERE national_code LIKE ?",
                ['ACS-P13-%']
            );
        } catch (\Throwable) {
            // ignore — best-effort
        }
    }

    public function run(): void
    {
        // Run updatePriorities() — should bulk-UPDATE only applicant A.
        $count = $this->service->updatePriorities();
        $this->assertGreaterThanOrEqual(1, $count, 'at least 1 applicant (A) was prioritised');

        // Re-read each applicant's priority from the DB.
        $priorities = [];
        foreach ($this->applicantIds as $label => $id) {
            $row = $this->conn->one('SELECT priority FROM applicants WHERE id = ?', [$id]);
            $priorities[$label] = $row === null ? null : (int)$row['priority'];
        }

        // A. eligible → priority = 1.
        $this->assertSame(1, $priorities['A_eligible'], 'A (13d + pre-issue + priority=0) → priority=1');

        // B. too recent → priority = 0 (unchanged).
        $this->assertSame(0, $priorities['B_too_recent'], 'B (11d + pre-issue) → priority stays 0 (not yet 12 days)');

        // C. post-issue → priority = 0 (unchanged).
        $this->assertSame(0, $priorities['C_post_issue'], 'C (13d + post-issue) → priority stays 0 (not pre-issue)');

        // D. already prioritised → priority = 1 (unchanged — bulk SQL sets priority=1 only where priority=0).
        $this->assertSame(1, $priorities['D_already'], 'D (already priority=1) → priority stays 1 (idempotent)');

        // E. registration_completed_at NULL → priority = 0 (unchanged).
        $this->assertSame(0, $priorities['E_null_completed'], 'E (registration_completed_at NULL) → priority stays 0 (never completed)');

        // Idempotency: re-running updatePriorities() should be a no-op
        // (A is already priority=1; the WHERE clause excludes priority=0).
        $count2 = $this->service->updatePriorities();
        $this->assertSame(0, $count2, 'idempotent: re-run finds nothing new to prioritise');
    }
}

return new AutoCancelServiceSqlTest();
