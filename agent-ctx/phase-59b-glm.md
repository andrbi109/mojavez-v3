# Phase 59b — Bulk import auto-creates user_accounts

Task ID: **phase-59b**
Agent: GLM (z.ai)
Base: branch `main` at `1514ee6` (release v2.2.4)
Branch: `phase/59b-bulk-import-accounts`
Bump: **none** (the task explicitly forbids a version bump)

## Summary of work

After Phase 18 introduced the CSV bulk-import of applicants, the imported
applicants had no `user_accounts` row — they could not log in to the user
panel to complete their application. Phase 59b closes that gap:

> Each bulk-imported applicant now gets a `user_accounts` row created
> automatically as part of `executeRow()`. `username = mobile`,
> `password = bcrypt(national_code)`, `is_active = 1`, and the
> `applicants.user_account_id` is linked to the new account. If an account
> with that mobile already exists (e.g., from a previous import or a
> self-signup via `/signup`), it is just linked — no duplicate is created.

The result page (`admin/bulk_result.php`) gains a new "حساب کاربری"
column showing the per-row user_account outcome (created / linked /
failed / skipped) with the username (mobile number) so the admin can
see at a glance which applicants can now log in.

## Changes by task

### 1. `app/Modules/Core/Controllers/BulkImportController.php`

- Added `use App\Shared\Contracts\UserAccountRepositoryInterface;` to the
  import block.
- Added a new constructor dependency:
  `private readonly UserAccountRepositoryInterface $userAccounts`.
  The Container's autowirer resolves it through the binding registered in
  `Core/Module.php` (`PdoUserAccountRepository` over the shared
  `Connection` singleton).
- `executeRow()`:
  - The outcome array now carries four new keys on every row, regardless
    of the import status:
    - `account_status`  — `'created' | 'linked' | 'failed' | 'skipped'`
    - `username`        — the username we set / found (the mobile for new ones)
    - `user_account_id` — the resolved account id (0 if none)
    - `account_message` — short Persian label for the result page
  - For the four early-return branches (`valid=false`, national_code
    duplicate, tracking-code generation failure) the four keys are set
    to safe defaults (`account_status='skipped'`, `username=''`,
    `user_account_id=0`, `account_message=''`).
  - For the main `inserted` and the catch `failed` branches, the keys are
    populated from the new `resolveOrCreateUserAccount()` helper.
  - The user_account is resolved BEFORE the applicant is inserted, so
    `applicants->create()` can accept `user_account_id` directly — no
    second UPDATE / `linkApplication()` call is needed. The
    `PdoApplicantRepository::create()` field whitelist already includes
    `user_account_id` (added back in Phase 10 / migration 002), so this
    is a no-op schema-wise.
  - The `logger->info('Bulk import inserted applicant', ...)` call now
    also includes `user_account_id` and `account_status`.
- New private helper `resolveOrCreateUserAccount(string $mobile, string
  $nationalCode): array`:
  - Returns a small descriptor: `{status, id, username, message}`.
  - Empty mobile → `skipped` (defensive — `normalizeRow()` already
    rejects empty mobiles as invalid rows, so this branch is only
    reached if the validation step is bypassed).
  - `findByMobile()` first → if a row exists, return it as `linked`
    (with the existing `username` if set, else the mobile). This is the
    no-duplicate path the task requires.
  - Otherwise, create a new account via `UserAccountRepositoryInterface::create()`
    with `username=mobile`, `mobile=mobile`,
    `password=passwordHash($nationalCode)`, `is_active=1`. The bcrypt
    cost matches the rest of v3 (`Hash::PASSWORD_COST = 10`).
  - On a thrown exception (e.g., a UNIQUE violation on the username that
    `findByMobile` somehow missed, or a transient DB error), the helper
    logs the error and returns `failed` — the applicant row is still
    inserted afterwards with `user_account_id = null`, so the import
    does not regress on the user_account step.

### 2. `app/Views/admin/bulk_result.php`

- Added a new "حساب کاربری" column to the per-row table, inserted
  between "کد رهگیری" and "وضعیت" (so the import-status badge stays at
  the right edge of the table).
- Per-row rendering:
  - `account_status='created'` → green badge "ایجاد شد" with
    `bi-person-plus-fill` icon.
  - `account_status='linked'` → blue/info badge "پیوند شد" with
    `bi-link-45deg` icon.
  - `account_status='failed'` → red badge "خطا" with
    `bi-exclamation-triangle-fill` icon.
  - `account_status='skipped'` or empty → grey badge "—" (or em-dash
    span for the truly empty case — rows that never reached the
    user_account resolution step).
  - Below the badge, the username (which equals the mobile number) is
    shown in `dir="ltr"` `<code>` (matching the existing mobile column's
    styling) and the `account_message` is shown as small muted text.
- Bumped the empty-rows `colspan` from 7 to 8 to match the new column
  count.

## Why this design

- **Resolve-before-create** (vs. create-applicant-then-link): one fewer
  SQL round trip and one fewer UPDATE on `applicants`. The
  `PdoApplicantRepository::create()` field whitelist already includes
  `user_account_id`, so passing it at insert time is a one-line change.
- **`findByMobile()` not `findByUsername()`**: the `mobile` column on
  `user_accounts` is UNIQUE and NOT NULL (migration 005), while
  `username` is NULLABLE. Two imported rows could in principle share
  the same username (e.g., if a future change made username editable
  and a user cleared it), but they can never share a mobile. Resolving
  by mobile is therefore the strictest uniqueness check we can make.
- **`username = mobile`** rather than `username = national_code`: the
  v3 user login form (`AuthController::login`) does `findByMobile` and
  the v2 user login accepted mobile-as-username. Setting both
  `username` and `mobile` to the mobile number means a user can type
  either field on the login form and reach the same account. (The
  `AuthController` actually uses `findByMobile` only — so the
  `username` value is effectively informational for the user panel,
  not for login. But matching them keeps the door open for a future
  `findByUsername` login path.)
- **`password = bcrypt(national_code)`**: matches the convention used
  by the manual `/admin/register` flow (`ApplicantAccountController`
  / `AdminController`), which the user is expected to be told in the
  admin's downstream communication (SMS / phone call). Same convention
  also makes a future "admin can re-issue password" tool trivial —
  `passwordHash($national_code)` is idempotent given the same cost
  factor.

## Schema impact

None. The `user_account_id` column on `applicants` and the
`user_accounts` table itself both exist since Phase 1 (migration 002 +
migration 005). No new migration is needed.

## Tests

No automated tests touch `BulkImportController` or `bulk_result.php`
(verified by searching `tests/` for `bulk_import|bulk_result|executeRow`).
The change is fully backward-compatible at the array-shape level:
existing readers of `bulk_import.result.rows` see the same keys they
did before plus four new ones (`account_status`, `username`,
`user_account_id`, `account_message`).

Manual verification:

- `php -l app/Modules/Core/Controllers/BulkImportController.php` → no
  syntax errors.
- `php -l app/Views/admin/bulk_result.php` → no syntax errors.

## Version

No version bump (per task instructions). `VERSION` and `CHANGELOG.md`
are untouched.

## Files touched

- `app/Modules/Core/Controllers/BulkImportController.php`
- `app/Views/admin/bulk_result.php`
- `agent-ctx/phase-59b-glm.md` (this file)
