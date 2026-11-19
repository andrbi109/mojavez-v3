# Phase 45 (v2.1.1) — v2-style document upload + Jalali dates + status notifications

Task ID: **phase-45-v2.1.1**
Branch:  `phase/45-docs-dates-notifications` (from `main` @ a6a9074)
Project: mojavez-v3 (Pure-PHP modular monolith, PHP 8.2 + MariaDB)

## Goal

Three fixes in one phase:
1. Fix incomplete document upload in registration step4 (port v2 upload boxes).
2. Convert all dates in views to Jalali (Persian) format.
3. Send notification to user when admin/system changes their application status.

## Status: COMPLETE — all 3 tasks implemented + committed

## Pre-flight findings

### Task 1 — step4 document upload
- `app/Views/registration/step4.php` already had v2-style upload boxes
  (per Phase 41 v2.0.7 comment). All 6 specific named file inputs were
  present: `national_card_front`, `national_card_back`, `birth_cert`,
  `ownership_doc`, `vocational_cert`, `education_degree`,
  `product_photos[]`, plus the 3 `optional_N` boxes.
- `RegistrationController::handleDocumentUploads()` already iterated
  the `DOC_FIELD_MAP` constant with these specific field names — no
  controller change was needed for the actual upload handling.
- **BUG found & fixed:** `$on = static function (string $key): bool { return $sysActive[$key] ?? true; };`
  — static closures do NOT capture outer-scope variables (only arrow
  functions do). So `$on('foo')` returned `true` regardless of
  `$sysActive`'s actual contents (with a PHP warning about accessing
  array offset on null). Optional docs were always shown even when
  the admin disabled them. Fixed: replaced with an arrow function
  `fn (string $key): bool => $sysActive[$key] ?? true` that auto-captures
  `$sysActive` by value.

### Task 2 — Jalali dates
- `PersianDate` already had instance methods `formatGregorianDateTime()`
  and `formatGregorianDateTimeFull()`. Admin views that inject
  `$persianDate` already used them via local closures
  (`view_application.php`, `print_single.php`).
- Added a static helper `PersianDate::format(string $greg): string`
  so views without an injected `$persianDate` instance can format
  dates too. The helper returns `—` for empty/zero dates and omits
  the time component if the input has no time portion.
- Updated 8 views (admin/applications, print, tracking/status,
  user/overview, user/requests, user/payments, user/licenses,
  user/notifications) to call `PersianDate::format()` directly via
  `use App\Shared\Support\PersianDate;` imports.
- Refactored `$fmtDate`/`$fmtDateTime` closures in
  `admin/view_application.php` and `admin/print_single.php` to fall
  back to the static `PersianDate::format()` when no instance is
  injected (better than the old raw-Gregorian fallback).
- `admin/dashboard.php` has no date displays (only stat counts).
- `admin/edit_application.php` doesn't render `_at` columns — only
  uses PersianDate for the birth_date Jalali selects (already done).

### Task 3 — Status change notification
- Schema: `user_notifications` table has columns
  `(id, user_account_id NULLABLE, applicant_id NULLABLE, type, title,
   body, is_read, created_at)` (migration 014 made user_account_id
  nullable). The INSERT pattern in NotificationService::createNotification
  was used as the reference.
- `AdminController::changeStatus()`: after the status change, reads
  `$applicant->userAccountId` (already loaded) and inserts a
  `user_notifications` row with `type='status_change'`,
  `title='تغییر وضعیت پرونده'`, body built from the new status's
  Persian title + the admin's description. Best-effort: a DB error
  is logged but doesn't roll back the status change.
- `AutoCancelService`: added an optional 4th constructor arg
  `?Connection $conn = null`. Both `cancelExpired()` and
  `autoProgress()` now call a private `notifyStatusChange()` helper
  that performs the same INSERT pattern. The optional Connection
  makes the change backwards-compatible with existing tests
  (AutoCancelServiceSqlTest still constructs via Container's
  explicit factory in Module.php, which now passes the Connection).
- Updated `Module.php` factory for AutoCancelService to pass
  `$container->make(Connection::class)` as the 4th arg.

## Files modified
- `app/Shared/Support/PersianDate.php` — added `format()` static method
- `app/Views/registration/step4.php` — fixed `$on` closure bug (arrow fn)
- `app/Views/admin/applications.php` — Jalali `created_at`
- `app/Views/admin/view_application.php` — `$fmtDate` fallback uses static
- `app/Views/admin/print_single.php` — `$fmtDate`/`$fmtDateTime` fallback
- `app/Views/print.php` — Jalali status log + print date
- `app/Views/tracking/status.php` — Jalali status log
- `app/Views/user/overview.php` — Jalali timeline / notif / payment
- `app/Views/user/requests.php` — Jalali `created_at`
- `app/Views/user/payments.php` — Jalali `created_at`
- `app/Views/user/licenses.php` — Jalali `license_valid_until`
- `app/Views/user/notifications.php` — Jalali `created_at`
- `app/Modules/Core/Controllers/AdminController.php` — notify on changeStatus
- `app/Modules/Core/Services/AutoCancelService.php` — notify on cancel/autoProgress
- `app/Modules/Core/Module.php` — pass Connection to AutoCancelService factory

## Validation
- `python3 tools/php_lint_simple.py`: 9 issues, ALL pre-existing (CSS
  braces in <style> blocks within PHP views + tests/setup_v2_source.php
  using define()). My changes added 2 balanced `{`/`}` to print_single.php
  (an if/else fallback block) — no new imbalance.
- `python3 tools/static_analysis.py`: 122 warnings, identical count
  to baseline (no new warnings introduced). All warnings are
  pre-existing XSS-heuristic false positives (integer counters, IDs
  from internal sources) — none in my added code.
- PHP runtime tests not executed (no PHP binary in this environment);
  CI's `php -l` + `php tests/run.php` will validate at merge time.
