# Phase 50 — Tehran timezone + payment-request modal + payments registration + Jalali filters

Task ID: `phase-50-v2.1.6`
Branch: `phase/50-timezone-payment-dates` (off `main` @ `12c537c`)
Agent: `glm`
Version: `2.1.5` → `2.1.6`

## Summary

Five user-facing fixes that close UX gaps surfaced by the production
rollout, plus a new additive migration (no schema-break). Bundled as
SemVer patch `v2.1.6` on top of `v2.1.5`.

1. **Session timezone → +03:30 (Tehran)** — `config/database.php` and
   `Connection::bootSession()` switched from `+00:00` (UTC). The previous
   regex silently fell back to `+00:00` for bare offsets like `+03:30`,
   so the config change had no effect; fixed to accept both bare offsets
   and IANA names.
2. **Payment-request modal in `admin/view_application`** — the
   "درخواست پرداخت" button no longer POSTs directly to a JSON-returning
   endpoint. It opens a Bootstrap modal collecting `amount` (pre-filled
   from settings, editable, with Persian-digit preview) + `description`
   (free-form Persian note). The controller inserts a `payments` row and
   **redirects** back to `/admin/view/{id}` with a flash banner.
3. **`AdminController::requestPayment()` rewritten** — accepts
   `amount` + `description`, inserts a `payments` row with the new
   `status='در انتظار'` (distinct from `'در انتظار رسید'`), sets
   `purpose='هزینهٔ ثبت‌نام'`, links the `user_account_id`, keeps the
   legacy `payment_required` flag + status log + user notification, and
   redirects (not JSON).
4. **User payments page** (`user/payments.php`) extended — new
   "توضیحات" column, new "پرداخت" button for `'در انتظار'` rows (opens
   a placeholder modal; the online-payment flow is coming in a later
   phase), and a badge palette distinguishing `'در انتظار'` (warning)
   from `'در انتظار رسید'` (info).
5. **Admin payments page** (`admin/payments.php`) extended — new
   "درخواست‌شده" summary card, new `'در انتظار'` status filter option,
   new "تاریخ ثبت" column (Jalali `created_at`), new "توضیحات" column
   (admin-entered note), badge palette distinguishes the two pending
   statuses, and an inline "در انتظار پرداخت کاربر" badge for
   `'در انتظار'` rows (instead of the review button).

Plus: the date-range filter inputs on the admin payments page were
converted from Gregorian `<input type="date">` to Jalali text inputs
(`pattern="\d{4}/\d{1,2}/\d{1,2}"`). The controller validates the regex,
converts to Gregorian via `PersianDate::jalaliStringToGregorian`,
applies the bound Y-m-d range to the WHERE clause, and preserves the
original Jalali strings in the `filters` array so the inputs re-populate
with what the admin typed.

No other admin view has date filters (verified by grep on `type="date"`
+ `name=from`/`name=to` — only `admin/payments.php` and
`admin/fee_tariff/edit.php` use date inputs; the latter is for tariff
validity, not a filter).

## Architecture / decisions

- **New `'در انتظار'` status** — the existing `'در انتظار رسید'`
  status means "user has uploaded a receipt, awaiting admin review".
  The user task asked for `status='در انتظار'` for the new admin-requested
  payments. Read literally, this is a distinct status: "admin requested,
  awaiting user payment". This matches the workflow:
  (1) admin creates request → `'در انتظار'`,
  (2) user uploads receipt → `'در انتظار رسید'`,
  (3) admin approves → `'تأیید شده'`, or
  (4) admin rejects → `'رد شده'`.
  The PaymentController's status whitelist was extended (not replaced)
  to accept the new value alongside the existing ones.

- **Schema additive migration** — the `payments` table got a new
  `description VARCHAR(500) NULL DEFAULT NULL AFTER reference_code`
  column via migration
  `app/Modules/Core/migrations/20261118_001_payments_description_column.sql`.
  The schema.sql file (auto-generated source of truth for fresh installs)
  was hand-edited to match (the next regen via
  `scripts/generate_schema_sql.py` will produce the same output —
  `SET time_zone` was also updated there for consistency).

- **Timezone regex fix** — the previous regex
  `/^[A-Za-z_\/]+(\+|-)\d{2}:\d{2}$/` only matched strings like
  `UTC+00:00` (letter prefix + offset). Bare offsets like `+03:30` and
  bare IANA names like `Asia/Tehran` failed silently and fell back to
  `+00:00`. So the Phase 49 config default `'timezone' => '+00:00'`
  worked only because the fallback was also `+00:00`. Switching the
  config to `'+03:30'` would have had no effect without fixing the
  regex. The new regex `/^(?:[A-Za-z_\/]+|[+-]\d{2}:\d{2})$/` accepts
  both bare offsets and IANA names, and rejects malformed input (so
  the allowlist-style SQL `SET time_zone = '{$tz}'` stays safe —
  no injection vector since only the validated string reaches the
  exec).

