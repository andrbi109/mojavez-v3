<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Modules\Core\Domain\JobDetail;
use App\Modules\Core\Domain\PropertyDetail;
use App\Shared\Contracts\JobAndPropertyRepositoryInterface;

final class PdoJobAndPropertyRepository extends PdoRepository implements JobAndPropertyRepositoryInterface
{
    public function findJobDetailByApplicantId(int $applicantId): ?JobDetail
    {
        $row = $this->one('SELECT * FROM job_details WHERE applicant_id = ? LIMIT 1', [$applicantId]);
        return $row === null ? null : JobDetail::fromArray($row);
    }

    public function upsertJobDetail(int $applicantId, array $data): void
    {
        $this->execute(
            'INSERT INTO job_details (applicant_id, license_title, experience_months, training_method)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 license_title = VALUES(license_title),
                 experience_months = VALUES(experience_months),
                 training_method = VALUES(training_method)',
            [
                $applicantId,
                $data['license_title'],
                $data['experience_months'],
                $data['training_method'] ?? 'در خانه',
            ]
        );
    }

    public function findPropertyDetailByApplicantId(int $applicantId): ?PropertyDetail
    {
        $row = $this->one('SELECT * FROM property_details WHERE applicant_id = ? LIMIT 1', [$applicantId]);
        return $row === null ? null : PropertyDetail::fromArray($row);
    }

    public function upsertPropertyDetail(int $applicantId, array $data): void
    {
        $this->execute(
            'INSERT INTO property_details
                (applicant_id, ownership_status, province, county, city, district,
                 rural_district, village, electricity_bill_id, address_street,
                 address_alley, address_plaque)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 ownership_status = VALUES(ownership_status),
                 province = VALUES(province),
                 county = VALUES(county),
                 city = VALUES(city),
                 district = VALUES(district),
                 rural_district = VALUES(rural_district),
                 village = VALUES(village),
                 electricity_bill_id = VALUES(electricity_bill_id),
                 address_street = VALUES(address_street),
                 address_alley = VALUES(address_alley),
                 address_plaque = VALUES(address_plaque)',
            [
                $applicantId,
                $data['ownership_status'] ?? 'شخص',
                $data['province'] ?? '',
                $data['county'] ?? '',
                $data['city'] ?? '',
                $data['district'] ?? null,
                $data['rural_district'] ?? null,
                $data['village'] ?? null,
                $data['electricity_bill_id'] ?? '',
                $data['address_street'] ?? null,
                $data['address_alley'] ?? null,
                $data['address_plaque'] ?? null,
            ]
        );
    }
}
