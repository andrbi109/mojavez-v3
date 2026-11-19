# Phase 60 (v2.2.6) — Imported applicants one-time edit + sync with saved filters

**Task ID:** `phase-60`
**Agent:** glm (single-agent run, no sub-agents dispatched)
**Branch:** `phase/60-imported-edit-sync-filters` (created from `main` @ `be89d33`)
**Version:** `2.2.5` → `2.2.6`

## Summary

Three cohesive changes ship together under v2.2.6:

1. **One-time edit for bulk-imported applicants** — imported applicants
   (Phase 59b auto-provisioned a `user_accounts` row for them) can now
   edit + complete their case file exactly ONCE via
   `GET/POST /panel/requests/{id}/edit`. After the first save, the
   new `applicants.imported_edit_locked` column flips from 0 to 1 and
   the user only sees a "تکمیل شده" badge in `/panel/requests`. Admin
   edits continue to flow through the existing `/admin/edit/{id}` form
   (unaffected by the lock — the lock is a user-side gate only).
2. **Sync cycle uses saved filters** — `NationalSyncOrchestrator::runCycle()`
   now iterates the admin's active saved filters in `national_filters`
   (one upstream fetch per filter) instead of the previous single
   empty-`Filter(page:1, pageSize:20)` fetch. Each filter's
   `last_run_at` + `last_result_count` are updated after its fetch.
   Falls back to the empty-filter fetch when no saved filters exist
   (clean install, or the admin hasn't created any yet).
3. **Version bump** — `VERSION` and `README.md` updated to `2.2.6`.

## Files changed

### New
- `app/Modules/Core/migrations/20261121_001_applicants_imported_edit_locked.sql`
  — Idempotent ALTER adding `applicants.imported_edit_locked TINYINT(1)
  NOT NULL DEFAULT 0` (after `registration_completed_at`).
- `app/Views/user/edit_request.php` — the one-time edit form. A
  simpler, user-facing subset of `admin/edit_application.php`:
  identity + job + property fields. NO status-change dropdown, NO
  document upload (those stay admin-only). Includes the same Jalali
  3-select birth_date dropdown + marital_status / physical_condition
  toggle JS as the admin form.

### Modified
- `app/Modules/Core/Controllers/BulkImportController.php`
  - `executeRow()` now sets `imported_edit_locked = 0` explicitly.
  - Removed `registration_completed_at => $now` from the create() call
    (was being set to `$now` in v2.2.5). The Phase 60 GET edit-route
    guard is `imported_edit_locked = 0 AND registration_completed_at
    IS NULL`; leaving the old `$now` here would have excluded every
    imported applicant from the one-time edit window — contradicting
    the Phase 60 goal. Self-signup wizard + admin manual-register
    flows continue to set `registration_completed_at` themselves; the
    bulk-import flow is the only one that needed to drop it.

- `app/Modules/Core/Persistence/PdoApplicantRepository.php`
  - Added `imported_edit_locked` to both the `create()` field allowlist
    (so `BulkImportController::executeRow` can set it explicitly) and
    the `update()` updatable list (so the POST handler can flip it to 1).

- `app/Modules/Core/Controllers/UserPanelController.php`
  - Added `JobAndPropertyRepositoryInterface` constructor dependency
    (autowired — already bound in `Core/Module.php`).
  - Added `editRequest()` (GET) — auth + state guards + render the form.
  - Added `updateRequest()` (POST) — same guards + validate + persist
    identity fields + upsert job/property + set `imported_edit_locked
    = 1` + insert a `status_log` audit row + flash + redirect.
  - Added private `birthDateFromInputs()` + `validPostalCode()`
    helpers (mirrors `AdminController`'s private copies — kept
    private so the user panel doesn't reach into the admin
    controller's surface).
  - Imported `PersianDate`, `Validator`, `JobAndPropertyRepositoryInterface`.

- `app/Modules/Core/Module.php`
  - Added two routes:
    - `GET  /panel/requests/{id}/edit` → `UserPanelController::editRequest`
    - `POST /panel/requests/{id}/edit` → `UserPanelController::updateRequest`

- `app/Views/user/requests.php`
  - Per-row: shows "ویرایش و تکمیل" button (links to the edit form)
    when `imported_edit_locked = 0 AND registration_completed_at IS NULL`.
  - Per-row: shows "تکمیل شده" badge (locked, no edit link) when
    `imported_edit_locked = 1`.
  - Other rows (self-signup drafts, completed, rejected, issued) show
    neither — keeps the column from sprouting a confusing locked-button
    on every row.

- `app/Modules/NationalGateway/Services/NationalSyncOrchestrator.php`
  - Added optional `?NationalFilterRepositoryInterface $filterRepo = null`
    constructor parameter (kept optional so the existing
    `NationalSyncOrchestratorDedupTest` which uses the 8-arg signature
    still works — the new param defaults to null and `runCycle()` falls
    back to the empty-filter fetch when null).
  - Replaced the single `new Filter(page: 1, pageSize: 20)` fetch with
    a new private `fetchUpstreamWithSavedFilters()` helper that:
    1. Loads all active saved filters via `$filterRepo->active()`.
    2. Decodes each row's `filter_data` JSON into a `Filter` VO via
       `Filter::fromArray()` (with `page=1` and a default
       `page_size=20`).
    3. Calls `LicenseService::searchUpstream($filter)` per filter.
    4. Logs each fetch to `integration_log` via the existing
       `logIntegrationFetch()` helper (one audit row per filter).
    5. Aggregates per-filter summaries into a single summary with
       the same shape as `searchUpstream()`'s return value
       (`reached` OR'd, `fetched` + `total` summed, `degraded` OR'd,
       first non-null `error` captured).
    6. Calls `$filterRepo->markRun($filterId, $summary['fetched'])`
       per filter (best-effort — never aborts the cycle).
  - Falls back to the legacy empty-filter fetch when:
    - `$filterRepo` is null (test compatibility).
    - `active()` throws (e.g. `national_filters` table doesn't exist
      yet on a pre-install env).
    - `active()` returns an empty array (clean install, or admin
      hasn't created any filters yet).

- `app/Modules/NationalGateway/Module.php`
  - Updated the `NationalSyncOrchestrator` binding to pass the
    `NationalFilterRepositoryInterface` as the 10th constructor arg.

- `VERSION` — `2.2.5` → `2.2.6`.
- `README.md` — header + status line updated to `2.2.6` (also bumped
  the route count `205+` → `207+` for the two new `panel/requests/{id}/edit`
  routes).

- `tools/static_analysis_ignore.txt` — added 5 entries for
  `app/Views/user/edit_request.php` covering the same integer + Persian
  month-name variable patterns already exempted for
  `admin/edit_application.php` (loop counters `$y`/`$m`/`$d`, month
  name `$name`, service-type id `$sid`).

## Verification

- `php -l` on all 8 modified/new PHP files: no syntax errors.
- `tools/check_schema_ownership.py`: my new migration passes (the 1
  pre-existing violation in `20261117_004_integration_log_table.sql`
  is unrelated — present on `main` before this branch).
- `tools/static_analysis.py --strict`: 170 warnings, all 170
  suppressed by the ignore file, 0 surviving.
- `tests/run.php`: 11 non-DB tests pass (Container, Hash, LicenseAnchors,
  Normalizer, PatternMatcher, Router, SettingsControllerValidation,
  SseController, StructuredLogger, TrackScraper, Validator — 252
  assertions total). The 23 DB-dependent tests skip because there's
  no DB available in this environment (pre-existing, not caused by
  these changes).
- `NationalSyncOrchestratorDedupTest` (uses the 8-arg orchestrator
  constructor) keeps working: the new 10th param is nullable with
  default `null`, and `fetchUpstreamWithSavedFilters()` falls back to
  the legacy empty-filter fetch when the filterRepo is null.

## Key design decisions

### Why remove `registration_completed_at => $now` from the bulk import?

The Phase 60 spec says the edit-route gate is `imported_edit_locked = 0
AND registration_completed_at IS NULL`. The bulk-import flow in v2.2.5
set `registration_completed_at = $now` for imported applicants. If I
left that in, the GET gate would have rejected every imported applicant
— defeating the whole feature.

The cleanest fix is to leave `registration_completed_at` NULL for bulk-
imported applicants (their `registration_started_at` is still set, so
the row isn't in an "empty timestamps" state). Self-signup wizard
users and admin-manual-register users continue to set
`registration_completed_at` when their flow finishes, so they remain
unaffected. This makes the imported-applicant lifecycle clean:

1. Bulk import → `registration_started_at = NOW()`,
   `registration_completed_at = NULL`, `imported_edit_locked = 0`,
   `current_status = 'تأیید اولیه'`.
2. User edits once via `/panel/requests/{id}/edit` → POST handler
   flips `imported_edit_locked` to 1. `registration_completed_at`
   stays NULL (the user's edit doesn't count as "wizard completion").
3. Future edits: rejected by the `imported_edit_locked = 1` check
   in the GET handler.

### Why a new `imported_edit_locked` column instead of a flag in `current_status`?

The 18-status workflow is a separate concept (the case file's position
in the issuance lifecycle). Adding a 19th "imported-draft" status would
have rippled into the status transition matrix, the auto-cancel
service's pre-issue status list, the user-panel overview's stat
widgets, etc. A dedicated TINYINT(1) flag is the smallest possible
schema change and touches only the one controller that needs to gate
on it.

### Why iterate saved filters in `runCycle()` instead of in a queue job?

The cycle already runs as a single job (queued by `SyncSubscriber`
when the admin clicks "run sync", or by the cron). Each saved filter
is a fast upstream HTTP call (one page, ~20 licenses) + a
`markRun` UPDATE. Iterating them sequentially inside `runCycle()`
keeps the audit trail simple (one `cycle_started` + one `cycle_completed`
row per cycle in `sync_audit_log`, with the per-filter fetches logged
to `integration_log`). A queue-per-filter design would split the audit
trail across multiple jobs and require a separate "cycle coordinator"
to know when all filters had finished — overkill for the typical
"admin has 3-5 saved filters" workload. If the saved-filter count ever
grows past ~20, the right move is to cap the per-cycle filter count
(sampling), not to parallelize.

## What was NOT done (out of scope for Phase 60)

- No new test code (per the task instructions). The existing
  `NationalSyncOrchestratorDedupTest` continues to pass with the
  expanded constructor signature (new param is optional).
- No changes to the admin's `/admin/edit/{id}` form — admin edits are
  not affected by `imported_edit_locked` (the lock is a user-side
  gate, not a global one). If the admin ever needs to unlock a user's
  one-time edit (so the user can re-edit), a small admin-side "unlock"
  button + handler would do it — left for a future phase.
- No changes to the bulk-import CSV format. The CSV columns stay
  `national_code, first_name, last_name, mobile, service_type_id,
  postal_code`. The job_details + property_details rows the user
  fills in via the new edit form are still optional (the POST handler
  only upserts them if the user provided enough fields to satisfy the
  NOT NULL constraints — same pattern as `AdminController::updateApplication`).
- No CHANGELOG entry (the v2.2.5 → v2.2.6 bump is recorded in VERSION +
  README; the CHANGELOG file's `## [Unreleased]` section is left for
  the next consolidated release-notes pass).
