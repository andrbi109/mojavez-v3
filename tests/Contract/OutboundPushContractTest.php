<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Contract test: OutboundPushContractTest.
 *
 * Verifies the OUTBOUND sync contract per docs/00-MASTER.md §29.1 (Stage 2):
 *   - Payload shape: push_status + push_document carry the agreed fields.
 *   - Idempotency: deterministic key derived from (operation, applicant_id,
 *     payload_hash). Same push twice → same key → second is a no-op.
 *   - integration_log audit fields: direction='outbound', operation matches,
 *     outcome ∈ {success, skipped, conflict, client_error, server_error, timeout},
 *     source_of_truth ∈ {v3, upstream, null}, conflict_resolution ∈
 *     {last_write_wins, v3_wins, upstream_wins, manual_review, null}.
 *   - Conflict resolution: when upstream_updated_at > v3 updated_at, push is
 *     'conflict' with source_of_truth='upstream',
 *     conflict_resolution='last_write_wins'.
 *
 * This is a CONTRACT test — it verifies the documented shape, not the actual
 * HTTP push (upstream has no writable endpoint yet, per §19.8). When upstream
 * adds one, these assertions remain valid; only new assertions for HTTP
 * status/response shape are added.
 */
final class OutboundPushContractTest extends TestCase
{
    private \App\Shared\Persistence\Connection $conn;
    private \App\Modules\NationalGateway\Services\OutboundSyncService $service;
    private int $applicantId;
    private array $createdLogIds = [];
    private array $settingsBackup = [];

    public function name(): string
    {
        return 'OutboundPushContractTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(dirname(__DIR__, 2) . '/storage/logs/test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $container = new \App\Container($env, $logger);
        $container->boot(dirname(__DIR__, 2));
        $this->conn = $container->make(\App\Shared\Persistence\Connection::class);
        $this->service = $container->make(\App\Modules\NationalGateway\Services\OutboundSyncService::class);

        $this->settingsBackup['sync_two_way_enabled'] = $this->readSetting('sync_two_way_enabled');
        $this->settingsBackup['sync_direction'] = $this->readSetting('sync_direction');
        $this->upsertSetting('sync_two_way_enabled', '1');
        $this->upsertSetting('sync_direction', 'two_way');

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
        $this->conn->execute("DELETE FROM applicants WHERE tracking_code = 'HG-CONTRACT'");
        $this->conn->execute("DELETE FROM national_licenses WHERE request_number = 'TEST-CONTRACT-001'");
    }

    public function run(): void
    {
        // === CONTRACT: push_status payload shape ===
        $result = $this->service->pushStatusChange(
            $this->applicantId,
            \App\Modules\Core\Domain\Enums\ApplicantStatus::from('تأیید اولیه'),
            'admin'
        );
        $this->createdLogIds[] = (int)$result['integration_log_id'];

        $row = $this->conn->one(
            'SELECT direction, operation, idempotency_key, request_payload_json,
                    response_payload_json, outcome, source_of_truth,
                    conflict_resolution, latency_ms, error_message
             FROM integration_log WHERE id = ?',
            [$result['integration_log_id']]
        );

        // direction must be 'outbound'.
        $this->assertSame('outbound', $row['direction'], 'contract: direction=outbound');

        // operation must be 'push_status'.
        $this->assertSame('push_status', $row['operation'], 'contract: operation=push_status');

        // idempotency_key must start with 'out_push_status_'.
        $this->assertTrue(
            str_starts_with($row['idempotency_key'], 'out_push_status_'),
            'contract: idempotency_key prefix out_push_status_'
        );

        // request_payload_json must contain the agreed fields.
        $req = json_decode((string)$row['request_payload_json'], true);
        $this->assertArrayHasKey('request_number', $req, 'contract: payload has request_number');
        $this->assertArrayHasKey('applicant_national_code', $req, 'contract: payload has applicant_national_code');
        $this->assertArrayHasKey('tracking_code', $req, 'contract: payload has tracking_code');
        $this->assertArrayHasKey('new_status', $req, 'contract: payload has new_status');
        $this->assertArrayHasKey('triggered_by', $req, 'contract: payload has triggered_by');
        $this->assertArrayHasKey('v3_updated_at', $req, 'contract: payload has v3_updated_at');

        // outcome must be one of the agreed values.
        $validOutcomes = ['success', 'skipped', 'conflict', 'client_error', 'server_error', 'timeout'];
        $this->assertTrue(
            in_array($row['outcome'], $validOutcomes, true),
            'contract: outcome in agreed set (got: ' . $row['outcome'] . ')'
        );

        // latency_ms must be a non-negative integer.
        $this->assertTrue(
            $row['latency_ms'] !== null && (int)$row['latency_ms'] >= 0,
            'contract: latency_ms is non-negative int'
        );

        // === CONTRACT: push_document payload shape ===
        $result2 = $this->service->pushDocument($this->applicantId, 42);
        $this->createdLogIds[] = (int)$result2['integration_log_id'];

        $row2 = $this->conn->one(
            'SELECT operation, idempotency_key, request_payload_json FROM integration_log WHERE id = ?',
            [$result2['integration_log_id']]
        );
        $this->assertSame('push_document', $row2['operation'], 'contract: operation=push_document');
        $this->assertTrue(
            str_starts_with($row2['idempotency_key'], 'out_push_document_'),
            'contract: idempotency_key prefix out_push_document_'
        );
        $req2 = json_decode((string)$row2['request_payload_json'], true);
        $this->assertArrayHasKey('document_id', $req2, 'contract: push_document payload has document_id');
        $this->assertArrayHasKey('request_number', $req2, 'contract: push_document payload has request_number');

        // === CONTRACT: idempotency — same push returns same log id ===
        $result3 = $this->service->pushStatusChange(
            $this->applicantId,
            \App\Modules\Core\Domain\Enums\ApplicantStatus::from('تأیید اولیه'),
            'admin'
        );
        $this->assertSame(
            $result['integration_log_id'],
            $result3['integration_log_id'],
            'contract: idempotent push returns same log id'
        );

        // === CONTRACT: conflict resolution labels ===
        // Insert a fake inbound log with upstream_updated_at in the future.
        $futureDate = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $this->conn->execute(
            'INSERT INTO integration_log (direction, operation, applicant_id, national_request_number,
                idempotency_key, outcome, upstream_updated_at, created_at)
             VALUES ("inbound", "fetch_license", ?, "TEST-CONTRACT-001", ?, "success", ?, NOW())',
            [$this->applicantId, 'inb_contract_conflict_' . uniqid(), $futureDate]
        );
        $conflictInboundId = (int)$this->conn->pdo()->lastInsertId();
        $this->createdLogIds[] = $conflictInboundId;

