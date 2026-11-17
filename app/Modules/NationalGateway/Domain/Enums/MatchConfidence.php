<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Domain\Enums;

/**
 * Match confidence levels for the golden-link orchestrator.
 *
 * NEW in v3 — used by PatternMatcher to decide the automated action:
 *
 *   AutoConfirmed (≥90): the scraper found a national license whose masked
 *     national code matches a local applicant's full national code AND at
 *     least 2 other fields match. → auto-transition applicant to
 *     'صدور مجوز', set license_state='صادر شده', send notification.
 *
 *   OperatorFlag (50-89): 1-2 fields match but no full national code match.
 *     → flag in admin panel for manual review.
 *
 *   AutoRejected (0): anchor mismatch on the masked national code (the
 *     visible digits are not aligned with the local applicant's code).
 *     → log as auto_rejected in sync_audit_log, no status change.
 *
 * Thresholds are configurable via SYNC_AUTO_CONFIRM_THRESHOLD and
 * SYNC_OPERATOR_FLAG_THRESHOLD env vars (defaults: 90, 50).
 */
enum MatchConfidence: int
{
    case AutoConfirmed = 90;
    case OperatorFlag = 50;
    case AutoRejected = 0;

    /**
     * Decide the confidence level from a numeric score.
     */
    public static function fromScore(int $score, int $autoThreshold = 90, int $operatorThreshold = 50): self
    {
        if ($score >= $autoThreshold) {
            return self::AutoConfirmed;
        }
        if ($score >= $operatorThreshold) {
            return self::OperatorFlag;
        }
        return self::AutoRejected;
    }

    public function titleFa(): string
    {
        return match ($this) {
            self::AutoConfirmed => 'تأیید خودکار',
            self::OperatorFlag  => 'نیازمند مرور اپراتور',
            self::AutoRejected  => 'رد خودکار',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::AutoConfirmed => 'success',
            self::OperatorFlag  => 'warning',
            self::AutoRejected  => 'danger',
        };
    }

    /**
     * Whether this confidence level may auto-transition the applicant
     * (golden-link "act" step — see docs/00-MASTER.md §9.5).
     */
    public function mayAutoAct(): bool
    {
        return $this === self::AutoConfirmed;
    }

    /**
     * Whether this confidence level requires an operator decision.
     */
    public function requiresOperator(): bool
    {
        return $this === self::OperatorFlag;
    }
}
