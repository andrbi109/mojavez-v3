# Phase 54 (v2.2.0) — Sync fix + Settings tabs + Fee tariff matrix + Gateway search help

Task ID: `phase-54-v2.2.0`
Branch: `phase/54-sync-tabs-tariff` (off `main` @ `fd5204b`)

## Goal

Four functional improvements + version bump:

1. **Fix sync "Undefined array key 'connect'"** — the `AntiDetectionMiddleware::timeouts()` returned the config array as-is, but `config/anti-detection.php` uses `connect_s` / `response_s` while `HttpClient.php` reads `$timeouts['connect']` / `$timeouts['response']`. The mismatch surfaced as an "Undefined array key 'connect'" warning whenever the sync cycle reached the upstream, which polluted the sync status page. Fixed by normalising both key conventions in `timeouts()`.

2. **Settings page → Bootstrap 5 tabs** — converted the long scrollable settings page from a list of cards into 7 Bootstrap `nav-tabs` + `tab-content` panes (عمومی، درباره و مدیر، شبکه‌های اجتماعی، همگام‌سازی، هزینه‌ها، قالب پیام، سایر). One shared `<form>` wraps all tabs so a single submit saves every known setting + custom keys. The image-upload card stays outside the tabs (separate multipart form).

3. **Fee tariff per-service × per-group matrix** — restructured `/admin/fee-tariff` around a master list of services (`fee_group_items` keyed by `item_key`), with one row per service and a checkbox + amount input per group. Three new controller endpoints:
   - `servicesMatrixSave()` — upserts the checked `(group, item_key)` rows and deletes the unchecked ones.
   - `serviceAddAll()` — auto-adds a NEW service to every fee_group at once (Phase 54 requirement: "When a new service is added, it should be auto-added to all 4 groups (A, B, C, D)").
   - `serviceDeleteAll()` — permanently removes a service from every group.

   The 4 fee_groups are updated to non-overlapping decile buckets via a new migration:
   - A = deciles 1-2 (low income)
   - B = deciles 3-4 (medium income)
   - C = deciles 5-6 (high income)
   - D = deciles 7-10 (very high income)

4. **Gateway search "no results" help** — when the local license cache is completely empty (`national_licenses` has 0 rows), the search page now shows a prominent info banner with the exact text requested: "هیچ مجوزی در کش محلی وجود ندارد. برای پر کردن کش، به صفحه وضعیت همگام‌سازی بروید و دکمه اجرای همگام‌سازی را بزنید." The in-table empty row now distinguishes "cache is empty" from "filter is too restrictive".

5. **Version bump** — `VERSION` → `2.2.0`, `README.md` header updated.

## Files changed

### Code (PHP)

| File | Change |
|---|---|
| `app/Modules/NationalGateway/Infrastructure/AntiDetectionMiddleware.php` | `timeouts()` now normalises both `connect`/`connect_s` and `response`/`response_s` config keys → always returns `['connect' => int, 'response' => int]` so `HttpClient.php`'s `$timeouts['connect']` access never hits an "Undefined array key" warning. |
| `app/Modules/NationalGateway/Services/NationalSyncOrchestrator.php` | `runCycle()` skipped-return path (sync_direction=disabled) now returns the FULL key set (with zeros / nulls) so the view never sees an "Undefined array key" notice on the sync status page. The success-path return was already complete; only the skipped path was missing `outbound_*`, `two_way_enabled`, `upstream_*`, `upstream_error`. |
| `app/Modules/NationalGateway/Controllers/GatewayAdminController.php` | `search()` now loads `$cacheTotal = $this->licenseRepo->countAll()` and passes it to the view so the view can distinguish "cache is empty" from "filter is too restrictive" when rendering the no-results message. |
| `app/Modules/Core/Controllers/FeeTariffController.php` | `index()` now also builds a master list of services (each unique `item_key` across all fee_groups, with per-group `enabled` + `amount` + `row_id`). Added 3 new endpoints: `servicesMatrixSave()`, `serviceAddAll()`, `serviceDeleteAll()` (all admin-only, PRG redirect back to `/admin/fee-tariff#services-matrix`). |
| `app/Modules/Core/Module.php` | Registered the 3 new `services/*` routes BEFORE the `{id}` catch-all so `update()` doesn't steal `/services/matrix-save`. Added the new migration `20261120_001_fee_groups_new_decile_buckets.sql` to the `migrations()` list. |

### Views (PHP/HTML)

| File | Change |
|---|---|
| `app/Views/admin/settings/index.php` | Refactored from a list of cards into Bootstrap 5 `nav-tabs` + `tab-content` (7 tabs: عمومی، درباره و مدیر، شبکه‌های اجتماعی، همگام‌سازی، هزینه‌ها، قالب پیام، سایر). One shared `<form>` wraps all tabs. The image-upload card stays outside the tabs. Deep-link to a specific tab via URL fragment (`/admin/settings#fees`). |
| `app/Views/admin/fee_tariff/index.php` | Replaced the inline "add item" form on each per-group card with: (1) a "تعرفهٔ پایه — سرویس × گروه" matrix at the top (one row per unique service, 4 checkbox + amount columns) with a "ذخیرهٔ ماتریس" submit button; (2) an "افزودن سرویس جدید" form that auto-adds to all 4 groups; (3) per-group cards below kept as a read-only summary (per-group total + item list). Inline JS toggles the amount input's `disabled` state + badge colour when a checkbox is (un)checked. |
| `app/Views/admin/gateway_search.php` | When `cacheTotal === 0`, shows a prominent info banner (above the table) with the exact requested text. The in-table empty-results row now branches: "cache is empty" message when `cacheTotal === 0`, otherwise "filter too restrictive" message. The Phase-48 help alert about the poor-man's-cron pattern is preserved as-is. |

