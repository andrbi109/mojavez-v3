# Phase 53 (v2.1.9) — Rejection reason + Close terminal + Redirect changeStatus + Print-footer

Task ID: `phase-53-v2.1.9`
Branch: `phase/53-reject-close-print-json` (off `main` @ `fd5204b`)

## Goal

Four functional improvements + version bump:

1. **Rejection reason visible to the user** — when admin rejects an
   application (status = "رد درخواست"), the rejection description
   stored in `status_log.description` is shown to the user:
     - `tracking/status.php` — a red Bootstrap alert above the
       timeline ("علت رد درخواست: {description}"). The statusLog is
       ordered ASC by created_at, so we iterate and keep the LATEST
       rejected entry's description (a case may be rejected, re-opened,
       rejected again — the latest is what the user cares about).
     - `user/requests.php` — per-row rejection reason under the status
       badge, for applications whose `current_status = 'رد درخواست'`.
       The description is attached by UserPanelController::requests()
       via a single status_log query per rejected row.

2. **Close rejected / cancelled applications** — terminal statuses
   (رد درخواست / لغو / تحویل مجوز / تأیید نهایی دریافت کد رهگیری —
   `ApplicantStatus::isTerminal()`) block further status changes in
   `AdminController::changeStatus()` — even for admin. The check is
   done BEFORE the workflow-rule check and applies to all roles.
   Additionally, when a status transition lands on a terminal status,
   `closed_at = NOW()` is stamped on the applicants row (column added
   by migration `20261119_001_applicants_closed_at_column.sql`).
   The migration is registered in `Core/Module.php::migrations()` and
   `database/schema.sql` is updated to include the column for fresh
   installs. Best-effort: a closed_at write failure logs a warning but
   does NOT roll back the status change.

3. **changeStatus redirect instead of JSON** — `AdminController::
   changeStatus()` previously returned `{"success":true,...}` which
   the admin browser rendered as raw text. The new flow:
     - AJAX (X-Requested-With: XMLHttpRequest) — still JSON (preserves
       the contract for any JS clients).
     - Form POST — flash message + 302 redirect to `/admin/view/{id}`.
       Success: "وضعیت پرونده با موفقیت تغییر یافت" (kind=success).
       Error: same flash-message channel with kind=danger.
   To support branching on AJAX-ness BEFORE responding, the base
   `Controller` got a new `statusTransitionError()` helper that
   returns a string error (or null) instead of exiting with JSON like
   `validateStatusTransition()`. The legacy method delegates to the
   new helper (back-compat for any other callers).

4. **print-footer fixed to bottom** — `print.php` is a self-contained
   printable A4 HTML document. The footer now:
     - In screen view: `body { min-height: 100vh; display: flex;
       flex-direction: column; }` + `.print-container { flex: 1 0 auto;
       display: flex; flex-direction: column; }` +
       `.print-footer { margin-top: auto; }` — pushes the footer to
       the bottom of the A4 sheet (filling the 297mm min-height) on
       short content (e.g. a draft with no status history).
     - In print view: `@media print { .print-footer { position:
       fixed; bottom: 0; left: 0; right: 0; margin-top: 0; } }` —
       anchors the footer to the bottom of every printed page.

5. **Version bump** — `VERSION` and `README.md` to `2.1.9`.

6. **Commit + push** to `origin/phase/53-reject-close-print-json`.

## Files Touched

- `app/Views/tracking/status.php`
  - New top-of-page block: iterate `$statusLog`, find the LATEST entry
    with `statusName === 'رد درخواست'`, capture its `description` if
    non-empty. Render a red Bootstrap alert (`alert-danger`) above
    the row/timeline section with the text "علت رد درخواست: {description}".

- `app/Modules/Core/Controllers/UserPanelController.php`
  - `requests()`: after fetching `$applications`, for each rejected
    row (`current_status === 'رد درخواست'`), attach
    `rejection_description` from the latest `status_log` row with
    `status_name = 'رد درخواست'` (single query per rejected row,
    via the injected `Connection $conn`). Other rows get an empty
    string so the view can use a simple truthy check.

- `app/Views/user/requests.php`
  - Inside the per-row foreach, read `$a['rejection_description']`
    (empty for non-rejected rows). If the row is rejected AND the
    description is non-empty, render a small red note under the status
    badge: "علت رد درخواست: {description}".

