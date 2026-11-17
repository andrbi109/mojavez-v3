<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for scripts/migrate_from_gateway.php.
 *
 * Verifies the gateway (SQLite) → v3 (MySQL) migration:
 *   - licenses: raw_json parsed for masked_national_code, father_name, etc.
 *   - lookups: kind mapping (province, main_org→main_organization, sub_org→sub_organization)
 *   - filters: filter_json → filter_data
 *   - events: direct
 *   - settings: merge (only new keys)
 *   - admin_users → users (role=admin)
 *   - migration marker in sync_audit_log (outcome='gateway_import')
 *
 * This test builds a synthetic SQLite source DB, runs the migration, and
 * verifies the destination rows.
 */
final class MigrateFromGatewayTest extends TestCase
{
    private \App\Shared\Persistence\Connection $conn;
    private string $php = '/home/z/my-project/bin/php';
    private string $script;
    private string $envDbName;
    private string $sqlitePath = '/tmp/test_gateway_migratetest.db';

    public function name(): string
    {
        return 'MigrateFromGatewayTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/gw-migr.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $this->envDbName = $env->dbName;
        $container = new \App\Container($env, $logger);
        $container->boot(dirname(__DIR__, 2));
        $this->conn = $container->make(\App\Shared\Persistence\Connection::class);
        $this->script = dirname(__DIR__, 2) . '/scripts/migrate_from_gateway.php';

        // Phase 27 (v1.9.9): the migrate_from_gateway.php script imports
        // from a SQLite source DB whose schema lives in the v2 gateway
        // repo (`mojavez-gateway/app/Persistence/Migrations/001_init.sql`).
        // That source repo isn't available in every environment (only on
        // the developer's machine where v2 is cloned side-by-side). If
        // the source file is missing, skip cleanly with a clear reason
        // instead of crashing the whole test run with a PDO exception
        // (which previously counted as 1 test failure + a PHP warning).
        $gatewaySchema = '/home/z/my-project/src_repos/mojavez-gateway/app/Persistence/Migrations/001_init.sql';
        if (!is_file($gatewaySchema)) {
            $this->markTestSkipped('v2 gateway source not available (mojavez-gateway repo not cloned)');
            return;
        }

        // Clean any leftover gateway-imported test data.
        $this->cleanGatewayData();

        // Build the synthetic source SQLite.
        $this->buildSourceSqlite();
    }

    public function tearDown(): void
    {
        $this->cleanGatewayData();
        @unlink($this->sqlitePath);
    }

