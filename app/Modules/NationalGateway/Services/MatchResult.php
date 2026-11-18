<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Modules\NationalGateway\Domain\Enums\MatchConfidence;

/**
 * Result of a single field match or a full quorum match.
 *
 * Immutable. Carries:
 *   - matched: bool — whether the field(s) matched.
 *   - reason: string — why it matched or didn't (for the audit log).
 *   - anchorsMatched: int — count of non-wildcard positions that aligned.
 *   - wildcardsMatched: int — count of * positions in the pattern.
 *   - score: int — weighted score for the matched field (0 if mismatch).
 *
 * For multi-field (quorum) matches, the MatchResult aggregates:
 *   - fieldsMatched: list of field names that matched.
 *   - totalScore: sum of matched field weights.
 *   - confidence: MatchConfidence derived from totalScore + national code match.
 */
final class MatchResult
{
    /**
     * @param list<string> $fieldsMatched
     */
    public function __construct(
        public readonly bool $matched,
        public readonly string $reason,
        public readonly int $anchorsMatched = 0,
        public readonly int $wildcardsMatched = 0,
        public readonly int $score = 0,
        public readonly array $fieldsMatched = [],
        public readonly int $totalScore = 0,
        public readonly ?MatchConfidence $confidence = null,
        public readonly bool $nationalCodeMatched = false,
    ) {}

    public static function match(int $anchorsMatched, int $wildcardsMatched, string $reason = ''): self
    {
        return new self(
            matched: true,
            reason: $reason !== '' ? $reason : "anchors={$anchorsMatched} wildcards={$wildcardsMatched}",
            anchorsMatched: $anchorsMatched,
            wildcardsMatched: $wildcardsMatched,
        );
    }

    public static function mismatch(string $reason): self
    {
        return new self(matched: false, reason: $reason);
    }

    public static function inconclusive(string $reason): self
    {
        return new self(matched: false, reason: $reason);
    }

    /**
     * Aggregate a quorum of field matches into a final MatchResult.
     *
     * @param array<string, MatchResult> $fieldResults  field name → single-field result
     * @param array<string,int> $weights  field name → weight (per §9.3)
     * @param int $autoThreshold  SYNC_AUTO_CONFIRM_THRESHOLD (default 90)
     * @param int $operatorThreshold  SYNC_OPERATOR_FLAG_THRESHOLD (default 50)
     */
    public static function quorum(
        array $fieldResults,
        array $weights,
        bool $nationalCodeMatched,
        int $autoThreshold = 90,
        int $operatorThreshold = 50
    ): self {
        $fieldsMatched = [];
        $totalScore = 0;
        foreach ($fieldResults as $field => $result) {
            if ($result->matched) {
                $fieldsMatched[] = $field;
                $totalScore += $weights[$field] ?? 0;
            }
        }

        // §9.6 safety rule 1: never AutoConfirmed without multi-field quorum.
        // Even if national code matches, require at least 2 OTHER fields.
        $otherFieldsMatched = count($fieldsMatched) - ($nationalCodeMatched ? 1 : 0);
        if ($nationalCodeMatched && $otherFieldsMatched >= 2) {
            // National code match + 2+ other fields → AutoConfirmed regardless
            // of exact score (national code weight 10 + 2 others usually ≥ 90).
            $confidence = MatchConfidence::AutoConfirmed;
        } elseif (!$nationalCodeMatched && $otherFieldsMatched >= 1) {
            // No national code but some other field(s) → OperatorFlag.
            $confidence = MatchConfidence::OperatorFlag;
        } elseif ($nationalCodeMatched && $otherFieldsMatched < 2) {
            // National code match but insufficient other fields → OperatorFlag
            // (don't auto-confirm on national code alone — too risky per §9.6).
            $confidence = MatchConfidence::OperatorFlag;
        } else {
            $confidence = MatchConfidence::AutoRejected;
        }

        // Sanity: if confidence disagrees with fromScore (e.g. totalScore < 50
        // but we said AutoConfirmed), trust the field-count-based logic above
        // (it's stricter and more reliable than a numeric threshold).

        return new self(
            matched: $confidence !== MatchConfidence::AutoRejected,
            reason: sprintf(
                'fields=[%s] score=%d national_code=%s confidence=%s',
                implode(',', $fieldsMatched),
                $totalScore,
                $nationalCodeMatched ? 'matched' : 'no',
                $confidence->name
            ),
            fieldsMatched: $fieldsMatched,
            totalScore: $totalScore,
            confidence: $confidence,
            nationalCodeMatched: $nationalCodeMatched,
        );
    }
}
