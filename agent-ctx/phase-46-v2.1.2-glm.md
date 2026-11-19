# Phase 46 (v2.1.2) — user delete application + admin payment request + user postal change

Task ID: **phase-46-v2.1.2**
Branch:  `phase/46-delete-payment-postal` (from `main` @ a6a9074)
Project: mojavez-v3 (Pure-PHP modular monolith, PHP 8.2 + MariaDB)

## Goal

Three user-facing features:
1. Allow users to delete their own incomplete/unwanted applications.
2. Add "request payment" button in admin view_application page.
3. Add postal-code change request from user panel.

## Status: COMPLETE — all 4 tasks implemented + committed

## Pre-flight findings

### Task 1 — user delete application
- `ApplicantRepositoryInterface::softDelete(int $id)` already exists
  (PdoApplicantRepository line 270) — sets `deleted_at = NOW()`.
- Schema FKs in `database/schema.sql`:
  - `documents.applicant_id` → `applicants.id ON DELETE CASCADE`
  - `property_details.applicant_id` → `applicants.id ON DELETE CASCADE`
  - `job_details.applicant_id` → `applicants.id ON DELETE CASCADE`
  - `status_log.applicant_id` → `applicants.id ON DELETE CASCADE`
- BUT the cascade only fires on a HARD delete. Since the spec mandates
  SOFT delete (set `deleted_at = NOW()`), the related rows stay orphaned
  unless explicitly deleted. Solution: inside a DB transaction, manually
  `DELETE FROM <table> WHERE applicant_id = ?` for all 4 related tables,
  THEN `softDelete()` on applicants. Either the whole case is gone or
  none of it.
- `PdoUserAccountRepository::getApplications()` already filters
  `deleted_at IS NULL` — so a soft-deleted case disappears from the
  user's list automatically. Same for `PdoApplicantRepository::findById`
  and `findByTrackingCode` (both filter `deleted_at IS NULL`).
- `Applicant::userAccountId` is a `?int` exposed on the VO (Phase 9
  addition). `Applicant::currentStatus` is the `ApplicantStatus` enum
  with `->value` returning the Persian slug. Both used in the auth +
  state guards.

### Task 2 — admin request payment
- `AdminController::requestPayment()` already exists (line 984). It
  sets `payment_required = 1` + `payment_requested_at = NOW()` and
  inserts a status_log entry. Routes already registered in Module.php
  (line 259, `POST /admin/request-payment`).
