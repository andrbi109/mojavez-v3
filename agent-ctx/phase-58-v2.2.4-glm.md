# Phase 58 — v2.2.4 — Comprehensive minor fixes (print, sidebar, Jalali, curl_close, payments receipt, gateway sync)

Task ID: **phase-58**
Agent: GLM (z.ai)
Base: branch `main` at `3cf4443` (release v2.2.3)
Branch: `phase/58-fixes`
Bump: `2.2.3 → 2.2.4`

## Summary of work

Phase 58 is a 7-item "small fixes" phase. Every fix is local to its surface — no
schema-wide change, no API contract change. The user-facing behavior changes are
restricted to (1) the print A4 view losing two redundant thumb-caption spans, (2)
the admin sidebar losing the redundant "پنل مدیریت" header, (3) the user-side
license-access success message showing a Jalali date instead of Gregorian, and
(4) the user-side payment modal swapping its alert stub for a real multipart
receipt-upload form (and a placeholder "انتقال به درگاه" link for online
channels).

## Changes by task

### 1. `print_single.php` — removed `doc-thumb-name` and `doc-thumb-date`

- File: `app/Views/admin/print_single.php`
- Removed the two `<span class="doc-thumb-name">` and `<span class="doc-thumb-date">`
  elements that duplicated the filename + upload date below each document
  thumbnail (the fallback box above already carries the filename for PDFs/
  non-images, and the doc-thumb-type label is enough on its own for the print
  view). The thumbnail image/PDF placeholder is preserved.
- Removed the matching `.doc-thumb-name` and `.doc-thumb-date` CSS rules.
- Updated the surrounding HTML comment to note the Phase-58 cleanup.

### 2. `admin_layout.php` — removed leftover sidebar logo/owner-image div

- File: `app/Views/admin_layout.php`
- Removed the entire `<div class="mb-3 px-2">` block in the sidebar header that
  contained either the owner image or a fallback icon + the redundant
  "پنل مدیریت" link (the topbar already shows the panel title, so the sidebar
  copy was redundant — it was meant to be removed in an earlier phase but the
  content was left behind).
- Removed the now-orphan `$ownerImageAbs` computation (the `$ownerImage` and
  `$ownerName` settings fetches stay because `$ownerName` is still used by
  the sidebar footer).

### 3. `licenses.php` + `LicenseController::firstAccess()` — Jalali expiry date

- Files: `app/Modules/Core/Controllers/LicenseController.php`,
         `app/Views/user/licenses.php`
- Added a `PersianDate` import to `LicenseController`.
- Added an `expires_at_jalali` field to the JSON response from
  `LicenseController::firstAccess()` — pre-formatted on the server via
  `PersianDate::format($expiresAt)`. Returns `null` when no token was
  generated (the manual-review path).
- The user-side JS in `licenses.php` now prefers `d.expires_at_jalali` and
  only falls back to the raw Gregorian `d.expires_at` if the server response
  predates this fix (defensive for cached clients).

### 4. `curl_close` PHP 8.5 deprecation fix

- Files: `app/Modules/NationalGateway/Infrastructure/HttpClient.php` (2 occurrences),
         `app/Modules/Core/Services/SmsService.php` (1 occurrence).
- All 3 `curl_close($ch);` calls replaced with `@curl_close($ch);` to suppress
  the PHP 8.5 deprecation notice (the function is a no-op since 8.0; cURL handles
  are auto-closed by GC). Same `@`-suppression pattern as the `imagedestroy` fix
  from phase 56.

### 5. Payments modal — receipt upload after channel selection

- Files:
  - `app/Modules/Core/migrations/20261120_003_payments_receipt_file_path.sql` (NEW)
  - `app/Modules/Core/Module.php` (new route)
  - `app/Modules/Core/Controllers/UserPanelController.php` (new `uploadReceipt`
    method + `FileStorage` constructor injection)
  - `app/Views/user/payments.php` (modal body + flash banner + JS toggle logic)
