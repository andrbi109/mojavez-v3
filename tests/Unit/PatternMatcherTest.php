<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for App\Modules\NationalGateway\Services\PatternMatcher.
 *
 * Per docs/00-MASTER.md §9.7 — mandatory tests:
 *   - 4 positive MATCH cases (national code with 4 different mask patterns, address mask).
 *   - 4 negative controls (anchor mismatch, length mismatch, different person, no-anchor mask).
 *   - Quorum threshold (AutoConfirmed at 3+ fields, OperatorFlag at 1-2 fields).
 *   - Persian/Arabic digit normalization.
 */
final class PatternMatcherTest extends TestCase
{
    private \App\Modules\NationalGateway\Services\PatternMatcher $matcher;

    public function name(): string
    {
        return 'PatternMatcherTest';
    }

    public function setUp(): void
    {
        // Phase 24 (v1.9.6 / D-5-4): default config wires
        // business_address → 'contains' mode. Construct the matcher
        // with that mode + the default 0.7 confidence multiplier.
        $this->matcher = new \App\Modules\NationalGateway\Services\PatternMatcher(
            ['business_address' => 'contains'],
            0.7
        );
    }

    public function run(): void
    {
        // ========================================
        // 1. matchNumeric — 4 positive MATCH cases
        // ========================================

        // 1a. Standard 10-digit national code: 0720593107 vs mask 072****107.
        $r = $this->matcher->matchNumeric('0720593107', '072****107');
        $this->assertTrue($r->matched, 'national code standard mask MATCH');
        $this->assertSame(6, $r->anchorsMatched, 'anchors matched = 6 (072 + 107)');
        $this->assertSame(4, $r->wildcardsMatched, 'wildcards = 4');

        // 1b. Mask in the middle: 1234567890 vs 1234***890 (4+3+3=10).
        $r = $this->matcher->matchNumeric('1234567890', '1234***890');
        $this->assertTrue($r->matched, 'national code middle mask MATCH');
        $this->assertSame(7, $r->anchorsMatched, 'anchors = 7');

        // 1c. Mask at the start: 0720593107 vs ****593107.
        $r = $this->matcher->matchNumeric('0720593107', '****593107');
        $this->assertTrue($r->matched, 'national code leading mask MATCH');
        $this->assertSame(6, $r->anchorsMatched, 'anchors = 6');

        // 1d. Postal code mask: 9571968123 vs 9571968***.
        $r = $this->matcher->matchNumeric('9571968123', '9571968***');
        $this->assertTrue($r->matched, 'postal code suffix mask MATCH');
        $this->assertSame(7, $r->anchorsMatched, 'postal anchors = 7');

        // ========================================
        // 2. matchNumeric — 4 negative controls
        // ========================================

        // 2a. Anchor mismatch: 0720593107 vs 072****269 (last 3 differ).
        $r = $this->matcher->matchNumeric('0720593107', '072****269');
        $this->assertFalse($r->matched, 'anchor mismatch → mismatch');
        $this->assertStringContainsString('anchor_mismatch', $r->reason, 'mismatch reason');

        // 2b. Length mismatch: 0720593107 vs 072*** (different lengths).
        $r = $this->matcher->matchNumeric('0720593107', '072***');
        $this->assertFalse($r->matched, 'length mismatch → mismatch');
        $this->assertStringContainsString('length', $r->reason, 'length reason');

        // 2c. Different person: 0720593107 vs 999****999 (totally different).
        $r = $this->matcher->matchNumeric('0720593107', '999****999');
        $this->assertFalse($r->matched, 'different person → mismatch');

        // 2d. No-anchor mask: 0720593107 vs ********** (all wildcards).
        $r = $this->matcher->matchNumeric('0720593107', '**********');
        $this->assertFalse($r->matched, 'all-wildcard mask → inconclusive (no anchor)');
        $this->assertStringContainsString('no_anchor', $r->reason, 'inconclusive reason');

        // ========================================
        // 3. Persian/Arabic digit normalization
        // ========================================

        // Persian digits in full value.
        $r = $this->matcher->matchNumeric('۰۷۲۰۵۹۳۱۰۷', '072****107');
        $this->assertTrue($r->matched, 'Persian digits normalized before match');

        // Arabic-Indic digits in mask.
        $r = $this->matcher->matchNumeric('0720593107', '٠٧٢****١٠٧');
        $this->assertTrue($r->matched, 'Arabic-Indic digits in mask normalized');

        // ========================================
        // 4. matchText — business address masks
        // ========================================

        // 4a. Suffix mask: ***** at the start, trailing anchors must align.
        $full = 'استان خراسان رضوی، شهر تربت جام، خیابان مطهری 11';
        $mask = '*****، خیابان مطهری 11';
        $r = $this->matcher->matchText($full, $mask);
        $this->assertTrue($r->matched, 'address suffix mask MATCH');

        // 4b. Suffix mask mismatch.
        $r = $this->matcher->matchText($full, '*****، خیابان مطهری 99');
        $this->assertFalse($r->matched, 'address suffix mask mismatch');

        // 4c. Empty pattern.
        $r = $this->matcher->matchText($full, '');
        $this->assertFalse($r->matched, 'empty pattern → inconclusive');

        // ========================================
        // 4d. matchAddress — fuzzy contains mode (Phase 24 / D-5-4)
        // ========================================

        // 4d-1. Strict suffix still works (matchAddress tries matchText first).
        $r = $this->matcher->matchAddress($full, '*****، خیابان مطهری 11');
        $this->assertTrue($r->matched, 'address strict suffix MATCH (full conf)');
        $this->assertSame(1.0, $r->scoreMultiplier, 'strict suffix = full confidence');

        // 4d-2. Fuzzy contains — local address doesn't END with the suffix
        //       but CONTAINS it. This is the case the strict matcher fails
        //       on (and the reason matchAddress exists).
        $localAddress = 'خیابان مطهری 11، کوچه علی‌آباد، پلاک 25';
        $maskedAddress = '*****، خیابان مطهری 11';
        $r = $this->matcher->matchAddress($localAddress, $maskedAddress);
        $this->assertTrue($r->matched, 'address fuzzy contains MATCH');
        $this->assertSame(0.7, $r->scoreMultiplier, 'fuzzy contains = 0.7 confidence');

        // 4d-3. No match — completely different addresses.
        $r = $this->matcher->matchAddress('خیابان ولیعصر 50، پلاک 12', $maskedAddress);
        $this->assertFalse($r->matched, 'address no-contains → mismatch');

        // 4d-4. Empty pattern is inconclusive.
        $r = $this->matcher->matchAddress($localAddress, '');
        $this->assertFalse($r->matched, 'address empty pattern → inconclusive');

        // 4d-5. Pattern with only `*****` (no visible suffix) is inconclusive.
        $r = $this->matcher->matchAddress($localAddress, '*****');
        $this->assertFalse($r->matched, 'address all-wildcard → inconclusive');

        // 4d-6. Persian digits in the local address normalize before
        //       contains check (۱۱ == 11).
        $r = $this->matcher->matchAddress('خیابان مطهری ۱۱، کوچه علی‌آباد', '*****، خیابان مطهری 11');
        $this->assertTrue($r->matched, 'address Persian-digit normalization before contains');

        // ========================================
        // 4e. Quorum uses fuzzy contains for business_address
        // ========================================

        // 4e-1. AutoConfirmed via national code + province + fuzzy
        //       business_address (contains mode). The fuzzy match
        //       counts as a real field for the quorum count (national
        //       + 2 others → AutoConfirmed) even though its score
        //       contribution is reduced (4 × 0.7 = 2.8 → 2 after floor).
        $licenseAddr = $this->buildTestLicense(
            maskedNationalCode: '072****107',
            applicantName: 'عبدالکریم محمدی',
            fatherName: 'محمد',
            provinceTitle: 'خراسان رضوی',
            townshipTitle: 'تربت جام',
            businessAddress: '*****، خیابان مطهری 11',
        );
        $personAddr = $this->buildTestPerson(
            nationalCode: '0720593107',
            firstName: 'عبدالکریم',
            lastName: 'محمدی',
            fatherName: 'محمد',
        );
        $propertyAddr = [
            'province' => 'خراسان رضوی',
            'township' => 'تربت جام',
            // Local address is the local concatenation order — does NOT
            // end with the upstream's suffix, but CONTAINS it after
            // normalization.
            'business_address' => 'خیابان مطهری 11، کوچه علی‌آباد، پلاک 25',
        ];
        $r = $this->matcher->matchQuorum($licenseAddr, $personAddr, $propertyAddr);
        $this->assertTrue($r->matched, 'quorum with fuzzy address → matched');
        $this->assertTrue($r->nationalCodeMatched, 'national code matched');
        $this->assertStringContainsString('business_address', implode(',', $r->fieldsMatched), 'business_address counted as matched field');
        $this->assertSame(
            \App\Modules\NationalGateway\Domain\Enums\MatchConfidence::AutoConfirmed,
            $r->confidence,
            'AutoConfirmed even with fuzzy address (national + name + father + province + township + address)'
        );

        // ========================================
        // 5. matchExactText — name, father name
        // ========================================

        $r = $this->matcher->matchExactText('عبدالکریم محمدی', 'عبدالکریم محمدی');
        $this->assertTrue($r->matched, 'exact name MATCH');

        $r = $this->matcher->matchExactText('عبدالکریم محمدی', 'عبدالکریم رضایی');
        $this->assertFalse($r->matched, 'different name → mismatch');

        // 5c. ZWNJ normalization (نیم‌فاصله).
        $r = $this->matcher->matchExactText('عبدالکریم', 'عبدالکریم');
        $this->assertTrue($r->matched, 'name with ZWNJ normalized');

        // ========================================
        // 6. matchFuzzyText — license title contains
        // ========================================

        $r = $this->matcher->matchFuzzyText('کسب و کار خانگی', 'کسب و کار خانگی دوخت چادر شب باف');
        $this->assertTrue($r->matched, 'fuzzy title contains MATCH');

        $r = $this->matcher->matchFuzzyText('دوخت چادر', 'کسب و کار خانگی دوخت چادر شب باف');
        $this->assertTrue($r->matched, 'fuzzy title contains reverse MATCH');

        $r = $this->matcher->matchFuzzyText('رستوران', 'کسب و کار خانگی دوخت چادر شب باف');
        $this->assertFalse($r->matched, 'fuzzy title no-overlap → mismatch');

        // ========================================
        // 7. matchQuorum — confidence thresholds
        // ========================================

        // 7a. AutoConfirmed: national code match + 2+ other fields.
        $license = $this->buildTestLicense(maskedNationalCode: '072****107');
        $person = $this->buildTestPerson(nationalCode: '0720593107');
        $property = ['province' => 'خراسان رضوی', 'township' => 'تربت جام'];
        // Set applicant name to match license's applicantName.
        $license2 = $this->buildTestLicense(
            maskedNationalCode: '072****107',
            applicantName: 'عبدالکریم محمدی',
            fatherName: 'محمد',
            provinceTitle: 'خراسان رضوی',
            townshipTitle: 'تربت جام',
        );
        $person2 = $this->buildTestPerson(
            nationalCode: '0720593107',
            firstName: 'عبدالکریم',
            lastName: 'محمدی',
            fatherName: 'محمد',
        );
        $r = $this->matcher->matchQuorum($license2, $person2, $property);
        $this->assertTrue($r->matched, 'quorum with national + name + father → matched');
        $this->assertTrue($r->nationalCodeMatched, 'national code matched');
        $this->assertSame(
            \App\Modules\NationalGateway\Domain\Enums\MatchConfidence::AutoConfirmed,
            $r->confidence,
            'AutoConfirmed when national + 2+ others match'
        );

        // 7b. OperatorFlag: national code match but <2 other fields.
        $license3 = $this->buildTestLicense(maskedNationalCode: '072****107');
        $person3 = $this->buildTestPerson(nationalCode: '0720593107');
        // No other matching fields — name, father, province all differ.
        $r = $this->matcher->matchQuorum($license3, $person3, []);
        $this->assertTrue($r->matched, 'national-only match → matched (but flagged)');
        $this->assertSame(
            \App\Modules\NationalGateway\Domain\Enums\MatchConfidence::OperatorFlag,
            $r->confidence,
            'OperatorFlag when national only (no other fields)'
        );

        // 7c. AutoRejected: national code anchor mismatch.
        $license4 = $this->buildTestLicense(maskedNationalCode: '999****999');
        $person4 = $this->buildTestPerson(nationalCode: '0720593107');
        $r = $this->matcher->matchQuorum($license4, $person4, []);
        $this->assertFalse($r->matched, 'national anchor mismatch → rejected');
        $this->assertFalse($r->nationalCodeMatched, 'national code did NOT match');
        $this->assertSame(
            \App\Modules\NationalGateway\Domain\Enums\MatchConfidence::AutoRejected,
            $r->confidence,
            'AutoRejected when no fields match'
        );

        // 7d. OperatorFlag: 1-2 fields match but no national code.
        $license5 = $this->buildTestLicense(
            applicantName: 'عبدالکریم محمدی',
            provinceTitle: 'خراسان رضوی',
            maskedNationalCode: '999****999',  // mismatch
        );
        $person5 = $this->buildTestPerson(
            nationalCode: '0720593107',
            firstName: 'عبدالکریم',
            lastName: 'محمدی',
        );
        $r = $this->matcher->matchQuorum($license5, $person5, ['province' => 'خراسان رضوی']);
        $this->assertTrue($r->matched, 'name + province match (no national) → matched');
        $this->assertSame(
            \App\Modules\NationalGateway\Domain\Enums\MatchConfidence::OperatorFlag,
            $r->confidence,
            'OperatorFlag when 1-2 fields without national'
        );
    }

