<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Modules\NationalGateway\Domain\License;
use App\Shared\Contracts\NationalLicenseRepositoryInterface;
use App\Shared\Support\PersianDate;

/**
 * ExportService — generates CSV / JSON / printable HTML exports of
 * cached national licenses.
 *
 * Phase 62 (v2.2.8) — fully rewritten to:
 *   - Use NationalLicenseRepositoryInterface (the previous version
 *     imported the non-existent `App\Persistence\LicenseRepository`
 *     class — a leftover from the gateway project's flat
 *     namespace that was never reachable from v3's autoloader, so
 *     the class was effectively dead code; the rewrite makes it
 *     actually constructible from the Container).
 *   - Use `$result['rows']` (the correct key returned by
 *     NationalLicenseRepository::queryCached — the previous version
 *     used `$result['items']` which never exists, so even if the
 *     class HAD been instantiated it would have produced empty CSVs).
 *   - Export ALL the columns that national_licenses stores
 *     (request_number, applicant_name, license_title,
 *     organization_title, province_title, township_title,
 *     responded_at, status_slug, status_title,
 *     masked_national_code, father_name, business_address,
 *     postal_code_masked, isic_code, isic_title, issue_type,
 *     expires_at, supervisory_authorities, is_saved, saved_at,
 *     last_seen_at, matched_applicant_id, match_confidence,
 *     matched_by). The previous version only exported 9 columns.
 *   - Add a `toHtml()` method that produces a print-friendly HTML
 *     table (with Jalali dates via PersianDate::format) — the
 *     task spec accepts either a real PDF or an HTML-to-print
 *     view; the print view is simpler, has no extra dependencies
 *     (the project is pure-PHP with no Composer), and the admin
 *     can use the browser's "Print to PDF" function.
 *
 * The service is intentionally stateless — it doesn't write to
 * the exportDir anymore (the directory creation was the only
 * reason the constructor needed it; the inline CSV/HTML/JSON
 * strings are returned to the caller, which streams them via
 * Response). The exportDir constructor argument is kept for
 * backward-compat (existing callers that may have been wired in
 * tests) but ignored.
 */
final class ExportService
{
    /**
     * The columns included in the CSV / JSON / HTML exports, in
     * the order they appear. Mirrors the columns on
     * national_licenses so the admin gets a complete picture of
     * every cached license (not just a 9-column subset).
     */
    private const EXPORT_COLUMNS = [
        'request_number', 'applicant_name', 'license_title',
        'organization_title', 'province_title', 'township_title',
        'responded_at', 'status_slug', 'status_title',
        'masked_national_code', 'father_name', 'business_address',
        'postal_code_masked', 'isic_code', 'isic_title', 'issue_type',
        'expires_at', 'supervisory_authorities',
        'is_saved', 'saved_at', 'last_seen_at',
        'matched_applicant_id', 'match_confidence', 'matched_by',
    ];

    public function __construct(
        private readonly NationalLicenseRepositoryInterface $licenseRepo,
        /** @deprecated unused since Phase 62 — kept for BC. */
        private readonly string $exportDir = ''
    ) {}

    /**
     * Export filtered licenses as a CSV string (UTF-8 with BOM for
     * Excel auto-detection).
     *
     * @param array<string,mixed> $filter
     */
    public function toCsv(array $filter, int $maxRows = 1000): string
    {
        $result = $this->licenseRepo->queryCached($filter, 1, min($maxRows, 1000));
        $rows = $result['rows'] ?? [];

        ob_start();
        $fp = fopen('php://output', 'w');
        // UTF-8 BOM so Excel auto-detects the encoding.
        fwrite($fp, "\xEF\xBB\xBF");
        // Header row.
        fputcsv($fp, self::EXPORT_COLUMNS, ',', '"', '');
        // Data rows.
        foreach ($rows as $row) {
            $line = [];
            foreach (self::EXPORT_COLUMNS as $col) {
                $v = $row[$col] ?? '';
                $line[] = $v === null ? '' : (string)$v;
            }
            fputcsv($fp, $line, ',', '"', '');
        }
        fclose($fp);
        return (string)ob_get_clean();
    }

