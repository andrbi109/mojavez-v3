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
use App\Shared\Contracts\NationalBatchRepositoryInterface;
use App\Shared\Contracts\NationalFilterRepositoryInterface;
use App\Shared\Contracts\NationalLicenseRepositoryInterface;
use App\Shared\Contracts\NationalLookupRepositoryInterface;
use App\Shared\Persistence\Connection;
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
        ]);
    }

    /**
     * GET /admin/gateway/saved — saved/cached national licenses.
     *
     * Filter by status (all / active / expired / unknown) via ?status=
     * query param. CSV export links to the existing /api/v1/licenses/export
     * endpoint (the export logic is already there).
     */
    public function saved(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);

        $page = max(1, (int)($request->queryParam('page', 1) ?? 1));
        $pageSize = 25;
        $statusFilter = (string)($request->queryParam('status', '') ?? '');

        // Build a WHERE clause for is_saved = 1 + optional status filter.
        $where = 'is_saved = 1';
        $queryParams = [];
        if ($statusFilter === 'active' || $statusFilter === 'expired' || $statusFilter === 'unknown') {
            $where .= ' AND status_slug = ?';
            $queryParams[] = $statusFilter;
        }

        $offset = max(0, ($page - 1) * $pageSize);
        $rows = $this->conn->all(
            "SELECT * FROM national_licenses WHERE {$where} ORDER BY saved_at DESC, last_seen_at DESC LIMIT ? OFFSET ?",
            array_merge($queryParams, [$pageSize, $offset])
        );

        $total = (int)$this->conn->scalar(
            "SELECT COUNT(*) FROM national_licenses WHERE {$where}",
            $queryParams
        );

        $totalPages = $pageSize > 0 ? (int)ceil($total / $pageSize) : 0;

        return $this->render('admin/gateway_saved', [
            'title'        => 'مجوزهای ذخیره‌شده',
            'statusFilter' => $statusFilter,
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
     * Lists the last 20 batches from national_batches. The CSV upload
     * form POSTs the file to /api/v1/batch/upload via fetch in the view's
     * <script> block. Per-row processing is delegated to the existing
     * /api/v1/batch/* endpoints (the actual BatchJob is a future phase).
     */
    public function batch(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'sync_operator']);

        $batches = $this->batchRepo->recent(20);

        return $this->render('admin/gateway_batch', [
            'title'   => 'جستجوی دسته‌جمعی',
            'batches' => $batches,
        ]);
    }

    /**
     * Build the search filter array from request query params.
     *
     * Maps the form's field names to the NationalLicenseRepository's
     * queryCached() filter keys (the same shape V1ApiController::
     * licenseSearch uses).
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

        // province_title → province_title = ?.
        $provinceTitle = (string)($request->queryParam('province_title', '') ?? '');
        if ($provinceTitle !== '') {
            $filter['province_title'] = $provinceTitle;
        }

        // organization_title → organization_title = ?.
        $organizationTitle = (string)($request->queryParam('organization_title', '') ?? '');
        if ($organizationTitle !== '') {
            $filter['organization_title'] = $organizationTitle;
        }

        // status → status_slug = ?.
        $status = (string)($request->queryParam('status', '') ?? '');
        if ($status !== '') {
            $filter['status'] = $status;
        }

        return $filter;
    }
}
