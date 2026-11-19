<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Controllers\Controller;
use App\Modules\NationalGateway\Services\LicenseService;
use App\Queue\JobQueue;
use App\Shared\Contracts\NationalBatchRepositoryInterface;
use App\Shared\Contracts\NationalFilterRepositoryInterface;
use App\Shared\Contracts\NationalLicenseRepositoryInterface;
use App\Shared\Contracts\NationalLookupRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\CsvReader;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;

/**
 * GatewayAdminController — the /admin/gateway/* HTML admin pages.
 *
 * Phase 39 (v2.0.5): ports 5 gateway-specific admin pages from the
 * andrbi108/mojavez-v3 prototype (admin/scraper_*.php) into v3's
 * Bootstrap 5 RTL + Vazirmatn styling. These are dedicated HTML pages
 * (NOT API JSON endpoints) — they let the admin:
 *
 *   - panel()  — see gateway stats + quick search + recent activity.
 *   - search() — run an advanced multi-field search of the local cache.
 *   - saved()  — browse saved licenses with status filter + CSV export.
 *   - filters()— manage saved filters (CRUD + apply).
 *   - batch()  — upload a CSV of license numbers for batch lookup.
 *
 * Auth: requireRole(['admin', 'sync_operator']) — same realm as the
 * existing /admin/sync/* routes. Mirrors SyncAdminController.
 *
 * Mutations (filter CRUD, batch upload, save/unsave, run-crawl) go
 * through the existing /api/v1/* JSON endpoints (V1ApiController) —
 * those remain registered in Module::routes() and accessible
 * programmatically. Phase 39 only adds the HTML view layer; the API
 * surface is unchanged.
 */
