<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Domain\Enums;

/**
 * License status in the national gateway (qr.mojavez.ir).
 *
 * Inherited from gateway's LicenseStatus. v3 adds `titleFa()` for UI.
 */
enum LicenseStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Unknown = 'unknown';

    public function titleFa(): string
    {
        return match ($this) {
            self::Active  => 'فعال',
            self::Expired => 'منقضی',
            self::Unknown => 'نامشخص',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Active  => 'success',
            self::Expired => 'warning',
            self::Unknown => 'secondary',
        };
    }

    /**
     * Construct from an upstream status slug + id (the id may be a numeric
     * code from upstream's payload).
     */
    public static function fromUpstream(?string $slug, ?string $id = null): self
    {
        if ($slug === null) {
            return self::Unknown;
        }
        $lower = strtolower(trim($slug));
        return match ($lower) {
            'active', '1'   => self::Active,
            'expired', '0', '2' => self::Expired,
            default         => self::Unknown,
        };
    }
}