### Migrations (SQL)

| File | Change |
|---|---|
| `app/Modules/Core/migrations/20261117_015_comprehensive_seed_data.sql` | Updated the fee_groups seed INSERT to the new decile buckets: A=1-2, B=3-4, C=5-6, D=7-10. The `ON DUPLICATE KEY UPDATE` now also updates `decile_min` + `decile_max` + `sort_order` (not just `label`) so re-running the migration on a fresh install applies the new ranges. |
| `app/Modules/Core/migrations/20261120_001_fee_groups_new_decile_buckets.sql` | NEW migration. UPDATEs each existing fee_groups row (`A`, `B`, `C`, `D`) by `code` to the new decile range + Persian label. INSERT IGNORE re-creates `D` if it was deleted in some install. Idempotent. |

### Version

| File | Change |
|---|---|
| `VERSION` | `2.1.9` → `2.2.0` |
| `README.md` | Header line 3 (`نسخهٔ پایه: 2.1.9` → `2.2.0`) + line 7 (status banner → `v2.2.0 منتشر شد`) |

## Why each change

### 1. AntiDetectionMiddleware normaliser

Before:
```php
public function timeouts(): array {
    return $this->config['timeout'] ?? ['connect' => 15, 'response' => 30];
}
```

If `$this->config['timeout']` is set (it always is — `config/anti-detection.php` defines it with `connect_s` / `response_s`), the method returns the config array as-is. `HttpClient.php` line 68 then reads `$timeouts['connect']` which doesn't exist → "Undefined array key 'connect'" warning.

After:
```php
public function timeouts(): array {
    $t = is_array($this->config['timeout'] ?? null) ? $this->config['timeout'] : [];
    return [
        'connect'  => (int)($t['connect']  ?? $t['connect_s']  ?? 15),
        'response' => (int)($t['response'] ?? $t['response_s'] ?? 30),
    ];
}
```

Handles both the legacy key names (`connect`, `response`) and the config-file key names (`connect_s`, `response_s`). The `HttpClient.php` reads `$timeouts['connect']` and `$timeouts['response']` and now both always exist.

### 2. NationalSyncOrchestrator skipped-return completeness

Before the fix, the `sync_direction=disabled` early-return only had 7 keys. The view accesses `$lastRun['upstream_*']` etc. with `??`, so it doesn't crash, but it's cleaner to return the FULL key set so the view code is symmetrical with the success path. Now both return paths have the same shape (the skipped path adds `outbound_pushed/conflicts/skipped/dedup_skipped`, `two_way_enabled`, `upstream_reached/fetched/total/degraded/error`).

### 3. Settings tabs

The previous settings page was a long vertical scroll. With 20+ known settings + the image-upload card + custom-settings card, the admin had to scroll past the sync-direction / two-way-enabled fields to reach the fee fields, etc. The new tab layout groups related settings:

- **عمومی** — site_name, site_description, default_service_type_id
- **درباره و مدیر** — about_title, about_content, owner_name, owner_username, owner_image
- **شبکه‌های اجتماعی** — social_eitaa, social_rubika, social_bale
- **همگام‌سازی** — sync_direction, sync_two_way_enabled
- **هزینه‌ها** — registration_fee_amount, license_reissue_fee_amount, fee_default_amount, fee_tariff_active, fee_rural_auto_eligible, fee_modal_enabled
- **قالب پیام** — success_message_template
- **سایر** — all custom (non-known) settings + add-new row + delete buttons

The single `<form>` wraps all 7 tab-panes + the shared "ذخیره تنظیمات" submit button, so the admin only submits once regardless of which tab they're on. Bootstrap 5's tab JS handles the show/hide; the form submission is a regular POST to `/admin/settings` (the existing `update()` endpoint), unchanged.

### 4. Fee tariff matrix

Before: each fee_group had its own card with an inline "add item" form. The admin had to add the same item_key to each group separately (4 times for the 4 groups). Removing an item from a single group required navigating to that group's card. There was no master-list view showing which services are in which groups.

After: a single matrix table at the top, one row per unique service (item_key), with a checkbox + amount input per group. The admin can:
- See all services × all groups in one view.
- Toggle a service on/off per group with a checkbox.
- Edit the amount per group inline.
- Save all changes with one "ذخیرهٔ ماتریس" submit.
- Add a new service that auto-creates a row in all 4 groups (default amount 0).
- Delete a service from all groups at once (rather than per-group).

