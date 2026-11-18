# Phase 55 (v2.2.1) — License upload on صدور مجوز + disable sections on terminal status

Task ID: `phase-55-v2.2.1`
Branch: `phase/55-license-upload-close` (off `main` @ `fd5204b`)
Previous agent record: `phase-54-v2.2.0-glm.md` (same `main` commit base)

## Goal

Two coordinated changes to the admin's applicant case view + one
version bump:

1. **License upload appears when status = "صدور مجوز"** — a new
   "بارگذاری تصویر مجوز" card is rendered right after the status-change
   card. The card POSTs to the existing `/admin/license/upload`
   endpoint (`LicenseController::upload`) with `applicant_id` + a file
   input named `license_image` (the controller's existing
   `$request->file('license_image')` call). The card is shown:
   - server-side when the applicant's current_status is already
     "صدور مجوز" (so the admin can immediately upload the license
     image);
   - client-side (via JS on the `#new_status` dropdown's `change`
     event) when the admin picks "صدور مجوز" — this is a preview
     before submitting the status change.
   When the current_status is terminal (رد/لغو/تحویل مجوز/تأیید نهایی
   دریافت کد رهگیری) the card stays hidden because the entire page
   is locked down.

2. **Disable / dim sections based on status** — three states:
   - **Terminal** → the entire page is locked. A "پرونده بسته شده است"
     banner appears at the top. The status-change form (select +
     description input + change-status button + payment modal trigger)
     is server-side disabled (HTML `disabled` attributes). The
     license-upload card is hidden. The "everything else" wrapper
     (License State card + applicant info + documents + timeline) gets
     the `moj-sections-disabled` CSS class which dims it (opacity
     0.55, pointer-events: none, grayscale 35%).
   - **"صدور مجوز"** → only the status-change card + the
     license-upload card are active. The "everything else" wrapper
     gets the `moj-sections-disabled` class. The status-change form
     stays enabled so the admin can advance the case further (e.g. to
     "تحویل مجوز") once the license image is uploaded.
   - **Otherwise** → everything is active (no banner, no disabled
     attrs, no dimmed wrapper).

3. **Version bump** — `VERSION` → `2.2.1`, `README.md` header → 2.2.1.

## Files changed

### Code (PHP)

| File | Change |
|---|---|
| `app/Modules/Core/Controllers/LicenseController.php` | `upload()` (POST `/admin/license/upload`) now redirects back to `/admin/view/{applicant_id}` with a one-shot flash banner on the session (keys `view_app_message` + `view_app_message_kind`) instead of returning JSON. Mirrors the Phase 50 (v2.1.6) `requestPayment()` PRG pattern. The success flash reports "تصویر مجوز با موفقیت بارگذاری شد." plus the count of auto-approved first-access requests (if any). Errors use `kind=danger`. The flash is read + cleared by `AdminController::viewApplication` (Phase 50 wiring already in place), so the existing flash banner at the top of `view_application.php` picks the upload result up with no new view-side plumbing. |
| `app/Modules/Core/Controllers/AdminController.php` | (a) `LicenseFileRepositoryInterface` added as a constructor dependency (autowired via `app/Modules/Core/Module.php` line 113 — already bound to `PdoLicenseFileRepository`). (b) `viewApplication()` now also loads the existing license_files row for the applicant (`$this->licenseFiles->findByApplicantId($id)`) + the backing Document (`$this->documents->findById($licenseFile->documentId)`) and passes them to the view as `$licenseFile` and `$licenseDoc`. Wrapped in a try/catch with a structured-log warning so a transient DB error doesn't crash the whole view. |

### Views (PHP/HTML)

