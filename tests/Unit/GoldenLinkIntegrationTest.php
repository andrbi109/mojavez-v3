<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Integration test for the golden-link cycle.
 *
 * End-to-end: insert a test applicant + a test national_license with matching
 * masked national code, then run NationalSyncOrchestrator::matchOne() and
 * verify the applicant is matched (national_license_request_number set,
 * status transitioned, audit log recorded, ApplicantMatched event dispatched).
 */
final class GoldenLinkIntegrationTest extends TestCase
{
    private \App\Container $container;
    private \App\Shared\Persistence\Connection $conn;
    private int $applicantId = 0;
    private string $testRn = '';
    private int $testPersonId = 0;

    public function name(): string
    {
        return 'GoldenLinkIntegrationTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/goldenlink-test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $this->container = new \App\Container($env, $logger);
        $this->container->boot(dirname(__DIR__, 2));
        $this->conn = $this->container->make(\App\Shared\Persistence\Connection::class);

        // Clean any leftover test data from a previous failed run.
        $this->conn->execute("DELETE FROM sync_audit_log WHERE national_request_number LIKE 'GOLDEN-%'");
        $this->conn->execute("DELETE FROM national_licenses WHERE request_number LIKE 'GOLDEN-%'");
        $this->conn->execute("DELETE FROM status_log WHERE applicant_id IN (SELECT id FROM applicants WHERE tracking_code LIKE 'TEST-%')");
        $this->conn->execute("DELETE FROM applicants WHERE tracking_code LIKE 'TEST-%'");
        $this->conn->execute("DELETE FROM persons WHERE national_code = '0720593188'");
        $this->conn->execute("DELETE FROM events WHERE type IN ('applicant_matched','sync_cycle_completed','license_saved','license_issued_on_national')");
    }

    public function tearDown(): void
    {
        // Clean up test data.
        if ($this->applicantId > 0) {
            $this->conn->execute('DELETE FROM property_details WHERE applicant_id = ?', [$this->applicantId]);
            $this->conn->execute('DELETE FROM status_log WHERE applicant_id = ?', [$this->applicantId]);
            $this->conn->execute('DELETE FROM applicants WHERE id = ?', [$this->applicantId]);
        }
        if ($this->testPersonId > 0) {
            $this->conn->execute('DELETE FROM persons WHERE id = ?', [$this->testPersonId]);
        }
        if ($this->testRn !== '') {
            $this->conn->execute('DELETE FROM national_licenses WHERE request_number = ?', [$this->testRn]);
            $this->conn->execute(
                "DELETE FROM sync_audit_log WHERE national_request_number = ?",
                [$this->testRn]
            );
        }
        $this->conn->execute("DELETE FROM events WHERE type IN ('applicant_matched','sync_cycle_completed','license_saved','license_issued_on_national')");
    }

    public function run(): void
    {
        // 1. Create a test person + applicant in a pre-issue status.
        $personId = (int)$this->conn->insert(
            'INSERT INTO persons (national_code, first_name, last_name, mobile, person_type, father_name, postal_code, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            ['0720593188', 'عبدالکریم', 'محمدی', '09150000000', 'حقیقی', 'محمد', '9571968123']
        );
        $this->testPersonId = $personId;

        $applicantId = (int)$this->conn->insert(
            'INSERT INTO applicants (person_id, tracking_code, national_code, current_status, license_state, '
            . 'first_name, last_name, mobile, postal_code, priority, registration_started_at, version, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, DATE_SUB(NOW(), INTERVAL 5 DAY), 1, NOW())',
            [$personId, 'TEST-' . random_int(100000, 999999), '0720593188', 'ثبت درخواست صدور', 'جاری', 'عبدالکریم', 'محمدی', '09150000000', '9571968123']
        );
        $this->applicantId = $applicantId;
        $this->assertGreaterThan(0, $applicantId, 'test applicant created');

        // 1b. Create a property_details row (1:1 with applicant) so the
        // PatternMatcher can match province + township + business_address.
        $this->conn->execute(
            'INSERT INTO property_details (applicant_id, ownership_status, province, county, city, '
            . 'electricity_bill_id, address_street, address_alley, address_plaque) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $applicantId,
                'شخص',
                'خراسان رضوی',   // matches license.provinceTitle
                'تربت جام',       // matches license.townshipTitle
                'تربت جام',
                'EL-12345',
                'خیابان مطهری',   // matches license.businessAddress suffix
                'کوچه ۱۱',
                '۱۱',
            ]
        );

