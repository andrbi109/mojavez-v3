<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Controllers\Api;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Controllers\Controller;
use App\Modules\NationalGateway\Services\LicenseService;
use App\Modules\NationalGateway\Services\NationalSyncOrchestrator;
use App\Queue\JobQueue;
use App\Queue\Worker;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\NationalBatchRepositoryInterface;
use App\Shared\Contracts\NationalFilterRepositoryInterface;
use App\Shared\Contracts\NationalLicenseRepositoryInterface;
use App\Shared\Contracts\NationalLookupRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;

/**
 * V1ApiController — REST API v1 surface for /api/v1/*.
 *
 * Phase 15 (v1.6.0): consolidates the ~35 public + admin API endpoints
 * that the spec calls out for v1. Each method is a functional skeleton
 * with the proper auth + request parsing + JSON response format in
 * place. Where the underlying service already exists (LicenseService,
 * JobQueue, NationalSyncOrchestrator, repositories), the method calls
 * it. Otherwise it returns a stub 200 with a "under construction"
 * message.
 *
 * Auth model (per spec):
 *   - GET  /api/v1/licenses*            → public (no auth) for list/show/track.
 *   - POST /api/v1/licenses/save-all     → admin (requireRole(['admin'])).
 *   - POST /api/v1/licenses/{id}/save    → admin.
 *   - DELETE /api/v1/licenses/{id}/save  → admin.
 *   - GET  /api/v1/licenses/saved        → admin.
 *   - GET  /api/v1/licenses/export       → admin.
 *   - POST /api/v1/licenses/search      → admin.
 *   - /api/v1/applicants/*              → requireApiKey() (X-API-Key header).
 *   - /api/v1/admin/*                   → requireRole(['admin']).
 *   - /api/v1/lookups/*                 → admin (index is public).
 *   - /api/v1/filters/*                 → admin.
 *   - /api/v1/batch/*                   → admin.
 *   - /api/v1/sync/*                    → admin.
 *   - /api/v1/proxy-image                → admin.
 *   - GET /api/v1/stream                → already exists (SseController).
 *
 * Route order matters: specific routes (`/saved`, `/{id}/save`, `/{id}/track`)
 * are registered before the generic `/{id}` route.
 */
