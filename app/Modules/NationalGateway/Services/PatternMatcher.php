<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Modules\NationalGateway\Domain\License;
use App\Modules\NationalGateway\Domain\Enums\MatchConfidence;
use App\Shared\Support\Normalizer;
use App\Modules\Core\Domain\Person;

/**
 * PatternMatcher — the core algorithm of the "golden link" (اتصال طلایی).
 *
 * Per docs/00-MASTER.md §9 — matches a national license (with masked fields
 * scraped from qr.mojavez.ir) against a local applicant (with full fields
 * captured at registration). The masked fields are NOT lost data — they are
 * confirmation anchors: the visible digits must align with the local
 * applicant's full value, and the * positions are wildcards that the local
 * value fills in.
 *
 * Two match modes (Phase 4 baseline):
 *   - matchNumeric($full, $masked): digit-by-digit alignment (national code,
 *     postal code). * = single-char wildcard.
 *   - matchText($full, $masked): glob-style alignment (business address).
 *     ***** (5 stars) = suffix wildcard — only the trailing anchors matter.
 *
 * Phase 24 (v1.9.6): pays D-5-4 — adds matchAddress() for fuzzy `contains`
 * address matching. The upstream masks business_address with `*****`
 * (the first 5 chars are hidden). The local property address is the full
 * concatenation (street + alley + plaque), usually longer and in a
 * different order. The old strict suffix matcher often failed when the
 * local concatenation order differed from the upstream's. The new
 * `contains` mode normalizes both strings (lowercase, strip punctuation,
 * collapse whitespace) and accepts a match if the shorter string is
 * contained in the longer one, with a reduced confidence multiplier
 * (default 0.7 — configurable via config/pattern_matcher.php).
 *
 * Quorum matching (§9.3): no single field is enough — the matcher aggregates
 * 9 weighted fields and requires national code match + ≥2 other fields for
 * AutoConfirmed (§9.6 safety rule 1).
 *
 * Reference implementation: scripts/pattern_matcher.py (Python port) — this
 * PHP class is the production version.
 */
final class PatternMatcher
{
    /**
     * Field weights per docs/00-MASTER.md §9.3.
     */
    private const WEIGHTS = [
        'national_code'        => 10,  // strongest signal — masked pattern
        'full_name'            => 5,  // exact text
        'father_name'          => 3,  // exact text
        'business_province'    => 2,  // exact text
        'business_township'    => 2,  // exact text
        'license_title'        => 2,  // fuzzy text (contains)
        'postal_code'          => 3,  // masked pattern
        'business_address'     => 4,  // masked text pattern (Phase 24: fuzzy contains)
        'request_number'       => 1,  // auxiliary signal
    ];

    /**
     * Per-field match mode (Phase 24 / D-5-4).
     *
     * Keys mirror WEIGHTS. Missing keys default to the original matcher
     * method (national_code/postal_code → matchNumeric; business_address
     * → matchText; everything else → exact / fuzzy as before).
     *
     * 'contains' = the new fuzzy matchAddress() path — strict suffix first,
     * then normalized contains-either-direction fallback at 0.7 confidence.
     *
     * @var array<string,string>
     */
    private readonly array $modes;

    /**
     * Confidence multiplier for `contains` (fuzzy) matches. 1.0 = full
     * weight, 0.7 = 70% credit. Applied to the field's weight when
     * summed into the quorum totalScore. The field still counts as 1
     * field for the count-based quorum (AutoConfirmed needs national +
     * 2+ others) — we just trust it less for the score.
     */
    private readonly float $containsConfidence;

    /**
     * @param array<string,string> $modes  Per-field mode overrides.
     * @param float|null $containsConfidence  Confidence multiplier for contains matches.
     */
    public function __construct(array $modes = [], ?float $containsConfidence = null)
    {
        $this->modes = $modes;
        $this->containsConfidence = $containsConfidence ?? 0.7;
    }

