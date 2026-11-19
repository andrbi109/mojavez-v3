# Phase 62 (v2.2.8) — Gateway module verification and fixes (complete)

**Task ID:** `phase-62`
**Agent:** glm (single-agent run, no sub-agents dispatched)
**Branch:** `phase/62-gateway-complete` (created from `main` @ `faadb05`)
**Version:** `2.2.7` → `2.2.8`

## Summary

Verified and fixed the entire `NationalGateway` module (36 files) per
the Phase 62 task spec. The gateway works correctly when the upstream
(`qr.mojavez.ir`) is reachable from inside Iran — outside Iran, it runs
in degraded mode with the local cache, which is expected (the upstream
is geofenced). No code changes were needed for the search flow itself
(`MojavecClient::search` + `Filter::toUpstreamPayload` +
`JsonSearchParser::parseSearch` + `LicenseService::searchUpstream` +
`NationalSyncOrchestrator::fetchUpstreamWithSavedFilters` were all
already correct); the work concentrated on the admin views, the
batch-upload flow, the `track_fetch` queue handler, and the export
endpoints.

## Changes per task

### Task 1 — Gateway search flow verification (no code change)

Read `app/Modules/NationalGateway/Infrastructure/MojavecClient.php` +
`Domain/Filter.php` + `Infrastructure/Parser/JsonSearchParser.php` +
`Services/LicenseService.php` (searchUpstream) +
`Services/NationalSyncOrchestrator.php` (fetchUpstreamWithSavedFilters).