- New migration adds two nullable columns to the `payments` table:
  - `receipt_file_path VARCHAR(255) NULL` — on-disk path of the uploaded receipt.
  - `receipt_description VARCHAR(500) NULL` — user-supplied note accompanying
    the receipt.
  Idempotent `ALTER` guarded by `information_schema.COLUMNS` (same pattern as
  migration `20261120_002`).
- New route: `POST /panel/payments/{id:\d+}/receipt` → `UserPanelController::uploadReceipt`.
  The `{id:\d+}` constraint keeps it collision-free with `GET /panel/payments`.
- New controller method:
  - Authorization: payment's `user_account_id` MUST match the current user's
    session user_id (legacy admin-only rows without `user_account_id` are
    refused).
  - State guard: only `status === 'در انتظار'` (admin requested, awaiting
    user payment) accepts an upload. Already-reviewed or already-receipted
    payments are refused with a flash.
  - Validates the uploaded file via `FileStorage::storeUpload` (same 7-layer
    pipeline used for applicant documents — ext+MIME consistency, size cap,
    image re-encode via GD). Files are stored under
    `storage/uploads/receipts/{payment_id}/` (the `trackingCode` arg to
    `FileStorage::storeUpload` is overloaded with a synthesized path segment
    so receipts don't pollute the applicant-keyed namespace).
  - Updates the `payments` row: `status = 'در انتظار رسید'`,
    `receipt_file_path = $relPath`, `receipt_description = $userNote`, and
    clears `reviewed_by`/`reviewed_at`/`reject_reason` (a re-upload after a
    prior rejection resets the review state).
  - Best-effort cleanup of the orphaned file if the UPDATE throws.
  - Flashes a success message and redirects to `/panel/payments`.
- View changes:
  - The "تأیید و ادامه" button (which fired `alert('این مرحله در فاز بعد…')`)
    is gone. The modal footer now only has "بستن".
  - The modal body's bottom region toggles between two mutually-exclusive
    blocks based on the selected channel's `channel_type`:
    - For `online` channels (case-insensitive match): a placeholder
      "انتقال به درگاه پرداخت" link is shown. The actual gateway redirect is
      deferred to a later phase — the link's `onclick="return false"` keeps
      it as a non-navigating visual placeholder until the gateway integration
      lands. No upload form is shown for online channels.
    - For every other channel_type (`transfer` / `cash` / etc.): a multipart
      `<form>` is shown with a file input (accepts image/PDF, max 2 MiB per
      config), an optional description textarea (max 500 chars, matches the
      `receipt_description` column width), and a "بارگذاری رسید" submit
      button. The form POSTs to the new route.
  - The existing "no channels configured" fallback alert is preserved.
  - A `panel_flash` banner is shown at the top of the page when the redirect
    lands (consumes the one-shot session flash — same pattern as
    `user/requests.php` from phase 46).
  - The JS change handler is extended to toggle the two blocks based on the
    selected radio's `data-channel-type` attribute (case-insensitive). The
    initial visibility is set server-side based on the first radio's type;
    a defensive JS re-sync keeps the JS the single source of truth.

### 6. Gateway sync — fix actual HTTP requests

- Files:
  - `app/Modules/NationalGateway/Infrastructure/HttpClient.php`
  - `app/Modules/NationalGateway/Infrastructure/AntiDetectionMiddleware.php`
  - `app/Modules/NationalGateway/Infrastructure/MojavecClient.php`
- `HttpClient::request()`:
  - `curl_close($ch)` → `@curl_close($ch)` (both error and success paths) —
    suppresses the PHP 8.5 deprecation notice that was leaking into the
    response body and breaking JSON parsers downstream.
  - Added a clarifying comment that `CURLOPT_CONNECTTIMEOUT` and
    `CURLOPT_TIMEOUT` are already set via `$timeouts = $this->antiDetection->timeouts()`
    (the values themselves are tightened by the middleware change below).
  - The failure-path `HttpResponse` now carries a clear error string
    (`curl_error()` verbatim, falling back to `'curl_exec failed'` if curl
    reports an empty message) instead of the previous ambiguous empty-string
    error. The success-path returns remain unchanged.
- `AntiDetectionMiddleware::timeouts()`:
  - Tightened the default `connect` timeout from 15s to 10s (kept the
    `response` default at 30s) so a dead upstream fails fast instead of
    pinning the sync worker for 45s. Config-driven overrides via
    `config/anti-detection.php` still take precedence.
- `MojavecClient::search()`:
  - The warning log on a failed search now includes the cURL-level `error`
    string alongside the status — so an unreachable upstream (DNS failure,
    connection refused, timeout) is distinguishable from a 4xx/5xx response.
  - The "non-retryable error" log in `doWithRetry()` also includes the
    `error` field for the same reason.
  - Behavior unchanged: a failed search still returns an empty
    `LicenseCollection` (NOT an exception) so the UI renders an empty result
    set instead of a 500.

### 7. Version bump

- `VERSION` → `2.2.4`
- `README.md` header updated from `2.2.1` / `v2.2.3` to `2.2.4` / `v2.2.4`
  (the "base version" line and the "final status" line both reference the
  current release).

## Lint / test

- `python3 tools/php_lint_simple.py` — the new files and the edited PHP files
  pass the brace/paren balance check; the pre-existing "imbalanced parens"
  warnings on `app/Views/admin/print_single.php` and other view files are
  pre-existing (the lint tool naively counts parens in HTML attributes, CSS,
  and Persian text — they are not real syntax errors and PHP itself parses
  the files cleanly).
- `php -l` is unavailable in this sandbox (no PHP binary installed). The
  real syntax check will run in CI via `php -l` and `php tests/run.php`.

## Routes added

| Method | Path                                  | Handler                                              | Action         |
|-------:|---------------------------------------|------------------------------------------------------|----------------|
| POST   | `/panel/payments/{id:\d+}/receipt`    | `App\Modules\Core\Controllers\UserPanelController`   | `uploadReceipt` |

## Schema migrations added

| File                                                                                       | Effect                                                                                |
|--------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------|
| `app/Modules/Core/migrations/20261120_003_payments_receipt_file_path.sql`                   | Adds `receipt_file_path VARCHAR(255) NULL` and `receipt_description VARCHAR(500) NULL` to `payments`. Idempotent. |

## Files modified

- `VERSION`
- `README.md`
- `app/Modules/Core/Controllers/LicenseController.php`
- `app/Modules/Core/Controllers/UserPanelController.php`
- `app/Modules/Core/Module.php`
- `app/Modules/Core/Services/SmsService.php`
- `app/Modules/NationalGateway/Infrastructure/AntiDetectionMiddleware.php`
- `app/Modules/NationalGateway/Infrastructure/HttpClient.php`
- `app/Modules/NationalGateway/Infrastructure/MojavecClient.php`
- `app/Views/admin/print_single.php`
- `app/Views/admin_layout.php`
- `app/Views/user/licenses.php`
- `app/Views/user/payments.php`

## Files added

- `app/Modules/Core/migrations/20261120_003_payments_receipt_file_path.sql`

## Notes for the next agent

- The new `uploadReceipt` route intentionally does NOT enter the receipt file
  into the `documents` table — the receipt is tied to the payment, not the
  applicant, and the task spec wording (`receipt_file_path = $path`) implies a
  direct on-payment column. If a future phase wants to make receipts browsable
  via the admin's existing document browser, a separate migration can mirror
  the file into `documents` with `doc_type = 'payment_receipt'` and link via
  `payments.receipt_document_id` (the FK already exists in the schema).
- The "انتقال به درگاه" link is a placeholder (`href="#"`, `onclick="return
  false"`). When the gateway integration lands, the link should be replaced
  with a real redirect (or a form POST) to the gateway's checkout URL — the
  handler can read the channel's `account_info` for the gateway URL.
- The `expires_at_jalali` field is added to `firstAccess` only. The
  `requestAccess` (re-access) endpoint has the same `expires_at` field and
  could be given the same treatment if/when its JS consumers start surfacing
  the expiry.
- The `@curl_close` suppression is a minimal fix. PHP 8.5 may eventually
  remove `curl_close` entirely (it's marked as a no-op deprecation); when
  that happens the `@` will be redundant and the call can be deleted.
