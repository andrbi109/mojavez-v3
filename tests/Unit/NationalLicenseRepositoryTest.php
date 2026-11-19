<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for App\Modules\NationalGateway\Persistence\NationalLicenseRepository.
 *
 * Verifies the full save/find/query lifecycle against a real MySQL
 * national_licenses table — including the golden-link anchor fields
 * (masked_national_code, father_name) and the match result columns
 * (matched_applicant_id, match_confidence, matched_by).
 */
final class NationalLicenseRepositoryTest extends TestCase
{
    private \App\Container $container;
    private \App\Modules\NationalGateway\Persistence\NationalLicenseRepository $repo;
    private \App\Shared\Persistence\Connection $conn;
    /** @var list<string> */
    private array $createdRns = [];

    public function name(): string
    {
        return 'NationalLicenseRepositoryTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/natlicrepo-test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $this->container = new \App\Container($env, $logger);
        $this->container->boot(dirname(__DIR__, 2));
        $this->conn = $this->container->make(\App\Shared\Persistence\Connection::class);
        $this->repo = new \App\Modules\NationalGateway\Persistence\NationalLicenseRepository($this->conn, $logger);
    }

    public function tearDown(): void
    {
        if ($this->createdRns !== []) {
            $placeholders = implode(',', array_fill(0, count($this->createdRns), '?'));
            $this->conn->execute(
                "DELETE FROM national_licenses WHERE request_number IN ($placeholders)",
                $this->createdRns
            );
        }
    }

    public function run(): void
    {
        // 1. Save a license with golden-link anchors.
        $rn = 'TEST-' . random_int(100000, 999999);
        $this->createdRns[] = $rn;
        $license = new \App\Modules\NationalGateway\Domain\License(
            requestNumber: $rn,
            applicantName: 'عبدالکریم محمدی',
            userImage: null,
            licenseTitle: 'کسب و کار خانگی',
            organizationTitle: 'سازمان بهداشت',
            provinceTitle: 'خراسان رضوی',
            townshipTitle: 'تربت جام',
            respondedAt: '2026-11-17 10:30:00',
            status: \App\Modules\NationalGateway\Domain\Enums\LicenseStatus::Active,
            maskedNationalCode: '072****107',
            fatherName: 'محمد',
            businessAddress: '*****، خیابان مطهری 11',
            postalCodeMasked: '9571968***',
        );
        $this->repo->save($license, null, ['filter_hash' => 'testhash123']);
        $this->assertStringContainsString($rn, $rn, 'save did not throw');

        // 2. findByRequestNumber round-trips all fields.
        $found = $this->repo->findByRequestNumber($rn);
        $this->assertNotNull($found, 'findByRequestNumber returns saved license');
        $this->assertSame('عبدالکریم محمدی', $found->applicantName, 'applicantName round-tripped');
        $this->assertSame('072****107', $found->maskedNationalCode, 'maskedNationalCode round-tripped');
        $this->assertSame('محمد', $found->fatherName, 'fatherName round-tripped');
        $this->assertSame('9571968***', $found->postalCodeMasked, 'postalCodeMasked round-tripped');
        $this->assertSame('active', $found->status->value, 'status slug round-tripped');

        // 3. Re-save updates fields (ON DUPLICATE KEY UPDATE).
        $license2 = new \App\Modules\NationalGateway\Domain\License(
            requestNumber: $rn,
            applicantName: 'عبدالکریم محمدی (به‌روزرسانی)',
            userImage: null,
            licenseTitle: 'کسب و کار خانگی دوخت',
            organizationTitle: 'سازمان بهداشت',
            provinceTitle: 'خراسان رضوی',
            townshipTitle: 'تربت جام',
            respondedAt: '2026-11-17 10:30:00',
            status: \App\Modules\NationalGateway\Domain\Enums\LicenseStatus::Active,
            maskedNationalCode: '072****107',
            fatherName: 'محمد',
        );
        $this->repo->save($license2);
        $updated = $this->repo->findByRequestNumber($rn);
        $this->assertSame('عبدالکریم محمدی (به‌روزرسانی)', $updated->applicantName, 're-save updates applicantName');

        // 4. setMatch — golden-link match result.
        $applicantId = 999999; // fake applicant ID (won't conflict)
        $this->repo->setMatch($rn, $applicantId, 90, 'auto_sync');
        $matched = $this->repo->findByRequestNumber($rn);
        $this->assertSame($applicantId, $matched->matchedApplicantId, 'matched_applicant_id set');
        $this->assertSame(90, $matched->matchConfidence, 'match_confidence set');
        $this->assertSame('auto_sync', $matched->matchedBy, 'matched_by set');

        // 5. findUnmatched does NOT return the matched license.
        $unmatched = $this->repo->findUnmatched(10);
        $unmatchedRns = array_map(fn ($l): string => $l->requestNumber, $unmatched);
        $this->assertFalse(in_array($rn, $unmatchedRns, true), 'matched license not in findUnmatched');

        // 6. markSaved + countSaved.
        $this->repo->markSaved($rn);
        $this->assertGreaterThanOrEqual(1, $this->repo->countSaved(), 'countSaved > 0 after markSaved');
        $saved = $this->repo->findSavedRequestNumbers([$rn]);
        $this->assertStringContainsString($rn, implode(',', $saved), 'findSavedRequestNumbers finds rn');

        // 7. countAll + countByStatus.
        $this->assertGreaterThanOrEqual(1, $this->repo->countAll(), 'countAll > 0');
        $byStatus = $this->repo->countByStatus();
        $this->assertTrue(isset($byStatus['active']), 'countByStatus has active key');

        // 8. queryCached with province filter.
        $result = $this->repo->queryCached(['province' => 'خراسان رضوی'], page: 1, pageSize: 10);
        $this->assertGreaterThanOrEqual(1, $result['total'], 'queryCached total > 0');
        $this->assertGreaterThanOrEqual(1, count($result['rows']), 'queryCached rows > 0');

        // 9. markUnsaved.
        $this->repo->markUnsaved($rn);
        $this->assertSame(0, count($this->repo->findSavedRequestNumbers([$rn])), 'markUnsaved clears is_saved');

        // Clean up the match + the fake applicant association.
        $this->conn->execute(
            'UPDATE national_licenses SET matched_applicant_id = NULL, match_confidence = NULL, matched_by = NULL WHERE request_number = ?',
            [$rn]
        );
    }
}

return new NationalLicenseRepositoryTest();