| File | Change |
|---|---|
| `app/Views/admin/view_application.php` | (1) Docblock + var-defaults updated to declare `$licenseFile` + `$licenseDoc` and import `LicenseFile` + `ApplicantStatus`. (2) Two new view-level flags computed at the top: `$isTerminal = $currentStatus->isTerminal()` and `$isLicenseIssuance = $currentStatus === ApplicantStatus::LicenseIssuance`. (3) A "پرونده بسته شده است" warning banner is rendered server-side after the flash banner when `$isTerminal`. (4) The Status Change Card's inputs (`#new_status` select, `#status_description` input, `#btn-change-status` button, and the "درخواست پرداخت" modal trigger) get a `disabled` attribute server-side when `$isTerminal`. The card itself gets the `moj-card-locked` class for a small visual hint. (5) A new "بارگذاری تصویر مجوز" card is inserted between the Status Change Card and the License State card. The card has `id="license-upload-card"` and `style="display:none"` unless `$isLicenseIssuance` (server-side render with the card visible). When `$licenseFile` + `$licenseDoc` are non-null, the card shows the already-uploaded license thumbnail (image / PDF icon) using the same `data-bs-toggle="modal" data-bs-target="#docViewerModal"` pattern as the existing documents section. The upload form POSTs to `/admin/license/upload` with `applicant_id` + a file input named `license_image` (`accept="image/jpeg,image/png,application/pdf"`). The submit button text becomes "بارگذاری مجدد تصویر مجوز" when a license is already uploaded. (6) The "everything else" sections (License State card + the big `<div class="row g-4">` with applicant info + documents + timeline) are wrapped in a new `<div id="moj-applicant-detail-sections">`. When `$isTerminal` OR `$isLicenseIssuance`, the wrapper gets the `moj-sections-disabled` class. The closing `</div>` for the wrapper is right before the docViewerModal comment. (7) A `<style>` block is added before the `<script>` tag defining `.moj-sections-disabled` (opacity 0.55 + pointer-events: none + grayscale 35% + disabled cursor on buttons/links) and `.moj-card-locked` (muted header + disabled cursor on form controls). (8) The existing `#new_status` change handler is extended to also toggle the license-upload card's visibility based on the selected option's value — shown when value is the Persian string `'صدور مجوز'` (matches `ApplicantStatus::LicenseIssuance->value`), hidden otherwise. The handler also accounts for the empty placeholder option (the very first `— انتخاب کنید —` option). |

### Version

| File | Change |
|---|---|
| `VERSION` | `2.2.0` → `2.2.1` |
| `README.md` | Header line 3 (`نسخهٔ پایه: 2.2.0` → `2.2.1`) + line 7 (status banner → `v2.2.1 منتشر شد`) |

## Why each change

### 1. LicenseController::upload() — JSON → redirect

Before Phase 55, `upload()` returned JSON via `$this->jsonSuccess(...)`
on success or `$this->jsonError(...)` on failure. The Phase 54 (v2.2.0)
agent already documented this pattern as a UX bug for any endpoint that
is reached from a normal browser form POST (the browser renders the
JSON response as raw text). Phase 50 (v2.1.6) fixed the parallel
`requestPayment` endpoint by switching to a PRG redirect with a flash
banner. Phase 55 applies the same fix to `upload()` because the new
license-upload form in `view_application.php` is a normal file-upload
form (no JS to handle JSON). The success flash uses the same session
keys (`view_app_message` + `view_app_message_kind`) that the existing
flash banner in the view already reads + clears, so the upload result
shows up automatically with no new view-side plumbing.

A small inline closure (`$flash`) is used to set the flash + redirect
in one line at every error site, keeping the validation ladder compact.
All error paths use `kind=danger` (Bootstrap red alert); the success
path uses `kind=success` (Bootstrap green alert).

### 2. AdminController — load existing license file + document

Before Phase 55, `viewApplication` did not load the license_files row
for the applicant. The view had no way to know whether a license image
had already been uploaded, so it couldn't render a thumbnail. Phase 55
adds the lookup using the existing `LicenseFileRepositoryInterface` +
`DocumentRepositoryInterface` (the latter was already injected as
`$this->documents`). The lookup is wrapped in a try/catch + structured
warning so a transient DB error doesn't crash the view — the card
simply renders without the thumbnail (the admin can still upload a new
file via the form below).

