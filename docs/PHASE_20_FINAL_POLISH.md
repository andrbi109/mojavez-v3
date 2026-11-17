# Phase 20 — Final polish (v1.9.2)

> **Branch:** `phase/20-tracking-final-polish` (off `main` @ `0fc34e9` — v1.9.1)
> **Tag:** `v1.9.2` · **Date:** 2026-11-17
> **Maintainer:** AI agent (z.ai)
> **Status:** last feature phase before user approval of v2.0.0.

## 1. Goal

Close the remaining v2→v3 feature gap (settings image upload +
FeeTariff extensions) and polish the 404 page, the print view, and
add a favicon. After Phase 20, Phase 21 (v1.9.3) will be the test-only
phase, then the user can approve v2.0.0.

## 2. What changed

### 2.1 New routes (6)

| Method | Path | Controller::action | Purpose |
|---|---|---|---|
| POST | `/admin/settings/upload-image` | `SettingsController::uploadImage` | image upload for `site_logo` + similar brand assets |
| POST | `/admin/fee-tariff/settings` | `FeeTariffController::saveSettings` | save 8 `fee_*` global settings |
| POST | `/admin/fee-tariff/group/save` | `FeeTariffController::groupSave` | INSERT/UPDATE on `fee_groups` |
| POST | `/admin/fee-tariff/group/{id}/delete` | `FeeTariffController::groupDelete` | DELETE on `fee_groups` (refuses if referenced by `payments`) |
| POST | `/admin/fee-tariff/items/save` | `FeeTariffController::itemsSave` | bulk upsert `fee_group_items` |
| POST | `/admin/payment/tick` | `FeeTariffController::toggleTick` | toggle a `payment_service_ticks` row |

All 6 routes are admin-only (`requireRole(['admin'])`).

### 2.2 Polish

- **`public/assets/favicon.svg`** — new simple SVG favicon (teal
  checkmark in a circle, brand color `#0f766e`, 64×64, <500 bytes).
- **`app/Views/layout.php`, `admin_layout.php`, `user_layout.php`** —
  `<link rel="icon" type="image/svg+xml" href="...assets/favicon.svg">`
  added to the `<head>`.
- **`app/Views/errors/404.php`** — added "تماس با پشتیبانی" mailto link
  (reads `settings.support_email` if present, hidden if absent) +
  small separator. The front controller (`public/index.php`) now
  reads `support_email` from the `settings` table before rendering
  the 404 view, failure-tolerant (any DB error → empty string → link
  hidden, 404 never 500s).
- **`app/Views/print.php`** — ported + polished from v2
  `layout_print.php`: A4 sheet container, gradient header bar with
  brand mark + tracking code box, numbered section titles, better
  table styling (zebra striping on the status log), print-mode CSS
  overrides, mobile responsive. Still self-contained inline CSS.
- **`app/Views/admin_layout.php` sidebar** — verified all 6 sections
  present; added 2 missing links: `/admin/license-requests` and
  `/admin/revisions` (the v1.9.1 CHANGELOG claimed they were added
  but they were not — now actually present under "درخواست‌ها").
- **`app/Views/admin/settings/index.php`** — added an image-upload
  card at the top of the settings page (Phase 20). Shows the current
  `site_logo` preview (if any), the upload form (`setting_key` + file
  input), and a small AJAX handler that posts to
  `/admin/settings/upload-image` and reloads on success.

### 2.3 Security notes — `uploadImage`

