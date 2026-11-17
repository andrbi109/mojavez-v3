<?php
declare(strict_types=1);

/**
 * Pattern matcher config — controls per-field match mode for the
 * "golden link" quorum matcher.
 *
 * Phase 24 (v1.9.6): pays D-5-4. The upstream national gateway masks
 * business_address with a `*****` prefix (the first 5 characters are
 * hidden). The local property address is the full concatenation
 * (street + alley + plaque), which is usually longer and starts with
 * characters the upstream never sees. The old `suffix` matcher
 * required the trailing anchors to align perfectly — which often
 * failed because the local concatenation order differs from the
 * upstream's address string.
 *
 * The new `contains` mode for business_address normalizes both
 * strings (lowercase, strip punctuation, collapse whitespace) and
 * accepts a match if the shorter string is contained in the longer
 * one. The match is scored at 70% of the full weight (rounded down)
 * to reflect the reduced confidence.
 *
 * Other fields (national_code, postal_code) keep the strict masked
 * matcher. License_title keeps the fuzzy contains matcher (already
 * in place since Phase 4). Exact-text fields (full_name, father_name,
 * province, township) keep matchExactText.
 *
 * Modes:
 *   - 'masked'    : digit-by-digit alignment with `*` wildcards (numeric).
 *   - 'suffix'    : `*****` at the start → trailing anchors must align (text).
 *   - 'contains'  : substring after normalization (text, fuzzy).
 *   - 'exact'     : case-sensitive exact match after persianText().
 *   - 'fuzzy'     : contains either direction (license_title).
 *
 * @return array<string,mixed>
 */
return [
    // Per-field match mode. Keys mirror PatternMatcher::WEIGHTS.
    // Missing keys default to the original behaviour:
    //   - national_code / postal_code → masked (matchNumeric).
    //   - business_address            → suffix (matchText).
    //   - business_province / business_township → exact (matchExactText).
    //   - full_name / father_name / request_number → exact (matchExactText).
    //   - license_title                → fuzzy (matchFuzzyText).
    'modes' => [
        'business_address' => 'contains',
    ],

    // Confidence multiplier applied to the field's weight when the
    // match is via `contains` (fuzzy) mode. 1.0 = full weight, 0.7 =
    // 70% credit (e.g. weight 4 → contributes 4 to totalScore but
    // counts as 0.7 fields for the field-count quorum). Phase 24
    // default: 0.7 — fuzzy address match is a real signal but weaker
    // than an exact suffix match.
    'contains_confidence' => 0.7,
];
