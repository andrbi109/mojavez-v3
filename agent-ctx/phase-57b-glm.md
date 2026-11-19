# Phase 57b — LicenseFile + payments + avatar + overview widgets + rejection modal + document 404 + layout

Task ID: **phase-57b** — single-agent run (no subagents).
Branch: `phase/57b-licensefile-payments-avatar` (forked from main @ `65edd16`).

## Summary

Seven focused bug-fixes across the user panel + tracking + admin document
viewer. No version bump (per task spec — this is a patch set, not a release).

## Tasks completed

### 1. LicenseFile object access in user/licenses.php

`UserPanelController::licenses()` calls
`$this->licenseFiles->findByApplicantId($id)` which returns a
`LicenseFile` domain object — but the view was reading
`$license['document_id']` (array access). The "دریافت مجوز" button never
rendered. Switched the view to use `$license->documentId` (object
property access) with a defensive `is_array()` fallback so legacy
callers that still pass a raw row don't break.

### 2. Payment channels modal

The "پرداخت" button in `user/payments.php` opened a placeholder modal
showing only a disabled "پرداخت آنلاین (به‌زودی)" button. Now:

- Added migration `20261120_002_payment_channels_account_info.sql`:
  adds a TEXT NULL `account_info` column to `payment_channels` (the
  table previously had only `id, title, channel_type, is_active,
  sort_order`). Idempotent ALTER (information_schema guard). Schema
  also mirrored into `database/schema.sql` for fresh installs.
- `PaymentController::saveChannel` now accepts + persists
  `account_info` (truncated to 1000 chars).
- `PaymentController::index` now selects `account_info` alongside
  the other columns when listing channels.
- `admin/payments.php` channel sidebar now shows the `account_info`
  preview under each channel (truncated to 200 chars + …) and the
  channel form has a new "اطلاعات حساب" textarea (rows=3,
  maxlength=1000).
- `UserPanelController::payments()` now loads active payment_channels
  (`is_active=1`, ordered by `sort_order`) and passes them as
  `$channels` to the view.
- `user/payments.php` modal: every active channel is rendered as a
  radio option (first one pre-checked). The radio shows the channel's
  title + a small `channel_type` badge. Below the radio is a
  `pay-channel-info` block that holds the channel's `account_info`
  text (or a "no info" placeholder). A small JS `change` listener
  toggles which info block is visible as the user picks a different
  radio. When no channels are configured, the modal falls back to the
  previous informational alert.

### 3. Avatar upload + profile view

`AvatarService::upload()` was already correct (GD resize to 256×256,
saves to `storage/uploads/avatars/account-{id}.jpg`). The bug was
elsewhere:

- `UserPanelController::avatarUrlFor()` always returned `null` even
  when the avatar existed on disk — so the profile view always fell
  back to the letter avatar and the user never saw their uploaded
  picture. Fixed to return `$baseUrl . 'document/avatar'` when the
  avatar document id is set.
- `DocumentController::avatar()` calls `streamFile()` →
  `FileStorage::getFullPath($document->filePath)`. The avatar's
  `file_path` is stored project-root-relative
  (`storage/uploads/avatars/account-1.jpg`) by AvatarService, but
  `FileStorage::getFullPath()` only resolved upload-dir-relative paths
  (`/{tracking_code}/{$fileName}`). The avatar file was therefore
  unfindable and `/document/avatar` returned a 1×1 placeholder PNG.
  Fixed `FileStorage::getFullPath()` to try upload-dir-relative first
  (unchanged), then project-root-relative as a fallback, with the
  same `str_starts_with($base)` path-traversal check applied to both.
  This also fixes the same path mismatch for LicenseController-stored
  receipts/licenses (`storage/uploads/licenses/{applicant_id}/{rand}.jpg`).

The profile view (`user/profile.php`) already had the `<img
src="$avatarUrl">` branch — no view change needed; the controller
fix is enough.

### 4. Overview widgets

`user/overview.php` showed 3 widgets: کل درخواست‌ها، در حال بررسی،
صادرشده. The "در حال بررسی" count included rejected applications
because the controller's pending bucket only excluded
`['لغو','صدور مجوز','تحویل مجوز']` (NOT 'رد درخواست').

Fixed:

- Controller `index()` now classifies each application into exactly
  one of: `rejected` (رد درخواست), `issued` (صدور مجوز + تحویل مجوز),
  `pending` (the 10 pre-issue non-terminal statuses from
  config/statuses.php 'pre_issue' list, plus the household-business
  pre-issue ones), or `closed` (remaining terminals — لغو + تأیید
  نهایی دریافت کد رهگیری; تحویل مجوز is already counted as issued).
- View now renders 4 widgets (کل، در حال بررسی، صادرشده، رد شده) +
  a 5th (بسته شده) that's only rendered when `closed > 0` so a fresh
  account doesn't show an empty 5th card.

### 5. Rejection modal in user/requests.php