    /**
     * Match a full numeric value against a masked pattern.
     *
     * Algorithm (per §9.2):
     *   1. Normalize Persian/Arabic digits to Latin.
     *   2. Lengths must be equal.
     *   3. Position-by-position: * = wildcard, else must equal.
     *   4. Must have at least one * AND at least one anchor.
     *
     * Examples:
     *   matchNumeric('0720593107', '072****107') → match (anchors 072 + 107 align)
     *   matchNumeric('0720593107', '072****269') → mismatch (anchor 107 ≠ 269)
     *   matchNumeric('12345', '*****') → inconclusive (no anchor)
     */
    public function matchNumeric(string $fullValue, string $maskedPattern): MatchResult
    {
        $full = Normalizer::digits($fullValue);
        $pattern = Normalizer::digits($maskedPattern);

        if (strlen($full) !== strlen($pattern)) {
            return MatchResult::mismatch('length_differs');
        }

        $starsCount = 0;
        $anchorsMatched = 0;
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $pc = $pattern[$i];
            $fc = $full[$i];
            if ($pc === '*') {
                $starsCount++;
            } else {
                if ($pc !== $fc) {
                    return MatchResult::mismatch("anchor_mismatch_at_{$i}");
                }
                $anchorsMatched++;
            }
        }

        if ($starsCount === 0 || $anchorsMatched === 0) {
            return MatchResult::inconclusive('no_mask_or_no_anchor');
        }

