<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for App\Modules\NationalGateway\Domain\License::fromArray.
 *
 * Phase 14 (v1.5.0) — pays D-4-6: verifies that fromArray populates
 * the golden-link anchor fields (masked_national_code, father_name)
 * from the input array. Previously fromArray dropped them — the
 * cache-restoration path and the DB-row path (via hydrateLicense)
 * both lost the anchors.
 *
 * Cases:
 *   1. Build with anchors set → getters return the values.
 *   2. Build without anchors (omitted) → getters return null.
 *   3. Build with empty strings → normalized to null (matches the
 *      nullableString helper's contract).
 *   4. Build with the basic fields → status object is correctly
 *      reconstructed (smoke check — was already correct, just
 *      regression coverage).
 */
final class LicenseAnchorsTest extends TestCase
{
    public function name(): string
    {
        return 'LicenseAnchorsTest';
    }

    public function run(): void
    {
        // 1. fromArray with anchors set → getters return the values.
        $license = \App\Modules\NationalGateway\Domain\License::fromArray([
            'request_number'        => 'I-12345',
            'applicant_name'         => 'تست آذر',
            'license_title'         => 'سفالگری',
            'organization_title'    => 'سازمان میراث',
            'province_title'         => 'تهران',
            'township_title'         => 'ری',
            'responded_at'           => '2026-11-17 10:00:00',
            'status'                 => ['slug' => 'active', 'id' => '10', 'title' => 'فعال'],
            'masked_national_code'  => '******1234',
            'father_name'           => 'محمد',
        ]);

        $this->assertSame('I-12345', $license->requestNumber, 'request number populated');
        $this->assertSame('تست آذر', $license->applicantName, 'applicant name populated');
        $this->assertSame('******1234', $license->maskedNationalCode, 'masked_national_code anchor populated (D-4-6)');
        $this->assertSame('محمد', $license->fatherName, 'father_name anchor populated (D-4-6)');
        $this->assertSame('active', $license->status->value, 'status slug reconstructed');

        // 2. fromArray without anchors (omitted) → getters return null.
        $licenseNoAnchors = \App\Modules\NationalGateway\Domain\License::fromArray([
            'request_number'     => 'I-67890',
            'applicant_name'     => '',
            'license_title'      => 'سفالگری',
            'organization_title' => 'سازمان میراث',
            'province_title'     => 'تهران',
            'township_title'     => 'ری',
            'responded_at'       => '2026-11-17 10:00:00',
            'status'             => ['slug' => 'pending'],
        ]);

        $this->assertNull($licenseNoAnchors->maskedNationalCode, 'masked_national_code defaults to null when missing');
        $this->assertNull($licenseNoAnchors->fatherName, 'father_name defaults to null when missing');

        // 3. fromArray with empty strings → normalized to null
        //    (nullableString helper: '' → null).
        $licenseEmpty = \App\Modules\NationalGateway\Domain\License::fromArray([
            'request_number'        => 'I-EMPTY1',
            'applicant_name'        => '',
            'license_title'         => 'سفالگری',
            'organization_title'    => 'سازمان میراث',
            'province_title'        => 'تهران',
            'township_title'        => 'ری',
            'responded_at'          => '2026-11-17 10:00:00',
            'status'                => ['slug' => 'pending'],
            'masked_national_code'  => '',
            'father_name'           => '   ',
        ]);

        $this->assertNull($licenseEmpty->maskedNationalCode, 'masked_national_code empty → null (normalized)');
        $this->assertNull($licenseEmpty->fatherName, 'father_name whitespace-only → null (normalized)');

        // 4. Build via the existing fromTrackPage path → anchors
        //    populated as before (regression check — fromTrackPage was
        //    already populating them; fromArray was the broken path).
        $licenseTrack = \App\Modules\NationalGateway\Domain\License::fromTrackPage([
            'tracking_code'    => 'I-TRACK1',
            'license_title'    => 'سفالگری',
            'issuing_authority' => 'سازمان میراث',
            'business_province' => 'تهران',
            'business_township' => 'ری',
            'issue_date'        => '2026-11-17 10:00:00',
            'status'            => ['status_slug' => 'active', 'status_id' => '10'],
            'national_id'       => '******9999',
            'father_name'       => 'علی',
        ]);

        $this->assertSame('I-TRACK1', $licenseTrack->requestNumber, 'fromTrackPage request number');
        $this->assertSame('******9999', $licenseTrack->maskedNationalCode, 'fromTrackPage masked_national_code (unchanged behavior)');
        $this->assertSame('علی', $licenseTrack->fatherName, 'fromTrackPage father_name (unchanged behavior)');
    }
}

return new LicenseAnchorsTest();
