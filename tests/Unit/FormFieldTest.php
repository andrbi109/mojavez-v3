<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Unit test for FormFieldController's CRUD happy-path (Phase 11).
 *
 * Inserts a fixture row in form_fields with a unique field_key
 * (test_ff_<rand>), reads it back via the Connection, edits it, deletes
 * it, and asserts the expected state at each step. Cleanup in tearDown
 * removes the fixture even if an assertion fails.
 *
 * This test exercises the Connection directly (not the controller's
 * Request/Response layer) — controller-level testing requires a session
 * + HTTP stack that's out of scope for v1.2's coverage goals. The
 * controller's logic is identical to the Connection calls below (same
 * prepared statements, same validate() rules).
 */
final class FormFieldTest extends TestCase
{
    private \App\Container $container;
    private \App\Shared\Persistence\Connection $conn;
    private string $fieldKey;
    private int $fieldId = 0;

    public function name(): string
    {
        return 'FormFieldTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/ff-test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $this->container = new \App\Container($env, $logger);
        $this->container->boot(dirname(__DIR__, 2));
        $this->conn = $this->container->make(\App\Shared\Persistence\Connection::class);

        // Clean leftovers from any prior run.
        $this->conn->execute("DELETE FROM form_fields WHERE field_key LIKE 'test_ff_%'");

        $this->fieldKey = 'test_ff_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    public function tearDown(): void
    {
        if ($this->fieldId !== 0) {
            $this->conn->execute('DELETE FROM form_fields WHERE id = ?', [$this->fieldId]);
        }
        $this->conn->execute("DELETE FROM form_fields WHERE field_key LIKE 'test_ff_%'");
    }

    public function run(): void
    {
        $this->testCreate();
        $this->testRead();
        $this->testUpdate();
        $this->testMove();
        $this->testDelete();
    }

    public function testCreate(): void
    {
        $this->fieldId = (int)$this->conn->insert(
            'INSERT INTO form_fields
                (wizard_step_id, service_type_id, field_key, field_label, field_type,
                 field_options, validation_rules, is_required, is_system, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                null, null,
                $this->fieldKey,
                'فیلد تست',
                'text',
                null, null,
                0, 0, 1,
                10,
            ]
        );
        $this->assertTrue($this->fieldId > 0, 'INSERT باید یک id مثبت برگرداند');
    }

    public function testRead(): void
    {
        if ($this->fieldId === 0) {
            $this->testCreate();
        }
        $row = $this->conn->one(
            'SELECT * FROM form_fields WHERE id = ?',
            [$this->fieldId]
        );
        $this->assertNotNull($row, 'row باید پیدا شود');
        $this->assertEquals($this->fieldKey, (string)$row['field_key'], 'field_key باید ذخیره شده باشد');
        $this->assertEquals('فیلد تست', (string)$row['field_label'], 'field_label باید ذخیره شده باشد');
        $this->assertEquals('text', (string)$row['field_type'], 'field_type باید text باشد');
        $this->assertEquals(10, (int)$row['sort_order'], 'sort_order باید ۱۰ باشد');
    }

    public function testUpdate(): void
    {
        if ($this->fieldId === 0) {
            $this->testCreate();
        }
        $this->conn->execute(
            'UPDATE form_fields SET field_label = ?, is_required = ?, validation_rules = ? WHERE id = ?',
            ['فیلد تست ویرایش‌شده', 1, '{"max_length":50}', $this->fieldId]
        );
        $row = $this->conn->one('SELECT field_label, is_required, validation_rules FROM form_fields WHERE id = ?', [$this->fieldId]);
        $this->assertNotNull($row, 'row باید پیدا شود');
        $this->assertEquals('فیلد تست ویرایش‌شده', (string)$row['field_label'], 'label باید آپدیت شود');
        $this->assertEquals(1, (int)$row['is_required'], 'is_required باید ۱ شود');
        $this->assertEquals('{"max_length":50}', (string)$row['validation_rules'], 'validation_rules باید JSON ذخیره شود');
    }

    public function testMove(): void
    {
        if ($this->fieldId === 0) {
            $this->testCreate();
        }
        // Create a second fixture to swap with.
        $otherKey = $this->fieldKey . '_other';
        $otherId = (int)$this->conn->insert(
            'INSERT INTO form_fields (field_key, field_label, field_type, sort_order, is_active) VALUES (?, ?, ?, ?, ?)',
            [$otherKey, 'دیگر', 'text', 20, 1]
        );
        try {
            // Swap sort_order.
            $this->conn->transactional(function () use ($otherId): void {
                $this->conn->execute('UPDATE form_fields SET sort_order = 20 WHERE id = ?', [$this->fieldId]);
                $this->conn->execute('UPDATE form_fields SET sort_order = 10 WHERE id = ?', [$otherId]);
            });
            $row1 = $this->conn->one('SELECT sort_order FROM form_fields WHERE id = ?', [$this->fieldId]);
            $row2 = $this->conn->one('SELECT sort_order FROM form_fields WHERE id = ?', [$otherId]);
            $this->assertEquals(20, (int)$row1['sort_order'], 'sort_order باید با ۲۰ جابجا شود');
            $this->assertEquals(10, (int)$row2['sort_order'], 'sort_order دیگر باید با ۱۰ جابجا شود');
        } finally {
            $this->conn->execute('DELETE FROM form_fields WHERE id = ?', [$otherId]);
        }
    }

    public function testDelete(): void
    {
        if ($this->fieldId === 0) {
            $this->testCreate();
        }
        $this->conn->execute('DELETE FROM form_fields WHERE id = ?', [$this->fieldId]);
        $row = $this->conn->one('SELECT id FROM form_fields WHERE id = ?', [$this->fieldId]);
        $this->assertNull($row, 'row باید پس از حذف null باشد');
        $this->fieldId = 0;  // tearDown won't double-delete
    }
}

return new FormFieldTest();