        return MatchResult::match($anchorsMatched, $starsCount);
    }

    /**
     * Match a full text value against a masked text pattern (suffix mode).
     *
     * The upstream masks business addresses with `*****` (5 stars) which
     * means "the first 5 characters are hidden; the trailing part is
     * visible". So:
     *   - If the pattern starts with `*****`, the full value must END with
     *     the trailing anchors.
     *   - Otherwise, treat * as a glob wildcard.
     *
     * Examples:
     *   matchText('استان خراسان رضوی، خیابان مطهری 11', '*****، خیابان مطهری 11')
     *     → match (suffix anchors align)
     *
     * Kept public for backward compatibility (Phase 4 tests call it
     * directly). The quorum matcher now routes business_address through
     * matchAddress() when the field's mode is 'contains'.
     */
    public function matchText(string $fullValue, string $maskedPattern): MatchResult
    {
        $full = Normalizer::digits($fullValue);
        $pattern = Normalizer::digits($maskedPattern);

        if ($pattern === '') {
            return MatchResult::inconclusive('empty_pattern');
        }

        // §9.2: ***** at the start means suffix-only match.
        if (str_starts_with($pattern, '*****')) {
            $endAnchors = substr($pattern, 5);
            if ($endAnchors === '') {
                return MatchResult::inconclusive('all_wildcard');
            }
            return str_ends_with($full, $endAnchors)
                ? MatchResult::match(strlen($endAnchors), 5)
                : MatchResult::mismatch('end_anchor_mismatch');
        }

        // Generic glob: convert * to .* and try regex match.
        // Escape regex metacharacters in the pattern except *.
        $escaped = preg_quote($pattern, '/');
        $regex = '/^' . str_replace('\\*', '.*', $escaped) . '$/u';
        if (@preg_match($regex, $full)) {
            $starsCount = substr_count($pattern, '*');
            $anchorsMatched = strlen($pattern) - $starsCount;
            if ($starsCount === 0 || $anchorsMatched === 0) {
                return MatchResult::inconclusive('no_mask_or_no_anchor');
            }
            return MatchResult::match($anchorsMatched, $starsCount);
        }
        return MatchResult::mismatch('glob_no_match');
    }

    /**
     * Fuzzy address match (Phase 24 / D-5-4).
     *
     * Strategy:
     *   1. Try the strict suffix matcher (matchText). If it matches,
     *      accept it at FULL confidence (backward-compat for the common
     *      case where suffix actually aligns).
     *   2. Otherwise, strip the leading `*****` mask from the pattern
     *      (the upstream always prefixes business_address with it),
     *      normalize both strings (lowercase, strip punctuation, collapse
     *      whitespace), and check if the visible suffix is CONTAINED in
     *      the local full address. Accept at REDUCED confidence
     *      (containsConfidence × weight).
     *   3. Otherwise, mismatch.
     *
     * The reduced confidence is applied via MatchResult::matchFuzzy(),
     * which carries a scoreMultiplier that the quorum function multiplies
     * into the field's weight. The field still counts as 1 toward the
     * field-count quorum (AutoConfirmed requires national + 2 others).
     *
     * Why this works: the upstream's address string is often a
     * different concatenation order than the local (street + alley +
     * plaque), but the visible suffix (e.g. "خیابان مطهری، پلاک ۱۱")
     * usually appears as a substring of the local address. The strict
     * suffix matcher required the suffix to align AT THE END; the
     * contains matcher just requires it to be ANYWHERE.
     *
     * Examples:
     *   matchAddress('خیابان مطهری 11، کوچه علی‌آباد، پلاک 25',
     *                '*****، خیابان مطهری 11')
     *     → match (contains, 0.7 confidence — suffix is "خیابان مطهری 11"
     *       which is contained in the full value, even though the full
     *       value doesn't end with it).
     */
    public function matchAddress(string $fullValue, string $maskedPattern): MatchResult
    {
        if ($maskedPattern === '') {
            return MatchResult::inconclusive('empty_pattern');
        }

        // 1. Strict suffix matcher first — if it passes, full confidence.
        $strict = $this->matchText($fullValue, $maskedPattern);
        if ($strict->matched) {
            return $strict;
        }

        // 2. Fuzzy contains mode. Strip the leading `*****` mask if present;
        //    the remainder is the visible trailing anchor.
        $visibleSuffix = $maskedPattern;
        if (str_starts_with($visibleSuffix, '*****')) {
            $visibleSuffix = substr($visibleSuffix, 5);
        }
        $visibleSuffix = $this->normalizeAddress($visibleSuffix);
        $fullNormalized = $this->normalizeAddress($fullValue);

        if ($visibleSuffix === '') {
            return MatchResult::inconclusive('pattern_all_wildcard');
        }
        if ($fullNormalized === '') {
            return MatchResult::inconclusive('empty_full_value');
        }

        // Contains either direction (the suffix is usually shorter and
        // contained in the full address, but the local address may also
        // be a substring of a longer upstream description).
        if (
            mb_strpos($fullNormalized, $visibleSuffix) !== false
            || mb_strpos($visibleSuffix, $fullNormalized) !== false
        ) {
            return MatchResult::matchFuzzy(
                anchorsMatched: mb_strlen($visibleSuffix),
                wildcardsMatched: 0,
                scoreMultiplier: $this->containsConfidence,
                reason: 'address_contains_match',
            );
        }

        return MatchResult::mismatch('address_no_contains');
    }

    /**
     * Normalize an address string for fuzzy comparison.
     *
     *   - Persian/Arabic digits → Latin (via Normalizer::digits).
     *   - Lowercase (for any Latin chars).
     *   - Strip Persian and Latin punctuation (., ، ؛ : ! ؟ - _ ' " () [] {}).
     *   - Strip ZWNJ / ZWJ (they don't carry address meaning).
     *   - Collapse whitespace runs to a single space.
     *   - Trim.
     */
    private function normalizeAddress(string $s): string
    {
        // Persian/Arabic digits → Latin (so ۱۱ == 11).
        $s = Normalizer::digits($s);
        // Lowercase Latin chars.
        $s = mb_strtolower($s);
        // Strip Persian and Latin punctuation + ZWNJ/ZWJ.
        $s = preg_replace(
            '/[.,،؛:!؟\-_\'"()\[\]{}\x{200c}\x{200d}]/u',
            ' ',
            $s
        ) ?? $s;
        // Collapse whitespace runs to a single space.
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * Match an exact text field (e.g. name, father name, province).
     *
     * Per docs/00-MASTER.md §9.3 these fields use "exact text" matching.
     * Normalizer::digits is applied (in case of Persian digits in names
     * like "۱۰م" — rare but possible) and ZWNJ-aware comparison.
     */
    public function matchExactText(string $fullValue, string $upstreamValue): MatchResult
    {
        $full = Normalizer::persianText($fullValue);
        $upstream = Normalizer::persianText($upstreamValue);

        if ($full === '' || $upstream === '') {
            return MatchResult::inconclusive('empty_value');
        }

        if ($full === $upstream) {
            return MatchResult::match(strlen($full), 0, 'exact_match');
        }
        return MatchResult::mismatch('text_differs');
    }

    /**
     * Fuzzy text match (for license_title) — contains check.
     *
     * The upstream license_title is often a longer description (e.g.
     * "کسب و کار خانگی دوخت چادر شب باف") while the local registration
     * may have a shorter service-type title. We check if one contains
     * the other (case-insensitive, after normalization).
     */
    public function matchFuzzyText(string $fullValue, string $upstreamValue): MatchResult
    {
        $full = Normalizer::persianText($fullValue);
        $upstream = Normalizer::persianText($upstreamValue);

        if ($full === '' || $upstream === '') {
            return MatchResult::inconclusive('empty_value');
        }

        if ($full === $upstream) {
            return MatchResult::match(strlen($full), 0, 'exact_match');
        }
        // contains either direction
        if (mb_strpos($full, $upstream) !== false || mb_strpos($upstream, $full) !== false) {
            return MatchResult::match(strlen($full), 0, 'contains_match');
        }
        return MatchResult::mismatch('fuzzy_no_match');
    }

    /**
     * Full quorum match: national license vs. local applicant's Person.
     *
     * Per docs/00-MASTER.md §9.3-9.5 — aggregates 9 weighted fields and
     * decides the MatchConfidence.
     *
     * @param License $license  The upstream national license (with masked fields).
     * @param Person $person  The local applicant's identity (full fields).
     * @param array{province?:string,township?:string,business_address?:string} $property  Local property details.
     */
    public function matchQuorum(
        License $license,
        Person $person,
        array $property = []
    ): MatchResult {
        $fieldResults = [];

        // 1. National code — masked pattern match.
        $nationalCodeResult = $this->matchNumeric(
            $person->nationalCode,
            $license->maskedNationalCode ?? ''
        );
        $fieldResults['national_code'] = $nationalCodeResult;

        // 2. Full name — exact text match.
        $fullName = trim($person->firstName . ' ' . $person->lastName);
        $fieldResults['full_name'] = $this->matchExactText($fullName, $license->applicantName ?? '');

        // 3. Father name — exact text match.
        $fieldResults['father_name'] = $this->matchExactText(
            $person->fatherName ?? '',
            $license->fatherName ?? ''
        );

        // 4. Business province — exact text match.
        // License carries the upstream province as provinceTitle (the gateway
        // didn't denormalize a separate businessProvince field).
        $fieldResults['business_province'] = $this->matchExactText(
            $property['province'] ?? '',
            $license->provinceTitle ?? ''
        );

        // 5. Business township — exact text match.
        // Phase 24 (D-5-5): prefer the new `township` field on
        // property_details (forwarded by ApplicantSyncAdapter) if set;
        // otherwise fall back to `county` (v2/v3 convention).
        $fieldResults['business_township'] = $this->matchExactText(
            $property['township'] ?? $property['county'] ?? '',
            $license->townshipTitle ?? ''
        );

        // 6. License title — fuzzy text match.
        $fieldResults['license_title'] = $this->matchFuzzyText(
            '',  // Local service-type title (TODO: wire from registration)
            $license->licenseTitle ?? ''
        );

        // 7. Postal code — masked numeric match.
        $fieldResults['postal_code'] = $this->matchNumeric(
            $property['postal_code'] ?? '',
            $license->postalCodeMasked ?? ''
        );

        // 8. Business address — Phase 24 / D-5-4: route via matchAddress()
        //    when the field mode is 'contains', else fall back to matchText()
        //    (suffix). The 'contains' mode tries strict suffix first, then
        //    fuzzy contains — so it's a superset of the old behaviour.
        $addressMode = $this->modes['business_address'] ?? 'suffix';
        $fieldResults['business_address'] = $addressMode === 'contains'
            ? $this->matchAddress(
                $property['business_address'] ?? '',
                $license->businessAddress ?? ''
            )
            : $this->matchText(
                $property['business_address'] ?? '',
                $license->businessAddress ?? ''
            );

        // 9. Request number — auxiliary signal (exact match).
        $fieldResults['request_number'] = $this->matchExactText(
            '',  // TODO: wire local tracking code if applicable
            $license->requestNumber ?? ''
        );

        return MatchResult::quorum(
            $fieldResults,
            self::WEIGHTS,
            nationalCodeMatched: $nationalCodeResult->matched,
            autoThreshold: 90,
            operatorThreshold: 50
        );
    }

    /**
     * @return array<string,int>
     */
    public function weights(): array
    {
        return self::WEIGHTS;
    }
}
