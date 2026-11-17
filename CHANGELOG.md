# Changelog — mojavez-v3

All notable changes to mojavez-v3 will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

`APP_VERSION` in `config/config.php`, the `VERSION` file, and the `README.md`
header all read from a single source of truth — this file's latest release tag.
This is the fix for the version three-way split seen in v2 (see
`docs/03-strategy-and-risk.md` §2.3).

## [Unreleased]

### Added — Phase 10: Route/View 404/500 prevention

After Phase 9 closed the `/panel/*` 404-after-login, the user requested a
broader audit of ALL routes and views to find every remaining 404 or hidden
500 that would surface at run time. This is the result of that audit.

**7 hidden runtime bugs fixed** (all present since Phase 7 but never caught
because no test logs in as an admin and exercises the affected routes):

1. **`UsersController` constructor signature mismatch** — called
   `parent::__construct()` with only 4 args (container, session, csrf,
   environment) while the base `Controller` requires 6 (the above + `Hash` +
   `StructuredLogger`). Every `/admin/users*` route returned a fatal "Too few
   arguments" 500. Fixed: both dependencies added to the constructor and
   passed to `parent::__construct()`.
2. **`SettingsController` constructor signature mismatch** — same bug. Every
   `GET/POST /admin/settings` returned a fatal 500. Fixed.