final class V1ApiController extends Controller
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
        private readonly NationalLookupRepositoryInterface $lookupRepo,
        private readonly NationalFilterRepositoryInterface $filterRepo,
        private readonly NationalBatchRepositoryInterface $batchRepo,
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly JobQueue $jobQueue,
        private readonly Worker $worker,
        private readonly NationalSyncOrchestrator $orchestrator,
        // Phase 62 (v2.2.8) — ExportService for /api/v1/licenses/export.
        // Optional via nullable default so existing tests that
        // construct the controller with the 15-arg signature still
        // work (autowire fills it when bound in the Container).
        private readonly ?\App\Modules\NationalGateway\Services\ExportService $exportService = null,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /* ================================================================== */
    /* Licenses API (mostly public; some admin-only)                      */
    /* ================================================================== */

    /**
     * GET /api/v1/licenses — public list of cached licenses.
     */
    public function licenseIndex(Request $request, array $params = []): Response
    {
        $page = max(1, (int)$request->queryParam('page', 1));
        $pageSize = min(100, max(1, (int)$request->queryParam('page_size', 20)));
        $filter = $this->licenseFilterFromQuery($request);
        $result = $this->licenseRepo->queryCached($filter, $page, $pageSize);
        return $this->jsonSuccess([
            'items' => $result['rows'] ?? [],
            'total' => $result['total'] ?? 0,
            'page'  => $page,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * GET /api/v1/licenses/saved — admin list of saved licenses.
     */
    public function licenseSaved(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $count = (int)$this->licenseRepo->countSaved();
        $rows = $this->connection()->all(
            'SELECT * FROM national_licenses WHERE is_saved = 1 ORDER BY saved_at DESC LIMIT 1000'
        );
        return $this->jsonSuccess(['count' => $count, 'items' => $rows]);
    }

    /**
     * GET /api/v1/licenses/export — admin export of saved licenses (CSV).
     */
    public function licenseExport(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);

        // Phase 62 (v2.2.8) — accept ?format=csv|json|html. Default
        // to CSV for backwards compat with the existing saved-page
        // link. Use ExportService when available (which exports
        // ALL national_licenses columns + supports HTML print
        // view). Fall back to the inline toCsv() helper when the
        // ExportService wasn't autowired (e.g. during tests).
        $format = strtolower((string)($request->queryParam('format', 'csv') ?? 'csv'));
        if (!in_array($format, ['csv', 'json', 'html'], true)) {
            $format = 'csv';
        }

        // The legacy behaviour exported only is_saved=1 rows. Keep
        // that as the default for CSV/JSON (so the saved-page link
        // still exports the "saved" subset). For HTML, export ALL
        // cached licenses (the printable report is meant for the
        // admin to see everything in the cache, not just the
        // explicitly-marked "saved" subset).
        $filter = [];
        if ($format === 'csv' || $format === 'json') {
            $filter['is_saved'] = '1';
        }

        // ExportService path (preferred — full columns + html).
        if ($this->exportService !== null) {
            if ($format === 'html') {
                $html = $this->exportService->toHtml($filter, 500, 'گزارش مجوزهای درگاه ملی');
                return new Response($html, 200, [
                    'Content-Type' => 'text/html; charset=utf-8',
                ]);
            }
            if ($format === 'json') {
                $json = $this->exportService->toJson($filter);
                return new Response($json, 200, [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="licenses.json"',
                ]);
            }
            $csv = $this->exportService->toCsv($filter);
            return new Response($csv, 200, [
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="licenses.csv"',
            ]);
        }

        // Fallback path (pre-Phase 62 behaviour) — inline CSV with
        // a smaller column set. Kept so the endpoint still works
        // when ExportService isn't autowired (e.g. in tests).
        $rows = $this->connection()->all(
            'SELECT request_number, applicant_name, license_title, organization_title,
                    province_title, township_title, status_slug, status_title,
                    masked_national_code, father_name, business_address,
                    postal_code_masked, isic_code, isic_title,
                    expires_at, supervisory_authorities
               FROM national_licenses WHERE is_saved = 1 ORDER BY saved_at DESC'
        );
        $csv = $this->toCsv($rows);
        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="licenses.csv"',
        ]);
    }

    /**
     * POST /api/v1/licenses/search — admin search with a complex filter.
     */
    public function licenseSearch(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->jsonBody();
        $page = (int)($body['page'] ?? 1);
        $pageSize = (int)($body['page_size'] ?? 20);
        $collection = $this->licenseService->searchLocal($body, $page, $pageSize);
        $asArray = $collection->toArray();
        return $this->jsonSuccess([
            'items' => $asArray['items'],
            'total' => $asArray['total'],
            'page'  => $asArray['page'],
            'page_size' => $asArray['page_size'],
        ]);
    }

    /**
     * POST /api/v1/licenses/save-all — admin: bulk-save the current
     * filter's results.
     */
    public function licenseSaveAll(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $filter = $this->licenseFilterFromQuery($request);
        $saved = $this->licenseRepo->markSavedByFilter($filter);
        return $this->jsonSuccess(['saved' => $saved]);
    }

    /**
     * POST /api/v1/licenses/{id}/save — admin: save a single license.
     */
    public function licenseSave(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->connection()->one(
            'SELECT request_number FROM national_licenses WHERE id = ?',
            [$id]
        );
        if ($row === null) {
            return $this->jsonError(404, 'not_found', 'مجوز یافت نشد.');
        }
        $ok = $this->licenseRepo->markSaved((string)$row['request_number']);
        return $this->jsonSuccess(['saved' => $ok]);
    }

    /**
     * DELETE /api/v1/licenses/{id}/save — admin: unsave a single license.
     */
    public function licenseUnsave(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->connection()->one(
            'SELECT request_number FROM national_licenses WHERE id = ?',
            [$id]
        );
        if ($row === null) {
            return $this->jsonError(404, 'not_found', 'مجوز یافت نشد.');
        }
        $ok = $this->licenseRepo->markUnsaved((string)$row['request_number']);
        return $this->jsonSuccess(['unsaved' => $ok]);
    }

    /**
     * GET /api/v1/licenses/{id}/track — admin: enqueue a track-fetch job
     * for this license (will scrape /track/{request_number}).
     */
    public function licenseTrack(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->connection()->one(
            'SELECT request_number FROM national_licenses WHERE id = ?',
            [$id]
        );
        if ($row === null) {
            return $this->jsonError(404, 'not_found', 'مجوز یافت نشد.');
        }
        $jobId = $this->jobQueue->enqueueTrackFetch((string)$row['request_number']);
        return $this->jsonSuccess(['job_id' => $jobId]);
    }

    /**
     * GET /api/v1/licenses/{id} — public: show a single license detail.
     */
    public function licenseShow(Request $request, array $params = []): Response
    {
        $id = (int)($params['id'] ?? 0);
        $row = $this->connection()->one(
            'SELECT id, request_number, applicant_name, license_title,
                    organization_title, province_title, township_title,
                    status_slug, status_title, masked_national_code,
                    father_name, business_address, postal_code_masked,
                    isic_code, isic_title, expires_at, supervisory_authorities
               FROM national_licenses WHERE id = ?',
            [$id]
        );
        if ($row === null) {
            return $this->jsonError(404, 'not_found', 'مجوز یافت نشد.');
        }
        return $this->jsonSuccess($row);
    }

    /* ================================================================== */
    /* Applicants API (API-key auth)                                       */
    /* ================================================================== */

    /**
     * GET /api/v1/applicants/{trackingCode} — full applicant detail.
     */
    public function applicantShow(Request $request, array $params = []): Response
    {
        $this->requireApiKey($request);
        $trackingCode = Validator::trackingCode($params['trackingCode'] ?? '');
        if ($trackingCode === null) {
            return $this->jsonError(422, 'invalid_tracking_code', 'کد رهگیری نامعتبر.');
        }
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            return $this->jsonError(404, 'not_found', 'پرونده یافت نشد.');
        }
        return $this->jsonSuccess($this->applicantToArray($applicant));
    }

    /**
     * GET /api/v1/applicants/{trackingCode}/status — minimal status.
     */
    public function applicantStatus(Request $request, array $params = []): Response
    {
        $this->requireApiKey($request);
        $trackingCode = Validator::trackingCode($params['trackingCode'] ?? '');
        if ($trackingCode === null) {
            return $this->jsonError(422, 'invalid_tracking_code', 'کد رهگیری نامعتبر.');
        }
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            return $this->jsonError(404, 'not_found', 'پرونده یافت نشد.');
        }
        return $this->jsonSuccess([
            'tracking_code'  => $applicant->trackingCode,
            'current_status' => $applicant->currentStatus->value,
            'license_state'  => $applicant->licenseState->value,
            'updated_at'     => $applicant->updatedAt,
        ]);
    }

    /**
     * GET /api/v1/applicants/by-national/{nationalCode} — lookup by national code.
     */
    public function applicantByNational(Request $request, array $params = []): Response
    {
        $this->requireApiKey($request);
        $nationalCode = (string)($params['nationalCode'] ?? '');
        $applicants = $this->applicants->findByNationalCode($nationalCode);
        $rows = array_map(fn ($a): array => $this->applicantToArray($a), $applicants);
        return $this->jsonSuccess(['count' => count($rows), 'items' => $rows]);
    }

    /* ================================================================== */
    /* Admin API (admin-only)                                             */
    /* ================================================================== */

    /**
     * GET /api/v1/admin/stats — overall system stats.
     */
    public function adminStats(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $stats = $this->licenseService->stats();
        $stats['applicants_total'] = (int)$this->connection()->scalar(
            'SELECT COUNT(*) FROM applicants WHERE deleted_at IS NULL'
        );
        $stats['jobs_pending'] = (int)$this->connection()->scalar(
            "SELECT COUNT(*) FROM jobs WHERE status = 'pending'"
        );
        return $this->jsonSuccess($stats);
    }

    /**
     * GET /api/v1/admin/analytics — analytics breakdown.
     */
    public function adminAnalytics(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        return $this->jsonSuccess($this->licenseService->analytics());
    }

    /**
     * GET /api/v1/admin/jobs — paginated job queue listing.
     */
    public function adminJobs(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->connection()->all(
            'SELECT id, type, status, attempts, priority, created_at, completed_at,
                    result_count, last_error
               FROM jobs ORDER BY created_at DESC LIMIT 200'
        );
        return $this->jsonSuccess(['items' => $rows]);
    }

    /**
     * POST /api/v1/admin/jobs/{id}/retry — re-queue a failed job.
     */
    public function adminRetryJob(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $this->connection()->execute(
            "UPDATE jobs SET status = 'pending', attempts = 0, last_error = NULL,
                             available_at = NOW()
             WHERE id = ? AND status IN ('failed', 'cancelled')",
            [$id]
        );
        return $this->jsonSuccess(['retried' => $id]);
    }

    /**
     * GET /api/v1/admin/logs — recent integration-log entries.
     */
    public function adminLogs(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->connection()->all(
            'SELECT * FROM integration_log ORDER BY created_at DESC LIMIT 200'
        );
        return $this->jsonSuccess(['items' => $rows]);
    }

    /**
     * POST /api/v1/admin/crawl — manually trigger one full crawl cycle.
     *
     * Phase 62 (v2.2.8) — supports two payload shapes:
     *   - {filter: {...}} — explicit filter dict (legacy shape; the
     *     search page's "Run in queue" button used to send this).
     *   - {filter_id: int} — reference to a saved filter in
     *     national_filters; the controller looks up the filter_data
     *     JSON, decodes it, and enqueues a search job with that
     *     filter. This is what the filters page's "Run in queue"
     *     button sends (it has the filter_id but not the filter
     *     dict). The lookup is best-effort: if the filter_id is
     *     unknown, an empty filter is enqueued (the Worker will
     *     still pull the latest page-1 results from upstream).
     */
    public function adminCrawl(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->jsonBody();
        $filterArray = is_array($body['filter'] ?? null) ? $body['filter'] : [];

        // If the caller passed a filter_id instead of an inline
        // filter, look up the saved filter and use its filter_data.
        if ($filterArray === [] && isset($body['filter_id'])) {
            $filterId = (int)$body['filter_id'];
            if ($filterId > 0) {
                try {
                    $saved = $this->filterRepo->find($filterId);
                    if (is_array($saved) && isset($saved['filter_data'])) {
                        $decoded = is_string($saved['filter_data'])
                            ? json_decode((string)$saved['filter_data'], true)
                            : (is_array($saved['filter_data']) ? $saved['filter_data'] : []);
                        if (is_array($decoded)) {
                            $filterArray = $decoded;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('adminCrawl: filter_id lookup failed', [
                        'filter_id' => $filterId, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $jobId = $this->jobQueue->enqueueSearch($filterArray);
        return $this->jsonSuccess(['job_id' => $jobId]);
    }

    /**
     * POST /api/v1/admin/worker/start — start the worker (mark a sentinel).
     */
    public function adminWorkerStart(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        @file_put_contents($this->workerSentinelPath(), (string)time());
        return $this->jsonSuccess(['started' => true]);
    }

    /**
     * POST /api/v1/admin/worker/stop — stop the worker (remove sentinel).
     */
    public function adminWorkerStop(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        if (is_file($this->workerSentinelPath())) {
            @unlink($this->workerSentinelPath());
        }
        return $this->jsonSuccess(['stopped' => true]);
    }

    /**
     * GET /api/v1/admin/worker/status — worker status.
     */
    public function adminWorkerStatus(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $running = is_file($this->workerSentinelPath());
        $lastTick = $running ? (int)file_get_contents($this->workerSentinelPath()) : 0;
        return $this->jsonSuccess([
            'running' => $running,
            'last_tick_at' => $lastTick > 0 ? date('Y-m-d H:i:s', $lastTick) : null,
        ]);
    }

    /* ================================================================== */
    /* Lookups + Filters API                                              */
    /* ================================================================== */

    /**
     * GET /api/v1/lookups — public list of all active lookups.
     */
    public function lookupIndex(Request $request, array $params = []): Response
    {
        $rows = $this->connection()->all(
            "SELECT kind, code, title, parent_code FROM national_lookups WHERE is_active = 1 ORDER BY kind, title"
        );
        return $this->jsonSuccess(['items' => $rows]);
    }

    /**
     * GET /api/v1/lookups/townships/{provinceId} — townships for a province.
     */
    public function lookupTownships(Request $request, array $params = []): Response
    {
        $provinceId = (string)($params['provinceId'] ?? '');
        $rows = $this->connection()->all(
            "SELECT code, title, parent_code FROM national_lookups
              WHERE kind = 'township' AND is_active = 1 AND parent_code = ? ORDER BY title",
            [$provinceId]
        );
        return $this->jsonSuccess(['items' => $rows]);
    }

    /**
     * GET /api/v1/lookups/sub-organizations/{orgCode} — sub-orgs under an org.
     */
    public function lookupSubOrgs(Request $request, array $params = []): Response
    {
        $orgCode = (string)($params['orgCode'] ?? '');
        $rows = $this->connection()->all(
            "SELECT code, title, parent_code FROM national_lookups
              WHERE kind = 'sub_organization' AND is_active = 1 AND parent_code = ? ORDER BY title",
            [$orgCode]
        );
        return $this->jsonSuccess(['items' => $rows]);
    }

    /**
     * POST /api/v1/lookups/refresh — admin: refresh lookups from upstream.
     */
    public function lookupRefresh(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $result = $this->licenseService->refreshLookups();
        return $this->jsonSuccess($result);
    }

    /**
     * GET /api/v1/filters — admin: list all filters.
     */
    public function filterIndex(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        return $this->jsonSuccess(['items' => $this->filterRepo->all()]);
    }

    /**
     * POST /api/v1/filters — admin: create a new filter.
     */
    public function filterStore(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->jsonBody();
        $id = $this->filterRepo->create($body);
        return $this->jsonSuccess(['id' => $id]);
    }

    /**
     * GET /api/v1/filters/{id} — admin: show a filter.
     */
    public function filterShow(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $filter = $this->filterRepo->find($id);
        if ($filter === null) {
            return $this->jsonError(404, 'not_found', 'فیلتر یافت نشد.');
        }
        return $this->jsonSuccess($filter);
    }

    /**
     * PUT /api/v1/filters/{id} — admin: update a filter.
     */
    public function filterUpdate(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $body = $request->jsonBody();
        $this->filterRepo->update($id, $body);
        return $this->jsonSuccess(['updated' => $id]);
    }

    /**
     * DELETE /api/v1/filters/{id} — admin: delete a filter.
     */
    public function filterDestroy(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $this->filterRepo->delete($id);
        return $this->jsonSuccess(['deleted' => $id]);
    }

    /* ================================================================== */
    /* Batch API (admin)                                                  */
    /* ================================================================== */

    /**
     * GET /api/v1/batch — admin: list batch jobs.
     */
    public function batchIndex(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->connection()->all(
            'SELECT id, original_filename, total_rows, processed_rows, matched_rows,
                    status, created_at, completed_at
               FROM national_batches ORDER BY created_at DESC LIMIT 200'
        );
        return $this->jsonSuccess(['items' => $rows]);
    }

    /**
     * GET /api/v1/batch/{id}/status — admin: batch status.
     */
    public function batchStatus(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->connection()->one(
            'SELECT * FROM national_batches WHERE id = ?',
            [$id]
        );
        if ($row === null) {
            return $this->jsonError(404, 'not_found', 'دسته یافت نشد.');
        }
        return $this->jsonSuccess($row);
    }

    /**
     * POST /api/v1/batch/upload — admin: upload a CSV batch.
     *
     * Stub: accepts the file + records it; actual row-by-row processing
     * is performed by a future BatchJob (Phase 16+).
     */
    public function batchUpload(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $file = $request->file('csv');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->jsonError(422, 'no_file', 'هیچ فایلی بارگذاری نشده است.');
        }
        $original = (string)($file['name'] ?? 'batch.csv');
        $csvPath = '/uploads/batch_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
        $dest = dirname(__DIR__, 4) . '/storage' . $csvPath;
        if (!is_dir(dirname($dest))) {
            @mkdir(dirname($dest), 0775, true);
        }
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return $this->jsonError(500, 'upload_failed', 'انتقال فایل ناموفق بود.');
        }
        $id = (int)$this->connection()->pdo()->query(
            "INSERT INTO national_batches
                (original_filename, total_rows, processed_rows, matched_rows, csv_path, status)
             VALUES (" . $this->connection()->pdo()->quote($original) . ", 0, 0, 0,
                     " . $this->connection()->pdo()->quote($csvPath) . ", 'pending')"
        )->rowCount() === 1
            ? (int)$this->connection()->pdo()->lastInsertId()
            : 0;
        return $this->jsonSuccess(['batch_id' => $id]);
    }

    /* ================================================================== */
    /* Sync API (admin or secret)                                         */
    /* ================================================================== */

    /**
     * GET /api/v1/sync/status — admin: orchestrator status.
     */
    public function syncStatus(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        return $this->jsonSuccess($this->orchestrator->status());
    }

    /**
     * GET /api/v1/sync/pending — admin: pending review matches.
     */
    public function syncPending(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->connection()->all(
            'SELECT sal.id, sal.applicant_id, sal.national_request_number,
                    sal.action, sal.outcome, sal.confidence, sal.created_at
               FROM sync_audit_log sal
              WHERE sal.reviewed_by IS NULL
              ORDER BY sal.created_at DESC LIMIT 200'
        );
        return $this->jsonSuccess(['items' => $rows]);
    }

    /**
     * POST /api/v1/sync/run — admin: manually trigger a sync cycle.
     */
    public function syncRun(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $summary = $this->orchestrator->runCycle();
        return $this->jsonSuccess($summary);
    }

    /**
     * POST /api/v1/sync/{licenseId}/confirm — admin: confirm a sync match.
     */
    public function syncConfirm(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $licenseId = (int)($params['licenseId'] ?? 0);
        $note = (string)$request->input('note', '');
        $adminId = (int)$this->session->get('admin_id');
        $this->connection()->execute(
            'UPDATE sync_audit_log
             SET review_decision = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ?',
            ['confirmed', $note !== '' ? $note : null, $adminId, $licenseId]
        );
        return $this->jsonSuccess(['confirmed' => $licenseId]);
    }

    /**
     * POST /api/v1/sync/{licenseId}/reject — admin: reject a sync match.
     */
    public function syncReject(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $licenseId = (int)($params['licenseId'] ?? 0);
        $note = (string)$request->input('note', '');
        $adminId = (int)$this->session->get('admin_id');
        $this->connection()->execute(
            'UPDATE sync_audit_log
             SET review_decision = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ?',
            ['rejected', $note !== '' ? $note : null, $adminId, $licenseId]
        );
        return $this->jsonSuccess(['rejected' => $licenseId]);
    }

    /* ================================================================== */
    /* Proxy-image API                                                    */
    /* ================================================================== */

    /**
     * GET /api/v1/proxy-image — admin: proxy an upstream image so the
     * browser's CSP allows it (img-src 'self').
     */
    public function proxyImage(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $url = (string)$request->queryParam('url', '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->jsonError(422, 'invalid_url', 'آدرس تصویر نامعتبر.');
        }
        $blob = @file_get_contents($url);
        if ($blob === false) {
            return $this->jsonError(502, 'fetch_failed', 'دریافت تصویر ناموفق بود.');
        }
        $mime = (string)(@mime_content_type('data:' . $url) ?: 'image/jpeg');
        return (new Response($blob, 200, [
            'Content-Type'  => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ]));
    }

    /* ================================================================== */
    /* Helpers                                                            */
    /* ================================================================== */

    private function connection(): Connection
    {
        return $this->container->make(Connection::class);
    }

    private function workerSentinelPath(): string
    {
        return dirname(__DIR__, 4) . '/storage/cache/worker_running';
    }

    /**
     * @return array<string,mixed>
     */
    private function licenseFilterFromQuery(Request $request): array
    {
        $filter = [];
        foreach (['province_title', 'township_title', 'organization_title',
                  'license_title', 'status_slug', 'request_number', 'q'] as $k) {
            $v = $request->queryParam($k);
            if (is_string($v) && $v !== '') {
                $filter[$k] = $v;
            }
        }
        return $filter;
    }

    /**
     * Convert an Applicant value object to a JSON-serializable array.
     *
     * @return array<string,mixed>
     */
    private function applicantToArray(\App\Modules\Core\Domain\Applicant $a): array
    {
        return [
            'id'                          => $a->id,
            'tracking_code'               => $a->trackingCode,
            'first_name'                  => $a->firstName,
            'last_name'                   => $a->lastName,
            'national_code'               => $a->nationalCode,
            'mobile'                      => $a->mobile,
            'current_status'              => $a->currentStatus->value,
            'license_state'               => $a->licenseState->value,
            'service_type_id'             => $a->serviceTypeId,
            'registration_started_at'     => $a->registrationStartedAt,
            'registration_completed_at'   => $a->registrationCompletedAt,
            'national_license_request_number' => $a->nationalLicenseRequestNumber,
            'matched_at'                  => $a->matchedAt,
            'match_confidence'            => $a->matchConfidence,
            'consent_external_share'      => $a->consentExternalShare,
            'created_at'                  => $a->createdAt,
            'updated_at'                  => $a->updatedAt,
        ];
    }

    /**
     * Convert an array of associative rows to a CSV string.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function toCsv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $cols = array_keys($rows[0]);
        $out = implode(',', array_map(static fn ($c): string => '"' . str_replace('"', '""', (string)$c) . '"', $cols)) . "\n";
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) {
                $v = (string)($r[$c] ?? '');
                $v = mb_convert_encoding($v, 'UTF-8');
                $line[] = '"' . str_replace('"', '""', $v) . '"';
            }
            $out .= implode(',', $line) . "\n";
        }
        // Prepend a UTF-8 BOM so Excel auto-detects the encoding.
        return "\xEF\xBB\xBF" . $out;
    }
}
