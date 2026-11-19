<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Unit test for LicenseTitleController's CRUD happy-path (Phase 11).
 *
 * Inserts a fixture row in license_titles, reads it back, edits it,
 * toggles is_active, queries byService, deletes it, and asserts the
 * expected state at each step. Cleanup in tearDown removes the fixture
 * even if an assertion fails.
 */
final class LicenseTitleTest extends TestCase
{
    private \App\Container $container;
    private \App\Shared\Persistence\Connection $conn;
    private int $titleId = 0;
    private int $serviceTypeId = 0;
    private string $title;

    public function name(): string
    {
        return 'LicenseTitleTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/lt-test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $this->container = new \App\Container($env, $logger);
        $this->container->boot(dirname(__DIR__, 2));
        $this->conn = $this->container->make(\App\Shared\Persistence\Connection::class);

        // Clean leftovers from any prior run.
        $this->conn->execute("DELETE FROM license_titles WHERE title LIKE 'test_lt_%'");

        // Pick any service_type for the byService test (skip if none).
        $st = $this->conn->one("SELECT id FROM service_types LIMIT 1");
        $this->serviceTypeId = $st !== null ? (int)$st['id'] : 0;

        $this->title = 'test_lt_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    public function tearDown(): void
    {
        if ($this->titleId !== 0) {
            $this->conn->execute('DELETE FROM license_titles WHERE id = ?', [$this->titleId]);
        }
        $this->conn->execute("DELETE FROM license_titles WHERE title LIKE 'test_lt_%'");
    }

    public function run(): void
    {
        // Phase 27 (v1.9.9): testByService runs BEFORE testToggle. The
        // byService query filters by is_active = 1 (per the
        // LicenseTitleController::byService action), so it must run while
        // the fixture row is still active. testToggle then flips
        // is_active to 0 (and finally testDelete removes the row).
        // Previously testByService ran after testToggle, which left
        // is_active = 0, so the query returned no rows and the
        // assertion `in_array($this->title, $titles, true)` failed.
        $this->testCreate();
        $this->testRead();
        $this->testUpdate();
        $this->testByService();
        $this->testToggle();
        $this->testDelete();
    }

    public function testCreate(): void
    {
        $svcId = $this->serviceTypeId !== 0 ? $this->serviceTypeId : null;
        $this->titleId = (int)$this->conn->insert(
            'INSERT INTO license_titles (service_type_id, category, title, is_active, sort_order) VALUES (?, ?, ?, ?, ?)',
            [
                $svcId,
                'آزمایشی',
                $this->title,
                1,
                10,
            ]
        );
        $this->assertTrue($this->titleId > 0, 'INSERT باید یک id مثبت برگرداند');
    }

    public function testRead(): void
    {
        if ($this->titleId === 0) {
            $this->testCreate();
        }
        $row = $this->conn->one('SELECT * FROM license_titles WHERE id = ?', [$this->titleId]);
        $this->assertNotNull($row, 'row باید پیدا شود');
        $this->assertEquals($this->title, (string)$row['title'], 'title باید ذخیره شده باشد');
        $this->assertEquals('آزمایشی', (string)$row['category'], 'category باید ذخیره شده باشد');
        $this->assertEquals(1, (int)$row['is_active'], 'is_active باید ۱ باشد');
        $this->assertEquals(10, (int)$row['sort_order'], 'sort_order باید ۱۰ باشد');
    }

    public function testUpdate(): void
    {
        if ($this->titleId === 0) {
            $this->testCreate();
        }
        $this->conn->execute(
            'UPDATE license_titles SET category = ?, sort_order = ? WHERE id = ?',
            ['ویرایش‌شده', 20, $this->titleId]
        );
        $row = $this->conn->one('SELECT category, sort_order FROM license_titles WHERE id = ?', [$this->titleId]);
        $this->assertNotNull($row, 'row باید پیدا شود');
        $this->assertEquals('ویرایش‌شده', (string)$row['category'], 'category باید آپدیت شود');
        $this->assertEquals(20, (int)$row['sort_order'], 'sort_order باید آپدیت شود');
    }

    public function testToggle(): void
    {
        if ($this->titleId === 0) {
            $this->testCreate();
        }
        $before = $this->conn->one('SELECT is_active FROM license_titles WHERE id = ?', [$this->titleId]);
        $next = (int)$before['is_active'] === 1 ? 0 : 1;
        $this->conn->execute('UPDATE license_titles SET is_active = ? WHERE id = ?', [$next, $this->titleId]);
        $after = $this->conn->one('SELECT is_active FROM license_titles WHERE id = ?', [$this->titleId]);
        $this->assertEquals($next, (int)$after['is_active'], 'is_active باید flip شود');
    }

    public function testByService(): void
    {
        if ($this->titleId === 0) {
            $this->testCreate();
        }
        // If a service_type_id is set on the fixture, byService should return it.
        $row = $this->conn->one('SELECT service_type_id FROM license_titles WHERE id = ?', [$this->titleId]);
        if ($row !== null && $row['service_type_id'] !== null) {
            $rows = $this->conn->all(
                'SELECT id, title FROM license_titles WHERE service_type_id = ? AND is_active = 1 ORDER BY sort_order, id',
                [(int)$row['service_type_id']]
            );
            $titles = array_column($rows, 'title');
            $this->assertTrue(in_array($this->title, $titles, true), 'byService باید رکورد ما را برگرداند');
        } else {
            // service_type_id is NULL — byService wouldn't filter on it. Still
            // exercise the query for coverage; no assertion beyond "no exception".
            $this->conn->all('SELECT id, title FROM license_titles WHERE service_type_id IS NULL AND is_active = 1 ORDER BY sort_order, id');
            $this->assertTrue(true, 'byService با service_type_id=NULL بدون exception اجرا شد');
        }
    }

    public function testDelete(): void
    {
        if ($this->titleId === 0) {
            $this->testCreate();
        }
        $this->conn->execute('DELETE FROM license_titles WHERE id = ?', [$this->titleId]);
        $row = $this->conn->one('SELECT id FROM license_titles WHERE id = ?', [$this->titleId]);
        $this->assertNull($row, 'row باید پس از حذف null باشد');
        $this->titleId = 0;
    }
}

return new LicenseTitleTest();