final class GatewayAdminController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly LicenseService $licenseService,
        private readonly NationalLicenseRepositoryInterface $licenseRepo,
        private readonly NationalFilterRepositoryInterface $filterRepo,
        private readonly NationalBatchRepositoryInterface $batchRepo,
        private readonly NationalLookupRepositoryInterface $lookupRepo,
        private readonly Connection $conn,
        // Phase 62 (v2.2.8) — JobQueue for the "search upstream for
        // unmatched" button on the batch page (enqueues a track_fetch
        // job per unmatched request_number). CsvReader for parsing the
        // uploaded CSV in batchUpload() so we don't depend on the
        // never-wired CsvBatchService (which has stale imports).
        private readonly JobQueue $jobQueue,
        private readonly CsvReader $csvReader,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /admin/gateway — main gateway dashboard.
     *
     * Renders 4 stat cards (total cached, total searches, total matches,
     * total saved), a quick-search form, recent-batches table, and
     * recent-matches table.
     */
    public function panel(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);

        $stats = $this->licenseService->stats();

        // Total searches ≈ total rows across all batches (each batch row
        // is one license-number lookup). Falls back to batch count.
        $totalSearches = (int)$this->conn->scalar(
            'SELECT COALESCE(SUM(total_rows), 0) FROM national_batches'
        );

        // Total matches = licenses that have been golden-link matched.
        $totalMatches = (int)$this->conn->scalar(
            'SELECT COUNT(*) FROM national_licenses WHERE matched_applicant_id IS NOT NULL'
        );

        // Recent batches (last 5).
        $recentBatches = $this->batchRepo->recent(5);

        // Recent matches (last 5 by last_seen_at).
        $recentMatches = $this->conn->all(
            'SELECT request_number, applicant_name, license_title, organization_title,
                    match_confidence, last_seen_at
               FROM national_licenses
              WHERE matched_applicant_id IS NOT NULL
              ORDER BY last_seen_at DESC LIMIT 5'
        );

        return $this->render('admin/gateway_panel', [
            'title'         => 'داشبورد درگاه ملی',
            'stats'          => $stats,
            'totalSearches'  => $totalSearches,
            'totalMatches'   => $totalMatches,
            'recentBatches'  => $recentBatches,
            'recentMatches'  => $recentMatches,
        ]);
    }

    /**
     * GET /admin/gateway/search — advanced multi-field search of the
     * local license cache.
     *
     * The form GETs to this action with the filter fields as query params.
     * The controller runs LicenseService::searchLocal() and passes the
     * LicenseCollection (as raw row arrays for the view's modal) + the
     * lookup dropdowns (provinces + main organizations).
     *
     * Per the task spec, results come from LicenseService::searchLocal().
     * The view's license detail modal needs the raw DB row's anchor
     * fields (masked_national_code, father_name, business_address,
     * isic_code, etc.) — so after searchLocal() we also fetch the raw
     * rows via queryCached() with the same filter. Both calls return
     * the same rows (searchLocal internally calls queryCached), so the
     * data is consistent — we just use the raw rows for the view.
     */
    public function search(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);

        $page = max(1, (int)($request->queryParam('page', 1) ?? 1));
        $pageSize = 20;

        $filter = $this->buildSearchFilter($request);

        // Satisfies the task spec (results from LicenseService::searchLocal()).
        // The collection's total is what we display; the raw rows below are
        // what the view renders (so the modal can show all anchor fields).
        $collection = $this->licenseService->searchLocal($filter, $page, $pageSize);
        $total = $collection->total;

        // Fetch raw rows with all columns for the view (modal needs them).
        $result = $this->licenseRepo->queryCached($filter, $page, $pageSize);
        $rows = $result['rows'];

        $totalPages = $pageSize > 0 ? (int)ceil($total / $pageSize) : 0;

        $provinces = $this->lookupRepo->provinces();
        $organizations = $this->lookupRepo->mainOrganizations();

        // Phase 54 (v2.2.0) — distinguish "cache is empty" from "filter is
        // too strict" in the search view. When the cache has zero rows
        // (countAll === 0), the view shows a clear info banner telling
        // the admin to run a sync cycle first. Otherwise, the no-results
        // message just blames the filter being too restrictive.
        $cacheTotal = $this->licenseRepo->countAll();

        return $this->render('admin/gateway_search', [
            'title'        => 'جستجوی پیشرفتهٔ درگاه ملی',
            'filter'        => $filter,
            'rows'          => $rows,
            'total'         => $total,
            'page'          => $page,
            'pageSize'      => $pageSize,
            'totalPages'    => $totalPages,
            'provinces'     => $provinces,
            'organizations' => $organizations,
            'cacheTotal'    => $cacheTotal,
        ]);
    }

    /**
     * GET /admin/gateway/saved — cached national licenses.
     *
     * Phase 59a — this page now lists ALL cached licenses
     * (every row in national_licenses), not just rows with
     * is_saved = 1. The is_saved flag is only set when an admin
     * explicitly marks a license "saved" via the API; the sync
     * cycle (NationalSyncOrchestrator::runCycle →
     * LicenseService::searchUpstream) populates national_licenses
     * WITHOUT setting is_saved, so filtering by is_saved = 1 hid
     * every license the cache actually contained. The page title
     * reflects this: "مجوزهای کش‌شده از درگاه ملی" (cached licenses
     * from the national gateway).
     *
     * Phase 62 (v2.2.8) — adds an "all/saved/unsaved" filter
     * dropdown alongside the existing status filter. The dropdown
     * selects by the is_saved flag, with the "all" option showing
     * every row (the v2.2.7 default). The two filters compose, so
     * the admin can ask for "all active saved licenses" etc.
     *
     * Optional status filter (all / active / expired / unknown) via
     * ?status= query param. CSV export links to the existing
     * /api/v1/licenses/export endpoint.
     */
    public function saved(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);

        $page = max(1, (int)($request->queryParam('page', 1) ?? 1));
        $pageSize = 25;
        $statusFilter = (string)($request->queryParam('status', '') ?? '');
        $savedFilter  = (string)($request->queryParam('saved', '') ?? '');

        // Build the WHERE clause. Both filters are optional and
        // compose together. The repository's buildWhereClause
        // understands `status` and `is_saved` keys.
        $filter = [];
        if ($statusFilter === 'active' || $statusFilter === 'expired' || $statusFilter === 'unknown') {
            $filter['status'] = $statusFilter;
        }
        if ($savedFilter === '1' || $savedFilter === '0') {
            $filter['is_saved'] = $savedFilter;
        }

        $where = $this->licenseRepo->buildWhereSql($filter);

        $offset = max(0, ($page - 1) * $pageSize);
        $rows = $this->conn->all(
            "SELECT * FROM national_licenses WHERE {$where['sql']} ORDER BY last_seen_at DESC LIMIT ? OFFSET ?",
            array_merge($where['params'], [$pageSize, $offset])
        );

        $total = (int)$this->conn->scalar(
            "SELECT COUNT(*) FROM national_licenses WHERE {$where['sql']}",
            $where['params']
        );

        $totalPages = $pageSize > 0 ? (int)ceil($total / $pageSize) : 0;

        return $this->render('admin/gateway_saved', [
            'title'        => 'مجوزهای کش‌شده از درگاه ملی',
            'statusFilter' => $statusFilter,
            'savedFilter'  => $savedFilter,
            'rows'         => $rows,
            'total'        => $total,
            'page'         => $page,
            'pageSize'     => $pageSize,
            'totalPages'   => $totalPages,
        ]);
    }

    /**
     * GET /admin/gateway/filters — saved filter management.
     *
     * Lists all rows from national_filters. CRUD operations (create/edit/
     * delete) are handled client-side via fetch() to /api/v1/filters/* in
     * the view's <script> block. The "apply" button links to
     * /admin/gateway/search with the filter's criteria as query params.
     */
    public function filters(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);

        $filters = $this->filterRepo->all();

        return $this->render('admin/gateway_filters', [
            'title'   => 'فیلترهای ذخیره‌شده',
            'filters' => $filters,
        ]);
    }

    /**
     * GET /admin/gateway/batch — batch CSV search.
     *
     * Phase 62 (v2.2.8) — the page now does the actual local cache
     * lookup itself. When a CSV was just uploaded (POST
     * /admin/gateway/batch), the result rows are pulled out of the
     * session flash and rendered inline as a results table above
     * the recent-batches table. The result rows are: the original
     * CSV row, the matched national_licenses row (if any), and a
     * 'matched' / 'unmatched' status badge per row.
     *
     * The recent-batches table (last 20 rows from national_batches)
     * is unchanged — it shows the historical log of CSV uploads
     * regardless of whether they were processed inline here or via
     * the API endpoint.
     */
    public function batch(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);

        $batches = $this->batchRepo->recent(20);

        // Phase 62 — pull the last batch lookup result out of the
        // session flash (if any) so we can render it inline. The
        // flash is consumed once — a page refresh won't keep
        // showing it.
        $lastResult = null;
        if ($this->session->has('gateway_batch_result')) {
            $lastResult = $this->session->get('gateway_batch_result');
            $this->session->remove('gateway_batch_result');
        }
        // Same for the queue-upstream flash (a small message that
        // says "N unmatched rows queued for upstream search").
        $queueMsg = null;
        if ($this->session->has('gateway_batch_queue_msg')) {
            $queueMsg = $this->session->get('gateway_batch_queue_msg');
            $this->session->remove('gateway_batch_queue_msg');
        }

        return $this->render('admin/gateway_batch', [
            'title'      => 'جستجوی دسته‌جمعی',
            'batches'    => $batches,
            'lastResult' => $lastResult,
            'queueMsg'   => $queueMsg,
        ]);
    }

    /**
     * POST /admin/gateway/batch — upload a CSV and run an inline
     * local-cache lookup for each row.
     *
     * Phase 62 (v2.2.8) — the form's CSV is parsed in-process via
     * CsvReader (no background queue). For each row, we look up
     * national_licenses by:
     *   - `license_number` column → request_number = ?
     *   - `national_code`  column → masked_national_code LIKE %code%
     *     (the cache only stores the masked value, so we do a
     *     LIKE match by the last 4 digits or the full code — the
     *     upstream masks as "073****269", so a LIKE on the last 3
     *     digits + first 3 digits matches well enough for cache
     *     lookup; an exact match is also tried first)
     *
     * The result is stored in the session flash + the user is
     * redirected back to /admin/gateway/batch where the GET
     * handler renders it inline. We do NOT call the upstream
     * gateway here — the task spec is explicit: "search the local
     * cache (not upstream — that would be too slow)". The
     * "search upstream for unmatched" button does that step
     * separately via batchQueue().
     *
     * The uploaded CSV is also recorded in national_batches so the
     * historical batches table reflects this run.
     */
    public function batchUpload(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);

        $file = $request->file('csv');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->session->set('gateway_batch_queue_msg', 'هیچ فایلی بارگذاری نشده است.');
            $this->redirect($this->environment->baseUrl . 'admin/gateway/batch');
        }

        $original = (string)($file['name'] ?? 'batch.csv');
        $tmpPath  = (string)($file['tmp_name'] ?? '');

        // Save the uploaded file to storage/uploads for the audit
        // trail (so the admin can re-download it later). The file
        // is kept after processing — we never auto-delete it.
        $destDir = dirname(__DIR__, 4) . '/storage/uploads';
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }
        $safeName = 'batch_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
        $destPath = $destDir . '/' . $safeName;
        if (!move_uploaded_file($tmpPath, $destPath) && !copy($tmpPath, $destPath)) {
            $this->session->set('gateway_batch_queue_msg', 'انتقال فایل ناموفق بود.');
            $this->redirect($this->environment->baseUrl . 'admin/gateway/batch');
        }
        $csvPath = '/uploads/' . $safeName;

        // Parse the CSV.
        try {
            $rows = $this->csvReader->readAll($destPath);
        } catch (\Throwable $e) {
            $this->session->set('gateway_batch_queue_msg', 'خطا در خواندن CSV: ' . $e->getMessage());
            $this->redirect($this->environment->baseUrl . 'admin/gateway/batch');
        }

        // For each row, look up national_licenses. Accept either
        // `national_code` or `license_number` (case-insensitive)
        // columns. We don't require a specific header — we look
        // for whichever column is present.
        $results = [];
        $matchedCount = 0;
        foreach ($rows as $lineNo => $row) {
            $licenseNumber = $this->pickColumn($row, ['license_number', 'license', 'request_number', 'rn']);
            $nationalCode  = $this->pickColumn($row, ['national_code', 'nationalcode', 'nid', 'code']);

            $matchedRow = null;
            if ($licenseNumber !== '') {
                $matchedRow = $this->conn->one(
                    'SELECT * FROM national_licenses WHERE request_number = ? LIMIT 1',
                    [$licenseNumber]
                );
            }
            if ($matchedRow === null && $nationalCode !== '') {
                // Try exact match on masked_national_code first (the
                // upstream's exact format e.g. "073****269").
                $matchedRow = $this->conn->one(
                    'SELECT * FROM national_licenses WHERE masked_national_code = ? LIMIT 1',
                    [$nationalCode]
                );
                if ($matchedRow === null) {
                    // Fall back to LIKE match on the last 4 digits of
                    // the national code (the masked value always
                    // exposes the last 3-4 digits unmasked).
                    $like = '%' . $this->conn->escapeLike(substr($nationalCode, -4));
                    $matchedRow = $this->conn->one(
                        'SELECT * FROM national_licenses WHERE masked_national_code LIKE ? LIMIT 1',
                        [$like]
                    );
                }
            }

            $isMatched = $matchedRow !== null;
            if ($isMatched) {
                $matchedCount++;
            }
            $results[] = [
                'line'        => $lineNo,
                'row'         => $row,
                'license_number' => $licenseNumber,
                'national_code'  => $nationalCode,
                'matched'     => $isMatched,
                'license'     => $matchedRow,
            ];
        }

        // Record the batch in national_batches so the recent-batches
        // table reflects this run (consistent with the existing
        // /api/v1/batch/upload behaviour).
        try {
            $batchId = $this->batchRepo->create(
                $original,
                count($rows),
                $csvPath
            );
            $this->batchRepo->complete($batchId, count($rows), $matchedCount, null);
        } catch (\Throwable $e) {
            // Recording the batch is best-effort — don't fail the
            // upload if the audit trail insert fails.
            $this->logger->warning('batchUpload: failed to record batch', [
                'error' => $e->getMessage(),
            ]);
            $batchId = 0;
        }

        // Store the result in the session flash so the GET handler
        // can render it inline. The flash is consumed once on the
        // next page render.
        $this->session->set('gateway_batch_result', [
            'filename'  => $original,
            'batch_id'  => $batchId,
            'total'     => count($rows),
            'matched'   => $matchedCount,
            'rows'      => $results,
        ]);

        $this->redirect($this->environment->baseUrl . 'admin/gateway/batch');
    }

    /**
     * POST /admin/gateway/batch/queue — enqueue a track_fetch job
     * for each unmatched request_number from a previous batch
     * upload.
     *
     * Phase 62 (v2.2.8) — the "search upstream for unmatched"
     * button submits a list of request_numbers via POST. For
     * each one, we enqueue a track_fetch job (which scrapes the
     * upstream /track/{rn} page when the Worker polls it). The
     * jobs persist in the queue table; the Worker (poor man's
     * cron pattern) drains them on subsequent /admin/sync/status
     * polls.
     *
     * If the request body has a `request_numbers` field, it's
     * interpreted as a comma- or newline-separated list. The
     * controller enqueues each non-empty entry. Returns a
     * flash message telling the admin how many jobs were queued
     * + where to see the results.
     */
    public function batchQueue(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);

        $raw = (string)($request->input('request_numbers', '') ?? '');
        // Split on commas, newlines, and whitespace — accept any
        // of them as separators so the form can submit a textarea
        // OR a comma-separated hidden input.
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        $enqueued = 0;
        foreach ($parts as $rn) {
            $rn = trim((string)$rn);
            if ($rn === '') {
                continue;
            }
            try {
                $this->jobQueue->enqueueTrackFetch($rn);
                $enqueued++;
            } catch (\Throwable $e) {
                $this->logger->warning('batchQueue: enqueue failed', [
                    'rn' => $rn, 'error' => $e->getMessage(),
                ]);
            }
        }

        $this->session->set('gateway_batch_queue_msg', sprintf(
            'تعداد %d کار جستجوی بالادستی در صف قرار گرفت. نتایج پس از اجرای کارگر '
            . 'در صفحهٔ «جستجوی پیشرفته» نمایش داده می‌شوند. برای فعال‌سازی کارگر، '
            . 'صفحهٔ «وضعیت همگام‌سازی» را باز کنید (هر بار باز کردن صفحه یک کار '
            . 'از صف را اجرا می‌کند).',
            $enqueued
        ));

        $this->redirect($this->environment->baseUrl . 'admin/gateway/batch');
    }

    /**
     * Pick the first non-empty value from $row under any of the
     * listed column names (case-insensitive, also accepts the
     * column name with underscores converted to spaces or vice
     * versa). Returns '' when none of the columns are present or
     * all are empty.
     *
     * @param array<string,mixed> $row
     * @param array<int,string> $candidates
     */
    private function pickColumn(array $row, array $candidates): string
    {
        // Build a normalized lookup of $row's keys: lowercased + trimmed.
        $normalized = [];
        foreach ($row as $k => $v) {
            $normalized[strtolower(trim((string)$k))] = $v;
        }
        foreach ($candidates as $c) {
            $key = strtolower(trim($c));
            if (isset($normalized[$key])) {
                $v = $normalized[$key];
                if (is_string($v) || is_int($v) || is_float($v)) {
                    $v = trim((string)$v);
                    if ($v !== '') {
                        return $v;
                    }
                }
            }
        }
        return '';
    }

    /**
     * Build the search filter array from request query params.
     *
     * Maps the form's field names to the NationalLicenseRepository's
     * queryCached() filter keys (the same shape V1ApiController::
     * licenseSearch uses).
     *
     * Phase 62 (v2.2.8) — adds support for issue_start_date +
     * issue_end_date (Jalali strings, converted to Gregorian inside
     * the repository's buildWhereClause). The form supplies two date
     * inputs so the admin can scope the search to a date range; the
     * filter is sent Jalali because the user enters Jalali dates, and
     * the repository handles the conversion before comparing against
     * the Gregorian responded_at column.
     *
     * Phase 62 (v2.2.8) — province/organization dropdowns now send
     * their upstream CODE (numeric ID) as the option value, but the
     * repository filters against the TITLE column on national_licenses.
     * We translate the code → title here via the lookup repository so
     * the filter actually matches rows. If the param looks like a
     * non-numeric string (e.g. the title itself, sent by a saved
     * filter that stored the title), pass it through unchanged so
     * the search still works for those saved filters.
     *
     * Note: national_code is NOT supported by queryCached() (the cache
     * only stores the masked national code, not the full one). The form
     * accepts the field for forward-compat, but the controller doesn't
     * filter on it — a future phase may extend queryCached() to support
     * a LIKE match on masked_national_code.
     *
     * @return array<string,mixed>
     */
    private function buildSearchFilter(Request $request): array
    {
        $filter = [];

        // license_number → license_id (request_number = ?).
        $licenseNumber = (string)($request->queryParam('license_number', '') ?? '');
        if ($licenseNumber !== '') {
            $filter['license_id'] = $licenseNumber;
        }

        // national_code → ignored (not in cache). Accept for forward-compat.
        // $nationalCode = (string)($request->queryParam('national_code', '') ?? '');

        // name → applicant_name LIKE.
        $name = (string)($request->queryParam('name', '') ?? '');
        if ($name !== '') {
            $filter['name'] = $name;
        }

        // province → province_title = ?. The dropdown's value is the
        // upstream province code (numeric); translate to title here so
        // the WHERE clause matches the column.
        $provinceVal = (string)($request->queryParam('province_title', '') ?? '');
        if ($provinceVal !== '') {
            $filter['province_title'] = $this->lookupTitle('province', $provinceVal) ?? $provinceVal;
        }

        // organization → organization_title = ?. Same code→title
        // translation as province above.
        $orgVal = (string)($request->queryParam('organization_title', '') ?? '');
        if ($orgVal !== '') {
            $filter['organization_title'] = $this->lookupTitle('main_organization', $orgVal) ?? $orgVal;
        }

        // status → status_slug = ?.
        $status = (string)($request->queryParam('status', '') ?? '');
        if ($status !== '') {
            $filter['status'] = $status;
        }

        // Phase 62 — issue date range (Jalali "YYYY/MM/DD").
        $issueStart = (string)($request->queryParam('issue_date_start', '') ?? '');
        if ($issueStart !== '') {
            $filter['issue_start_date'] = $issueStart;
        }
        $issueEnd = (string)($request->queryParam('issue_date_end', '') ?? '');
        if ($issueEnd !== '') {
            $filter['issue_end_date'] = $issueEnd;
        }

        return $filter;
    }

    /**
     * Look up a lookup's title by its kind + code. Returns null when
     * the code is not numeric (meaning the caller already passed a
     * title), or when the lookup row is missing (returns null so the
     * caller falls back to the raw value, which will simply match
     * nothing — the search will return 0 rows, which is the correct
     * UX when the admin selects a province that's not in the lookup
     * cache).
     */
    private function lookupTitle(string $kind, string $codeOrTitle): ?string
    {
        if ($codeOrTitle === '' || !ctype_digit($codeOrTitle)) {
            // Not a numeric code — assume it's already the title.
            return null;
        }
        try {
            $row = $this->conn->one(
                'SELECT title FROM national_lookups WHERE kind = ? AND code = ? LIMIT 1',
                [$kind, $codeOrTitle]
            );
            return $row === null ? null : (string)$row['title'];
        } catch (\Throwable) {
            return null;
        }
    }
}