- MIME validated via `finfo::file()` (not the browser-supplied
  `$_FILES['image']['type']` — that's spoofable).
- Allowed MIME: `image/jpeg`, `image/png`, `image/webp` (no SVG —
  SVG can carry JS; the admin can use raster images for the logo).
- File size capped at 2 MB (defense + UX; the PHP `upload_max_filesize`
  is typically higher but we don't want 50 MB photos in the header).
- The `setting_key` from the user is sanitized to `[a-zA-Z0-9_.-]`
  (the same charset enforced by `SettingsController::validateCustomKey()`)
  — this is used as the on-disk filename prefix, so path-traversal is
  impossible.
- The destination directory is created with `0755` if missing.
- `move_uploaded_file()` is used (not `copy`/`rename`) so the SAPI
  `tmp_name` check is enforced.

### 2.4 Schema notes — FeeTariff extensions

The v3 `fee_groups` schema is a trimmed version of v2's: only
`code, label, decile_min, decile_max, total_amount, is_active, sort_order`.
The v2 controller accepted extra fields (`name`, `icon`,
`is_rural_eligible`, `message_title`, `message_body`) that don't
exist as columns in v3. The Phase-20 `groupSave` accepts the v3
schema fields plus a `name` alias for `label` (backwards-compat with
any client still using the v2 field name). The other v2-only fields
are silently dropped (no schema change, per the phase constraints).

The v3 `payment_service_ticks` schema is `payment_id` + `item_key`
+ `is_done` + `done_reason` + `ticked_by` + `ticked_at`. The v2
`toggleTick` accepted `tick_id` + `done` + optional `reason`; the
Phase-20 `toggleTick` accepts the same fields and matches the v3
schema (no `service_type_id` column exists, so the task's literal
`service_type_id + is_active` description is interpreted as
`tick_id + done` to match the actual schema).

## 3. Debt paid

| ID | Description | Resolution |
|---|---|---|
| D-2-3 (full) | TrackingController::submitPayment + submitSms were listed as "stub" in Phase 19 | Verified: both methods are fully implemented since Phase 15 (v1.6.0). The Phase-19 debt-review entry was a documentation error — they were not stubs. This phase verified and documented them as complete. The D-2-3 debt entry is now closed in full. |
| (doc fix) | v1.9.1 CHANGELOG claimed `/admin/license-requests` and `/admin/revisions` were added to the sidebar but they were not | Added to `admin_layout.php` under "درخواست‌ها" with icons `bi-patch-check` and `bi-arrow-clockwise`. |

## 4. Debt introduced

None.

## 5. Debt carried forward

Same 15 low-priority items as Phase 19 (all `low` severity, sandbox/
deploy-only — see `docs/DEBT_REVIEW.md` for the full list). No new
debt introduced.

## 6. Testing

- `python3 tools/php_lint_simple.py` — passes.
- `python3 tools/static_analysis.py --strict` — passes (0 surviving
  warnings).
- `python3 tools/check_schema_ownership.py` — passes (no schema
  changes, no ownership drift).
- Manual curl tests:
  - `curl -I http://127.0.0.1:8000/assets/favicon.svg` → 200,
    `Content-Type: image/svg+xml`.
  - `/admin/settings` (admin login) → 200, shows the new upload card.
  - `/admin/fee-tariff` (admin login) → 200, shows the existing
    tariff list (the new endpoints are POST-only, no view change).
  - `POST /admin/payment/tick` (admin) → 200/302/422 depending on
    payload (admin gate enforced before processing).

## 7. Files

| File | Change |
|---|---|
| `app/Modules/Core/Controllers/SettingsController.php` | +1 method (`uploadImage`) |
| `app/Modules/Core/Controllers/FeeTariffController.php` | +5 methods (`saveSettings`, `groupSave`, `groupDelete`, `itemsSave`, `toggleTick`) + private `upsertSetting` helper |
| `app/Modules/Core/Module.php` | +6 routes |
| `app/Views/admin/settings/index.php` | + image-upload card at the top |
| `app/Views/admin_layout.php` | +2 sidebar links + favicon link |
| `app/Views/layout.php` | + favicon link |
| `app/Views/user_layout.php` | + favicon link |
| `app/Views/errors/404.php` | + support-email link + separator |
| `app/Views/print.php` | polished (A4 container + gradient header + numbered sections + responsive) |
| `public/assets/favicon.svg` | new — teal checkmark in a circle |
| `public/index.php` | read `support_email` from settings for 404 view (failure-tolerant) |
| `VERSION` | `1.9.1` → `1.9.2` |
| `README.md` | header version `1.9.1`/فاز ۱۹ → `1.9.2`/فاز ۲۰ |
| `CHANGELOG.md` | this section |
| `docs/DEBT_REVIEW.md` | Phase 20 section + live-debt summary update |
| `docs/PHASE_20_FINAL_POLISH.md` | new — this file |

## 8. Route count

- **Before Phase 20:** 193 routes (Core 150 + NationalGateway 43).
- **After Phase 20:** 199 routes (Core 156 + NationalGateway 43).
- Net: +6 admin routes.

## 9. What's next

- **Phase 21 (v1.9.3)** — test-only phase:
  - End-to-end smoke test of every public + admin route.
  - Coverage report for the existing Unit tests.
  - Manual QA pass of the registration wizard (step 1 → 4 → success).
  - Manual QA pass of the tracking + revision + license flows.
- **v2.0.0** — after user approval. No code changes from v1.9.2
  (just the version bump + the user's sign-off).
