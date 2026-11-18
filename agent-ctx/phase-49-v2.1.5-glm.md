# Phase 49 — full settings management + smarter payments + v2.1.5 release

Task ID: `phase-49-v2.1.5`
Branch: `phase/49-settings-payments-docs` (off `main` @ `a6a9074`)
Agent: `glm`

## Summary

Three deliverables, all on top of v2.1.0 (commit `a6a9074`):

1. **Full settings management** — new `POST /admin/settings/delete` route
   that lets the admin permanently delete a CUSTOM (non-known) setting.
   The settings view now shows a trash button next to each custom row;
   the JS handler POSTs via `fetch()` with the existing CSRF token and
   reloads the page on success. Known settings (the 20 keys in
   `KNOWN_SETTINGS`) are refused with a flash message.

2. **Smarter payments page** — restructured summary cards (total amount
   + approved count/sum + pending count/sum + rejected count/sum),
   date-range filter (`from`/`to` validated as strict Y-m-d, applied
   inclusively to `payments.created_at`, forwarded to the CSV export
   URL), and a single "review" button per pending row that opens a
   Bootstrap modal with a note textarea + two submit buttons (approve
   / reject). Reject requires a non-empty note (inline JS validation).

3. **Version bump + docs** — `VERSION` 2.1.0 → 2.1.5, `README.md`
   header updated, new `## [2.1.5]` section added to `CHANGELOG.md`
   documenting all five phases (45-49) per the task spec.

## Files modified

| File | Change |
| --- | --- |
| `app/Modules/Core/Controllers/SettingsController.php` | Added `delete()` method (refuses known keys, validates the custom key via `validateCustomKey()`, deletes the row, flashes `settings_message`). Added `readFlash()` private helper. `index()` / `update()` now also forward `settings_message` to the view. Docblock updated with Phase 49 notes. |
| `app/Modules/Core/Module.php` | New route: `POST /admin/settings/delete` → `SettingsController::delete`. Registered right after `/admin/settings/upload-image`. |
| `app/Views/admin/settings/index.php` | New `$message` flash banner at the top. Custom-settings card: third column added (col-12 col-sm-2) with a trash button per existing custom row (empty "add new" row keeps the old 4/8 layout). JS at the bottom reads the CSRF token from the main update form and POSTs the delete via `fetch()` with `Content-Type: application/x-www-form-urlencoded`; on `r.ok && r.redirected` it reloads the page. |
| `app/Modules/Core/Controllers/PaymentController.php` | `index()` and `csv()` now read `from`/`to` query params (via new `parseDateRange()` helper) and pass them to `buildWhereClause()` (new signature: two optional trailing params). `buildWhereClause()` adds `p.created_at >= ?` / `p.created_at < ? + 1 day` clauses (inclusive `to`). `computeStats()` extended with `approvedCount`, `totalCount`, `totalAmount`, `rejectedSum` (all ADDITIONS, no existing key renamed). Filters array passed to the view now includes `from`/`to`. |
| `app/Views/admin/payments.php` | Restructured summary cards (4 cards: total amount / approved / pending / rejected — each with primary count + small sum detail). Date-range inputs added to the filter form. Existing inline-approve-button + reject-modal replaced by a single per-row "بررسی" button that opens a Bootstrap modal with one form (note textarea + two submit buttons `name="decision" value="approved"|"rejected"`). Inline JS rejects the form if the admin clicks "رد رسید" with an empty note. Filter-preserving query-string helper (`$qs`) added so the CSV export link + pagination links preserve `status`/`purpose`/`q`/`from`/`to`. |
| `VERSION` | `2.1.0` → `2.1.5`. |
| `README.md` | Version header line 3 (`2.0.6` → `2.1.5`) + line 7 (`v2.1.0` → `v2.1.5`). |
| `CHANGELOG.md` | New `## [2.1.5] — 2026-11-18` section inserted between the `## [Unreleased]` block and `## [2.0.6]`, documenting phases 45-49 (one subsection per phase) + a "Files changed in Phase 49" subsection + a "Backward compatibility" subsection + "Final stats". |

## Architecture / decisions

