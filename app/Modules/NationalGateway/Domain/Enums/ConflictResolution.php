<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Domain\Enums;

/**
 * Conflict resolution outcome from the ACL ApplicantSyncAdapter.
 *
 * See docs/integration-contract.md §4.3 (Contract C).
 *
 * When the golden-link orchestrator (Phase 4) tries to apply a License
 * delta to a local Applicant, the ApplicantSyncAdapter consults the
 * source-of-truth matrix (docs/00-MASTER.md §23.1) and the optimistic
 * concurrency version to decide how to proceed:
 *
 *   ApplyAsIs        — upstream is newer than local; apply the delta.
 *   SkipLocalNewer    — local is newer than upstream's last_seen_at; skip.
 *   ForceLocal        — local is the source of truth for this field; ignore upstream.
 *   RequiresOperator  — ambiguous case; flag for manual review.
 */
enum ConflictResolution: string
{
    case ApplyAsIs = 'apply_as_is';
    case SkipLocalNewer = 'skip_local_newer';
    case ForceLocal = 'force_local';
    case RequiresOperator = 'requires_operator';

    public function titleFa(): string
    {
        return match ($this) {
            self::ApplyAsIs        => 'اعمال تغییرات upstream',
            self::SkipLocalNewer    => 'رد به‌روزرسانی (محلی جدید‌تر است)',
            self::ForceLocal        => 'حفظ مقدار محلی',
            self::RequiresOperator  => 'نیازمند تصمیم اپراتور',
        };
    }

    /**
     * Whether this resolution allows the delta to be applied.
     */
    public function appliesDelta(): bool
    {
        return $this === self::ApplyAsIs;
    }

    /**
     * Whether this resolution requires operator action.
     */
    public function requiresOperator(): bool
    {
        return $this === self::RequiresOperator;
    }
}