- **Lazy-loaded `PersianDate` in `PaymentController`** — the
  controller's constructor signature is unchanged (no new dependency
  injection) so existing tests/callers don't break. A private
  `?PersianDate $persianDate = null` property is populated on first
  call to a new `persianDate()` helper method. The Container always
  returns the same instance for a stateless class, so the memoization
  is safe.

- **Flash message session keys** — `view_app_message` (text) and
  `view_app_message_kind` (`'success'|'danger'|'warning'|'info'`). The
  `viewApplication()` method reads + clears both after reading so the
  banner shows once. The view maps the kind to a Bootstrap alert class
  via a `match` expression (kept unescaped — same pattern as Phase 15/17's
  `$badge`; documented in `tools/static_analysis_ignore.txt` §16).

- **CSRF + nested-form problem** — the new payment-request modal lives
  OUTSIDE the existing `status-change-form` in `admin/view_application.php`
  (forms cannot be nested in HTML — the previous Phase 46 inline form
  was already outside; the new modal keeps that invariant). The modal
  has its own `<form>` with its own `<?= $csrfField ?>`.

- **Jalali input UX** — the inputs use `pattern="\d{4}/\d{1,2}/\d{1,2}"`
  + `placeholder="YYYY/MM/DD"` + `title="..."` (Persian hint). The
  browser's pattern validation fires on submit; the controller also
  re-validates server-side (defense in depth). The filter form is
  `method="get"`, so the URL bar shows `?from=1405/08/28&to=1405/08/30`
  which is sharable + bookmarkable.

## Files modified

