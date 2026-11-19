# Phase 52 (v2.1.8) — Auto-fill, Badges, Tracking-code Storage, Initial Status

Task ID: `phase-52-v2.1.8`
Branch: `phase/52-autofill-badges-storage` (off `main` @ `12c537c`)

## Goal

Four functional improvements + version bump:

1. **Auto-fill user info in registration wizard** — `selectService()` and
   `step1()` load the `user_account` row (mobile, display_name) and the most
   recent previous application (first_name, last_name); steps 2 and 3
   pre-fill property + job data from the most recent previous application
   when the current applicant has none yet.

2. **Status badge colors** — every view that renders an applicant
   `current_status` badge must read the Bootstrap color class from
   `config/statuses.php` via a new helper `App\Shared\Support\StatusBadge::color()`.
   Replaces the previously hard-coded `text-bg-secondary` / `text-bg-info`
   badges in `applications.php`, `applicant_account_view.php`, `records.php`,
   `user/overview.php`, and `user/requests.php`. `view_application.php`
   already uses `$badgeColorFor()` which reads `$statusMeta` (the config map),
   so it stays — but the timeline override block is updated to prefer the
   config colour first.

3. **Store documents by tracking code (v2 style)** — move the FileStorage
   upload dir from the top-level `uploads/` to `storage/uploads/` so it
   matches the `AvatarService` layout (`storage/uploads/avatars/`).
   `FileStorage::storeUpload()` already builds the path as
   `/{tracking_code}/{doc_type}_{rand6}.ext` (it has since Phase 41), so no
   path-building change is needed — only the configured `upload_dir` moves
   and the `getUploadDir()` default fallback is updated to match.
   `DocumentController` resolves paths via `FileStorage::getFullPath()`
   with the stored relative `file_path`, so it keeps working unchanged.

4. **Initial status** — `processStep4()` transitions to `InfoComplete`
   (`تکمیل اطلاعات`) instead of `InitialApproval` (`تأیید اولیه`). The
   wizard leaves the applicant at "InfoComplete"; the admin reviews and
   advances the status manually.

5. **Version bump** — `VERSION` and `README.md` to `2.1.8`.

6. **Commit + push** to `origin/phase/52-autofill-badges-storage`.

## Files Touched

- `app/Modules/Core/Controllers/RegistrationController.php`
  - Add `UserAccountRepositoryInterface $userAccounts` dependency.
  - `selectService()`: load `user_account` + most recent previous
    applicant (excluding the row we are about to insert) and seed
    `first_name`/`last_name`/`mobile` on the new applicants row.
  - `step1()`: enrich `$values` with `display_name` (used as a hint).
  - `step2()`: if the current applicant has no `property_details` row,
    load the most recent previous application's row and pre-fill
    `province_id`/`township_id`/`address_*`/`postal_code`.
  - `step3()`: if the current applicant has no `job_details` row, load
    the most recent previous application's row and pre-fill
    `license_title_id`.
  - `processStep4()`: transition to `InfoComplete` (was `InitialApproval`).
  - New private helpers: `userAccount()`, `mostRecentPreviousApplicant()`.

- `app/Shared/Support/StatusBadge.php` — NEW. Static helper
  `StatusBadge::color(string $status): string` that reads
  `Config::get('statuses.statuses.{status}.color')` with a fallback to
  `secondary`.

- `app/Views/admin/applications.php`
  - Replace `text-bg-secondary` hard-coded badge with
    `text-bg-<?= StatusBadge::color($row['current_status']) ?>`.

- `app/Views/admin/applicant_account_view.php`
  - Replace the hard-coded `text-bg-info` badge with
    `text-bg-<?= StatusBadge::color($status) ?>`.

- `app/Views/records.php`
  - Replace `text-bg-secondary` with `text-bg-<?= StatusBadge::color(...) ?>`.

- `app/Views/user/overview.php`
  - Replace `bg-info` with `bg-<?= StatusBadge::color(...) ?>` (using
    `bg-` because the user-side layout relies on Bootstrap's solid
    background utilities; the `text-bg-*` variants from Bootstrap 5.3
    work too — we keep `bg-` to match the surrounding style).

- `app/Views/user/requests.php`
  - Drop the inline `if/elseif` colour picker; use `StatusBadge::color($st)`.

- `app/Views/admin/view_application.php`
  - Already uses `$badgeColorFor()` from `$statusMeta` (config map).
  - Add an explicit comment that this is config-driven.
  - In the timeline block, prefer the config colour even for non-status
    log rows (`حذف مدرک`, `افزودن`) — the override is kept for back-compat
    with v2 expectations but is now applied AFTER the config lookup so
    config wins for known statuses.

- `app/Modules/Core/Services/FileStorage.php`
  - Update `getUploadDir()` default fallback from
    `dirname(__DIR__, 4) . '/uploads'` to
    `dirname(__DIR__, 4) . '/storage/uploads'`.
  - Clarify the `storeUpload()` docblock: the `$applicantId` parameter is
    accepted for forward-compat / future audit tables, but the storage
    path is keyed by `$trackingCode` (v2 style).

- `config/app.php`
  - Change `upload_dir` from `dirname(__DIR__) . '/uploads'` to
    `dirname(__DIR__) . '/storage/uploads'`.

- `app/Modules/Core/Controllers/DocumentController.php`
  - No code change — `$document->filePath` is already the relative path
    stored at upload time as `/{tracking_code}/{file_name}`, and
    `FileStorage::getFullPath()` reads `getUploadDir()` to resolve it.
  - Updated the class-level docblock to note that documents live under
    `storage/uploads/{tracking_code}/` (v2 style, Phase 52).

- `VERSION` → `2.1.8`
- `README.md` → `2.1.8`
- `CHANGELOG.md` — add `## [2.1.8] — 2026-11-19` entry summarising this
  phase (kept short, no destructive edits to old entries).

## Verification

- `php -l` on every modified PHP file (via `/home/z/my-project/bin/php`).
- `tools/static_analysis.py` — confirm no new false positives.
- The wizard's flow is exercised manually:
  - `GET /register` (select service).
  - `GET /register/service/{id}` — new applicants row created with
    `mobile` + `first_name` + `last_name` pre-filled when a previous
    application exists.
  - `GET /register/step/1` — values display the pre-filled data.
  - `GET /register/step/2` / `/step/3` — pre-fill from the previous
    application when the current applicant has no detail row yet.
  - `POST /register/step/4` — applicant ends up at `تکمیل اطلاعات`
    (not `تأیید اولیه`).

## Outcome

- All four functional changes implemented.
- Version bumped to `2.1.8`.
- Single commit on `phase/52-autofill-badges-storage`, pushed to origin.