The `LicenseFileRepositoryInterface` was already bound in the
container (`app/Modules/Core/Module.php` line 113 →
`PdoLicenseFileRepository`), so adding it as a constructor parameter
on `AdminController` is just autowired by the existing DI container.
Verified end-to-end by booting the container + asking for an
`AdminController` instance (script in the work record's "Verification"
section below).

### 3. View — terminal banner + license upload card + disabled sections

The terminal banner + the disabled-form attributes are server-side
(so the page is read-only even before JS runs — the JS-only
`moj-sections-disabled` class is a UX nicety, not a security
boundary). The server-side `disabled` attributes also prevent form
submission via keyboard (Enter in a focused-but-disabled input is
swallowed by the browser). The `changeStatus()` controller still
enforces terminal-status rejection (Phase 53, v2.1.9) — this is the
belt to the view's suspenders.

The license-upload card is hidden by default (`style="display:none"`)
and made visible by:
- the server when `$isLicenseIssuance` (so the admin can upload
  immediately on the first page load after the status changed);
- the JS dropdown handler when the admin picks "صدور مجوز" in the
  `#new_status` dropdown (preview before submitting the status
  change).

The two paths are independent — the server-side render sets the
initial visibility, the JS handles the dropdown interaction. When the
admin changes the dropdown away from "صدور مجوز" (e.g. to "تأیید
نهایی" or any other status), the JS hides the card again. This
matches the user's task spec: "Use JS: when the status dropdown
changes to 'صدور مجوز', show the license upload section."

The "everything else" wrapper (`#moj-applicant-detail-sections`)
gets `moj-sections-disabled` when `$isTerminal` OR
`$isLicenseIssuance`. The CSS class uses `opacity: 0.55` (visual
dim), `pointer-events: none` (block all clicks), and `filter:
grayscale(35%)` (subtle desaturation) to make the lock visually
obvious. The wrapper holds:
- the License State card (informational only, but dimming it visually
  groups it with the rest of the locked content);
- the applicant info card (with the inline edit form);
- the golden-link + property + job detail cards;
- the documents card (with the upload form + delete buttons);
- the status timeline table.

The Status Change Card stays OUTSIDE the wrapper so it remains
active when `$isLicenseIssuance` (the admin needs to be able to
advance the case to "تحویل مجوز" once the license image is uploaded).
The new License Upload Card is also OUTSIDE the wrapper for the same
reason.

The inline `<style>` block is kept in the view (rather than moved to
`public/assets/css/app.css`) so the Phase 55 behaviour is
self-contained and easy to grep. Other phase-specific styles in this
codebase follow the same convention (Phase 50's modal styles are
also inline).

## Verification

### PHP syntax

```bash
/home/z/my-project/bin/php -l app/Modules/Core/Controllers/LicenseController.php
/home/z/my-project/bin/php -l app/Modules/Core/Controllers/AdminController.php
/home/z/my-project/bin/php -l app/Views/admin/view_application.php
# → "No syntax errors detected" for all three
```

### Container wiring (with the new LicenseFileRepositoryInterface dependency)

```bash
cd /home/z/my-project/mojavez-v3 && /home/z/my-project/bin/php -r "
define('ROOT_PATH', __DIR__);
spl_autoload_register(static function (string \$class): void {
    \$prefix = 'App\\\\';
    if (str_starts_with(\$class, \$prefix)) {
        \$relative = substr(\$class, strlen(\$prefix));
        \$file = __DIR__ . '/app/' . str_replace('\\\\', '/', \$relative) . '.php';
        if (is_file(\$file)) require \$file;
    }
});
\$logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/lint-test.log', 'debug');
\$env = \App\Shared\Support\Environment::load(__DIR__ . '/.env');
\$c = new \App\Container(\$env, \$logger);
\$c->boot(__DIR__);
\$adminController = \$c->make(\App\Modules\Core\Controllers\AdminController::class);
echo 'AdminController wired successfully: ' . get_class(\$adminController) . PHP_EOL;
"
# → "Container booted successfully"
# → "AdminController wired successfully: App\Modules\Core\Controllers\AdminController"
```

### Test suite (sandbox — no MySQL available)

```bash
/home/z/my-project/bin/php tests/run.php
# → ContainerTest PASS (13 assertions)
# → HashTest PASS (19 assertions)
# → LicenseAnchorsTest PASS (12 assertions)
# → NormalizerTest PASS (23 assertions)
# → PatternMatcherTest PASS (49 assertions)
# → RouterTest PASS (16 assertions)
# → SettingsControllerValidationTest PASS (22 assertions)
# → SseControllerTest PASS (15 assertions)
# → StructuredLoggerTest PASS (16 assertions)
# → TrackScraperTest PASS (19 assertions)
# → ValidatorTest PASS (48 assertions)
# → Total: 252 assertions, 23 failures
# (all 23 failures are "Could not connect to database" — environment
#  failures, not test logic failures; same baseline as Phase 54.)
```

### Linter (`tools/php_lint_simple.py`)

All three modified PHP files PASS the simple linter. The view file
also reports "unbalanced parens / braces" but this is a pre-existing
false positive (confirmed by running the linter against the
`git stash`ed original — same warning). A PHP-aware stripper (regex
that strips strings + comments + HTML, then counts parens/braces only
inside `<?php ?>` blocks) confirms all three files are actually
balanced:
- `view_application.php`: parens 89/89, braces 12/12, brackets 22/22
- `LicenseController.php`: parens 278/278, braces 53/53, brackets 68/68
- `AdminController.php`: parens 549/549, braces 124/124, brackets 295/295

## What I did NOT change (intentional)

- `app/Modules/Core/Module.php` — `LicenseFileRepositoryInterface`
  was already bound (line 113 → `PdoLicenseFileRepository`), so no
  route or binding change was needed for the new controller
  dependency.
- `LicenseController::upload()` validation ladder — the controller's
  existing size limit (`MAX_LICENSE_SIZE = 10 MiB`) + MIME whitelist
  (`LICENSE_ALLOWED_MIME = ['image/jpeg', 'image/png', 'application/pdf']`)
  are unchanged. The view's `accept="image/jpeg,image/png,application/pdf"`
  attribute is a file-picker hint only; the controller re-validates the
  actual MIME via `detectMime()` (which uses `mime_content_type()` /
  `finfo`).
- The `changeStatus()` controller's terminal-status rejection (Phase 53,
  v2.1.9) — already in place. Phase 55 adds the view-side lock-down on
  top of it (visual + form-disabled) but does not modify the server
  enforcement.
