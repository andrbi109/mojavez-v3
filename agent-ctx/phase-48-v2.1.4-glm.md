# Phase 48 — Gateway search help + service types info + fee tariff items

Task ID: `phase-48-v2.1.4`
Branch: `phase/48-filter-queue-services` (off `main` @ `a6a9074`)
Agent: `glm`

## Summary

Three admin-panel improvements bundled as v2.1.4 (no version bump, per task
spec). All changes are PHP view + controller changes — no schema migration,
no new routes, no version bump.

1. **Gateway search help & inline sync button** — `gateway_search.php` now
   explains *how* the cache gets populated (run sync from /admin/sync/status)
   and offers a green «اجرای همگام‌سازی» button that POSTs to `/admin/sync/run`
   right from the search page itself. The empty-results row in the table was
   also rewritten so it surfaces the same hint with a direct link to the
   sync-status page. The sibling `gateway_filters.php` page got a clearer
   "run in queue" alert message: the old "جستجو در صف افتاد (کار #N)" is now
   "فیلتر در صف قرار گرفت (کار #N). پس از اجرای کارگر، برای مشاهدهٔ نتایج به
   صفحهٔ «جستجوی پیشرفته» مراجعه کنید: …/admin/gateway/search", and there is a
   pre-submit confirmation dialog so the admin understands what running in
   the queue actually does.