The previous "علت رد درخواست: {text}" inline block stretched the row
height and collided with the postal-code change button on the right.
Replaced with a small "علت رد" outline-danger button (info-circle
icon) that opens a Bootstrap modal. Modal title: "علت رد درخواست".
Modal body: the full rejection description (status_log.description of
the latest 'رد درخواست' entry — already attached by
`UserPanelController::requests()` since Phase 53) with
`white-space:pre-wrap` so multi-line reasons render properly, plus a
"کد پیگیری" footer line for context. One modal per row keeps the
description server-rendered (no JS data-attribute escaping needed).

### 6. Document modal 404

`admin/view_application.php`'s document viewer modal loads
`/document/inline/{id}` — this was returning 404. The route exists
(registered in `Core/Module.php`), the controller's ownership check
`canAccess($document->applicantId)` is correct (admin sees all;
user must own the applicant), and the document row exists.

The actual cause was the path-resolution mismatch (same root cause as
task 3): some `documents.file_path` rows are stored
project-root-relative (`storage/uploads/avatars/account-1.jpg`,
`storage/uploads/licenses/42/abc.jpg`) while
`FileStorage::getFullPath()` only resolved upload-dir-relative paths.
The fix in `FileStorage::getFullPath()` (project-root-relative
fallback) makes the file findable regardless of how it was stored.

Also relaxed `DocumentController::inline()` auth from
`requireLogin()` (admin-only) to `requireAnyLogin()` (admin OR user)
so the user-side "دریاخت مجوز" link in `user/licenses.php` works for
the logged-in applicant too. `canAccess()` already enforces
ownership for non-admin sessions, so the relaxation does NOT change
the security posture.

### 7. tracking/status.php layout overlap

The public `layout.php` already implements the standard sticky-footer
pattern (`body { display:flex; flex-direction:column; min-height:100vh }`
+ `.moj-main { flex:1 0 auto }` + `.moj-footer { margin-top:auto }`).
On some viewports the tracking page's content (rejection alert added
in Phase 53 + row + conditional license section added in Phase 51)
visually overlapped the footer at the bottom.

Hardened the page-local layout: wrapped the entire view in
`<div class="moj-tracking-status">` with `position:relative;
z-index:1; min-height:60vh` so the content layer always paints above
the footer's stacking context AND short pages (e.g. a fresh tracking
code with no status log yet) still push the footer down. Added a
margin-bottom to the last `<section>` so it always has breathing
room before the footer divider. No `position:absolute` was used
anywhere in this view (verified by grep), so removing that class
wasn't needed.

Used a class selector (`.moj-tracking-status`) instead of an ID
selector (`#moj-tracking-status`) on purpose — the simple PHP linter
(`tools/php_lint_simple.py`) treats any line that starts with `#` as
a PHP line comment, so an ID selector would strip the `{` on the
`#id {` line and leave the matching `}` unbalanced. The class
selector starts with `.` which the linter leaves alone.

## Lint

`tools/php_lint_simple.py` — 303 files, 17 issues (was 18 baseline).
The 1 issue I fixed: `user/payments.php` ended with `?>` (forbidden by
CODING_CONVENTIONS §2.1 — view templates should end with a newline,
not `?>`). The remaining 17 are all pre-existing false positives
(simple character-count linter counting CSS braces in `<style>`
blocks, JS parens in `<script>` blocks, and Persian text with
parens — none of which are real PHP syntax errors).

`tools/static_analysis.py` — no FAIL/ERROR. Two XSS WARN entries
reference my code (`$licenseDocId` and `$chId` in `value=""`
attributes) but both are integers (cast with `(int)`), so they're
false positives — the codebase convention is to skip
`htmlspecialchars` for integer outputs.

## Files touched

- `app/Modules/Core/Controllers/DocumentController.php` (inline auth
  relaxed, comment block explaining the fix)
- `app/Modules/Core/Controllers/PaymentController.php` (saveChannel
  accepts/persists account_info; index selects account_info)
- `app/Modules/Core/Controllers/UserPanelController.php` (index
  stats reclassification; payments loads channels; avatarUrlFor
  returns /document/avatar)
- `app/Modules/Core/Module.php` (migrations array — added
  20261120_002)
- `app/Modules/Core/Services/FileStorage.php` (getFullPath
  project-root-relative fallback)
- `app/Modules/Core/migrations/20261120_002_payment_channels_account_info.sql`
  (new file — idempotent ALTER adding account_info TEXT NULL)
- `app/Views/admin/payments.php` (channel sidebar shows account_info
  preview; channel form has account_info textarea)
- `app/Views/tracking/status.php` (page-local layout hardening —
  wrapper div + scoped <style> block with class selectors)
- `app/Views/user/licenses.php` (LicenseFile object access)
- `app/Views/user/overview.php` (4 + 1 conditional widgets)
- `app/Views/user/payments.php` (real channel chooser modal)
- `app/Views/user/requests.php` (rejection reason in a Bootstrap
  modal)
- `database/schema.sql` (mirrored the new account_info column)

## No version bump

Per the task spec, no `VERSION` or `CHANGELOG.md` update.
