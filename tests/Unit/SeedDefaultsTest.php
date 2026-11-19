<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Smoke test for the seed-defaults migration 009 (Phase 14 / D-2-9).
 *
 * Verifies that the migration file's INSERT statements, when run
 * against the test database, leave the expected 5 service_types rows
 * + 8 license_titles rows. Idempotency: re-running the migration (via
 * the MigrationRunner's checksum-skip) is a no-op.
 *
 * The test:
 *   1. Reads the migration file from disk and runs it via the
 *      MigrationRunner (or directly via PDO exec, since the runner
 *      records the checksum which would block the next test run).
 *      We use a direct exec + cleanup so the test is repeatable.
 *   2. Asserts 5 service_types rows exist with ids 1..5 and the
 *      expected Persian titles.
 *   3. Asserts 8 license_titles rows exist (3 + 2 + 1 + 1 + 1 per
 *      service_type).
 *   4. Idempotency: re-run the migration's INSERTs → no duplicate rows
 *      (the ON DUPLICATE KEY UPDATE / UNIQUE constraint catches them).
 *
 * Note: the test does NOT call the MigrationRunner because the runner
 * records a checksum — re-running the same migration file under the
 * same name would be skipped on the second test invocation (the test
 * would then pass trivially without actually exercising the SQL).
 * Instead we exec the SQL directly against the test DB. The migration
 * file is idempotent by construction (INSERT ... ON DUPLICATE KEY
 * UPDATE on UNIQUE constraints), so re-runs are safe.
 */
final class SeedDefaultsTest extends TestCase
{
    private \App\Shared\Persistence\Connection $conn;
    private string $migrationFile;

    public function name(): string
    {
        return 'SeedDefaultsTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/seed-test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $container = new \App\Container($env, $logger);
        $container->boot(dirname(__DIR__, 2));
        $this->conn = $container->make(\App\Shared\Persistence\Connection::class);
        $this->migrationFile = dirname(__DIR__, 2)
            . '/app/Modules/Core/migrations/20261117_009_seed_default_service_types.sql';
    }

    public function run(): void
    {
        // Precondition: the migration file must exist.
        $this->assertTrue(is_file($this->migrationFile), 'migration 009 file exists');

        // 1. Run the migration's SQL directly (split on `;\n` like the
        //    MigrationRunner does). Idempotent by construction.
        $sql = (string)file_get_contents($this->migrationFile);
        // Strip /* ... */ block comments and -- line comments.
        $sql = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;
        $sql = preg_replace('#--[^\n]*#', '', $sql) ?? $sql;
        $statements = array_filter(
            array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: []),
            static fn (string $s): bool => $s !== ''
        );
        $pdo = $this->conn->pdo();
        foreach ($statements as $stmt) {
            if ($stmt === '') {
                continue;
            }
            $stmtObj = $pdo->prepare($stmt);
            $stmtObj->execute();
            $stmtObj->closeCursor();
        }

        // 2. Assert 5 service_types rows with the expected titles.
        $serviceTypes = $this->conn->all(
            'SELECT id, title FROM service_types WHERE id IN (1, 2, 3, 4, 5) ORDER BY id'
        );
        $this->assertCount(5, $serviceTypes, '5 default service_types seeded (D-2-9)');

        $expectedTitles = [
            1 => 'صنایع دستی',
            2 => 'مشاغل خانگی',
            3 => 'صنایع غذایی خانگی',
            4 => 'خدمات فنی',
            5 => 'فرش و قالی',
        ];
        foreach ($serviceTypes as $st) {
            $id = (int)$st['id'];
            $this->assertSame($expectedTitles[$id], (string)$st['title'], "service_type {$id} has the expected Persian title");
        }

        // 3. Assert 8 license_titles rows (3 + 2 + 1 + 1 + 1 per service).
        $licenseTitlesCount = (int)$this->conn->scalar(
            'SELECT COUNT(*) FROM license_titles WHERE service_type_id IN (1, 2, 3, 4, 5)'
        );
        $this->assertSame(8, $licenseTitlesCount, '8 license_titles seeded across the 5 service_types');

        // 4. Spot-check a couple of license_title rows.
        $pottery = $this->conn->one(
            "SELECT * FROM license_titles WHERE service_type_id = 1 AND title = 'سفالگری' LIMIT 1"
        );
        $this->assertNotNull($pottery, 'license_title سفالگری exists for service_type 1');

        $mobile = $this->conn->one(
            "SELECT * FROM license_titles WHERE service_type_id = 4 AND title = 'تعمیرات موبایل' LIMIT 1"
        );
        $this->assertNotNull($mobile, 'license_title تعمیرات موبایل exists for service_type 4');

        // 5. Idempotency — re-run the migration's INSERTs → no duplicates.
        foreach ($statements as $stmt) {
            if ($stmt === '') {
                continue;
            }
            $stmtObj = $pdo->prepare($stmt);
            $stmtObj->execute();
            $stmtObj->closeCursor();
        }
        $serviceTypesAfterRerun = (int)$this->conn->scalar(
            'SELECT COUNT(*) FROM service_types WHERE id IN (1, 2, 3, 4, 5)'
        );
        $this->assertSame(5, $serviceTypesAfterRerun, 'idempotent: still 5 service_types after re-run');

        $licenseTitlesAfterRerun = (int)$this->conn->scalar(
            'SELECT COUNT(*) FROM license_titles WHERE service_type_id IN (1, 2, 3, 4, 5)'
        );
        $this->assertSame(8, $licenseTitlesAfterRerun, 'idempotent: still 8 license_titles after re-run');
    }
}

return new SeedDefaultsTest();