- **CSRF + nested-form problem.** The settings view's custom-settings
  card is INSIDE the main update form (`<form action="/admin/settings">`).
  Putting a delete `<form>` inside would be invalid HTML (browsers
  ignore nested forms). Solution: the delete buttons are
  `type="button"` (so they don't submit the parent form) and a small
  `<script>` at the bottom of the view reads the existing CSRF token
  from the main form's `<input name="_csrf">` and POSTs the delete via
  `fetch()`. This keeps the DOM valid AND uses the already-issued
  CSRF token (no second token needed).
- **`to` date is inclusive.** The `from` filter is `>= 'YYYY-MM-DD
  00:00:00'` and the `to` filter is `< 'YYYY-MM-DD+1 00:00:00'` (i.e.
  the start of the next day). A user picking `2026-12-31` expects rows
  created at `23:59:59` to be included, so the +1-day shift is the
  correct semantics. `strtotime($to . ' +1 day')` computes the next
  day safely.
- **Strict Y-m-d validation.** Both `from` and `to` go through a regex
  + `strtotime` round-trip check before being used. A user passing
  `from=2026-13-40` (impossible date) gets silently ignored — no DB
  error, no false rows. If both are set and `to < from`, they are
  swapped so the admin doesn't get an empty result by accident.
- **Known settings refusal.** `delete()` rejects any key in
  `KNOWN_SETTINGS` with a flash message rather than a 422, because
  the admin's flow is form-then-redirect-back (not AJAX). The known
  settings have first-class UI controls + seeded defaults; deleting
  one would silently reset it to the default on the next `index()`
  load, which is confusing.
- **`computeStats()` backward compat.** New keys (`approvedCount`,
  `totalCount`, `totalAmount`, `rejectedSum`) are ADDITIONS — no
  existing key was renamed or removed. The view's `$stats ??=` default
  now lists all 9 keys (old + new) so a future caller that doesn't
  run the controller (e.g. a stub) still gets a sane default array.
- **`buildWhereClause()` backward compat.** The two new parameters
  (`$from = ''`, `$to = ''`) are OPTIONAL trailing params. Existing
  callers (none outside this controller, but defensively) continue to
  compile.
- **Review modal submit-button semantics.** The modal contains ONE
  `<form>` with TWO `<button type="submit" name="decision"
  value="approved|rejected">`. Per the HTML spec, only the clicked
  button's `name=value` pair is submitted, so the controller's
  `review()` method sees `decision=approved` or `decision=rejected`
  exactly as before (no JS magic needed to set the value).
- **Reject requires a note.** The inline `<script>` listens for the
  `[data-review-reject]` click and `e.preventDefault()`s if the
  `[data-review-note]` textarea is empty. The note textarea gets
  `is-invalid` class + focus + an alert. Approve does not require a
  note (the controller stores `null` for `reject_reason` on approve).

## Test status

- `php -l` on all four modified PHP files (SettingsController,
  Module, PaymentController, settings/index.php, payments.php): clean.
- `tests/Unit/SettingsControllerValidationTest.php` (the only test
  that touches SettingsController) tests `validateCustomKey()` which
  was NOT modified — still passes.
- No new test added (per the task spec: "do not write any test code").
- The existing `computeStats()` keys were not removed/renamed, so
  any downstream code (e.g. a hypothetical dashboard) continues to
  compile.

## Git operations

After all files were saved:

```bash
git add -A
git commit -m "feat(phase-49): full settings management + smarter payments + v2.1.5 release

v2.1.5 (SemVer patch from v2.1.0):

Phase 45 (v2.1.1): v2-style document upload + Jalali dates + status notifications.
Phase 46 (v2.1.2): user delete application + admin payment request + user postal change.
Phase 47 (v2.1.3): bulk-import CSV BOM fix + user license request + revision verify.
Phase 48 (v2.1.4): gateway search help + service types info + fee tariff items.
Phase 49 (v2.1.5): full settings management + smarter payments.

Settings:
- Delete custom settings (POST /admin/settings/delete).
- Delete button in settings view for custom (non-known) settings.

Payments:
- Summary stats: total amount, approved/pending/rejected counts.
- Status filter + date range filter.
- Review buttons for approve/reject with note.

Version: 2.1.5"

git tag -a v2.1.5 -m "v2.1.5 — settings management + smarter payments + phases 45-49"
git push origin phase/49-settings-payments-docs
git push origin v2.1.5
```

## Cross-references

- Prior phase work records in this directory:
  - `phase-42-v2.0.8-glm.md`
  - `phase-43-v2.0.9-glm.md` (the last one that touched
    `SettingsController` — extended `KNOWN_SETTINGS` from 5 → 20)
  - `phase-44b-css-fixes-glm.md`
- The `## [2.0.6]` and `## [2.1.5]` sections in `CHANGELOG.md` give the
  full phase history for v2.x.