    public function run(): void
    {
        $dsn = 'mysql:unix_socket=/home/z/my-project/opt/mariadb/mysql.sock;dbname=' . $this->envDbName;
        // Run the migration.
        $cmd = sprintf(
            '%s %s --source-sqlite=%s 2>&1',
            escapeshellarg($this->php),
            escapeshellarg($this->script),
            escapeshellarg($this->sqlitePath)
        );
        $out = shell_exec($cmd);
        $this->assertStringContainsString('Migration complete', (string)$out, 'migration completed');
        $this->assertStringContainsString('gateway_import', (string)$out, 'marker outcome=gateway_import');

        // 1. licenses migrated with raw_json parsed into anchor columns.
        $lic = $this->conn->one("SELECT * FROM national_licenses WHERE request_number='GW-MIGRTEST01'");
        $this->assertNotNull($lic, 'license migrated');
        $this->assertSame('تست گیتوی', (string)$lic['applicant_name'], 'applicant_name');
        $this->assertSame('active', (string)$lic['status_slug'], 'status_slug');
        $this->assertSame('072****107', (string)$lic['masked_national_code'], 'masked_national_code parsed from raw_json');
        $this->assertSame('محمد', (string)$lic['father_name'], 'father_name parsed from raw_json');
        $this->assertSame('***** خیابان مطهری', (string)$lic['business_address'], 'business_address parsed from raw_json');

        // 2. lookups migrated with kind mapping.
        $prov = $this->conn->one("SELECT * FROM national_lookups WHERE kind='province' AND code='1'");
        $this->assertNotNull($prov, 'province lookup migrated');
        $this->assertSame('خراسان رضوی', (string)$prov['title'], 'province title');

        $mainOrg = $this->conn->one("SELECT * FROM national_lookups WHERE kind='main_organization' AND code='10'");
        $this->assertNotNull($mainOrg, 'main_org mapped to main_organization');
        $this->assertSame('سازمان بهداشت', (string)$mainOrg['title'], 'main_org title');

        $subOrg = $this->conn->one("SELECT * FROM national_lookups WHERE kind='sub_organization' AND code='100'");
        $this->assertNotNull($subOrg, 'sub_org mapped to sub_organization');
        $this->assertSame('10', (string)$subOrg['parent_code'], 'sub_org parent_code');

        // 3. filters migrated (filter_json → filter_data).
        $filter = $this->conn->one("SELECT * FROM national_filters WHERE label='گیتوی فیلتر تست'");
        $this->assertNotNull($filter, 'filter migrated');
        $filterData = json_decode((string)$filter['filter_data'], true);
        $this->assertSame('خراسان رضوی', $filterData['province'], 'filter_data parsed');

        // 4. events migrated.
        $eventCount = (int)$this->conn->scalar("SELECT COUNT(*) FROM events WHERE type='license_saved'");
        $this->assertGreaterThanOrEqual(1, $eventCount, 'events migrated');

        // 5. settings merged (only new keys).
        $gwSetting = $this->conn->one("SELECT * FROM settings WHERE setting_key='gw_unique_key'");
        $this->assertNotNull($gwSetting, 'gateway-only setting merged');
        $this->assertSame('gw_value', (string)$gwSetting['setting_value'], 'gw setting value');

        // 6. admin_users → users (role=admin).
        $user = $this->conn->one("SELECT * FROM users WHERE username='gw_admin_test'");
        $this->assertNotNull($user, 'admin_user migrated to users');
        $this->assertSame('admin', (string)$user['role'], 'migrated user role=admin');

        // 7. migration marker in sync_audit_log.
        $marker = $this->conn->one("SELECT * FROM sync_audit_log WHERE outcome='gateway_import' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($marker, 'gateway migration marker');
        $this->assertSame('migrated', (string)$marker['action'], 'marker action=migrated');

        // 8. Idempotency — re-run inserts 0 for licenses (INSERT IGNORE).
        $out2 = shell_exec($cmd);
        $this->assertStringContainsString('inserted: 0', (string)$out2, 'idempotent: re-run inserts 0 licenses');
    }

    private function buildSourceSqlite(): void
    {
        @unlink($this->sqlitePath);
        $pdo = new PDO('sqlite:' . $this->sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents('/home/z/my-project/src_repos/mojavez-gateway/app/Persistence/Migrations/001_init.sql');
        $pdo->exec($schema);

        $raw = json_encode([
            'national_id' => '072****107',
            'father_name' => 'محمد',
            'business_address' => '***** خیابان مطهری',
            'postal_code' => '9571968***',
        ], JSON_UNESCAPED_UNICODE);

        $pdo->prepare(
            'INSERT INTO licenses (request_number, applicant_name, license_title, organization_title, '
            . 'province_title, township_title, responded_at, status_slug, status_id, status_title, '
            . 'first_seen_at, last_seen_at, raw_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            'GW-MIGRTEST01', 'تست گیتوی', 'کسب و کار خانگی', 'سازمان بهداشت',
            'خراسان رضوی', 'تربت جام', '2026-11-15 10:00:00', 'active', '5', 'صادر شده',
            '2026-11-15 10:00:00', '2026-11-15 10:00:00', $raw,
        ]);

        $pdo->exec(
            'INSERT INTO lookups (kind, code, title, parent_code) VALUES '
            . "('province','1','خراسان رضوی',NULL),"
            . "('main_org','10','سازمان بهداشت',NULL),"
            . "('sub_org','100','سازمان بهداشت تربت جام','10')"
        );

        $filterJson = json_encode(['province' => 'خراسان رضوی'], JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO filters (label, filter_json, is_active) VALUES (?, ?, 1)')
            ->execute(['گیتوی فیلتر تست', $filterJson]);

        $pdo->prepare('INSERT INTO events (type, payload_json) VALUES (?, ?)')
            ->execute(['license_saved', json_encode(['rn' => 'GW-MIGRTEST01'], JSON_UNESCAPED_UNICODE)]);

        $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute(['gw_unique_key', 'gw_value']);

        $pdo->prepare('INSERT INTO admin_users (username, password_hash, is_active) VALUES (?, ?, 1)')
            ->execute(['gw_admin_test', '$2y$10$testhash']);
    }

    private function cleanGatewayData(): void
    {
        $this->conn->execute('SET FOREIGN_KEY_CHECKS = 0');
        $this->conn->execute("DELETE FROM national_licenses WHERE request_number='GW-TEST001' OR request_number='GW-MIGRTEST01'");
        $this->conn->execute("DELETE FROM national_lookups WHERE code IN ('1','10','100')");
        $this->conn->execute("DELETE FROM national_filters WHERE label IN ('تست فیلتر','گیتوی فیلتر تست')");
        $this->conn->execute("DELETE FROM users WHERE username IN ('gw_admin','gw_admin_test')");
        $this->conn->execute("DELETE FROM events WHERE type IN ('search_requested','license_saved') AND id > 0");
        $this->conn->execute("DELETE FROM settings WHERE setting_key='gw_unique_key'");
        $this->conn->execute("DELETE FROM sync_audit_log WHERE outcome IN ('gateway_import','v2_import')");
        $this->conn->execute('SET FOREIGN_KEY_CHECKS = 1');
    }
}

return new MigrateFromGatewayTest();
