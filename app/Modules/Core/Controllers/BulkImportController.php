<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Services\FileStorage;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\DocumentRepositoryInterface;
use App\Shared\Contracts\ServiceTypeRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\CsvReader;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;

/**
 * BulkImportController — CSV bulk import of applicants + missing-docs upload.
 *
 * Phase 18 (v1.9.0): ported from v2's BulkImportController (689 lines),
 * condensed to a lean v3 version that uses the v3 modular architecture
 * (DI Container, Connection prepared statements, CsvReader, FileStorage,
 * Validator) and the v3 column contract: the CSV must have a header row
 * with `national_code, first_name, last_name, mobile, service_type_id,
 * postal_code` (all required). No v2-style fuzzy header mapping.
 *
 * Flow:
 *   1) upload   — POST a CSV, parse + validate, store rows in session.
 *   2) preview  — show the first 100 parsed rows + per-row errors.
 *   3) execute  — INSERT each valid row as a new applicants row.
 *   4) result   — summary table (inserted / failed / skipped).
 *   5) missingDocs — list applicants with no documents yet.
 *   6) uploadDocs/{code} — show one applicant's docs + upload form.
 *   7) saveDocs/{code}  — accept file uploads + persist via FileStorage.
 *
 * Per docs/00-MASTER.md §11 — only role='admin' can access any of these.
 *
 * Routes (8 — registered in Core/Module.php):
 *   GET  /admin/bulk-import                            → index
 *   POST /admin/bulk-import/upload                     → upload
 *   GET  /admin/bulk-import/preview                    → preview
 *   POST /admin/bulk-import/execute                   → execute
 *   GET  /admin/bulk-import/result                     → result
 *   GET  /admin/bulk-import/missing-docs               → missingDocs
 *   GET  /admin/bulk-import/documents/{code}           → uploadDocs
 *   POST /admin/bulk-import/documents/{code}/save      → saveDocs
 *
 * Session keys (all namespaced under `bulk_import.`):
 *   bulk_import.rows       — array of parsed rows (associative arrays)
 *   bulk_import.filename   — original uploaded filename (for display)
 *   bulk_import.errors     — array of global errors (e.g. missing columns)
 *   bulk_import.result     — array of per-row outcomes from execute()
 */
final class BulkImportController extends Controller
{
    /** Required CSV columns (header row must contain all of these). */
    private const REQUIRED_COLUMNS = [
        'national_code', 'first_name', 'last_name', 'mobile', 'service_type_id', 'postal_code',
    ];

    /** Maximum number of rows we'll accept in one CSV upload. */
    private const MAX_ROWS = 1000;

