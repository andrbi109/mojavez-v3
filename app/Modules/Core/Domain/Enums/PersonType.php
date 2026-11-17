<?php
declare(strict_types=1);

namespace App\Modules\Core\Domain\Enums;

/**
 * Person type — حقیقی (real person) or حقوقی (legal entity).
 *
 * Used in `persons.person_type` and `applicants.applicant_type`.
 * Affects which identity fields are required (company_national_id for
 * legal entities, etc.).
 */
enum PersonType: string
{
    case Real = 'حقیقی';
    case Legal = 'حقوقی';

    public function titleFa(): string
    {
        return $this->value;
    }

    public function isLegal(): bool
    {
        return $this === self::Legal;
    }

    public function isReal(): bool
    {
        return $this === self::Real;
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
}
