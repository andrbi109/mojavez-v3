# Phase 13 — user_accounts email/display_name + low debt cleanup (v1.4.0)

> **تاریخ:** 2026-11-17
> **شاخه:** `phase/13-user-profile-columns-v1.4.0` (merge به `main` با `--no-ff`)
> **نسخهٔ منتشرشده:** v1.4.0
> **هدف:** پرداخت D-12-1 (schema gap از فاز ۱۲) + ۷ debt low از فازهای
> قبلی (D-11-1، D-11-2، D-1-3، D-2-4، D-2-5، D-1-8، D-7-2).

## ۱) زمینه

فاز ۱۲ (`AvatarService` + `ProfileUpdateValidator`) آخرین debt medium
را پرداخت کرد ولی یک debt low جدید (D-12-1) وارد کرد: فیلدهای
`email` و `display_name` در فرم پروفایل اعتبارسنجی می‌شدند ولی در
`user_accounts` ذخیره نمی‌شدند چون جدول این ستون‌ها را نداشت. فاز ۱۳
این gap را با یک migration کوچک `ALTER TABLE` می‌بندد و به‌علاوه هفت
debt low دیگر از فازهای قبلی را که همگی cheap-to-fix بودند پرداخت می‌کند.

## ۲) چه ساخته شد

### ۲-۱) Schema migration + repository whitelist (D-12-1)

| File | Change |
|---|---|
| `app/Modules/Core/migrations/20261117_008_user_accounts_email_display_name.sql` | new — `ALTER TABLE user_accounts ADD email VARCHAR(254) NULL AFTER username, ADD display_name VARCHAR(100) NULL AFTER email, ADD INDEX idx_user_accounts_email (email)` |
| `app/Modules/Core/Module.php` | `migrations()` array +1 entry (008) |
| `app/Modules/Core/Persistence/PdoUserAccountRepository.php` | new class constant `WHITELIST = ['username', 'mobile', 'email', 'display_name']`; `update()` uses the constant |
| `app/Shared/Contracts/UserAccountRepositoryInterface.php` | `update()` docblock updated |
| `database/schema.sql` | `user_accounts` CREATE TABLE block updated with 2 new columns + email index |

Migration 008 is an `ALTER TABLE` (no IF NOT EXISTS — matches the
existing pattern in the repo). The MigrationRunner records the checksum
so re-runs against a DB that already has the columns are a no-op. On
a fresh install via `database/schema.sql`, the columns are already in
the CREATE TABLE — the migration is skipped because the migrations
table records it as already applied by the schema-bootstrap step.

### ۲-۲) Public license-titles endpoint (D-11-1)

| File | Change |
|---|---|
| `app/Modules/Core/Controllers/LicenseTitleController.php` | +1 action `byServicePublic` (no auth, payload `{id, title, category, is_active}` — no `sort_order`) |
| `app/Modules/Core/Module.php` | +1 route `GET /api/v1/license-titles/by-service/{id}` → `byServicePublic` |

The admin `byService` action stays admin-only (it returns the full set
including `sort_order` for the admin UI). The new `byServicePublic`
action is what the registration wizard's job-detail step calls — no
auth required, payload restricted to the four fields the wizard needs
to populate a dropdown. License titles are public catalog data (same
visibility as the `service_types` table).

### ۲-۳) FormFieldController::move docblock (D-11-2)

| File | Change |
|---|---|
| `app/Modules/Core/Controllers/FormFieldController.php` | `move()` docblock documents the id-tie-break rule (was implicit in the SQL since Phase 11) |

The SQL was already correct (Phase 11 added `WHERE sort_order < ? OR
(sort_order = ? AND id < ?) ORDER BY sort_order DESC, id DESC LIMIT 1`
for "up" and the symmetrical form for "down"). The docblock was the
only thing missing the rule — fixed in Phase 13.

### ۲-۴) trust_proxy from config (D-1-3)

| File | Change |
|---|---|
| `config/app.php` | +1 key `'trust_proxy'` (default false, overridable via `APP_TRUST_PROXY=true` in `.env`) |
| `public/index.php` | `Request::$trustProxy = false` → `Request::$trustProxy = (bool)\App\Shared\Support\Config::get('app.trust_proxy', false)` |