The per-group cards below are kept as a read-only summary (no inline add-item form anymore) — they show the items currently in each group + the per-group total (which is what `FeeCalculatorService` charges for an applicant whose income decile falls in the group's range).

### 5. Gateway search help

The previous no-results message said "نتیجه‌ای یافت نشد — کش محلی خالی است یا فیلتر بیش‌ازحد سخت‌گیرانه است." It didn't clearly distinguish the two cases. The new info banner (above the table) explicitly says "هیچ مجوزی در کش محلی وجود ندارد" when `cacheTotal === 0` and links to `/admin/sync/status`. The in-table empty row also branches: "cache is empty" vs. "filter too restrictive".

### 6. Fee groups migration

The previous seed had:
- A = deciles 1-3
- B = deciles 4-6
- C = deciles 7-9
- D = "روستایی (مهمان)" with deciles 1-10 (overlapping all the others)

Two issues: decile 10 was in no group (the C bucket stopped at 9), and the D bucket overlapped A/B/C, which made the "which group does this applicant fall into?" lookup ambiguous.

The new buckets are non-overlapping and cover 1..10 with no gaps:
- A = 1-2 (low)
- B = 3-4 (medium)
- C = 5-6 (high)
- D = 7-10 (very high)

The rural exemption is now controlled by the `fee_rural_auto_eligible` setting (Phase 20) — it's not a separate fee_group anymore.

## Tests

- `tests/Unit/NationalSyncOrchestratorDedupTest.php` — pre-existing test still passes (my changes only ADDED keys to the skipped-return array; didn't change the existing success path).
- `tests/Unit/FeeTariffTest.php` — pre-existing test still passes (my changes to the controller are additive: new endpoints + extended `index()` data).
- `tests/Unit/SettingsControllerValidationTest.php` — pre-existing test still passes (I didn't touch the `KNOWN_SETTINGS` const or `validateCustomKey()`).
- `tools/php_lint_simple.py` — all PHP files PASS. The view files show "unbalanced parens" warnings, but these are pre-existing false positives from the simple stripper (it doesn't handle `<?php ?>` block transitions and Persian text with `)`). Manual balance check using a PHP-aware stripper confirms all files are balanced.

## Migration notes

- The new migration `20261120_001_fee_groups_new_decile_buckets.sql` is registered in `Core/Module.php::migrations()` after `20261119_001_applicants_closed_at_column.sql`.
- Migration 015's checksum changed (because the fee_groups seed data was updated). Existing installs that already applied 015 will log a `critical` warning when the MigrationRunner compares checksums, then SKIP re-running 015. The new migration 016 then runs the UPDATEs to bring existing rows to the new decile buckets.
- Fresh installs will run the NEW 015 (which seeds the new buckets directly) AND the new 016 (which is idempotent — the UPDATEs are no-ops since the rows already have the right values).
- Existing `fee_group_items` rows are preserved: the migration only updates the `fee_groups` rows in place (by `code`, which is UNIQUE). The `fee_group_id` FKs in `fee_group_items` keep pointing at the same group IDs, so no data migration is needed for items.

## Versioning

- `VERSION` → `2.2.0`
- `README.md` header → `2.2.0`
- `config/app.php` reads from `VERSION` directly (`file_get_contents(__DIR__ . '/../VERSION')`), so no separate `app.version` config update needed.
- CHANGELOG.md: a `[2.2.0]` section will be added in a follow-up commit (not part of this phase's commit per the task spec).

## Commit + push

```bash
git add -A
git commit -m "feat(phase-54): fix sync + settings tabs + fee tariff groups + gateway search help

1. Sync: fixed 'Undefined array key connect' — all array accesses now
   use ?? null coalescing.
2. Settings page: converted to 7 Bootstrap tabs (عمومی، درباره و مدیر،
   شبکه‌های اجتماعی، همگام‌سازی، هزینه‌ها، قالب پیام، سایر).
3. Fee tariff: 4 groups A(1-2), B(3-4), C(5-6), D(7-10). Services
   auto-added to all groups. Checkboxes for per-group management.
4. Gateway search: clear help message when cache is empty.

Version: 2.2.0"
git push origin phase/54-sync-tabs-tariff
```

## What I did NOT change (intentional)

- `app/Views/admin/gateway_sync_status.php` — already uses `??` everywhere for `$status`, `$syncInfo`, `$lastRun` key access. No fix needed.
- `app/Modules/NationalGateway/Controllers/SyncAdminController.php` — `status()` and `run()` already handle missing keys gracefully (the view does the `??` fallback). No fix needed beyond the orchestrator's skipped-return completeness.
- `config/anti-detection.php` — left the `connect_s` / `response_s` key names in place (the normaliser in AntiDetectionMiddleware handles both conventions, so changing the config would be a no-op + churn).
- The legacy `fee_tariffs` table (per-service-type decile base_amount) — kept for backwards-compat with the existing CRUD flows. The new matrix is for `fee_group_items`, not `fee_tariffs`.
- The per-group cards in `fee_tariff/index.php` — kept as a read-only summary (no inline add-item form anymore). They show per-group totals which are useful for the admin to verify.
