<?php
declare(strict_types=1);

namespace App\Modules\Core\Domain;

use App\Modules\Core\Domain\Enums\PersonType;
use App\Shared\Support\Normalizer;

/**
 * Person — the identity anchor (one person → many applications).
 *
 * Inherited from v2's `persons` table, extended for v3 with:
 *  - readonly immutable Value Object (CODING_CONVENTIONS §2.3).
 *  - father_name and birth_date fields (for golden-link pattern matching).
 *  - fullAddressMaskPattern() helper (NEW in v3) — used by PatternMatcher
 *    to build the masked-pattern anchor for property address matching.
 *
 * Storage: `persons` table. National code is UNIQUE (the identity anchor).
 */
final readonly class Person
{
    public function __construct(
        public int $id,
        public string $nationalCode,             // CHAR(10), validated + padded
        public string $firstName,
        public string $lastName,
        public string $mobile,                   // canonical 09XXXXXXXXX
        public PersonType $personType,
        public ?string $companyNationalId = null, // CHAR(11) for legal entities
        public ?string $companyName = null,
        public ?string $fatherName = null,       // for pattern matching
        public ?string $birthDate = null,         // DATE (Gregorian)
        public ?string $mobile2 = null,
        public ?string $mobile3 = null,
        public ?string $postalCode = null,
        public string $createdAt = '',
    ) {}

    /**
     * Build a Person from a raw DB row (FETCH_ASSOC).
     *
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (int)($row['id'] ?? 0),
            nationalCode: (string)($row['national_code'] ?? ''),
            firstName: (string)($row['first_name'] ?? ''),
            lastName: (string)($row['last_name'] ?? ''),
            mobile: (string)($row['mobile'] ?? ''),
            personType: PersonType::fromSlug((string)($row['person_type'] ?? 'حقیقی')) ?? PersonType::Real,
            companyNationalId: isset($row['company_national_id']) ? (string)$row['company_national_id'] : null,
            companyName: isset($row['company_name']) ? (string)$row['company_name'] : null,
            fatherName: isset($row['father_name']) ? (string)$row['father_name'] : null,
            birthDate: isset($row['birth_date']) ? (string)$row['birth_date'] : null,
            mobile2: isset($row['mobile_2']) ? (string)$row['mobile_2'] : null,
            mobile3: isset($row['mobile_3']) ? (string)$row['mobile_3'] : null,
            postalCode: isset($row['postal_code']) ? (string)$row['postal_code'] : null,
            createdAt: (string)($row['created_at'] ?? ''),
        );
    }

    /**
     * Full name for display.
     */
    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /**
     * Build a masked national-code pattern from this person's full code.
     * Used by PatternMatcher to compare with the upstream's masked code.
     *
     * E.g. 0720593107 → 072****107 (first 3 + last 3 are anchors).
     */
    public function nationalCodeMaskPattern(): string
    {
        return Normalizer::nationalCodeMask($this->nationalCode);
    }

    /**
     * Build a masked pattern for the postal code (used in pattern matching).
     * The postal code is 10 digits; mask the middle 4.
     */
    public function postalCodeMaskPattern(): string
    {
        $code = preg_replace('/[^0-9]/', '', Normalizer::digits($this->postalCode ?? ''));
        if (strlen($code) !== 10) {
            return '';
        }
        return substr($code, 0, 3) . '****' . substr($code, -3);
    }

    /**
     * Whether this person is a legal entity (شرکت).
     */
    public function isLegal(): bool
    {
        return $this->personType->isLegal();
    }
}
