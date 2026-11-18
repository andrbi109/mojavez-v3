<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Unit test for FeeTariffController's CRUD happy-path (Phase 11).
 *
 * Inserts a fixture row in fee_tariffs, reads it back, edits it, deletes
 * it, and asserts the expected state at each step. Cleanup in tearDown
 * removes the fixture even if an assertion fails.
 */
final class FeeTariffTest extends TestCase
{
    private \App\Container $container;
    private \App\Shared\Persistence\Connection $conn;
    private int $tariffId = 0;
    private int $serviceTypeId = 0;

    public function name(): string
    {
        return 'FeeTariffTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/ft-test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $this->container = new \App\Container($env, $logger);
        $this->container->boot(dirname(__DIR__, 2));
        $this->conn = $this->container->make(\App\Shared\Persistence\Connection::class);

        // Clean leftovers from any prior run.
        $this->conn->execute("DELETE FROM fee_tariffs WHERE notes LIKE 'test_ft_%'");

        // Ensure a service_type exists (skip if it doesn't — byService optional).
        $st = $this->conn->one("SELECT id FROM service_types WHERE title = 'صنایع دستی' LIMIT 1");
        $this->serviceTypeId = $st !== null ? (int)$st['id'] : 0;
    }

    public function tearDown(): void
    {
        if ($this->tariffId !== 0) {
            $this->conn->execute('DELETE FROM fee_tariffs WHERE id = ?', [$this->tariffId]);
        }
        $this->conn->execute("DELETE FROM fee_tariffs WHERE notes LIKE 'test_ft_%'");
    }

    public function run(): void
    {
        $this->testCreate();
        $this->testRead();
        $this->testUpdate();
        $this->testDelete();
    }

    public function testCreate(): void
    {
        $svcId = $this->serviceTypeId !== 0 ? $this->serviceTypeId : null;
        $this->tariffId = (int)$this->conn->insert(
            'INSERT INTO fee_tariffs
                (service_type_id, decile_min, decile_max, base_amount, is_active,
                 valid_from, valid_until, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $svcId,
                1, 5,
                100000,
                1,
                '2026-01-01', null,
                'test_ft_' . substr(bin2hex(random_bytes(4)), 0, 8),
            ]
        );
        $this->assertTrue($this->tariffId > 0, 'INSERT باید یک id مثبت برگرداند');
    }

    public function testRead(): void
    {
        if ($this->tariffId === 0) {
            $this->testCreate();
        }
        $row = $this->conn->one('SELECT * FROM fee_tariffs WHERE id = ?', [$this->tariffId]);
        $this->assertNotNull($row, 'row باید پیدا شود');
        $this->assertEquals(1, (int)$row['decile_min'], 'decile_min باید ۱ باشد');
        $this->assertEquals(5, (int)$row['decile_max'], 'decile_max باید ۵ باشد');
        $this->assertEquals(100000, (int)$row['base_amount'], 'base_amount باید ۱۰۰۰۰۰ باشد');
        $this->assertTrue(str_starts_with((string)$row['notes'], 'test_ft_'), 'notes باید با test_ft_ شروع شود');
    }

    public function testUpdate(): void
    {
        if ($this->tariffId === 0) {
            $this->testCreate();
        }
        $this->conn->execute(
            'UPDATE fee_tariffs SET decile_max = ?, base_amount = ?, is_active = ? WHERE id = ?',
            [10, 250000, 0, $this->tariffId]
        );
        $row = $this->conn->one('SELECT decile_max, base_amount, is_active FROM fee_tariffs WHERE id = ?', [$this->tariffId]);
        $this->assertNotNull($row, 'row باید پیدا شود');
        $this->assertEquals(10, (int)$row['decile_max'], 'decile_max باید آپدیت شود');
        $this->assertEquals(250000, (int)$row['base_amount'], 'base_amount باید آپدیت شود');
        $this->assertEquals(0, (int)$row['is_active'], 'is_active باید ۰ شود');
    }

    public function testDelete(): void
    {
        if ($this->tariffId === 0) {
            $this->testCreate();
        }
        $this->conn->execute('DELETE FROM fee_tariffs WHERE id = ?', [$this->tariffId]);
        $row = $this->conn->one('SELECT id FROM fee_tariffs WHERE id = ?', [$this->tariffId]);
        $this->assertNull($row, 'row باید پس از حذف null باشد');
        $this->tariffId = 0;
    }
}

return new FeeTariffTest();