- `app/Modules/Core/Controllers/AdminController.php`
  - `changeStatus()`: three behavioural changes:
    1. Block status change when `$applicant->currentStatus->isTerminal()`
       (returns true for رد درخواست / لغو / تحویل مجوز / تأیید نهایی
       دریافت کد رهگیری). Error: "این پرونده بسته شده است." Applies
       to ALL roles (including admin) — checked BEFORE the workflow
       rules.
    2. After the status change, if the new status is terminal, stamp
       `closed_at = NOW()` on the applicants row (best-effort — wraps
       the UPDATE in try-catch and logs a warning on failure).
    3. Branch on `$isAjax = strtolower($request->header('X-Requested-With',
       '')) === 'xmlhttprequest'` — AJAX requests still get JSON;
       form POSTs get a flash message + 302 redirect to
       `/admin/view/{id}` (or `/admin/applications` if we don't have a
       non-zero id). The `$respondError` closure handles both flows.
  - The existing `validateStatusTransition()` call was replaced by
    `statusTransitionError()` (see Controller.php below) so we can
    branch on AJAX-ness before responding.

- `app/Modules/Core/Controllers/Controller.php`
  - New `statusTransitionError(ApplicantStatus $current,
    ApplicantStatus $new, string $role): ?string` — same rules as
    `validateStatusTransition()` but returns the error string
    instead of exiting with a JSON 422. Lets callers branch on
    request type before deciding JSON vs flash-redirect.
  - `validateStatusTransition()` now delegates to
    `statusTransitionError()` and calls `failTransition()` on non-null
    (preserves back-compat — existing callers see no change).

- `app/Modules/Core/migrations/20261119_001_applicants_closed_at_column.sql`
  - NEW migration. Adds `closed_at DATETIME NULL DEFAULT NULL` to
    `applicants` (after `registration_completed_at`). Idempotent
    ALTER (information_schema guard) so re-running is safe.
    Registered in `Core/Module.php::migrations()`.

- `app/Modules/Core/Module.php`
  - `migrations()`: register two new entries:
    `20261118_001_payments_description_column.sql` (Phase 50 — file
    existed but was never registered; this Phase 53 commit fixes
    that by adding it to the list) and
    `20261119_001_applicants_closed_at_column.sql` (this phase).

- `database/schema.sql`
  - `applicants` table: add `closed_at datetime DEFAULT NULL` after
    `registration_completed_at` (mirrors the migration for fresh
    installs).

- `app/Views/print.php`
  - CSS in three places:
    1. `body { min-height: 100vh; display: flex; flex-direction:
       column; }` — sticky-footer foundation for screen view.
    2. `.print-container { flex: 1 0 auto; display: flex;
       flex-direction: column; }` — fills the 100vh body and becomes
       a flex column itself so the footer's `margin-top:auto` pushes
       to the bottom of the A4 sheet.
    3. `.print-footer { margin-top: auto; }` (was `margin-top: 1.5rem`)
       — pushes to bottom of print-container on screen.
    4. `@media print { .print-footer { position: fixed; bottom: 0;
       left: 0; right: 0; margin-top: 0; } }` — anchors to bottom of
       every printed page.

- `VERSION` — `2.1.8` → `2.1.9`.
- `README.md` — `2.1.8` → `2.1.9` in two places (base version line +
  "وضعیت نهایی" announcement line).

## Notes

- The Phase 50 migration file `20261118_001_payments_description_column.sql`
  existed in the filesystem but was never registered in
  `Core/Module.php::migrations()`, so it was never actually applied
  by the migration runner. This Phase 53 commit fixes that latent bug
  by adding it to the list (it runs BEFORE my new
  20261119_001_applicants_closed_at_column.sql migration, in
  chronological order).

- The closed_at stamp is best-effort: if the migration hasn't been
  applied yet (e.g. on a fresh deploy before the boot-time migration
  run), the UPDATE wraps in try-catch and logs a warning. The status
  change itself is already committed — the case is still closed by
  its `current_status` (the terminal check blocks any further
  changes). `closed_at` is just the timestamp marker.

- The base `Controller::validateStatusTransition()` method is kept
  (not deleted) for back-compat. It still exits with a JSON 422 on
  failure — only `AdminController::changeStatus()` switched to the
  non-exiting `statusTransitionError()` helper.

- The terminal check in `changeStatus()` is BEFORE the
  workflow-rule check (`statusTransitionError()`). Order matters:
  the workflow check has its own terminal check (line 127 of the
  original `Controller.php`) but that check is bypassed for admin
  (`if ($role === 'admin') return null;` at the top). The new
  `changeStatus()` check is role-independent, so it covers admin too.

- `database/install.sql` was NOT updated because it's a v1-era
  install script that the v3 migration runner supersedes; the
  migration runner applies the migration automatically on first
  boot.

## Validation

- `php -l` on all modified PHP files: no syntax errors.
- `php -l` on all modified view files: no syntax errors.
- The changeStatus flow: form POST → flash + redirect; AJAX → JSON.
  Both paths exercise the same terminal check, workflow check,
  status change, closed_at stamp, and user notification.