        // 2. Create a test national_license with golden-link anchor fields
        //    matching the applicant + property. The masked national code,
        //    father name, province, township, business_address (masked),
        //    and postal_code (masked) all align → 6+ matching fields.
        $this->testRn = 'GOLDEN-' . random_int(100000, 999999);
        $licenseRepo = $this->container->make(\App\Shared\Contracts\NationalLicenseRepositoryInterface::class);
        $license = new \App\Modules\NationalGateway\Domain\License(
            requestNumber: $this->testRn,
            applicantName: 'عبدالکریم محمدی',
            userImage: null,
            licenseTitle: 'کسب و کار خانگی',
            organizationTitle: 'سازمان بهداشت',
            provinceTitle: 'خراسان رضوی',
            townshipTitle: 'تربت جام',
            respondedAt: date('Y-m-d H:i:s'),
            status: \App\Modules\NationalGateway\Domain\Enums\LicenseStatus::Active,
            maskedNationalCode: '072****188',
            fatherName: 'محمد',
            businessAddress: '***** خیابان مطهری',  // suffix-masked — matches our address
            postalCodeMasked: '9571968***',           // masked — matches our postal_code
        );
        $licenseRepo->save($license);
        $this->assertNotNull($licenseRepo->findByRequestNumber($this->testRn), 'test license saved');

        // 3. Run the orchestrator's matchOne() for this license.
        $orchestrator = $this->container->make(\App\Modules\NationalGateway\Services\NationalSyncOrchestrator::class);
        $summary = $orchestrator->matchOne($this->testRn);

        // 4. Verify the match summary.
        $this->assertGreaterThanOrEqual(1, $summary['tried'], 'matchOne tried at least 1 applicant');
        $this->assertGreaterThanOrEqual(1, $summary['auto_confirmed'], 'matchOne auto_confirmed >= 1');

        // 4b. Verify the quorum matched 4+ fields (national_code + full_name
        // + father_name + business_province + business_township + business_address
        // + postal_code = 7 potential matches). Read the audit log to count.
        $auditRows = $this->conn->all(
            "SELECT context FROM sync_audit_log WHERE applicant_id = ? AND action = 'match_attempted' ORDER BY id DESC LIMIT 1",
            [$applicantId]
        );
        if (count($auditRows) > 0) {
            $ctx = json_decode((string)$auditRows[0]['context'], true) ?: [];
            $fieldsMatched = $ctx['fields_matched'] ?? [];
            $this->assertGreaterThanOrEqual(
                4,
                count($fieldsMatched),
                'quorum matched 4+ fields (got: ' . implode(',', $fieldsMatched) . ')'
            );
            $this->assertStringContainsString('national_code', implode(',', $fieldsMatched), 'national_code in matched fields');
            $this->assertStringContainsString('business_province', implode(',', $fieldsMatched), 'business_province matched (from property_details)');
        }

        // 5. Verify the applicant was updated.
        $applicantRepo = $this->container->make(\App\Shared\Contracts\ApplicantRepositoryInterface::class);
        $matched = $applicantRepo->findById($applicantId);
        $this->assertNotNull($matched, 'applicant still findable after match');
        $this->assertSame($this->testRn, $matched->nationalLicenseRequestNumber, 'applicant.national_license_request_number set');
        $this->assertSame(90, $matched->matchConfidence, 'applicant.match_confidence = 90');
        $this->assertSame('صدور مجوز', $matched->currentStatus->value, 'applicant status transitioned to صدور مجوز');
        $this->assertSame('صادر شده', $matched->licenseState->value, 'applicant license_state = صادر شده');

        // 6. Verify the license row was updated.
        $matchedLicense = $licenseRepo->findByRequestNumber($this->testRn);
        $this->assertSame($applicantId, $matchedLicense->matchedApplicantId, 'license.matched_applicant_id set');
        $this->assertSame(90, $matchedLicense->matchConfidence, 'license.match_confidence = 90');
        $this->assertSame('auto_sync', $matchedLicense->matchedBy, 'license.matched_by = auto_sync');

        // 7. Verify the audit log.
        $auditRepo = $this->container->make(\App\Shared\Contracts\SyncAuditLogRepositoryInterface::class);
        $auditRows = $auditRepo->recentForApplicant($applicantId, 10);
        $this->assertGreaterThanOrEqual(2, count($auditRows), 'audit log has >= 2 entries (match_attempted + auto_confirmed)');
        $actions = array_column($auditRows, 'action');
        $this->assertStringContainsString('match_attempted', implode(',', $actions), 'audit log has match_attempted');
        $this->assertStringContainsString('auto_confirmed', implode(',', $actions), 'audit log has auto_confirmed');

        // 8. Verify the ApplicantMatched event was persisted to the events table.
        $bus = $this->container->make(\App\Events\EventBus::class);
        $events = $bus->eventsSince(0, 100);
        $eventTypes = array_column($events, 'type');
        $this->assertStringContainsString('applicant_matched', implode(',', $eventTypes), 'ApplicantMatched event persisted');

        // 9. Idempotency: running matchOne again should NOT re-match (license
        //    is now matched, so findUnmatched excludes it).
        $summary2 = $orchestrator->matchOne($this->testRn);
        $this->assertSame(0, $summary2['tried'], 'second matchOne tried 0 (license already matched)');

        // 10. Status check.
        $status = $orchestrator->status();
        $this->assertArrayHasKey('counts', $status, 'status has counts');
    }
}

return new GoldenLinkIntegrationTest();
