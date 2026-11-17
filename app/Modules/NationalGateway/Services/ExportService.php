<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Modules\NationalGateway\Domain\License;
use App\Persistence\LicenseRepository;
use App\Shared\Support\CsvReader;

/**
 * Export service — generates CSV/JSON from cached licenses.
 */
final class ExportService
{
    public function __construct(
        private readonly LicenseRepository $licenseRepo,
        private readonly string $exportDir
    ) {
        if (!is_dir($exportDir)) {
            @mkdir($exportDir, 0775, true);
        }
    }

    /**
     * Export filtered licenses as CSV string.
     *
     * @param array<string,mixed> $filter
     */
    public function toCsv(array $filter, int $maxRows = 1000): string
    {
        $result = $this->licenseRepo->queryCached($filter, 1, min($maxRows, 1000));
        $rows = array_map(static fn (License $l) => [
            'request_number' => $l->requestNumber,
            'applicant_name' => $l->applicantName,
            'license_title' => $l->licenseTitle,
            'organization_title' => $l->organizationTitle,
            'province_title' => $l->provinceTitle,
            'township_title' => $l->townshipTitle,
            'responded_at' => $l->respondedAt,
            'status' => $l->status->value,
            'status_title' => $l->status->titleFa(),
        ], $result['items']);

        // Build CSV in memory
        $headers = ['request_number','applicant_name','license_title','organization_title','province_title','township_title','responded_at','status','status_title'];
        ob_start();
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // BOM
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
        return (string)ob_get_clean();
    }

    /**
     * Export filtered licenses as JSON string.
     *
     * @param array<string,mixed> $filter
     */
    public function toJson(array $filter, int $maxRows = 1000): string
    {
        $result = $this->licenseRepo->queryCached($filter, 1, min($maxRows, 1000));
        $items = array_map(static fn (License $l) => $l->toArray(), $result['items']);
        return (string)json_encode([
            'total' => $result['total'],
            'exported' => count($items),
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