    /**
     * Build a test License with golden-link anchor fields.
     */
    private function buildTestLicense(
        ?string $maskedNationalCode = null,
        string $applicantName = '',
        ?string $fatherName = null,
        string $provinceTitle = '',
        string $townshipTitle = '',
        string $licenseTitle = 'کسب و کار خانگی',
        ?string $businessAddress = null,
    ): \App\Modules\NationalGateway\Domain\License {
        return new \App\Modules\NationalGateway\Domain\License(
            requestNumber: 'TEST-' . random_int(1000, 9999),
            applicantName: $applicantName,
            userImage: null,
            licenseTitle: $licenseTitle,
            organizationTitle: '',
            provinceTitle: $provinceTitle,
            townshipTitle: $townshipTitle,
            respondedAt: '',
            status: \App\Modules\NationalGateway\Domain\Enums\LicenseStatus::Active,
            maskedNationalCode: $maskedNationalCode,
            fatherName: $fatherName,
            businessAddress: $businessAddress,
        );
    }

    /**
     * Build a test Person.
     */
    private function buildTestPerson(
        string $nationalCode = '0720593107',
        string $firstName = 'تست',
        string $lastName = 'تست',
        ?string $fatherName = null,
    ): \App\Modules\Core\Domain\Person {
        return new \App\Modules\Core\Domain\Person(
            id: 0,
            nationalCode: $nationalCode,
            firstName: $firstName,
            lastName: $lastName,
            mobile: '09150000000',
            personType: \App\Modules\Core\Domain\Enums\PersonType::Real,
            fatherName: $fatherName,
        );
    }
}

return new PatternMatcherTest();
