<?php
declare(strict_types=1);

namespace App\Modules\Core\Domain;

/**
 * PropertyDetail — property info for an applicant (1:1 with applicants).
 *
 * Inherited from v2's `property_details` table. The golden-link orchestrator
 * (Phase 4) reads `province` + `county` to build a search Filter for the
 * upstream national gateway.
 *
 * Storage: `property_details` table.
 */
final readonly class PropertyDetail
{
    public function __construct(
        public int $id,
        public int $applicantId,
        public string $ownershipStatus,        // 'شخص'|'مستأجر'|'سرپرست'
        public string $province,
        public string $county,
        public string $city,
        public ?string $district = null,
        public ?string $ruralDistrict = null,
        public ?string $village = null,
        public string $electricityBillId = '',
        public ?string $addressStreet = null,
        public ?string $addressAlley = null,
        public ?string $addressPlaque = null,
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (int)($row['id'] ?? 0),
            applicantId: (int)($row['applicant_id'] ?? 0),
            ownershipStatus: (string)($row['ownership_status'] ?? 'شخص'),
            province: (string)($row['province'] ?? ''),
            county: (string)($row['county'] ?? ''),
            city: (string)($row['city'] ?? ''),
            district: isset($row['district']) ? (string)$row['district'] : null,
            ruralDistrict: isset($row['rural_district']) ? (string)$row['rural_district'] : null,
            village: isset($row['village']) ? (string)$row['village'] : null,
            electricityBillId: (string)($row['electricity_bill_id'] ?? ''),
            addressStreet: isset($row['address_street']) ? (string)$row['address_street'] : null,
            addressAlley: isset($row['address_alley']) ? (string)$row['address_alley'] : null,
            addressPlaque: isset($row['address_plaque']) ? (string)$row['address_plaque'] : null,
        );
    }

    /**
     * Full assembled address (for display and pattern matching).
     */
    public function fullAddress(): string
    {
        $parts = array_filter([
            $this->province,
            $this->county,
            $this->city,
            $this->district,
            $this->ruralDistrict,
            $this->village,
            $this->addressStreet,
            $this->addressAlley,
            $this->addressPlaque,
        ], static fn ($v): bool => $v !== null && $v !== '');
        return implode('، ', $parts);
    }

    /**
     * Whether this property is rural (has a village or rural_district set).
     * Used by FeeCalculatorService for rural exemption.
     */
    public function isRural(): bool
    {
        return ($this->village !== null && $this->village !== '')
            || ($this->ruralDistrict !== null && $this->ruralDistrict !== '');
    }
}
