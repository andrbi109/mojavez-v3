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
 * Two match modes:
 *   - matchNumeric($full, $masked): digit-by-digit alignment (national code,
 *     postal code). * = single-char wildcard.
 *   - matchText($full, $masked): glob-style alignment (business address).
 *     ***** (5 stars) = suffix wildcard — only the trailing anchors matter.
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
        'business_address'     => 4,  // masked text pattern
        'request_number'       => 1,  // auxiliary signal
    ];

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
     * Match a full text value against a masked text pattern.
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
            return MatchResult::match(min(strlen($full), strlen($upstream)), 0, 'contains_match');
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
        $fieldResults['business_province'] = $this->matchExactText(
            $property['province'] ?? '',
            $license->businessProvince ?? ''
        );

        // 5. Business township — exact text match.
        $fieldResults['business_township'] = $this->matchExactText(
            $property['township'] ?? '',
            $license->businessTownship ?? ''
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

        // 8. Business address — masked text match.
        $fieldResults['business_address'] = $this->matchText(
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