    /** Maximum CSV file size (5 MiB). */
    private const MAX_CSV_SIZE = 5 * 1024 * 1024;

    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly Connection $conn,
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly ServiceTypeRepositoryInterface $serviceTypes,
        private readonly DocumentRepositoryInterface $documents,
        private readonly FileStorage $storage,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /admin/bulk-import — show the CSV upload form.
     */
    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        return $this->render('admin/bulk_import', [
            'title'       => 'ایمپورت دسته‌جمعی',
            'error'       => $this->session->get('bulk_import.error'),
            'columns'     => self::REQUIRED_COLUMNS,
        ]);
    }

    /**
     * GET /admin/bulk-import/template — download a sample CSV with the
     * required headers + one example row. The browser will save it as
     * `bulk_import_template.csv`. (Phase 37 — ported from v2's column
     * template help text.)
     */
    public function template(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $headers = self::REQUIRED_COLUMNS;
        // One example row using safe placeholder values (the national_code
        // is a valid 10-digit checksum, the mobile is a valid Iran prefix).
        $example = ['1234567890', 'علی', 'احمدی', '09123456789', '1', '1234567890'];
        $bom     = "\xEF\xBB\xBF";  // UTF-8 BOM so Excel opens UTF-8 correctly.
        $body    = $bom . $this->csvLine($headers) . "\r\n" . $this->csvLine($example) . "\r\n";
        return new Response($body, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="bulk_import_template.csv"',
        ]);
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
            // Formula-injection protection (inherited from PaymentController::csvLine).
            if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true)) {
                $cell = "'" . $cell;
            }
            $out[] = '"' . str_replace('"', '""', $cell) . '"';
        }
        return implode(',', $out);
    }

    /**
     * POST /admin/bulk-import/upload — accept the CSV file, parse + validate
     * the header row, store parsed rows in the session, redirect to preview.
     */
    public function upload(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);

        $file = $request->file('csv_file');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->session->set('bulk_import.error', 'لطفاً یک فایل CSV انتخاب کنید.');
            return $this->redirect('/admin/bulk-import');
        }

        // Extension check (CSV only).
        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $this->session->set('bulk_import.error', 'فقط فایل CSV پشتیبانی می‌شود.');
            return $this->redirect('/admin/bulk-import');
        }

        // Size check.
        if (($file['size'] ?? 0) > self::MAX_CSV_SIZE) {
            $this->session->set(
                'bulk_import.error',
                'حداکثر حجم فایل ۵ مگابایت است.'
            );
            return $this->redirect('/admin/bulk-import');
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if (!is_file($tmpPath)) {
            $this->session->set('bulk_import.error', 'فایل آپلودشده یافت نشد.');
            return $this->redirect('/admin/bulk-import');
        }

        // Parse via the shared CsvReader (BOM-stripped, associative).
        try {
            $reader = new CsvReader();
            $rows = $reader->readAll($tmpPath);
        } catch (\Throwable $e) {
            $this->session->set('bulk_import.error', 'خطا در خواندن CSV: ' . $e->getMessage());
            return $this->redirect('/admin/bulk-import');
        }

        if ($rows === []) {
            $this->session->set('bulk_import.error', 'فایل خالی است یا هیچ ردیفی خوانده نشد.');
            return $this->redirect('/admin/bulk-import');
        }
        if (count($rows) > self::MAX_ROWS) {
            $this->session->set(
                'bulk_import.error',
                'حداکثر ' . self::MAX_ROWS . ' ردیف مجاز است.'
            );
            return $this->redirect('/admin/bulk-import');
        }

        // Validate that every required column exists in the header row.
        $headers = array_keys($rows[0]);
        $missing = array_diff(self::REQUIRED_COLUMNS, $headers);
        if ($missing !== []) {
            $this->session->set(
                'bulk_import.error',
                'ستون‌های الزامی وجود ندارند: ' . implode('، ', $missing)
                    . '. هدرهای موردنیاز: ' . implode('، ', self::REQUIRED_COLUMNS)
            );
            return $this->redirect('/admin/bulk-import');
        }

        // Normalize + validate each row.
        $normalized = [];
        foreach ($rows as $i => $row) {
            $normalized[] = $this->normalizeRow((int)$i, $row);
        }

        // Store in the session + clear any stale error/result.
        $this->session->set('bulk_import.rows', $normalized);
        $this->session->set('bulk_import.filename', $name);
        $this->session->remove('bulk_import.error');
        $this->session->remove('bulk_import.result');

        return $this->redirect('/admin/bulk-import/preview');
    }

    /**
     * GET /admin/bulk-import/preview — show the first 100 parsed rows +
     * per-row validation errors + the confirm-execute form.
     */
    public function preview(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->session->get('bulk_import.rows', []);
        if (!is_array($rows) || $rows === []) {
            $this->session->set('bulk_import.error', 'داده‌ای برای پیش‌نمایش وجود ندارد. فایل را مجدداً آپلود کنید.');
            return $this->redirect('/admin/bulk-import');
        }

        // Show only the first 100 rows (full set is stored in the session
        // and is what execute() will process — the preview is just for the
        // admin to eyeball the data before committing).
        $previewRows = array_slice($rows, 0, 100);
        $validCount = count(array_filter($rows, static fn ($r): bool => $r['valid']));
        $invalidCount = count($rows) - $validCount;

        return $this->render('admin/bulk_preview', [
            'title'         => 'پیش‌نمایش ایمپورت',
            'rows'          => $previewRows,
            'totalCount'    => count($rows),
            'validCount'    => $validCount,
            'invalidCount'  => $invalidCount,
            'filename'      => $this->session->get('bulk_import.filename', ''),
            'columns'       => self::REQUIRED_COLUMNS,
        ]);
    }

    /**
     * POST /admin/bulk-import/execute — INSERT each selected row as a new
     * `applicants` row. Accepts `selected_rows[]` (array of row indices)
     * from the preview form; if missing or empty, falls back to all valid
     * rows in the session (backwards-compat — preserves Phase 18 behaviour
     * for callers that POST without selected_rows). Stores the per-row
     * outcome summary in the session and redirects to the result page.
     */
    public function execute(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->session->get('bulk_import.rows', []);
        if (!is_array($rows) || $rows === []) {
            $this->session->set('bulk_import.error', 'داده‌ای برای ایمپورت وجود ندارد.');
            return $this->redirect('/admin/bulk-import');
        }

        // Filter to only the rows the admin selected in the preview. If the
        // caller POSTed `selected_rows[]`, use it (sparse-array of integer
        // indices). If absent or empty, fall back to every valid row — this
        // preserves the Phase 18 contract where the preview's submit button
        // alone triggers execution of all valid rows.
        $selected = $request->input('selected_rows', []);
        if (!is_array($selected)) {
            $selected = [];
        }
        $selectedIdx = array_map('intval', $selected);
        $selectedSet = array_flip($selectedIdx);  // idx => true
        $useSelection = $selectedIdx !== [];

        $rowsToProcess = [];
        foreach ($rows as $row) {
            $idx = (int)($row['idx'] ?? -1);
            if ($useSelection && !isset($selectedSet[$idx])) {
                continue;  // admin unchecked this row.
            }
            $rowsToProcess[] = $row;
        }
        if ($rowsToProcess === []) {
            $this->session->set('bulk_import.error', 'هیچ ردیفی برای ایمپورت انتخاب نشده است.');
            return $this->redirect('/admin/bulk-import/preview');
        }

        // Bulk import is potentially slow (per-row INSERT + tracking-code
        // generation). Give PHP enough time + memory to finish.
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $results = [];
        $inserted = 0;
        $failed = 0;
        $skipped = 0;
        $adminId = (int)$this->session->get('admin_id', 0);

        foreach ($rowsToProcess as $row) {
            $outcome = $this->executeRow($row, $adminId);
            $results[] = $outcome;
            if ($outcome['status'] === 'inserted') {
                $inserted++;
            } elseif ($outcome['status'] === 'failed') {
                $failed++;
            } else {
                $skipped++;
            }
        }

        $this->session->set('bulk_import.result', [
            'rows'     => $results,
            'inserted' => $inserted,
            'failed'   => $failed,
            'skipped'  => $skipped,
            'total'    => count($rowsToProcess),
        ]);
        // Clear the raw rows so a stale preview can't be re-executed.
        $this->session->remove('bulk_import.rows');

        $this->logger->info('Bulk import executed', [
            '_module'      => 'core',
            'total'        => count($rowsToProcess),
            'selected_in'  => count($selectedIdx),
            'inserted'     => $inserted,
            'failed'       => $failed,
            'skipped'      => $skipped,
            'admin_id'     => $adminId,
        ]);

        return $this->redirect('/admin/bulk-import/result');
    }

    /**
     * GET /admin/bulk-import/result — render the summary + per-row table.
     */
    public function result(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $result = $this->session->get('bulk_import.result');
        if (!is_array($result)) {
            $this->session->set('bulk_import.error', 'نتیجه‌ای برای نمایش وجود ندارد.');
            return $this->redirect('/admin/bulk-import');
        }
        // Clear after display so the result page can't be refreshed into a
        // duplicate "import done" state.
        $this->session->remove('bulk_import.result');
        return $this->render('admin/bulk_result', [
            'title'    => 'گزارش ایمپورت',
            'rows'     => $result['rows'] ?? [],
            'inserted' => (int)($result['inserted'] ?? 0),
            'failed'   => (int)($result['failed'] ?? 0),
            'skipped'  => (int)($result['skipped'] ?? 0),
            'total'    => (int)($result['total'] ?? 0),
        ]);
    }

    /**
     * GET /admin/bulk-import/missing-docs — list applicants with no
     * documents yet (the v2 query — all applicants with doc_count = 0,
     * most-recent first, capped at 500).
     */
    public function missingDocs(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->conn->all(
            "SELECT a.id, a.tracking_code, a.first_name, a.last_name, a.national_code,
                    a.service_type_id, st.title AS service_title,
                    (SELECT COUNT(*) FROM documents d WHERE d.applicant_id = a.id) AS doc_count
               FROM applicants a
               LEFT JOIN service_types st ON st.id = a.service_type_id
              WHERE a.deleted_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.applicant_id = a.id)
              ORDER BY a.id DESC
              LIMIT 500"
        );
        return $this->render('admin/bulk_missing_docs', [
            'title'      => 'کاربران نیازمند مدرک',
            'applicants' => $rows,
        ]);
    }

    /**
     * GET /admin/bulk-import/documents/{code} — render the document
     * upload form for one applicant (by tracking_code) + show the docs
     * they already have.
     */
    public function uploadDocs(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $code = (string)($params['code'] ?? '');
        $applicant = $this->applicants->findByTrackingCode($code);
        if ($applicant === null) {
            $this->session->set('bulk_import.error', 'کد رهگیری یافت نشد.');
            return $this->redirect('/admin/bulk-import/missing-docs');
        }

        $existing = $this->documents->findByApplicantId((int)$applicant->id);
        $docsByType = [];
        foreach ($existing as $doc) {
            $docsByType[$doc->docType][] = $doc;
        }

        return $this->render('admin/bulk_upload_docs', [
            'title'      => 'بارگذاری مدارک: ' . $applicant->trackingCode,
            'applicant'  => $applicant,
            'docsByType' => $docsByType,
            'docTypes'   => $this->docTypeOptions(),
            'message'    => $this->session->get('bulk_import.docs_message'),
        ]);
    }

    /**
     * POST /admin/bulk-import/documents/{code}/save — accept one file per
     * document type (named `doc_{key}` in the form) + store them via
     * FileStorage + INSERT a row per uploaded file via the documents repo.
     *
     * Ported from v2's saveDocs() which iterated over DocTypes::allowedForUserUpload()
     * and saved each provided file. v3 keeps the same shape but uses the
     * 5 standard doc types from migration 010 (national_id, personal_photo,
     * ownership_deed, skill_cert, business_license).
     */
    public function saveDocs(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $code = (string)($params['code'] ?? '');
        $applicant = $this->applicants->findByTrackingCode($code);
        if ($applicant === null) {
            $this->session->set('bulk_import.error', 'کد رهگیری یافت نشد.');
            return $this->redirect('/admin/bulk-import/missing-docs');
        }

        $applicantId = (int)$applicant->id;
        $uploaded = 0;
        $errors = [];

        // Iterate over the 5 standard doc types. For each, look for a file
        // uploaded under `doc_{key}` and save it if present.
        foreach ($this->docTypeOptions() as $dt) {
            $key = (string)$dt['key'];
            $file = $request->file('doc_' . $key);
            if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $result = $this->storage->storeUpload(
                $file,
                $applicantId,
                $applicant->trackingCode,
                $key
            );
            if ($result['success']) {
                $this->documents->create([
                    'applicant_id'  => $applicantId,
                    'doc_type'      => $key,
                    'file_name'      => $result['file_name'],
                    'original_name'  => $result['original_name'],
                    'file_path'      => $result['file_path'],
                ]);
                $uploaded++;
            } elseif (!empty($result['error'])) {
                $errors[] = $dt['label'] . ': ' . $result['error'];
            }
        }

        $msg = $uploaded . ' مدرک بارگذاری شد.';
        if ($errors !== []) {
            $msg .= ' خطاها: ' . implode(' | ', $errors);
        }
        $this->session->set('bulk_import.docs_message', $msg);
        $this->logger->info('Bulk import docs uploaded', [
            '_module'      => 'core',
            'applicant_id' => $applicantId,
            'uploaded'     => $uploaded,
            'errors'       => count($errors),
        ]);
        return $this->redirect('/admin/bulk-import/documents/' . $applicant->trackingCode);
    }

    // ----------------------- private helpers -----------------------

    /**
     * Normalize one parsed row (associative array) into the canonical
     * shape + compute its per-row validation status. The returned array
     * has the original column values + a `valid` bool + `errors` array.
     *
     * @param array<string,string> $row
     * @return array{idx:int, national_code:string, first_name:string, last_name:string, mobile:string, service_type_id:string, postal_code:string, valid:bool, errors:array<int,string>}
     */
    private function normalizeRow(int $idx, array $row): array
    {
        $errors = [];
        $nc = Validator::nationalCode($row['national_code'] ?? '');
        $mobile = Validator::mobile($row['mobile'] ?? '');
        $firstName = trim((string)($row['first_name'] ?? ''));
        $lastName = trim((string)($row['last_name'] ?? ''));
        $serviceTypeId = (int)($row['service_type_id'] ?? 0);
        $postalCode = preg_replace('/\D/', '', (string)($row['postal_code'] ?? '')) ?? '';

        if ($nc === null) {
            $errors[] = 'کد ملی نامعتبر';
        }
        if ($mobile === null) {
            $errors[] = 'موبایل نامعتبر';
        }
        if ($firstName === '') {
            $errors[] = 'نام خالی';
        }
        if ($lastName === '') {
            $errors[] = 'نام خانوادگی خالی';
        }
        if ($serviceTypeId <= 0) {
            $errors[] = 'نوع خدمت نامعتبر';
        }
        if ($postalCode === '' || strlen($postalCode) > 10) {
            $errors[] = 'کد پستی نامعتبر';
        }

        return [
            'idx'              => $idx,
            'national_code'     => $nc ?? '',
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'mobile'            => $mobile ?? '',
            'service_type_id'   => (string)$serviceTypeId,
            'postal_code'       => $postalCode,
            'valid'             => $errors === [],
            'errors'            => $errors,
        ];
    }

    /**
     * Insert one normalized row as a new `applicants` row. Returns the
     * per-row outcome for the result page.
     *
     * @param array<string,mixed> $row
     * @return array{idx:int, name:string, national_code:string, mobile:string, tracking_code:string, status:string, message:string}
     */
    private function executeRow(array $row, int $adminId): array
    {
        $idx = (int)($row['idx'] ?? 0);
        $name = (string)$row['first_name'] . ' ' . (string)$row['last_name'];
        $nc = (string)$row['national_code'];
        $mobile = (string)$row['mobile'];

        if (!($row['valid'] ?? false)) {
            return [
                'idx' => $idx, 'name' => $name, 'national_code' => $nc, 'mobile' => $mobile,
                'tracking_code' => '', 'status' => 'skipped',
                'message' => 'نامعتبر — skip شد (' . implode('، ', $row['errors'] ?? []) . ')',
            ];
        }

        // Uniqueness check — skip if national_code already exists.
        $exists = $this->conn->one(
            'SELECT id FROM applicants WHERE national_code = ? AND deleted_at IS NULL LIMIT 1',
            [$nc]
        );
        if ($exists !== null) {
            return [
                'idx' => $idx, 'name' => $name, 'national_code' => $nc, 'mobile' => $mobile,
                'tracking_code' => '', 'status' => 'skipped',
                'message' => 'کد ملی موجود در دیتابیس — skip شد',
            ];
        }

        // Generate a unique tracking code (retry on the rare collision).
        $trackingCode = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $this->hash->generateTrackingCode();
            $exists = $this->conn->scalar(
                'SELECT id FROM applicants WHERE tracking_code = ?',
                [$candidate]
            );
            if ($exists === null) {
                $trackingCode = $candidate;
                break;
            }
        }
        if ($trackingCode === null) {
            return [
                'idx' => $idx, 'name' => $name, 'national_code' => $nc, 'mobile' => $mobile,
                'tracking_code' => '', 'status' => 'failed',
                'message' => 'تولید کد رهگیری ناموفق بود',
            ];
        }

        try {
            $now = date('Y-m-d H:i:s');
            $applicantId = $this->applicants->create([
                'service_type_id'         => (int)$row['service_type_id'],
                'first_name'              => $row['first_name'],
                'last_name'               => $row['last_name'],
                'national_code'           => $nc,
                'postal_code'             => $row['postal_code'],
                'mobile'                  => $mobile,
                'tracking_code'           => $trackingCode,
                'current_status'          => 'تأیید اولیه',
                'registration_started_at'  => $now,
                'registration_completed_at' => $now,
            ]);
            $this->logger->info('Bulk import inserted applicant', [
                '_module'       => 'core',
                'applicant_id'  => $applicantId,
                'tracking_code' => $trackingCode,
                'admin_id'      => $adminId,
            ]);
            return [
                'idx' => $idx, 'name' => $name, 'national_code' => $nc, 'mobile' => $mobile,
                'tracking_code' => $trackingCode, 'status' => 'inserted',
                'message' => 'درج شد — کد رهگیری ' . $trackingCode,
            ];
        } catch (\Throwable $e) {
            return [
                'idx' => $idx, 'name' => $name, 'national_code' => $nc, 'mobile' => $mobile,
                'tracking_code' => '', 'status' => 'failed',
                'message' => 'خطا در درج: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * The set of document-type options offered in the upload form. These
     * are the 5 standard doc types seeded by migration 010 (Phase 16):
     * national_id, personal_photo, ownership_deed, skill_cert,
     * business_license. The bulk-upload form renders one file input per
     * type (matching v2's bulk_upload_docs.php layout).
     *
     * `required` is informational only (shown as a red asterisk in the
     * view) — the controller saves every provided file regardless of
     * this flag, so admins can still upload partial sets.
     *
     * @return array<int,array{key:string,label:string,required:bool}>
     */
    private function docTypeOptions(): array
    {
        return [
            ['key' => 'national_id',     'label' => 'تصویر کارت ملی',       'required' => true],
            ['key' => 'personal_photo',  'label' => 'عکس پرسنلی',            'required' => true],
            ['key' => 'ownership_deed',  'label' => 'سند مالکیت محل کسب',   'required' => true],
            ['key' => 'skill_cert',      'label' => 'گواهی مهارت',          'required' => false],
            ['key' => 'business_license','label' => 'مجوز کسب',             'required' => false],
        ];
    }
}