- The Phase 50 (v2.1.6) payment modal — kept as-is, only the trigger
  button gets a `disabled` attribute when the case is terminal.
- The "نسخهٔ چاپی" print button — kept active even when the case is
  terminal (it opens a new tab + the print view is read-only, so
  there's no risk).
- `app/Modules/Core/Domain/Enums/ApplicantStatus.php` — the enum's
  `isTerminal()` and `LicenseIssuance` case were already in place;
  Phase 55 just consumes them in the view.
- `CHANGELOG.md` — the Phase 54 work record notes that a `[2.2.0]`
  section will be added in a follow-up commit; the same pattern applies
  to Phase 55's `[2.2.1]` section (not part of this commit per the
  task spec).

## Commit + push

```bash
git add -A
git commit -m "feat(phase-55): license upload on صدور مجوز + disable sections on terminal status

1. When admin changes status to 'صدور مجوز', a license upload section
   appears. The form POSTs to /admin/license/upload.
2. When status is terminal (رد/لغو/تحویل مجوز/تأیید نهایی), the entire
   page is disabled with a 'پرونده بسته شده است' banner.
3. When status is 'صدور مجوز', only status-change + license-upload are
   active; everything else is dimmed.

Version: 2.2.1"
git push origin phase/55-license-upload-close
```