2. **Service types — show what's wired up** — `ServiceTypeController::index()`
   now also SELECTs `license_title_count`, `form_field_count`, and
   `wizard_step_count` per row (three cheap correlated subqueries). The
   `service_types.php` view renders them as three new columns at the end of
   each row, as clickable badges that link to the matching admin page
   (`/admin/license-titles`, `/admin/form-fields`, `/admin/wizard-steps`).
   Above the table, an info alert explains that the concrete services /
   sub-services of each service type (e.g. "ثبت درخواست در درگاه ملی مجوزها",
   "بارگذاری مدارک در سامانه تخصصی وزارت میراث فرهنگی", "تغییر کد پستی در
   سامانه املاک و اسکان") are not modeled as a separate sub-service table —
   they are modeled as `fee_group_items` inside the `fee_groups` decile
   groups, and are priced in the **نرخ‌نامه** section.

3. **Fee tariff — show fee_groups + items + per-group total + add-item form**
   — `FeeTariffController::index()` now also loads every `fee_groups` row
   with the per-group `items_total` (SUM of all `fee_group_items.amount` in
   that group, computed server-side), plus every `fee_group_items` row, and
   groups them by `fee_group_id`. The `fee_tariff/index.php` view splits the
   page into two clearly labeled sections: (1) the legacy
   per-service-type / per-decile `fee_tariffs` table (kept intact), and (2) a
   new "نرخ‌نامهٔ گروهی" section that renders one Bootstrap card per
   `fee_groups` row. Each card shows: the group code + label + decile range +
   active status, a table of all items (item_key / item_label / amount /
   sort_order / notes), the **total amount per group** in both the card
   header (as a badge) and the table `<tfoot>`, and an inline "add item"
   form that POSTs to the existing `itemsSave()` endpoint. The add-item
   form uses `data-*` attributes (not `name=`) so a small JS handler can
   dynamically build the `items[<key>][label|amount|sort|notes]` nested
   array shape that the existing bulk-upsert handler already expects. This
   avoids the need for a new route or a new controller method — the form
   just reuses `/admin/fee-tariff/items/save`.

## Files modified

| File | Change |
| --- | --- |
| `app/Views/admin/gateway_search.php` | Header toolbar: replaced the single "dashed back" button with a row containing the new «اجرای همگام‌سازی» POST button (form → `/admin/sync/run`, with a confirm dialog), a «وضعیت همگام‌سازی» outline-info link, and the original «داشبورد» outline-secondary link. Added an info alert immediately below the header explaining that this page only searches the *cached* licenses and how to populate the cache (run sync from /admin/sync/status — with a link, or via the green button above). The empty-results row in the table was rewritten to a two-line block that surfaces the same hint with a `link-primary` to /admin/sync/status. No controller/route/JS-modal changes. |
| `app/Views/admin/gateway_filters.php` | Only the `.btn-run-filter` click handler was changed: added a pre-submit `confirm()` dialog explaining what "run in queue" actually does, and replaced the post-submit alert text with a two-line message — "فیلتر در صف قرار گرفت (کار #N). پس از اجرای کارگر، برای مشاهدهٔ نتایج به صفحهٔ «جستجوی پیشرفته» مراجعه کنید: …/admin/gateway/search". No HTML markup, no new routes. |
| `app/Modules/Core/Controllers/ServiceTypeController.php` | `index()` query now SELECTs three extra correlated-subquery columns — `license_title_count`, `form_field_count`, `wizard_step_count` — alongside the existing `applicant_count`. Same change applied to the failure-path query in `save()` (the one used when validation fails and we re-render the view with the form + table). No new method, no new route. |
| `app/Views/admin/service_types.php` | Added an info alert above the add/edit card explaining how services / sub-services are managed (they are `fee_group_items` in نرخ‌نامه). The table gained three new columns (عناوین مجوز / فیلدهای فرم / مراحل ویزارد); each value is rendered as a clickable badge linking to the matching admin page (text-bg-primary when > 0, text-bg-light+border when 0). The empty-state `colspan` was bumped from 7 to 10 to match the new column count. |
| `app/Modules/Core/Controllers/FeeTariffController.php` | `index()` now also loads every `fee_groups` row with the per-group `items_total` (SUM of `fee_group_items.amount`) and every `fee_group_items` row, grouped by `fee_group_id` in a PHP array. The view data now includes `groups` and `itemsByGroup` alongside the existing `rows`. The `itemsSave()`, `groupSave()`, `groupDelete()` methods are unchanged — the new "add item" form reuses `itemsSave()` via the standard `items[<key>][...]` request shape. No new route. |
| `app/Views/admin/fee_tariff/index.php` | Rewritten into two sections: (1) the original `fee_tariffs` list (unchanged markup, kept under a new "۱. تعرفهٔ پایه به ازای نوع خدمت / دهک" heading); (2) a new "۲. نرخ‌نامهٔ گروهی (گروه‌های دهک + آیتم‌های خدمت)" section that renders one Bootstrap card per `fee_groups` row. Each card shows the group's code/label/decile range/active badge/sort, a table of all items, the per-group total in both the header badge and the table `<tfoot>`, and an inline "add item" form. A `<script>` block at the end of the page intercepts each `.fee-item-add-form` submit, reads the user-typed `item_key`, validates it against the same `[A-Za-z][A-Za-z0-9_]{0,49}` pattern the controller enforces, then constructs a hidden form with `items[<key>][label|amount|sort]` + `group_id` + `_csrf` and submits it. |

## How the inline "add item" form works (no new route)

The existing `FeeTariffController::itemsSave()` (Phase 20 / v1.9.2) accepts
a `group_id` + an `items` array of `{item_key: {label, amount, sort, notes?}}`.
PHP auto-parses form-encoded nested keys like `items[my_key][label]=foo` into
that exact nested array shape. The new "add item" form uses `data-field=...`
attributes (no `name=`) so the JS handler can dynamically build the proper
nested field names from the user-typed `item_key` before submission. A hidden
temp form is created in JS, populated with `group_id`, the nested `items[...]`
fields, and the CSRF token, then submitted as a regular POST. This reuses the
existing endpoint and existing validation, with zero new server-side code.

## Test results

- `php -l` on all six modified files: clean (no syntax errors).
- `php tests/run.php`: 12 unit tests PASS (ContainerTest, HashTest,
  LicenseAnchorsTest, NormalizerTest, PatternMatcherTest, RouterTest,
  SettingsControllerValidationTest, SseControllerTest, StructuredLoggerTest,
  TrackScraperTest, ValidatorTest, ContainerTest) + 1 SKIP
  (MigrateFromGatewayTest, v2 source not available). The 23 reported
  failures are all `SQLSTATE[HY000] [2002] Connection refused` from
  DB-backed tests — the sandbox has no MySQL running, so those failures
  are pre-existing and not caused by these changes (they would fail on a
  clean checkout of `main` too).

## No version bump

Per the task spec, the `VERSION` file and `CHANGELOG.md` are untouched.
