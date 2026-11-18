<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\DocumentRepositoryInterface;
use App\Shared\Contracts\JobAndPropertyRepositoryInterface;
use App\Shared\Contracts\ServiceTypeRepositoryInterface;
use App\Shared\Contracts\SettingRepositoryInterface;
use App\Shared\Contracts\StatusLogRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\PersianDate;
use App\Shared\Support\StructuredLogger;

/**
 * ReportController — statistics reports + CSV/PDF exports.
 *
 * Phase 18 (v1.9.0): ported from v2's ReportController, adapted to v3's
 * modular architecture. The five `by*` actions share a single
 * `admin/report_stats.php` view (passed a `$report` array: title,
 * group_label, rows[label,count,percentage]). The general + grouped
 * reports have their own views. The CSV export uses UTF-8 BOM +
 * formula-injection protection; the PDF export is a printable HTML
 * page (no external PDF library — the user uses the browser's
 * "Save as PDF" dialog).
 *
 * Per docs/00-MASTER.md §11 — only role='admin' can access any of these.
 *
 * Routes (11 — registered in Core/Module.php):
 *   GET  /admin/reports/by-province              → byProvince
 *   GET  /admin/reports/by-city                  → byCity
 *   GET  /admin/reports/by-village               → byVillage
 *   GET  /admin/reports/by-license               → byLicense
 *   GET  /admin/reports/by-date                  → byDate
 *   GET  /admin/output/general                   → generalReport
 *   GET  /admin/output/single/{trackingCode}     → singleApplicantReport
 *   GET  /admin/output/grouped                   → groupedReportForm
 *   POST /admin/output/grouped                   → groupedReportResult
 *   GET  /admin/report/csv                        → csvReport
 *   GET  /admin/report/pdf                        → pdfReport
 */
