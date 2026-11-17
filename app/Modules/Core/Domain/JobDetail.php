<?php
declare(strict_types=1);

namespace App\Modules\Core\Domain;

/**
 * JobDetail — occupational info for an applicant (1:1 with applicants).
 *
 * Inherited from v2's `job_details` table. Includes the `license_title`
 * which is the bridge to `license_titles.title` (the canonical license
 * title string for the applicant's service type).
 *
 * Storage: `job_details` table.
 */
final readonly class JobDetail
{
    public function __construct(
        public int $id,
        public int $applicantId,
        public string $licenseTitle,            // references license_titles.title
        public int $experienceMonths,
        public string $trainingMethod,           // 'در خانه'|'استاد شاگردی'|'کارگاهی'
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (int)($row['id'] ?? 0),
            applicantId: (int)($row['applicant_id'] ?? 0),
            licenseTitle: (string)($row['license_title'] ?? ''),
            experienceMonths: (int)($row['experience_months'] ?? 0),
            trainingMethod: (string)($row['training_method'] ?? 'در خانه'),
        );
    }

    /**
     * Human-readable experience in years+months.
     */
    public function experienceLabel(): string
    {
        $years = intdiv($this->experienceMonths, 12);
        $months = $this->experienceMonths % 12;
        $parts = [];
        if ($years > 0) {
            $parts[] = $years . ' سال';
        }
        if ($months > 0) {
            $parts[] = $months . ' ماه';
        }
        return $parts === [] ? 'بدون سابقه' : implode(' و ', $parts);
    }
}