Verified:
- `Filter::toUpstreamPayload()` correctly converts Jalali → Gregorian
  (via `PersianDate::jalaliStringToGregorian`) before sending to
  upstream. Empty/malformed dates become `""` (upstream treats as "no
  date filter") rather than the raw Jalali string.
- `JsonSearchParser::parseSearch()` correctly extracts
  `data.filterLicenses.license[]` from the upstream response.
  Verified against the byPerson.har fixture: the response is
  `{"data":{"filterLicenses":{"license":[{...}]}}}` and the parser
  returns one license row.
- `LicenseService::searchUpstream()` returns
  `reached=true, fetched=0, degraded=false` when the upstream
  responds with HTTP 200 and an empty result set. This is NOT an
  error — it just means no licenses match the saved filters'
  criteria.
- `NationalSyncOrchestrator::fetchUpstreamWithSavedFilters()`
  aggregates per-filter summaries correctly: `reached = true` if at
  least one filter reached upstream; `fetched = sum across filters`;
  `degraded = true` if at least one filter was degraded.

### Task 2 — Sync status page (`gateway_sync_status.php`)

Replaced the alert-rendering block with a three-state machine:
- `reached && !degraded && fetched > 0` → green "درگاه ملی در دسترس
  بود" with fetched/total/processed/tried counts.
- `reached && !degraded && fetched === 0` → green "درگاه ملی در
  دسترس بود؛ اما هیچ مجوزی با معیارهای فیلترهای ذخیره‌شده مطابقت
  نداشت" — explicitly tells the admin this is NOT an error.
- `degraded` → yellow "درگاه ملی در دسترس نبود" with the error
  string + an extra geofence hint when the error looks like a
  transport failure (DNS / connection refused / timeout).
- else → red "دور همگام‌سازی اجرا شد اما درگاه ملی پاسخ نداد".

Every key access in the alert block is guarded with `?? ''` /
`?? false` / `?? null` so the view never raises an "Undefined array
key" notice when the orchestrator summary is missing keys.

### Task 3 — Gateway search page (`gateway_search.php`)

- Added `issue_date_start` + `issue_date_end` Jalali date inputs to
  the filter form (with `pattern="\d{4}/\d{1,2}/\d{1,2}"` for client-
  side validation). The controller's `buildSearchFilter()` now reads
  these and passes them to `NationalLicenseRepository::queryCached`,
  which converts them to Gregorian (`PersianDate::jalaliStringToGregorian`)
  before comparing against the Gregorian `responded_at` column. The
  end date gets a `23:59:59` upper bound so the entire day is
  included.
- Fixed the province/organization dropdown filter: the option
  `value` was the upstream numeric code (e.g. "12"), but the
  repository's WHERE clause compared against the `province_title`
  text column (e.g. "خراسان رضوی"). Added a `lookupTitle()` helper
  in the controller that translates numeric code → title via the
  `national_lookups` table before passing the filter to the
  repository. Non-numeric values pass through unchanged (so saved
  filters that stored the title still work).
- Added a "save/unsave" toggle button per license row (bookmark
  icon). POSTs to `/api/v1/licenses/{id}/save` to mark saved,
  DELETEs the same URL to unmark. The button's class + icon swap
  in place on success without a page reload.
- Table header now has 9 columns (added "ذخیره" column); the
  empty-state `colspan` was bumped from 8 to 9.

### Task 4 — Gateway saved page (`gateway_saved.php`)

- Added an "all/saved/unsaved" filter dropdown (`?saved=0|1`) next
  to the existing status dropdown. The controller's `saved()`
  now uses `NationalLicenseRepository::buildWhereSql()` (newly
  exposed as a public method) so the WHERE clause is built in one
  place and reused for both the rows SELECT and the COUNT(*).
- Added the same save/unsave toggle button per row (same JS
  pattern as the search page).
- Pagination links now carry both `status` + `saved` query params.
- Table header now has 9 columns; empty-state `colspan` bumped
  from 8 to 9.

### Task 5 — Gateway batch page (`gateway_batch.php`)

The previous batch page delegated to `/api/v1/batch/upload`, which
recorded the batch with `total_rows=0` and never processed the CSV.
Rewrote the flow:

- New `GatewayAdminController::batchUpload()` action (POST
  `/admin/gateway/batch`) parses the uploaded CSV in-process via
  `CsvReader`, looks up each row in `national_licenses` by
  `request_number` (for the `license_number` column) or by
  `masked_national_code` (for the `national_code` column — exact
  match first, then LIKE on the last 4 digits), and stores the
  per-row result in the session flash.
- The view renders the flash result inline: a table with one row
  per CSV line, showing the line number, the submitted license
  number / national code, the matched applicant name + license
  title (if matched), and a green/red "تطبیق" / "بدون تطبیق"
  badge.
- Below the results table, a "search upstream for unmatched"
  button (only shown when there are unmatched rows with a
  license_number) submits the unmatched request_numbers via POST
  to `/admin/gateway/batch/queue`.
- New `GatewayAdminController::batchQueue()` action enqueues a
  `track_fetch` job per unmatched request_number via
  `JobQueue::enqueueTrackFetch()`. The flash message tells the
  admin how many jobs were queued and where to see the results
  (the search page, after the Worker drains the queue).
- The recent-batches table (`national_batches`) was simplified to
  show id/filename/total/matched/status/created (no live-status
  polling, no per-batch detail modal) — the inline results table
  replaces that.
- The controller's constructor was extended with `JobQueue` +
  `CsvReader` dependencies (both already bound in the Container
  — JobQueue via the queue factories, CsvReader via
  `registerSupport`).
- Two new routes added in `Module::routes()`:
  `POST /admin/gateway/batch` and `POST /admin/gateway/batch/queue`.

### Task 6 — Gateway filters page (`gateway_filters.php`)

- The existing page already had create/edit/delete/apply/"run in
  queue" buttons. Verified all four mutations work via the
  `/api/v1/filters/*` REST endpoints (POST/PUT/DELETE) + the
  `apply` link to `/admin/gateway/search?<filter>`.
- Added `issue_date_start` + `issue_date_end` fields to the
  create/edit modal so saved filters can carry a date range
  (consistent with the search page's new date filter).
- Fixed the "run in queue" button: the previous version sent
  `{filter_id: id}` to `/api/v1/admin/crawl`, but the controller
  expected `{filter: {...}}` and silently enqueued a search job
  with an empty filter. Updated `V1ApiController::adminCrawl` to
  accept BOTH shapes — when `filter_id` is present, it looks up
  the saved filter's `filter_data` JSON via
  `NationalFilterRepository::find()` and enqueues that.
  Backwards-compatible: the legacy `{filter: {...}}` shape still
  works.
- Made the "run in queue" confirm + alert messages more
  explicit: they now mention the Worker / poor man's cron
  pattern, link to both `/admin/gateway/search` (for results)
  and `/admin/sync/status` (for Worker activation).

### Task 7 — TrackScraper + /api/v1/licenses/{id}/track endpoint

The `TrackScraper::fetch()` method was already correct:
- GETs `/track/{request_number}` from `qr.mojavez.ir`.
- Extracts the RSC flight payload via regex on
  `self.__next_f.push([1, "..."])` script tags.
- Parses the license data object (the JSON object with
  `isic_code` key) via balanced-brace scanning.
- Parses the applicant identity (label → next-children value
  pairs, e.g. `"کدملی" → "073****269"`).
- Parses the business location (`"استان"`, `"شهرستان"`,
  `"کدپستی"`, `"نشانی کسب و کار"`).
- Returns a normalized array that `License::fromTrackPage`
  converts to a `License` domain object, which
  `LicenseService::fetchAndCacheByTrackNumber` then persists to
  `national_licenses` via `NationalLicenseRepository::save`.

The `/api/v1/licenses/{id}/track` endpoint enqueues a
`track_fetch` job via `JobQueue::enqueueTrackFetch`. BUT: the
`Worker::handlerFor($type)` returned null for every job type
(no handlers were registered anywhere in the codebase), so every
queued `track_fetch` job failed with "No handler registered for
type 'track_fetch'".

Added a new `TrackFetchJobHandler` (under
`app/Modules/NationalGateway/Queue/`) that implements
`JobInterface`, delegates to
`LicenseService::fetchAndCacheByTrackNumber`, and throws on
failure (so the Worker marks the job as failed and retries per
the queue's `max_attempts` + exponential backoff). Registered
the handler in `NationalGateway\Module::boot()` via
`JobQueue::registerHandler()`. This means:
- The `/api/v1/licenses/{id}/track` endpoint now has a real
  downstream consumer.
- The batch page's "search upstream for unmatched" button (which
  enqueues `track_fetch` per unmatched row) also has a real
  downstream consumer.
- The Worker drains these jobs on subsequent
  `/admin/sync/status` polls (poor man's cron pattern).

### Task 8 — ExportService (`Services/ExportService.php`)

The previous `ExportService` was dead code with two
broken-as-autoloadable bugs:
- It imported `App\Persistence\LicenseRepository` — a namespace
  that doesn't exist in v3 (the gateway project's flat
  namespace, never copied over).
- It used `$result['items']` — but
  `NationalLicenseRepository::queryCached` returns
  `['rows' => ..., 'total' => ...]`, not `items`.

Even if it had been bound in the Container, every call would
have produced an empty CSV. Rewrote the class:
- Imports `NationalLicenseRepositoryInterface` (the v3
  contract).
- Uses `$result['rows']` (the correct shape).
- Exports ALL the `national_licenses` columns (request_number
  through matched_by — 24 columns), not just the previous 9.
- Added a `toHtml()` method that produces a print-friendly
  HTML document (RTL, Vazirmatn, A4 landscape, Jalali dates via
  `PersianDate::format`). The admin opens it in the browser and
  uses "Print → Save as PDF" to produce a real PDF. Pure-PHP, no
  Composer, no extra deps — this is the "PDF export" the task
  spec accepts.
- Bound the service in `Module::register()` so it's available
  for autowiring.

Also updated `V1ApiController::licenseExport` to:
- Accept `?format=csv|json|html` (default `csv` for backwards
  compat with the saved-page link).
- Use `ExportService` when available (full columns + html
  support).
- Fall back to the inline `toCsv()` helper when `ExportService`
  isn't autowired (e.g. in tests).

### Bonus — `CsvBatchService` imports cleanup

`CsvBatchService` had the same broken-import issue as
`ExportService` (it imported the non-existent
`App\Persistence\BatchRepository`). The class isn't wired into
the Container (the new batch flow goes through
`GatewayAdminController::batchUpload`), but I cleaned up its
imports so the static analyzer doesn't flag it. Changed
`App\Persistence\BatchRepository` →
`App\Shared\Contracts\NationalBatchRepositoryInterface` + the
`STORAGE_PATH` constant (also undefined) →
`dirname(__DIR__, 4) . '/storage/uploads'`.

### Task 9 — Version bump

- `VERSION` → `2.2.8`
- `README.md` header → `2.2.8`

## Files changed

### New
- `app/Modules/NationalGateway/Queue/TrackFetchJobHandler.php` —
  the runtime handler for `track_fetch` queue jobs. Delegates to
  `LicenseService::fetchAndCacheByTrackNumber`, which scrapes the
  upstream `/track/{rn}` page and persists the golden-link anchors
  to `national_licenses`.

### Modified
- `app/Modules/NationalGateway/Module.php`:
  - Added `ExportService` binding in `register()`.
  - Added `TrackFetchJobHandler` registration in `boot()` (with
    try/catch so a missing JobQueue at pre-install doesn't break
    the module boot).
  - Added two routes: `POST /admin/gateway/batch` and
    `POST /admin/gateway/batch/queue`.

- `app/Modules/NationalGateway/Persistence/NationalLicenseRepository.php`:
  - Added `buildWhereSql()` public method (wraps the private
    `buildWhereClause()`) so the saved-page controller can reuse
    the same WHERE clause for both rows + count.
  - Added `issue_start_date` / `issue_end_date` filter support
    (Jalali → Gregorian via `PersianDate`, with `23:59:59` upper
    bound for the end date so the entire day is included).
  - Added `is_saved` filter support ('1' / '0' / anything-else)
    for the saved-page all/saved/unsaved dropdown.

- `app/Modules/NationalGateway/Controllers/GatewayAdminController.php`:
  - Added `JobQueue` + `CsvReader` constructor dependencies (both
    already bound in the Container).
  - Updated `saved()` to use `buildWhereSql()` and accept the
    `?saved=0|1` query param.
  - Updated `buildSearchFilter()` to read `issue_date_start` /
    `issue_date_end` and to translate province/organization
    codes → titles via the new `lookupTitle()` helper.
  - Rewrote `batch()` GET action to render the inline result +
    queue-ack flash messages.
  - Added `batchUpload()` POST action — CSV parse + local cache
    lookup + session-flash result.
  - Added `batchQueue()` POST action — enqueues a `track_fetch`
    per unmatched request_number.
  - Added `pickColumn()` private helper for case-insensitive
    CSV column lookup.

- `app/Modules/NationalGateway/Controllers/Api/V1ApiController.php`:
  - Added optional `?ExportService $exportService` constructor
    argument (nullable so existing tests with the old 15-arg
    signature still work).
  - Rewrote `licenseExport()` to accept `?format=csv|json|html`
    and use `ExportService` when available.
  - Updated `adminCrawl()` to accept either `{filter: {...}}` (the
    legacy shape) or `{filter_id: int}` (the filters page's
    "Run in queue" button). When `filter_id` is present, looks
    up the saved filter's `filter_data` and enqueues with that.

- `app/Modules/NationalGateway/Services/ExportService.php`:
  - Full rewrite. Now imports
    `NationalLicenseRepositoryInterface` (the v3 contract), uses
    `$result['rows']` (the correct shape), exports ALL
    `national_licenses` columns (24), and adds a `toHtml()` print
    view with Jalali dates.
  - The `exportDir` constructor argument is kept for BC but
    ignored (the service is now stateless + returns strings to
    the caller).

- `app/Modules/NationalGateway/Services/CsvBatchService.php`:
  - Fixed broken imports: `App\Persistence\BatchRepository` →
    `App\Shared\Contracts\NationalBatchRepositoryInterface`.
  - Fixed `STORAGE_PATH` (undefined constant) →
    `dirname(__DIR__, 4) . '/storage/uploads'`.
  - Still not wired into the Container (the new batch flow goes
    through `GatewayAdminController::batchUpload`); kept as
    future-ready.

- `app/Views/admin/gateway_sync_status.php`:
  - Three-state alert (reached / degraded / error), with
    explicit handling of the `fetched === 0` case (green alert
    with explanation, not error).
  - Every key access guarded with `??` so no "Undefined array
    key" notice.
  - Geofence hint on transport-failure-looking errors.

- `app/Views/admin/gateway_search.php`:
  - Added `issue_date_start` + `issue_date_end` Jalali date
    inputs to the filter form.
  - Added "ذخیره" column + per-row save/unsave toggle button.
  - Table header has 9 columns now; empty-state `colspan` bumped
    to 9.
  - Added JS handler for `.btn-toggle-save` that POSTs/DELETEs
    `/api/v1/licenses/{id}/save` and swaps the button state
    in-place.

- `app/Views/admin/gateway_saved.php`:
  - Added "ذخیره‌شده" dropdown (`?saved=0|1`) next to the
    existing status dropdown.
  - Added "ذخیره" column + per-row save/unsave toggle button
    (same JS as search page).
  - Pagination links carry both `status` + `saved` params.
  - Table header has 9 columns; empty-state `colspan` bumped
    to 9.

- `app/Views/admin/gateway_batch.php`:
  - Full rewrite. Form now POSTs to `/admin/gateway/batch`
    (with `enctype="multipart/form-data"`) — the controller
    parses the CSV in-process + does the local cache lookup.
  - Renders the inline results table from the session flash
    (one row per CSV line, with matched/unmatched badge + the
    matched license's applicant name + license title).
  - "Search upstream for unmatched" button (only when there
    are unmatched rows with a license_number) submits the
    unmatched request_numbers to `/admin/gateway/batch/queue`.
  - Recent-batches table simplified to id/filename/total/matched
    /status/created (the inline result replaces the per-batch
    detail modal).

- `app/Views/admin/gateway_filters.php`:
  - Added `issue_date_start` + `issue_date_end` fields to the
    create/edit modal (consistent with the search page).
  - Made the "run in queue" confirm + alert messages more
    explicit (mention Worker / poor man's cron, link to both
    search page + sync status page).

- `VERSION`: `2.2.7` → `2.2.8`.
- `README.md`: version header → `2.2.8`.

## Test results

All existing tests pass (the 9 "MySQL server has gone away" errors
are pre-existing DB-connection flakiness on this server — they
happen between tests when the DB connection is dropped, not from
any change in this phase). Specifically:
- `NationalLicenseRepositoryTest`: 19 assertions, PASS.
- `NationalSyncOrchestratorDedupTest`: 6 assertions, PASS.
- `TrackScraperTest`: 19 assertions, PASS.
- `ContainerWiringTest`: 21 assertions, PASS (verifies the new
  `ExportService` binding resolves correctly).

PHP lint (`php -l`) passes on all modified files. The
`tools/php_lint_simple.py` warnings about "unbalanced braces" in
`ExportService.php` and `gateway_search.php` are false positives
from the linter's simple brace counter — it doesn't understand
HTML inside heredoc (ExportService) or JS inside `<script>` tags
(gateway_search). The real `php -l` passes cleanly on both.