| File | Change |
| --- | --- |
| `VERSION` | `2.1.5` → `2.1.6` |
| `README.md` | Header lines 3 + 7 updated to `2.1.6` / `v2.1.6` |
| `CHANGELOG.md` | New `## [2.1.6] — 2026-11-19` section (full notes + Files changed table + Backward compatibility subsection) |
| `config/database.php` | `'timezone' => '+00:00'` → `'+03:30'` + docblock updated |
| `app/Shared/Persistence/Connection.php` | `bootSession()` timezone regex fixed (now accepts both bare offsets like `+03:30` and IANA names like `Asia/Tehran`); fallback default `+00:00` → `+03:30`; docblock updated |
| `database/schema.sql` | `SET time_zone = '+00:00'` → `'+03:30'` (fresh-install session); added `description` column to `payments` table |
| `scripts/generate_schema_sql.py` | `SET time_zone = '+00:00'` → `'+03:30'` (so next regen stays consistent) |
| `app/Modules/Core/migrations/20261118_001_payments_description_column.sql` | NEW — adds `description VARCHAR(500) NULL DEFAULT NULL AFTER reference_code` to `payments` |
| `app/Modules/Core/Controllers/AdminController.php` | `requestPayment()` rewritten (accepts `amount` + `description` from POST, validates amount > 0, inserts `payments` row with `status='در انتظار'` + `purpose='هزینهٔ ثبت‌نام'` + `description` + linked `user_account_id`, keeps `payment_required=1` flag + status log + user notification, redirects to `/admin/view/{id}` with flash — NO JSON). `viewApplication()` now reads `view_app_message`/`view_app_message_kind` flash + suggested amount from `settings` (`registration_fee_amount` → `fee_default_amount` → 500000 fallback) and forwards them to the view. |
| `app/Views/admin/view_application.php` | New docblock entries for `$flashMessage`/`$flashKind`/`$suggestedAmount`. New flash banner at the top (Bootstrap alert, dismissible, kind-aware icon). The "درخواست پرداخت" button now opens a Bootstrap modal (applicant name read-only, editable amount with `pattern` + `inputmode="numeric"` + thousands-separator preview via JS, description textarea `maxlength=500`, submit+cancel buttons). JS at the bottom strips non-digits as the admin types, refreshes the Persian-digit preview hint, validates the amount > 0 on submit, and asks for confirmation before POSTing. |
| `app/Views/user/payments.php` | Rewritten. New "توضیحات" column shows `p.description`. New "پرداخت" button for `status='در انتظار'` rows (opens a placeholder modal saying the online-payment flow is coming soon; offers a disabled "پرداخت آنلاین (به‌زودی)" button + a Close button). Badge palette distinguishes `'در انتظار'` (warning) from `'در انتظار رسید'` (info) and translates both to Persian display labels. |
| `app/Modules/Core/Controllers/PaymentController.php` | New `STATUS_FILTERS` whitelist class constant (adds `'در انتظار'`). New private `?PersianDate $persianDate` property + `persianDate()` lazy-loader helper. New `use App\Shared\Support\PersianDate`. `index()` uses the new whitelist + captures the raw Jalali `from`/`to` strings (passes them to the view for input re-population) + parses them via the rewritten `parseDateRange()`. SQL now also fetches `p.description`. `csv()` uses the new whitelist + Jalali-aware `parseDateRange()` + new `description`/`created_at` columns in the CSV. `parseDateRange()` rewritten to accept Jalali `YYYY/MM/DD` (regex + `PersianDate::jalaliStringToGregorian` round-trip) and return Gregorian Y-m-d. `computeStats()` adds `requestedCount` + `requestedSum` for the new `'در انتظار'` status. Docblocks updated with Phase 50 notes. |
| `app/Views/admin/payments.php` | Rewritten. Summary cards restructured to 5 cards (added "درخواست پرداخت" with the new stats). Date filter inputs converted from `<input type="date">` (Gregorian) to Jalali text inputs (`pattern` + `placeholder` + `title` + `dir="ltr"`). Status filter dropdown gained `'در انتظار'` (label clarifies it's "در انتظار پرداخت (درخواست مدیر)"). Table gained "تاریخ ثبت" (Jalali formatted `created_at` via `PersianDate::format`) and "توضیحات" columns. Badge palette distinguishes `'در انتظار'` (warning) from `'در انتظار رسید'` (info). Row class highlights `'در انتظار'` rows with `table-warning`. Rows for `'در انتظار'` show an inline "در انتظار پرداخت کاربر" badge instead of the review button (the review button only makes sense for `'در انتظار رسید'` rows where the user has uploaded a receipt). The "today Jalali" hint is shown next to the filter buttons for reference. Empty-state row `colspan` bumped from 7 to 9 to match the new column count. |
| `tools/static_analysis_ignore.txt` | New §16 with two false-positive suppressions: `$flashKindClass` in `admin/view_application.php` (match expression against fixed Bootstrap alert-class literals) and `$rowClass` in `admin/payments.php` (match expression against fixed Bootstrap table-* class literals — same pattern as Phase 25's `$rowClass` in `bulk_preview`/`bulk_result`). |

## Verification

- `php -l` on all 5 modified PHP files: PASS (no syntax errors).
- `python3 tools/static_analysis.py --strict`: 302 files analyzed,
  135 warnings (135 suppressed by ignore list, **0 surviving**) → PASS.
- `python3 tools/check_schema_ownership.py`: new migration
  `20261118_001_payments_description_column.sql` PASSES (1 pre-existing
  unrelated ownership violation for `integration_log` remains).
- `php tests/run.php`: 439 assertions, 9 failures — **all 9 are
  pre-existing `MySQL server has gone away` infrastructure errors**
  (no MySQL running in this sandbox). No new failures introduced by
  these changes. The DB-independent tests (RouterTest, ValidatorTest,
  PatternMatcherTest, ContainerTest, NormalizerTest, HashTest,
  StructuredLoggerTest, LicenseTitleTest, FeeTariffTest, FormFieldTest,
  EventBusTest, JobQueueTest, SettingsControllerValidationTest,
  SseControllerTest, TrackScraperTest, etc.) all PASS.

## Backward compatibility

- The `payments` migration is additive (new nullable column with a
  NULL default) — no existing data is changed; the column is omitted
  for legacy rows.
- The new `'در انتظار'` status is only set by the new code path
  (`AdminController::requestPayment`) — existing rows keep their
  previous status. `PaymentController`'s status filter whitelist was
  extended (not replaced) to accept the new value; existing status
  filters still work the same.
- The timezone change is observable: any code that called `SELECT NOW()`
  or relied on a `DATETIME` column's session-tz interpretation will now
  see Tehran local time instead of UTC. This is the intended
  user-visible behavior (Persian users see Persian times in the admin
  panel); no code change is required elsewhere because the application
  already formats via `PersianDate::format()` which works on absolute
  timestamps.
- The Jalali date filter is backward-incompatible at the URL level
  (`?from=2026-11-19` no longer filters; `?from=1405/08/28` does). This
  is intentional — the admin-facing filter UI is the only entry point,
  and it now sends Jalali. Programmatic callers (e.g. CSV export links
  from the page) get Jalali too. The CSV export itself uses Gregorian
  for the `paid_at`/`reviewed_at` columns (matches the DB storage) —
  only the new `created_at` column is the raw DB datetime.
