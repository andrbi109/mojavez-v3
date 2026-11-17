<?php
declare(strict_types=1);

namespace App\Modules\Core\Domain\Enums;

/**
 * The 4 license state values (inherited from v2).
 *
 * Independent of `current_status` — `license_state` reflects the actual
 * license validity state, set by `Person::updateLicenseState()` or by the
 * golden-link orchestrator (Phase 4).
 *
 * Maps to the upstream national-gateway statuses (docs/00-MASTER.md §6.2):
 *   Issued   → 'active' (when license_valid_until >= today)
 *   Current  → 'pending'
 *   Revoked  → 'revoked'
 *   Expired  → 'expired'
 */
enum LicenseState: string
{
    case Current = 'جاری';          // in review / pending
    case Issued = 'صادر شده';        // valid (if valid_until >= today)
    case Revoked = 'باطل شده';       // revoked
    case Expired = 'منقضی';          // expired (system-set)

    /**
     * Map v3 license state to the upstream national-gateway status slug.
     */
    public function toNationalStatus(): string
    {
        return match ($this) {
            self::Issued   => 'active',
            self::Current  => 'pending',
            self::Revoked  => 'revoked',
            self::Expired  => 'expired',
        };
    }

    /**
     * The Persian title (the enum value itself).
     */
    public function titleFa(): string
    {
        return $this->value;
    }

    /**
     * Bootstrap badge colour for the license state.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Issued   => 'success',
            self::Current  => 'info',
            self::Revoked  => 'danger',
            self::Expired  => 'warning',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $slug) {
                return $case;
            }
        }
        return null;
    }

    /**
     * Map an upstream national-gateway status slug to a v3 LicenseState.
     */
    public static function fromNationalStatus(string $slug): self
    {
        return match ($slug) {
            'active'  => self::Issued,
            'pending' => self::Current,
            'revoked' => self::Revoked,
            'expired' => self::Expired,
            default   => self::Current,
        };
    }
}