    /**
     * Export filtered licenses as a JSON string (pretty-printed,
     * UTF-8 unescaped).
     *
     * @param array<string,mixed> $filter
     */
    public function toJson(array $filter, int $maxRows = 1000): string
    {
        $result = $this->licenseRepo->queryCached($filter, 1, min($maxRows, 1000));
        $rows = $result['rows'] ?? [];
        // Project to the export columns so the JSON output matches
        // the CSV output 1:1 (the raw DB row may have extra columns
        // like id, filter_hash, first_seen_at that aren't useful
        // for export).
        $items = array_map(function (array $row): array {
            $out = [];
            foreach (self::EXPORT_COLUMNS as $col) {
                $out[$col] = $row[$col] ?? null;
            }
            return $out;
        }, $rows);
        return (string)json_encode([
            'total'    => $result['total'] ?? count($items),
            'exported' => count($items),
            'items'    => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Export filtered licenses as a printable HTML document.
     *
     * Phase 62 (v2.2.8) — this is the "PDF export" the task spec
     * mentions ("or at least generates a simple HTML-to-print
     * view"). The HTML is a self-contained document with a
     * print-friendly stylesheet; the admin opens it in the
     * browser and uses "Print → Save as PDF" to produce a real
     * PDF. Pure-PHP, no Composer, no extra deps.
     *
     * Dates are formatted as Jalali via PersianDate::format() so
     * the printed document is consistent with the rest of the
     * admin UI (which shows Jalali dates everywhere).
     *
     * @param array<string,mixed> $filter
     */
    public function toHtml(array $filter, int $maxRows = 500, string $title = 'گزارش مجوزهای درگاه ملی'): string
    {
        $result = $this->licenseRepo->queryCached($filter, 1, min($maxRows, 1000));
        $rows = $result['rows'] ?? [];
        $total = (int)($result['total'] ?? count($rows));
        $generatedAt = (new PersianDate())->todayJalali();

        $h = static function (?string $v): string {
            return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
        };
        $jdate = static function (?string $greg): string {
            if ($greg === null || $greg === '') return '—';
            $f = PersianDate::format($greg);
            return $f === '—' ? '—' : $f;
        };

        ob_start();
        ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $h($title) ?></title>
    <style>
        @page { size: A4 landscape; margin: 1cm; }
        body { font-family: Vazirmatn, 'Vazirmatn', 'Tahoma', sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 4px 0; }
        .meta { color: #6b7280; font-size: 10px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: right; }
        thead th { background: #f3f4f6; font-weight: 700; }
        tbody tr:nth-child(even) { background: #fafafa; }
        .small { font-size: 9px; color: #6b7280; }
        @media print {
            .no-print { display: none; }
        }
        .toolbar { margin-bottom: 12px; }
        .toolbar button { font-family: inherit; padding: 6px 14px; border: 1px solid #1f2937; background: #1f2937; color: #fff; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <button onclick="window.print()">چاپ / ذخیره به‌عنوان PDF</button>
    </div>
    <h1><?= $h($title) ?></h1>
    <div class="meta">
        تولیدشده در <?= $h($generatedAt) ?> ·
        <?= count($rows) ?> رکورد از مجموع <?= $total ?> ·
        فیلتر: <?= $h(json_encode($filter, JSON_UNESCAPED_UNICODE)) ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>شماره مجوز</th>
                <th>متقاضی</th>
                <th>عنوان مجوز</th>
                <th>سازمان</th>
                <th>استان / شهرستان</th>
                <th>تاریخ پاسخ</th>
                <th>وضعیت</th>
                <th>کد ملی (ماسک‌شده)</th>
                <th>نام پدر</th>
                <th>تاریخ انقضا</th>
                <th>تطبیق</th>
            </tr>
        </thead>
        <tbody>
        <?php $i = 1; foreach ($rows as $row): ?>
            <tr>
                <td class="small"><?= $i++ ?></td>
                <td><?= $h($row['request_number'] ?? '') ?></td>
                <td><?= $h($row['applicant_name'] ?? '') ?></td>
                <td class="small"><?= $h($row['license_title'] ?? '') ?></td>
                <td class="small"><?= $h($row['organization_title'] ?? '') ?></td>
                <td class="small"><?= $h(($row['province_title'] ?? '') . ' / ' . ($row['township_title'] ?? '')) ?></td>
                <td class="small"><?= $jdate($row['responded_at'] ?? null) ?></td>
                <td><?= $h($row['status_title'] ?? $row['status_slug'] ?? '') ?></td>
                <td class="small"><?= $h($row['masked_national_code'] ?? '') ?></td>
                <td class="small"><?= $h($row['father_name'] ?? '') ?></td>
                <td class="small"><?= $jdate($row['expires_at'] ?? null) ?></td>
                <td class="small"><?= (int)($row['match_confidence'] ?? 0) ?>٪</td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
            <tr><td colspan="12" style="text-align: center; padding: 24px; color: #6b7280;">هیچ مجوزی برای این فیلتر یافت نشد.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
        <?php
        return (string)ob_get_clean();
    }
}