        // Now a push with a DIFFERENT status (new idempotency key) should detect conflict.
        $result4 = $this->service->pushStatusChange(
            $this->applicantId,
            \App\Modules\Core\Domain\Enums\ApplicantStatus::from('تأیید نهایی'),
            'operator'
        );
        $this->createdLogIds[] = (int)$result4['integration_log_id'];

        $this->assertSame('conflict', $result4['outcome'], 'contract: conflict outcome');

        $row4 = $this->conn->one(
            'SELECT source_of_truth, conflict_resolution, outcome FROM integration_log WHERE id = ?',
            [$result4['integration_log_id']]
        );
        $this->assertSame('upstream', $row4['source_of_truth'], 'contract: source_of_truth=upstream on conflict');
        $this->assertSame('last_write_wins', $row4['conflict_resolution'], 'contract: conflict_resolution=last_write_wins');

        // === CONTRACT: enabled flag check ===
        $this->assertTrue($this->service->isEnabled(), 'contract: isEnabled() reflects sync_two_way_enabled=1');

        // Flip the flag and verify.
        $this->upsertSetting('sync_two_way_enabled', '0');
        $this->assertFalse($this->service->isEnabled(), 'contract: isEnabled() reflects sync_two_way_enabled=0');
    }

    private function ensureTestApplicant(): int
    {
        $row = $this->conn->one("SELECT id FROM applicants WHERE tracking_code = 'HG-CONTRACT' LIMIT 1");
        if ($row !== null) {
            return (int)$row['id'];
        }
        $exists = $this->conn->one("SELECT id FROM national_licenses WHERE request_number = 'TEST-CONTRACT-001'");
        if ($exists === null) {
            $this->conn->execute(
                "INSERT INTO national_licenses (request_number, applicant_name, license_title,
                    organization_title, province_title, status_slug, masked_national_code)
                 VALUES ('TEST-CONTRACT-001', 'تست قرارداد', 'صنایع دستی', 'سازمان میراث', 'تهران', 'active', '00******5678')"
            );
        }
        $this->conn->execute(
            "INSERT INTO applicants (first_name, last_name, national_code, postal_code, mobile,
                tracking_code, current_status, license_state, national_license_request_number, matched_at, match_confidence)
             VALUES ('تست', 'قرارداد', '0000000004', '0000000000', '09120000004',
                'HG-CONTRACT', 'صدور مجوز', 'جاری', 'TEST-CONTRACT-001', NOW(), 95)"
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

return new OutboundPushContractTest();