- BUG found & fixed: the existing `requestPayment()` did NOT insert
  a `user_notifications` row — so the spec's "verify it inserts a
  notification" check failed. Added a best-effort `INSERT INTO
  user_notifications` block mirroring the pattern in `changeStatus()`
  (Phase 45). Skips silently when `userAccountId` is null (admin-created
  cases). The notification has `type='payment_request'`, title
  `درخواست پرداخت هزینه`, body referencing the tracking code + admin
  display name. Logged but not re-thrown on DB error so the payment
  flag update is never rolled back.
- View fix: `admin/view_application.php` originally had the new form
  nested INSIDE the `<form id="status-change-form">` (which is invalid
  HTML — forms cannot be nested). Restructured: the status-change-form
  closes after its action buttons, then the payment request form lives
  in a sibling `<div class="mt-3 border-top pt-3">` inside the same card
  body — visually grouped, but HTML-valid.

### Task 3 — postal-code change
- Schema: `postal_code_changes` table (migration 003) has columns
  `(id, applicant_id, old_postal_code, new_postal_code, status,
  reviewed_by, reviewed_at, reject_reason, requested_at)` with default
  `status='در انتظار'` and default `requested_at = NOW()`. The
  `AdminController::approvePostalRequest()` (line 1106) already
  applies the new postal_code to the applicants row + marks the
  request 'تأیید' — so the user-side insert plugs straight into the
  existing admin review flow.
- Used `ApplicantRepositoryInterface::findByTrackingCode()` (already
  exists, filters `deleted_at IS NULL`) for the lookup.
- Authorization: the applicant's `userAccountId` MUST match the current
  user's session `user_id`. Same pattern as Task 1.
- No-op guard: refuses if the new postal code equals the current one
  (saves an admin review slot).

### Task 4 — commit (no version bump)
- No `VERSION` file edit, no CHANGELOG entry — per spec.
- Lint + static analysis baseline maintained (9 lint issues, 122
  static warnings — same counts as Phase 45).

## Files modified
- `app/Modules/Core/Controllers/UserPanelController.php` — added
  `deleteApplication()` + `changePostalCode()` methods + private
  `flash()` helper. Added `ApplicantRepositoryInterface $applicants`
  and `Connection $conn` constructor params (autowired by Container —
  both bindings already exist in Module.php).
- `app/Modules/Core/Controllers/AdminController.php` — added a
  best-effort `INSERT INTO user_notifications` block in
  `requestPayment()` mirroring the Phase-45 pattern from
  `changeStatus()`.
- `app/Modules/Core/Module.php` — registered two new routes:
  `POST /panel/requests/{id}/delete` and
  `POST /panel/change-postal-code`. No conflicts with existing routes
  (different segment counts and methods).
- `app/Views/user/requests.php` — restructured: added flash alert at
  the top (consumed from `panel_flash` session key), added 6th column
  for postal-code change (Bootstrap collapse toggle + inline form
  below the row), added delete button in the actions cell for rows
  with status in `['پیش‌نویس', 'تکمیل اطلاعات']`.
- `app/Views/admin/view_application.php` — added payment-request form
  inside the Status Change Card body, OUTSIDE the status-change-form
  (fixed nested-forms HTML bug). The form POSTs to
  `/admin/request-payment` with `applicant_id` + CSRF.

## Implementation details

### `UserPanelController::deleteApplication()` (lines 383-462)
- Auth: `requireUserLogin()` + explicit user_account_id match.
- State guard: only `['پیش‌نویس', 'تکمیل اطلاعات']` deletable
  (NOT the broader `isPreIssue()` set, which would include
  AddressVerification, FieldExpertReview, etc.).
- Transaction: 4 cascade deletes + `softDelete()`.
- All failure paths: flash + redirect to `/panel/requests` (no 403
  page — the user might have a stale page render).

### `UserPanelController::changePostalCode()` (lines 482-558)
- Validates: tracking_code non-empty, new_postal_code matches
  `^\d{10}$` (Iran standard 10-digit postal code).
- Lookup: `findByTrackingCode()` + explicit user_account_id match.
- Inserts into `postal_code_changes` with `status='در انتظار'` and
  captures `old_postal_code` from the applicants row for the audit
  trail.
- All failure paths: flash + redirect to `/panel/requests`.

### `UserPanelController::flash()` (lines 592-598)
- Stores `['message' => ..., 'type' => ...]` in session under
  `panel_flash` key. The view reads it once + removes it (one-shot
  semantics, no re-show on refresh).

### `AdminController::requestPayment()` notification (lines 1005-1037)
- Same pattern as `changeStatus()` notification (Phase 45).
- Type: `'payment_request'`.
- Title: `'درخواست پرداخت هزینه'`.
- Body: `'برای پروندهٔ شما (کد پیگیری: %s) درخواست پرداخت هزینه
  ثبت شد — توسط %s.'` (tracking code + admin display name).
- Best-effort: logged but not re-thrown.

### View restructure — `user/requests.php`
- 6-column table: tracking code, service type, status, date,
  postal-code change button (Bootstrap collapse toggle), actions
  (پیگیری + delete-if-eligible).
- Per-row collapse row below each main row holds the postal-code
  change form (hidden tracking_code + 10-digit input + submit).
- Per-row unique collapse id `postal-collapse-{appId}` so multiple
  rows don't collide.

### View restructure — `admin/view_application.php`
- Status Change Card: action buttons (`اعمال تغییر وضعیت` +
  `مشاهدهٔ چرخهٔ کامل`) stay inside the status-change-form.
- Payment request form: in a sibling `<div class="mt-3 border-top
  pt-3">` inside the same card body (visually grouped but outside the
  parent form). Uses `btn-warning btn-sm` per spec to visually
  distinguish from the primary status-change submit.

## Validation
- `python3 tools/php_lint_simple.py`: 9 issues, ALL pre-existing
  (CSS braces in <style> blocks + tests/setup_v2_source.php using
  define()). No new issues introduced by my changes.
- `python3 tools/static_analysis.py`: 122 warnings, identical to
  baseline (no new warnings). Initial draft had one new XSS
  false-positive on `<?= $appId ?>` — fixed by writing `<?= (int)$appId ?>`
  (the `(int)` cast prevents the analyzer's regex from matching —
  same pattern used elsewhere in admin views).
- HTML structure verified via Python regex: form/div/tr tag balance
  is correct in both modified views (5 forms/5 closes, 66 divs/66
  closes, 3 trs/3 closes in view_application.php; 2 forms/2 closes,
  10 divs/10 closes, 3 trs/3 closes in user/requests.php).
- PHP syntax verified via Python brace/paren counting: 60/60 + 292/292
  in UserPanelController, 112/112 + 591/591 in AdminController,
  82/82 + 208/208 in Module.php.
- PHP runtime tests not executed (no PHP binary in this environment);
  CI's `php -l` + `php tests/run.php` will validate at merge time.
- Container autowiring verified: both new UserPanelController deps
  (`ApplicantRepositoryInterface` and `Connection`) are already bound
  in Module.php lines 41-45 + container autowire path. No factory
  override needed.