final class ReportController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly Connection $conn,
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly StatusLogRepositoryInterface $statusLog,
        // Phase 38 (v2.0.4): the dedicated admin/print_single view needs the
        // full applicant graph (job + property + documents + service type
        // title + raw row for fields not exposed by the Applicant VO + the
        // site_name setting for the header). Resolved via the Container's
        // Core module bindings.
        private readonly JobAndPropertyRepositoryInterface $jobAndProperty,
        private readonly DocumentRepositoryInterface $documents,
        private readonly ServiceTypeRepositoryInterface $serviceTypes,
        private readonly SettingRepositoryInterface $settings,
        private readonly PersianDate $persianDate,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /admin/reports/by-province — count of applicants grouped by
     * property_details.province.
     */
    public function byProvince(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->conn->all(
            "SELECT COALESCE(NULLIF(p.province, ''), '— (ثبت نشده)') AS label,
                    COUNT(*) AS cnt
               FROM applicants a
               JOIN property_details p ON a.id = p.applicant_id
              WHERE a.deleted_at IS NULL
              GROUP BY p.province
              ORDER BY cnt DESC"
        );
        return $this->renderStatsReport('گزارش بر اساس استان', 'استان', $rows);
    }

    /**
     * GET /admin/reports/by-city — count grouped by property_details.city.
     */
    public function byCity(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->conn->all(
            "SELECT COALESCE(NULLIF(p.city, ''), '— (ثبت نشده)') AS label,
                    COUNT(*) AS cnt
               FROM applicants a
               JOIN property_details p ON a.id = p.applicant_id
              WHERE a.deleted_at IS NULL
              GROUP BY p.city
              ORDER BY cnt DESC"
        );
        return $this->renderStatsReport('گزارش بر اساس شهر', 'شهر', $rows);
    }

    /**
     * GET /admin/reports/by-village — count grouped by property_details.village
     * (only rows where village is non-empty).
     */
    public function byVillage(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->conn->all(
            "SELECT p.village AS label, COUNT(*) AS cnt
               FROM applicants a
               JOIN property_details p ON a.id = p.applicant_id
              WHERE a.deleted_at IS NULL
                AND p.village IS NOT NULL AND p.village <> ''
              GROUP BY p.village
              ORDER BY cnt DESC"
        );
        return $this->renderStatsReport('گزارش بر اساس روستا', 'روستا', $rows);
    }

    /**
     * GET /admin/reports/by-license — count grouped by job_details.license_title.
     */
    public function byLicense(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->conn->all(
            "SELECT COALESCE(NULLIF(j.license_title, ''), '— (ثبت نشده)') AS label,
                    COUNT(*) AS cnt
               FROM job_details j
               JOIN applicants a ON j.applicant_id = a.id
              WHERE a.deleted_at IS NULL
              GROUP BY j.license_title
              ORDER BY cnt DESC"
        );
        return $this->renderStatsReport('گزارش بر اساس عنوان مجوز', 'عنوان مجوز', $rows);
    }

    /**
     * GET /admin/reports/by-date — count grouped by DATE(applicants.created_at).
     */
    public function byDate(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->conn->all(
            "SELECT DATE(a.created_at) AS label, COUNT(*) AS cnt
               FROM applicants a
              WHERE a.deleted_at IS NULL
              GROUP BY DATE(a.created_at)
              ORDER BY label DESC"
        );
        return $this->renderStatsReport('گزارش روزانه', 'تاریخ', $rows);
    }

    /**
     * GET /admin/output/general — overall stats dashboard + filters
     * that feed into the CSV/PDF exports.
     */
    public function generalReport(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $total = (int)$this->conn->scalar('SELECT COUNT(*) FROM applicants WHERE deleted_at IS NULL');
        $byStatus = $this->conn->all(
            "SELECT current_status AS label, COUNT(*) AS cnt
               FROM applicants WHERE deleted_at IS NULL
              GROUP BY current_status ORDER BY cnt DESC"
        );
        $byServiceType = $this->conn->all(
            "SELECT COALESCE(st.title, '— (ثبت نشده)') AS label, COUNT(*) AS cnt
               FROM applicants a
               LEFT JOIN service_types st ON st.id = a.service_type_id
              WHERE a.deleted_at IS NULL
              GROUP BY st.title ORDER BY cnt DESC"
        );
        $byLicenseState = $this->conn->all(
            "SELECT COALESCE(NULLIF(license_state, ''), 'جاری') AS label, COUNT(*) AS cnt
               FROM applicants WHERE deleted_at IS NULL
              GROUP BY license_state ORDER BY cnt DESC"
        );
        $monthlyTrend = $this->conn->all(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COUNT(*) AS cnt
               FROM applicants WHERE deleted_at IS NULL
              GROUP BY DATE_FORMAT(created_at, '%Y-%m')
              ORDER BY label DESC LIMIT 12"
        );

        return $this->render('admin/output_general', [
            'title'           => 'گزارش کلی',
            'total'           => $total,
            'byStatus'        => $byStatus,
            'byServiceType'   => $byServiceType,
            'byLicenseState'  => $byLicenseState,
            'monthlyTrend'    => $monthlyTrend,
        ]);
    }

    /**
     * GET /admin/output/single/{trackingCode} — render the printable
     * single-applicant view (its own dedicated admin/print_single.php
     * complete HTML doc, rendered with layout='none').
     *
     * Phase 38 (v2.0.4): replaces the previous reuse of the public
     * print.php layout. The new admin-only view shows ALL applicant fields
     * (including admin-only ones the public print.php omits:
     * income_decile, golden-link match info, documents list, full status
     * timeline with triggered_by, license section with valid_until).
     */
    public function singleApplicantReport(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $code = strtoupper(trim((string)($params['trackingCode'] ?? '')));
        $applicant = $this->applicants->findByTrackingCode($code);
        if ($applicant === null) {
            $this->session->set(
                'flash_error',
                'متقاضی با کد رهگیری «' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '» یافت نشد.'
            );
            return $this->redirect('/admin/dashboard');
        }

        $applicantId = (int)$applicant->id;

        // Job + property details (1:1 with applicants).
        $jobDetail      = $this->jobAndProperty->findJobDetailByApplicantId($applicantId);
        $propertyDetail = $this->jobAndProperty->findPropertyDetailByApplicantId($applicantId);

        // Documents (1:N).
        $documents = $this->documents->findByApplicantId($applicantId);

        // Full status log (with triggered_by).
        $statusLog = $this->statusLog->findByApplicantId($applicantId);

        // Service type title (from service_types lookup).
        $serviceTypeTitle = '';
        if ($applicant->serviceTypeId !== null) {
            $serviceTypeRow = $this->serviceTypes->findById($applicant->serviceTypeId);
            if (is_array($serviceTypeRow) && isset($serviceTypeRow['title'])) {
                $serviceTypeTitle = (string)$serviceTypeRow['title'];
            }
        }

        // Raw applicants row for the fields NOT exposed by the Applicant VO
        // (father_name, birth_date, applicant_type, marital_status,
        // children_count, physical_condition, disability_details,
        // income_decile, has_court_case, has_active_loan,
        // has_overdue_installments). Same pattern as
        // AdminController::viewApplication.
        $row = $this->conn->one(
            'SELECT * FROM applicants WHERE id = ? AND deleted_at IS NULL',
            [$applicantId]
        ) ?? [];

        // Site name from settings (with a sensible default).
        $siteName = $this->settings->get('site_name', 'سامانهٔ مجوز');

        return $this->render('admin/print_single', [
            'title'            => 'پروندهٔ چاپی متقاضی - ' . $applicant->trackingCode,
            'applicant'        => $applicant,
            'row'              => $row,
            'jobDetail'        => $jobDetail,
            'propertyDetail'   => $propertyDetail,
            'documents'        => $documents,
            'statusLog'        => $statusLog,
            'serviceTypeTitle' => $serviceTypeTitle,
            'siteName'         => $siteName,
            'persianDate'      => $this->persianDate,
        ], 'none');
    }

    /**
     * GET /admin/output/grouped — render the grouped-report form.
     */
    public function groupedReportForm(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        return $this->render('admin/output_grouped_form', [
            'title'  => 'گزارش گروه‌بندی‌شده',
            'report' => null,
        ]);
    }

    /**
     * POST /admin/output/grouped — accept the group_by form, render the
     * same `output_grouped_form.php` view WITH the results inline.
     */
    public function groupedReportResult(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $groupBy = (string)$request->input('group_by', 'province');

        // Whitelisted group_by → SQL map (prevents SQL injection via
        // arbitrary column names).
        $queries = [
            'province'          => "SELECT COALESCE(NULLIF(p.province, ''), '— (ثبت نشده)') AS label, COUNT(*) AS cnt FROM applicants a JOIN property_details p ON a.id = p.applicant_id WHERE a.deleted_at IS NULL GROUP BY p.province ORDER BY cnt DESC",
            'city'              => "SELECT COALESCE(NULLIF(p.city, ''), '— (ثبت نشده)') AS label, COUNT(*) AS cnt FROM applicants a JOIN property_details p ON a.id = p.applicant_id WHERE a.deleted_at IS NULL GROUP BY p.city ORDER BY cnt DESC",
            'village'           => "SELECT p.village AS label, COUNT(*) AS cnt FROM applicants a JOIN property_details p ON a.id = p.applicant_id WHERE a.deleted_at IS NULL AND p.village IS NOT NULL AND p.village <> '' GROUP BY p.village ORDER BY cnt DESC",
            'license_title'     => "SELECT COALESCE(NULLIF(j.license_title, ''), '— (ثبت نشده)') AS label, COUNT(*) AS cnt FROM job_details j JOIN applicants a ON j.applicant_id = a.id WHERE a.deleted_at IS NULL GROUP BY j.license_title ORDER BY cnt DESC",
            'registration_date' => "SELECT DATE(a.created_at) AS label, COUNT(*) AS cnt FROM applicants a WHERE a.deleted_at IS NULL GROUP BY DATE(a.created_at) ORDER BY label DESC",
            'status'            => "SELECT current_status AS label, COUNT(*) AS cnt FROM applicants a WHERE a.deleted_at IS NULL GROUP BY current_status ORDER BY cnt DESC",
            'license_state'     => "SELECT COALESCE(NULLIF(license_state, ''), 'جاری') AS label, COUNT(*) AS cnt FROM applicants a WHERE a.deleted_at IS NULL GROUP BY license_state ORDER BY cnt DESC",
        ];
        $labelMap = [
            'province'          => 'استان',
            'city'              => 'شهر',
            'village'           => 'روستا',
            'license_title'     => 'عنوان مجوز',
            'registration_date' => 'تاریخ ثبت‌نام',
            'status'            => 'وضعیت',
            'license_state'     => 'وضعیت مجوز',
        ];
        $sql = $queries[$groupBy] ?? $queries['province'];
        $groupLabel = $labelMap[$groupBy] ?? 'گروه';
        $rows = $this->conn->all($sql);

        $report = $this->buildReport(
            'گزارش گروه‌بندی‌شده بر اساس ' . $groupLabel,
            $groupLabel,
            $rows
        );

        return $this->render('admin/output_grouped_form', [
            'title'    => 'گزارش گروه‌بندی‌شده',
            'report'   => $report,
            'groupBy'  => $groupBy,
            'groupLabels' => $labelMap,
        ]);
    }

    /**
     * GET /admin/report/csv — export all applicants + related data as a
     * CSV (one row per applicant). UTF-8 BOM + formula-injection protection.
     */
    public function csvReport(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->conn->all(
            "SELECT a.tracking_code, a.first_name, a.last_name, a.national_code,
                    a.mobile, a.postal_code, a.current_status, a.license_state,
                    a.priority, a.created_at,
                    COALESCE(st.title, '') AS service_title,
                    COALESCE(j.license_title, '') AS license_title,
                    COALESCE(j.experience_months, 0) AS experience_months,
                    COALESCE(j.training_method, '') AS training_method,
                    COALESCE(p.province, '') AS province,
                    COALESCE(p.city, '') AS city,
                    COALESCE(p.village, '') AS village,
                    COALESCE(p.ownership_status, '') AS ownership_status
               FROM applicants a
               LEFT JOIN service_types st ON st.id = a.service_type_id
               LEFT JOIN job_details j   ON j.applicant_id = a.id
               LEFT JOIN property_details p ON p.applicant_id = a.id
              WHERE a.deleted_at IS NULL
              ORDER BY a.created_at DESC, a.id DESC"
        );

        $headers = [
            'کد رهگیری', 'نام', 'نام خانوادگی', 'کد ملی', 'موبایل', 'کد پستی',
            'نوع خدمت', 'رشته (عنوان مجوز)', 'سابقه (ماه)', 'نحوه آموزش',
            'استان', 'شهر', 'روستا', 'وضعیت مالکیت',
            'وضعیت فعلی', 'وضعیت مجوز', 'اولویت‌دار', 'تاریخ ثبت',
        ];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                (string)$r['tracking_code'],
                (string)$r['first_name'],
                (string)$r['last_name'],
                (string)$r['national_code'],
                (string)$r['mobile'],
                (string)$r['postal_code'],
                (string)$r['service_title'],
                (string)$r['license_title'],
                (string)$r['experience_months'],
                (string)$r['training_method'],
                (string)$r['province'],
                (string)$r['city'],
                (string)$r['village'],
                (string)$r['ownership_status'],
                (string)$r['current_status'],
                (string)$r['license_state'],
                ((int)$r['priority']) === 1 ? 'بله' : 'خیر',
                (string)$r['created_at'],
            ];
        }
        $filename = 'applicants-' . date('Ymd') . '.csv';
        return $this->csvResponse($filename, $headers, $out);
    }

    /**
     * GET /admin/report/pdf — render a printable HTML view (layout='none')
     * the user can save as PDF via the browser's print dialog.
     */
    public function pdfReport(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->conn->all(
            "SELECT a.tracking_code, a.first_name, a.last_name, a.national_code,
                    a.mobile, a.current_status, a.license_state, a.priority,
                    a.created_at,
                    COALESCE(j.license_title, '') AS license_title,
                    COALESCE(p.province, '') AS province,
                    COALESCE(p.city, '') AS city
               FROM applicants a
               LEFT JOIN job_details j ON j.applicant_id = a.id
               LEFT JOIN property_details p ON p.applicant_id = a.id
              WHERE a.deleted_at IS NULL
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT 500"
        );
        $e = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $genAt = date('Y-m-d H:i');
        $html  = '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>گزارش درخواست‌های مجوز صنایع دستی</title>';
        $html .= '<style>
            body{font-family:Vazirmatn,Tahoma,sans-serif;padding:12mm;color:#111;background:#fff}
            h2{text-align:center;margin-bottom:4px}
            .meta{text-align:center;color:#555;font-size:.85rem;margin-bottom:14px}
            table{width:100%;border-collapse:collapse;font-size:.8rem}
            th,td{border:1px solid #888;padding:5px 6px;text-align:right}
            th{background:#eef3f6}
            tr:nth-child(even) td{background:#fafafa}
            thead{display:table-header-group}
            tr{page-break-inside:avoid}
            .toolbar{position:sticky;top:0;text-align:center;padding:10px;background:#fff;border-bottom:1px solid #ddd;margin-bottom:12px}
            .toolbar button{font-family:inherit;font-size:1rem;padding:.5rem 1.5rem;border-radius:8px;border:1px solid #0f766e;background:#0f766e;color:#fff;cursor:pointer}
            @media print{ .toolbar{display:none} body{padding:0} }
            @page{ size:A4 landscape; margin:12mm; }
        </style></head><body>';
        $html .= '<div class="toolbar"><button onclick="window.print()">چاپ / ذخیرهٔ PDF</button></div>';
        $html .= '<h2>گزارش درخواست‌های مجوز صنایع دستی</h2>';
        $html .= '<p class="meta">تاریخ تولید گزارش: ' . $e($genAt) . ' — تعداد رکوردها: ' . count($rows) . '</p>';
        $html .= '<table><thead><tr>';
        $html .= '<th>ردیف</th><th>کد رهگیری</th><th>نام و نام خانوادگی</th><th>کد ملی</th><th>موبایل</th>'
            . '<th>رشته</th><th>استان</th><th>شهر</th><th>وضعیت</th><th>مجوز</th><th>اولویت</th><th>تاریخ ثبت</th>';
        $html .= '</tr></thead><tbody>';
        $i = 1;
        foreach ($rows as $app) {
            $html .= '<tr>';
            $html .= '<td>' . $i++ . '</td>';
            $html .= '<td>' . $e($app['tracking_code']) . '</td>';
            $html .= '<td>' . $e((string)$app['first_name'] . ' ' . (string)$app['last_name']) . '</td>';
            $html .= '<td dir="ltr">' . $e($app['national_code']) . '</td>';
            $html .= '<td dir="ltr">' . $e($app['mobile']) . '</td>';
            $html .= '<td>' . $e($app['license_title']) . '</td>';
            $html .= '<td>' . $e($app['province']) . '</td>';
            $html .= '<td>' . $e($app['city']) . '</td>';
            $html .= '<td>' . $e($app['current_status']) . '</td>';
            $html .= '<td>' . $e($app['license_state']) . '</td>';
            $html .= '<td>' . (((int)$app['priority']) === 1 ? 'بله' : '') . '</td>';
            $html .= '<td>' . $e($app['created_at']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';
        return Response::html($html);
    }

    // ----------------------- private helpers -----------------------

    /**
     * Build the `$report` array passed to `admin/report_stats.php` from
     * a raw `[{label, cnt}, ...]` query result.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{title:string, group_label:string, total:int, rows:array<int,array{label:string,count:int,percentage:float}>}
     */
    private function buildReport(string $title, string $groupLabel, array $rows): array
    {
        $total = 0;
        foreach ($rows as $r) {
            $total += (int)($r['cnt'] ?? 0);
        }
        $out = [];
        foreach ($rows as $r) {
            $count = (int)($r['cnt'] ?? 0);
            $out[] = [
                'label'      => (string)($r['label'] ?? ''),
                'count'      => $count,
                'percentage' => $total > 0 ? round($count * 100 / $total, 1) : 0.0,
            ];
        }
        return [
            'title'       => $title,
            'group_label' => $groupLabel,
            'total'       => $total,
            'rows'        => $out,
        ];
    }

    /**
     * Render the stats view with a freshly-built report array.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function renderStatsReport(string $title, string $groupLabel, array $rows): Response
    {
        $report = $this->buildReport($title, $groupLabel, $rows);
        return $this->render('admin/report_stats', [
            'title'  => $title,
            'report' => $report,
        ]);
    }

    /**
     * Build a CSV Response with UTF-8 BOM + formula-injection protection.
     *
     * @param array<int,string> $headers
     * @param array<int,array<int,string>> $rows
     */
    private function csvResponse(string $filename, array $headers, array $rows): Response
    {
        $bom = "\xEF\xBB\xBF";
        $lines = [];
        $lines[] = $this->csvLine($headers);
        foreach ($rows as $row) {
            $lines[] = $this->csvLine($row);
        }
        $body = $bom . implode("\r\n", $lines) . "\r\n";
        return (new Response($body, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]));
    }

    /**
     * Convert one row (array of strings) to a CSV line, applying
     * formula-injection protection to every cell.
     *
     * @param array<int,string> $cells
     */
    private function csvLine(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            $cell = (string)$cell;
            if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true)) {
                $cell = "'" . $cell;
            }
            $out[] = '"' . str_replace('"', '""', $cell) . '"';
        }
        return implode(',', $out);
    }
}