3. **`UsersController` / `SettingsController` render() third-arg misuse** —
   passed a Persian title string as the third argument to `render()`, which
   the base method interprets as the **layout name**. The layout resolver
   then looked for `app/Views/مدیریت کاربران.php` (which doesn't exist),
   silently fell back to "no layout", and the admin pages were rendered
   without `admin_layout.php` — no sidebar, no header, no Bootstrap CSS.
   Fixed: title is now passed via the `$data` array (`'title' => ...`),
   and `render()` is called with the default layout (which auto-detects
   `admin/*` views and uses `admin_layout`).
4. **`admin/login.php` nested-HTML** — a complete HTML document
   (`<html>/<head>/<body>`), but `render('admin/login', [])` auto-wrapped it
   in `admin_layout.php` (which is also a full HTML document) → `<html>`
   inside `<body>`. Fixed by adding a `'none'` sentinel to
   `Controller::render()` that skips layout wrapping, and using it for the
   login page (which is shown to unauthenticated users and should not have
   the admin sidebar anyway).
5. **`print.php` nested-HTML** — same as above for `/tracking/print/{code}`:
   a full HTML document wrapped by `layout.php` (site nav + footer) — which
   is also conceptually wrong for a printable A4 page. Fixed with
   `render('print', ..., 'none')`.
6. **`Container` self-registration missing** — the `Container` did not
   register itself as a singleton, so when a controller was autowired with
   a `Container` dependency, a fresh empty `Container` (no module bindings)
   was constructed. Subsequent calls like
   `$this->container->make(Connection::class)` then threw
   `RuntimeException: Cannot autowire Connection: parameter $host has no
   type` because the empty Container had no factory for `Connection`. This
   meant `AdminController::dashboard()` (which uses
   `$this->connection()->scalar(...)`) returned a 500 on the first
   logged-in dashboard view. Fixed: `$this->singletons[self::class] = $this;`
   in the `Container` constructor — autowired controllers now get the real,
   fully wired Container.
7. **404 page was bare HTML** — only `<h1>۴۰۴</h1><p>صفحه یافت نشد.</p>`,
   no site layout, no nav, no recovery actions. Fixed: new
   `app/Views/errors/404.php` content-only view wrapped in `layout.php`,
   with buttons for "back to home", "tracking", and "guide". The front
   controller renders this view when `RouteNotFoundException` is thrown,
   with a try/catch fallback to the bare 404 string if the view itself
   fails — so the 404 page never 500s.

**3 missing admin routes added** (sidebar links that previously returned 404):

- `GET /admin/form-fields` → `AdminController::formFields` — renders
  `admin/stub_coming_soon.php` with a Persian "coming in v1.2" message.
- `GET /admin/fee-tariff` → `AdminController::feeTariff` — same.
- `GET /admin/license-titles` → `AdminController::licenseTitles` — same.

These three routes are linked from `admin_layout.php`'s sidebar but had no
matching controller/route — clicking any of them produced a 404. The stubs
return HTTP 200 with a clear explanation that the full CRUD is deferred to
v1.2 (DEBT_REVIEW D-7-1 + D-10-1), plus a back-to-dashboard link. This is
intentionally a stub (not a 501, not a 404) so users can discover the
planned sections without hitting a dead end.

**Security fix:**

- `SyncAdminController` (Phase 8) did NOT extend the base `Controller` class
  and therefore bypassed `requireLogin()/requireRole()` entirely. The
  docblock claimed the base class enforced auth, but the class didn't
  extend it — so `/admin/sync/status`, `/admin/sync/pending`, and
  `/admin/sync/run` were all reachable by unauthenticated users. Fixed:
  `SyncAdminController` now extends `Controller` and calls
  `requireRole(['admin', 'sync_operator'])` at the top of every action.
  Unauthenticated users now get a 302 redirect to `/admin/login` (the
  expected admin-area UX) instead of raw JSON.

**`Controller::render()` API addition:**

- New sentinel value `'none'` for the `$layout` parameter — renders the
  view as-is with NO layout wrapping. Use this for views that are already
  complete HTML documents (e.g. `admin/login.php`, `print.php`). Without
  this, such views would be nested inside a layout, producing invalid HTML
  (`<html>` inside `<body>`). See `Controller.php` docblock for details.

**New view files:**

- `app/Views/admin/stub_coming_soon.php` — shared "coming soon" view for
  the three deferred admin sections. Parameterised by `$title`, `$icon`,
  `$description`.
- `app/Views/errors/404.php` — content-only 404 view wrapped by
  `layout.php`, with site nav + footer + recovery buttons. Rendered by the
  front controller on `RouteNotFoundException`.

### Files changed (Phase 10)

| File | Change |
|---|---|
| `app/Container.php` | +1 line: self-register as singleton in constructor |
| `app/Modules/Core/Controllers/Controller.php` | +`'none'` layout sentinel in `render()` |
| `app/Modules/Core/Controllers/AuthController.php` | `adminLoginForm` uses `render('admin/login', [], 'none')` |
| `app/Modules/Core/Controllers/TrackingController.php` | `printView` uses `render('print', ..., 'none')` |
| `app/Modules/Core/Controllers/UsersController.php` | Fix constructor (add `Hash` + `StructuredLogger`); fix `render()` calls (title via `$data`, no third arg) |
| `app/Modules/Core/Controllers/SettingsController.php` | Same fix as UsersController |
| `app/Modules/Core/Controllers/AdminController.php` | +3 stub actions: `formFields`, `feeTariff`, `licenseTitles` |
| `app/Modules/NationalGateway/Controllers/SyncAdminController.php` | Now extends `Controller`; `requireRole()` in every action |
| `app/Modules/Core/Module.php` | +3 stub routes: `/admin/form-fields`, `/admin/fee-tariff`, `/admin/license-titles` |
| `public/index.php` | 404 handler now renders `errors/404.php` view inside `layout.php`, with fallback to bare HTML |
| `app/Views/admin/stub_coming_soon.php` | new — shared "coming soon" view |
| `app/Views/errors/404.php` | new — content-only 404 page with recovery buttons |
| `docs/DEBT_REVIEW.md` | Phase 10 section + updated live-debt summary |
| `docs/PHASE_10_ROUTES_VIEWS.md` | new — Phase 10 documentation |
| `CHANGELOG.md` | this section |

### Notes

- All existing unit tests (391), contract tests (21), and HTTP smoke tests
  (66 pass / 8 pre-existing D-8-5) remain green — no regression introduced.
- The `static_analysis.py` and `php_lint_simple.py` warnings are
  unchanged (all pre-existing false positives around `$csrfField` /
  `$content` / `define()` / CSS braces).
- The `Container` self-registration fix is the highest-impact change: it
  converts a class of "first logged-in admin click → 500" failures into
  working pages.

---

## [1.1.0] — 2026-11-17

Phase 8 — Rollout Stage 2 (two-way sync). The infrastructure for pushing v3
state changes back to the upstream national gateway is now in place. Stage 2
is NOT auto-enabled — it requires a human (admin) to review the
Stage2ReadinessChecker report and flip `sync_direction='two_way'` +
`sync_two_way_enabled='1'` via the settings panel (runtime, no deploy).

Per `docs/00-MASTER.md §29.1` (Stage 2) + `§29.2` (4 readiness conditions)
+ `§29.3` (fallback kill-switch).

### Added — Phase 8 (Rollout Stage 2)

- **`integration_log` table** — the canonical two-way sync audit trail.
  Every interaction with upstream (both inbound reads and outbound writes)
  is logged with `direction`, `operation`, `idempotency_key`, request/response
  payloads, `http_status`, `outcome`, `source_of_truth` (v3|upstream),
  `conflict_resolution` (last_write_wins|v3_wins|upstream_wins|manual_review),
  `upstream_updated_at`, `latency_ms`. 16 columns, 6 indexes, FK to
  applicants. Auto-generated into `database/schema.sql` (now 42 tables).
- **`OutboundSyncService`** — pushes v3 state changes to upstream.
  `pushStatusChange()` + `pushDocument()` build the payload, check
  idempotency (SHA-256 of operation+applicant_id+payload), detect conflicts
  (compares upstream_updated_at from last inbound fetch against v3's
  applicants.updated_at — if upstream newer, push is 'conflict' with
  source_of_truth='upstream'), perform the push (currently a stub — upstream
  has no writable endpoint yet per §19.8), and log to integration_log.
  **Kill-switch**: on 3 consecutive 5xx failures, auto-flips
  `sync_two_way_enabled→'0'` + `sync_direction→'inbound'` — no deploy
  needed (§29.3 fallback).
- **`Stage2ReadinessChecker`** — evaluates the 4 §29.2 conditions:
  1. Stage 1 stable ≥ 90 days (from first integration_log inbound entry).
  2. AutoConfirmed false-positive rate < 0.1% (auto_confirmed later
     operator_rejected, from sync_audit_log).
  3. Circuit-breaker open rate < 5% in last 30 days (distinct days with
     server_error in integration_log inbound).
  4. Contract tests exist in `tests/Contract/`.
  Returns `{ready: bool, conditions: {...4 conditions with passed/value/detail...}, summary}`. Never auto-enables — only reports.
- **Contract tests** (`tests/Contract/`) — verify the two-way sync contract:
  payload shape, idempotency key format, integration_log audit fields,
  conflict resolution labels. 21 assertions. Dedicated runner
  `tests/contract.php`. Added to CI workflow.
- **`NationalSyncOrchestrator` Stage 2 path** — after the inbound matching
  loop, if `sync_two_way_enabled='1'` AND `sync_direction='two_way'`,
  queries applicants matched during the cycle window and pushes their status
  changes to upstream via `OutboundSyncService::pushStatusChange()`. Cycle
  summary now includes `outbound_pushed`, `outbound_conflicts`,
  `outbound_skipped`, `two_way_enabled`.

### Stage 2 activation path (§29.1 + §29.3)

1. `Stage2ReadinessChecker::check()` reports `ready=true` (all 4 conditions
   pass — 3 months stable, false-positive < 0.1%, circuit-breaker < 5%,
   contract tests exist).
2. Admin flips `sync_direction='two_way'` + `sync_two_way_enabled='1'` via
   `SettingsController` (runtime, no deploy).
3. Next cron tick: orchestrator's `runCycle()` now pushes outbound.
4. If upstream returns 3 consecutive 5xx, `OutboundSyncService` auto-disables
   two-way → reverts to Stage 1 (kill-switch).

### Known debt (live, post-v1.1.0)

- 0 critical, 0 high, 4 medium (D-2-2, D-2-3, D-7-1, D-8-4 — admin UI for
  readiness report), 25 low (4 new: D-8-1 stub push, D-8-2 contract test
  execution, D-8-3 dedup set, D-8-4 readiness admin UI).
- See `docs/DEBT_REVIEW.md` for the full list + the v1.2 (Phase 9) plan.

---

## [1.0.0] — 2026-11-17

First stable release of mojavez-v3 — the unified successor to Mojavez-v2
and mojavez-gateway. Rollout Stage 1 (one-way inbound sync from the
national gateway) is live; Stage 2 (two-way) is deferred to v1.1+ pending
3-month stabilization (see `docs/00-MASTER.md §29`).

### Architecture
- Modular monolith: Core, NationalGateway, Api, Queue, Reporting modules.
  No Composer, no external runtime dependencies — pure PHP 8.2 + MariaDB 10.11.
- `ModuleInterface` contract (name, version, register, boot, routes,
  migrations, subscribers, ownedTables) + `ModuleLoader` discovery.
- `Container` with autowiring via ReflectionClass, memoized singletons,
  factory closures. Setter injection for cyclic deps.
- `MigrationRunner` with idempotent per-module SQL migrations + migrations
  meta-table (audit trail of when each module was introduced).
- `StructuredLogger` — JSON lines with `_module` context + `metric()`.
- `EventBus` + subscribers (NotificationSubscriber, AuditLogSubscriber).

### Domain (Phase 2)
- 18-status applicant workflow (Draft → ... → LicenseIssuance → ViewAndPrint).
- 4 household-business-only statuses (AddressSabtAhvalMatch, etc.).
- Applicant, Person, JobDetail, PropertyDetail, Document, Payment, License
  domain objects (readonly, immutable).
- `version` (optimistic concurrency), `deleted_at` (soft-delete),
  `consent_external_share` columns on applicants.

### National Gateway (Phase 3)
- `TrackScraper` — parses the upstream qr.mojavez.ir track page HTML.
- `MojavecClient` — HTTP client with retry + rate-limit + jitter.
- `LicenseService::fetchAndCacheByTrackNumber` + `refreshLookups`.
- `AntiDetectionMiddleware` — static headers + jitter.
- `CircuitBreaker` — open/half-open/closed states (protects upstream).
- Iran geofence: qr.mojavez.ir only reachable from inside Iran.

### Async + Golden Link (Phase 4)
- `integration_log` + `idempotency_key` + optimistic concurrency.
- `PatternMatcher` quorum: 9 fields (national_code masked pattern is the
  anchor), weighted scoring → MatchConfidence enum (AutoConfirmed ≥90 /
  OperatorFlag 50-89 / AutoRejected 0).
- `ApplicantSyncAdapter` — match + act + audit (sync_audit_log).
- `NationalSyncOrchestrator::runCycle` + `matchOne`.
- 6-field quorum (up from 3 in v2) — property_details province/township
  + business_address + postal_code now participate.

### Data Migration (Phase 5)
- `migrate_from_v2.php` — 17 tables, INSERT IGNORE, source-prefix strategy.
- `migrate_from_gateway.php` — 8 tables, raw_json parsing.
- `seed_lookups.php` — upstream fetch + --from-gateway fallback (geofence-aware).

### Security, Tests, Deployment (Phase 6)
- Centralized CSRF in `public/index.php` for all mutating requests (419).
- `IpRateLimiter` — dual mechanism (session + IP-in-DB), 7 route rules,
  `rate_limit_buckets` table, 429 + X-RateLimit headers.
- Security headers: CSP without `unsafe-eval` (v2 fixed), no external CDN
  (gateway fixed), X-Frame-Options, X-Robots-Tag noindex, COOP/CORP.
- Session: MOJAVEZSESSID, use_strict_mode, SameSite=Lax, secure auto (HTTPS).
- `static_analysis.py` — 185 files, 0 FAIL.
- `tests/smoke-test.sh` — 41 PASS + HTTP checks (with running server).
- `scripts/pattern_matcher.py` — 3/3 synthetic test cases PASS.
- `scripts/test_track_scraper.py` — fixture-based scraper regression.
- `deploy/` artifacts for all 6 targets: Docker (Dockerfile + 3-service
  compose), Apache vhost, Nginx server block, Linux systemd timers,
  Windows XAMPP, shared-hosting (install.php + check.php + .htaccess).
- `.github/workflows/ci.yml` — MariaDB 10.11 service, lint + static
  analysis + unit tests + smoke + pattern matcher on every push.
- 20 Persian technical docs in `docs/` (architecture, domain, security,
  API, database, national-gateway, deployment, migration, testing,
  golden-link, contributing).

### Finalization (Phase 7)
- UI unification: Bootstrap 5 RTL + Vazirmatn (self-hosted, no CDN) +
  day/night theme toggle (persisted in localStorage). All 14 views rebased.
- `database/schema.sql` — single source of truth (41 tables, 35 KB,
  idempotent, auto-generated by `scripts/generate_schema_sql.py`).
- `SmsService` — Kavenegar-compatible SMS gateway with log-fallback in
  non-production. `run_worker.php` CLI drains SMS + job queues.
- `UsersController` + `SettingsController` — admin CRUD for users +
  settings (including the `sync_direction` runtime flag for Rollout Stage 1/2).
- VERSION bumped to 1.0.0.

### Rollout Stage 1
- `sync_direction = 'inbound'` (default): scraper reads from upstream,
  only AutoConfirmed matches auto-transition applicant status,
  OperatorFlag matches require manual operator approval,
  **no writes are sent to upstream** (eliminates two-way drift risk).
- `sync_audit_log` records every match decision (action, outcome,
  confidence, context, reviewed_by, reviewed_at, review_decision).
- `sync_two_way_enabled = 0` (default): the runtime kill-switch for Stage 2.

### Predecessors archived
- `Mojavez-v2` and `mojavez-gateway` are now in maintenance-only mode.
  Their source lives in `docs/01-v2-reference.md` and `docs/02-gateway-reference.md`
  as historical/technical reference appendices. No further development.
- Production URLs will migrate from v2 to v3 (Rollout Stage 1 first,
  then full cutover once Stage 1 stabilizes).

### Known debt (live)
- 0 critical, 0 high, 5 medium (D-2-1 paid, D-2-7 paid, D-4-1 paid;
  D-2-2/D-2-3 partial — remaining admin controllers deferred to v1.1),
  21 low (sandbox limitations + edge cases). See `docs/DEBT_REVIEW.md`.

---

## [Unreleased]

### Added — Phase 9: User Panel (`/panel/*`)

- **`/panel/*` route surface implemented** — closes the 404 reported after
  login (AuthController::login() redirects to `/panel` since Phase 6, but no
  route existed to serve it). 10 routes registered:
  `GET /panel`, `GET/POST /panel/profile`, `POST /panel/avatar`,
  `GET /panel/requests`, `GET /panel/payments`, `GET /panel/licenses`,
  `GET /panel/notifications`, `POST /panel/notifications/read`,
  `GET /panel/tracking`. See `docs/PHASE_9_USER_PANEL.md`.
- **`UserPanelController`** with 10 actions, autowired via the Container. Reads
  from existing Phase-6 repositories (`UserAccountRepository`,
  `PaymentRepository`, `LicenseFileRepository`, `ServiceTypeRepository`,
  `StatusLogRepository`) — no schema changes needed.
- **`UserNotificationRepositoryInterface` + `PdoUserNotificationRepository`** —
  new contract for `user_notifications`: `findByUser`, `unreadCount`,
  `markAllRead`. Registered in the Core container.
- **`app/Views/user_layout.php`** — RTL sidebar layout (Bootstrap 5
  self-hosted + Vazirmatn), unread-notifications badge, 7-item nav.
- **Seven panel views** under `app/Views/user/`: `overview`, `requests`,
  `payments`, `licenses`, `notifications`, `tracking`, `profile`.
- **Unit test** `UserNotificationRepositoryTest` (9 assertions) — verifies
  newest-first ordering, limit, unread count, markAllRead scope, and account
  isolation. Total unit assertions: 382 → 391.

### Notes

- Inspired by the v2 surface (`mojavez.zip`, deployed on shared hosting) but
  re-implemented on v3's modular architecture — no v2 code copied.
- `POST /panel/profile` (updateProfile) and `POST /panel/avatar`
  (uploadAvatar) return stub 501 responses for now; full implementation
  needs an `AvatarService` + form validation and is tracked as DEBT_REVIEW
  D-9-1 (v1.3).
- All existing routes, unit tests (391), contract tests (21), and HTTP smoke
  tests (66 pass / 8 pre-existing D-8-5) remain green.

### Fixed — post-v1.1.0 hotfix (ISSUE-001)

- **`PdoRepository::all()` / `one()` / `scalar()` renamed to `fetchAll()` /
  `fetchOne()` / `fetchScalar()`.** The base class `PdoRepository` had a
  `protected all(string $sql, array $params = []): array` helper, but the
  subclass `PdoSettingRepository` also defines a domain method
  `public all(): array` (from `SettingRepositoryInterface`). Under PHP 8.5+
  this is a fatal signature-mismatch error, so the HTTP dev server (`php -S`)
  could not boot at all — `PHP Fatal error: Declaration of
  PdoSettingRepository::all(): array must be compatible with
  PdoRepository::all(string $sql, array $params = []): array`. The bug has been
  present since Phase 6 but was hidden because `smoke-test.sh` probed
  `/health` (which 404s) and silently fell back to `--no-http` mode when the
  server didn't respond — masking the boot failure. Renaming the base helpers
  (which are `protected`, internal only) removes the collision. All 57 call
  sites across 12 repository files updated. `PdoSettingRepository::all()` is
  now the only `all()` and recursively calls `$this->fetchAll(...)` instead of
  infinite self-recursion. See `docs/ISSUES.md` ISSUE-001.
- **`tests/smoke-test.sh` HTTP probe hardened.** Previously the probe hit
  `/health` (non-existent → 404) and silently fell back to `--no-http` when the
  server didn't return 200, hiding any boot-time fatal. The probe now hits `/`
  (home page, 200), treats ANY HTTP status code as "boot succeeded" (the
  server returned a real response, not a connection refusal), checks the
  server log for `Fatal error`, and **fails loudly** instead of silently
  skipping — a boot failure is now a hard regression, not a quiet skip.

### Added — Phase 1 (Core & Architecture)
- Repository scaffold: `README.md`, `LICENSE` (MIT), `.gitignore`,
  `.editorconfig`, `.gitattributes`, `VERSION` (`0.1.0`), `CHANGELOG.md`.
- Engineered documentation bundle ported into `docs/` (00-MASTER, 01-v2-ref,
  02-gateway-ref, 03-strategy, 04-comparison, SCHEMA_OWNERSHIP,
  CODING_CONVENTIONS, integration-contract, DEBT_REVIEW).
- `app/Modules/ModuleInterface` and `app/Modules/ModuleLoader` — the formal
  module contract (`name`, `version`, `register`, `boot`, `routes`,
  `migrations`, `subscribers`, `ownedTables`).
- `app/Shared/Support/StructuredLogger` — JSON line-by-line structured logs
  with `_module` context, levels, `metric()`, and `request_id`.
- `app/Shared/Support/Environment` — `.env` loader (no Composer).
- `app/Shared/Support/Config` — array-returning config files (no `define()`).
- `app/Shared/Support/Normalizer`, `PersianDate`, `Hash`, `Validator`,
  `CsvReader` (ported & extended from both predecessors).
- `app/Container` — manual DI with autowire + memoized singletons + factory
  closures + setter injection (for cyclic dependency resolution, fix 003-01).
- `app/Shared/Persistence/Connection` — MySQL PDO memoized singleton,
  `EMULATE_PREPARES=false`, `+00:00` timezone.
- `app/Shared/Persistence/MigrationRunner` — scans `app/Modules/*/migrations/`,
  uses `migrations` table with `checksum`, atomic per migration.
- `app/Http` layer — `Request`, `Response`, `Router` (first-match, `/?` regex),
  `Session`, `Csrf` (always on, fix S1), `Exceptions/*`.
- `public/index.php` front controller + security headers + IP blocklist +
  `/health`, `/check-cancel`, `/check-sync`.
- `public/.htaccess` three-layer (root / public / uploads).
- `database/schema.sql` reference + `database/install.sql` (idempotent,
  auto-generated from schema.sql).
- First migration `app/Modules/Core/migrations/20261117_001_create_migrations_table.sql`
  + `app/Modules/Core/Module.php`.
- Unit tests for core utilities (Container, Router, Normalizer, StructuredLogger).
- `.github/workflows/ci.yml` — GitHub Actions pipeline.

## [0.1.0] — Phase 1 release

Initial scaffold release. Phase 1 (Core & Architecture) complete: modular
kernel, DI container, migration runner, structured logger, HTTP layer, MySQL
connection, security headers, `/health` endpoint.