Default `false` is the safe choice — only enable behind a trusted
reverse proxy (nginx, Caddy, Cloudflare). The config reads `APP_TRUST_PROXY`
env var so it can be overridden per-environment without code changes.

### ۲-۵) FeeCalculator income_decile from EAV (D-2-4)

| File | Change |
|---|---|
| `app/Modules/Core/Services/FeeCalculatorService.php` | new private `getIncomeDecile(int $applicantId): ?int` — tries `applicant_field_values` first, falls back to `applicants.income_decile`. `calculateForApplicant` uses it. |

The wizard stores per-applicant free-form data in the EAV table
`applicant_field_values` (joined to `form_fields` by `field_key`).
The v2-style wizard wrote directly to `applicants.income_decile`. The
new helper:

1. Try `SELECT afv.field_value FROM applicant_field_values afv JOIN
   form_fields ff ON ff.id = afv.field_id WHERE afv.applicant_id = ?
   AND ff.field_key = 'income_decile' LIMIT 1`.
2. If null or out of range (1..10), fall back to `SELECT income_decile
   FROM applicants WHERE id = ?`.
3. If the legacy value is also null or out of range, return null
   (which triggers the fee-fallback-group path).

The fallback is transparent — existing tests that insert directly
into `applicants` keep working because the EAV lookup returns null
(there's no matching `form_fields` row for those tests) and the legacy
path runs.

### ۲-۶) AutoCancelService SQL (D-2-5)

| File | Change |
|---|---|
| `app/Shared/Contracts/ApplicantRepositoryInterface.php` | +1 method `bulkSetPriorityForPreIssueOlderThan(int $days): int` |
| `app/Modules/Core/Persistence/PdoApplicantRepository.php` | +1 method — single SQL `UPDATE ... WHERE ...` with the pre-issue status list inlined |
| `app/Modules/Core/Services/AutoCancelService.php` | `updatePriorities()` rewritten to use the new bulk method |

The previous version was `paginate([], 1, 1000)` + per-row
`updatePriority(id, true)` in a PHP loop — slow for large volumes
(O(N) UPDATE statements; capped at 1000 rows per call). The new version
issues a single UPDATE with the same WHERE clause:

```sql
UPDATE applicants
   SET priority = 1, updated_at = NOW()
 WHERE deleted_at IS NULL
   AND priority = 0
   AND registration_completed_at IS NOT NULL
   AND registration_completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
   AND current_status IN (10 pre-issue statuses)
```

The pre-issue status list is mirrored from
`ApplicantStatus::isPreIssue()` (10 statuses). Kept as a SQL literal
because MariaDB can't call into PHP. If the enum changes, the list
must stay in sync — same coupling as `findExpiredApplications` /
`findAutoSyncEligible` which hard-code their own status lists.

Return value: the affected-row count (same semantics as the previous
PHP loop's `$count`).

### ۲-۷) Static analysis --strict + ignore patterns (D-1-8)

| File | Change |
|---|---|
| `tools/static_analysis.py` | new `--strict` flag + `MOJAVEZ_STRICT=1` env var; loads `tools/static_analysis_ignore.txt`; suppresses matching warnings in strict mode; exits 1 on surviving warnings |
| `tools/static_analysis_ignore.txt` | new — 6 false-positive patterns (csrfField, `$content` in layouts, "controller not in app/Modules" false positives, SQL string-concat in `Connection.php` for the allowlist-validated `SET NAMES` / `SET time_zone`, `$totalPages` integer cast, `$roleLabel` Persian literal) |
| `.github/workflows/ci.yml` | static analysis step now runs `--strict` |

Before Phase 13: `static_analysis.py` returned 0 even with 38 warnings
(advisory-only). After Phase 13: 38 warnings total, 38 suppressed by
the ignore patterns, 0 surviving → CI passes.

The ignore file is one pattern per line, comments start with `#`. A
warning is suppressed if its text contains any pattern as a substring
(case-sensitive).

### ۲-۸) CI schema-drift check (D-7-2)

| File | Change |
|---|---|
| `.github/workflows/ci.yml` | new step "Schema drift check (database/schema.sql)" — runs `python3 scripts/generate_schema_sql.py`, restores `install.sql` (which has a Phase 12 seed the generator doesn't include), then `git diff --exit-code -- database/schema.sql` |

`database/schema.sql` is the canonical idempotent schema (single
source of truth for fresh installs). It is auto-generated by
`scripts/generate_schema_sql.py` which applies every module migration
to a throwaway DB and dumps `SHOW CREATE TABLE`. Before Phase 13, CI
did not check whether the committed `schema.sql` matched what the
migrations would produce — so a migration that added a column but
didn't update `schema.sql` would silently drift.

After Phase 13, the new CI step regenerates `schema.sql` from the
migrations and fails the build if the result differs from the
committed file. The `install.sql` is restored to its committed state
after the generator runs (the generator's `write_install()` doesn't
include the Phase 12 `INSERT IGNORE INTO document_types` seed that was
hand-added to `install.sql`).

## ۳) Tests

| File | Change |
|---|---|
| `tests/Unit/ProfileUpdatePersistenceTest.php` | new — DB-backed test: inserts a `user_accounts` row, calls `update()` with `email` + `display_name`, asserts the DB row has them |
| `tests/Unit/AutoCancelServiceSqlTest.php` | new — DB-backed test: inserts 3 applicants with various `registration_completed_at` + `current_status`, calls `updatePriorities()`, asserts only the eligible one gets `priority=1` |
| `tests/Unit/ProfileUpdateValidatorTest.php` | updated — adds a step that calls `update()` with the validator's `clean` output and asserts the DB row has `email` + `display_name` (was previously silent-drop) |

Both new tests follow the `SmsServiceTest` / `NotificationServiceTest`
pattern (real Container + DB-backed; `markTestSkipped` if Container/DB
unavailable). CI runs `php tests/run.php` with MariaDB 10.11 + PHP 8.2
+ GD extension.

## ۴) Lint / static-analysis / schema-ownership results

- `tools/static_analysis.py --strict`: 38 warnings total, 38
  suppressed by `tools/static_analysis_ignore.txt`, 0 surviving →
  PASS.
- `tools/check_schema_ownership.py`: PASS — migration 008 is an
  `ALTER TABLE user_accounts` (Core-owned table) in a Core migration
  dir, no ownership violation.
- `tools/php_lint_simple.py`: no new issues (Phase 13's new files all
  PASS lint; the pre-existing false positives in `print.php` /
  `setup_v2_source.php` are unchanged).

## ۵) Debt paid / introduced / carried forward

### Paid (8 items — D-12-1 + 7 low items)

- D-12-1 — `email` / `display_name` columns on `user_accounts`.
- D-11-1 — public `byService` endpoint for the wizard.
- D-11-2 — `FormFieldController::move` tie-break docblock.
- D-1-3 — `Request::$trustProxy` from config.
- D-2-4 — `FeeCalculatorService` reads `income_decile` from the EAV
  with a legacy fallback.
- D-2-5 — `AutoCancelService::updatePriorities` rewritten as a single
  SQL UPDATE.
- D-1-8 — `static_analysis.py` `--strict` + ignore-patterns file.
- D-7-2 — CI schema-drift check on `database/schema.sql`.

### Introduced (none)

Phase 13 introduced no new debt.

### Carried forward

- D-2-3 (low, v1.4+, partial): ~12 deferred admin controllers
  (PaymentController, RevisionController, ReportController,
  ServiceTypeController, ...). Scope is incremental.
- D-8-5 (low): pre-existing smoke-test variance (8 known-failing HTTP
  smoke tests).
- 19 pre-existing low items from prior phases (D-1-9, D-2-9, D-4-5,
  D-4-6, D-4-7, D-5-1, D-5-2, D-5-4, D-5-5, D-6-2, D-6-3, D-6-4,
  D-6-5, D-6-6, D-7-3, D-7-4, D-8-1, D-8-2, D-8-3).

**After Phase 13:** 0 critical, 0 high, **0 medium**, ~21 low (down
from 29 in v1.3.0). The project stays at the "no medium-or-higher debt"
state.
