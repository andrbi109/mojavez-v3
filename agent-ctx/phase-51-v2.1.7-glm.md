# Phase 51 (v2.1.7) — docs modal + print thumbnails + status-cycle link removal

Task ID: `phase-51-v2.1.7`
Branch: `phase/51-docs-modal-print-links` (forked from `ad8ba6e` on `phase/50-timezone-payment-dates`)
Project: `mojavez-v3` (pure-PHP modular monolith — PHP 8.2, MariaDB, no Composer)

## Scope

Five user-facing improvements + a version bump:

1. **Complete document upload in `admin/register.php`** — replaced the
   legacy 5 plain `<input type=file>` boxes with the v2-style
   `.upload-box` clickable upload boxes used everywhere else in v3
   (registration/step4, view_application, print_single, edit_application).
   The new admin form now exposes the same set of document fields as
   the public wizard:
     - `national_card_front`, `national_card_back`, `birth_cert`,
       `ownership_doc`, `vocational_cert`, `education_degree`,
       `product_photos[]` (15 inputs), `optional_1..3`.
   The controller's `AdminController::handleDocumentUploads()` was
   widened to match: it iterates the 9 single-file fields AND the
   `product_photos[]` multi-file array (PHP flattens the array upload
   to `$_FILES['product_photos']['name'][i]` etc., so each entry is
   reassembled into the single-file shape `FileStorage::storeUpload`
   expects). The legacy 5 field names (`national_id`, `personal_photo`,
   `ownership_deed`, `skill_certificate`, `optional_document`) are no
   longer emitted by the form nor handled by the controller.

2. **Removed all "مشاهدهٔ چرخهٔ کامل" links** from admin views.
   - `view_application.php`: dropped the `<a href="admin/status-cycle"
     target=_blank>مشاهدهٔ چرخهٔ کامل</a>` button next to the
     status-change submit button.
   - `dashboard.php`: dropped the `<a href="/admin/status-cycle"
     class="btn btn-outline-primary">چرخهٔ ۱۸ وضعیت</a>` quick-action
     button.
   - `admin/status_cycle.php`: the standalone admin page no longer
     renders the 18-status diagram (it was already duplicated in
     `guide.php` by Phase 41). It now shows a "moved to /guide#status-cycle"
     notice + a JS redirect. The `/admin/status-cycle` route and
     `AdminController::statusCycle()` are kept so old bookmarks still
     resolve (no 404) but the diagram itself only lives in `guide.php`.
   The cycle still appears in `guide.php#status-cycle` (the public help
   page) — unchanged.

3. **Document viewer modal in `view_application.php`** — clicking a
   document thumbnail now opens a Bootstrap modal (`#docViewerModal`)
   instead of jumping to `/document/inline/{id}` in a new tab. The
   modal body is swapped by JS based on the doc type:
     - image → `<img class="img-fluid w-100">`
     - PDF   → `<iframe width="100%" height="500px">` (browser PDF viewer)
     - other → centered "no preview" notice + a download button
   Each thumbnail carries `data-doc-*` attributes (id, is-image, is-pdf,
   name, label, url). The modal footer always wires a "Download" button
   to `/document/inline/{id}` so the admin can save the file regardless
   of type. On `hidden.bs.modal` the body is cleared so the previous
   iframe doesn't keep loading in the background.

4. **Print page document thumbnails** in `print_single.php` — the
   documents section previously listed each file as a single row of
   text (label + name + date). It now renders a 5-per-row flex grid of
   130×150px thumbnails:
     - images → `<img src="/document/thumb/{id}"
       style="width:130px;height:150px;object-fit:cover;margin:0.5rem;">`
     - PDFs / other → a same-sized placeholder box with a `PDF` / `FILE`
       marker (no thumbnail — print view cannot embed PDFs as images).
   Each thumbnail tile also carries the doc-type label, original
   filename and upload date below the image so the print output stays
   self-documenting. New CSS classes: `.doc-thumbs`, `.doc-thumb`,
   `.doc-thumb-fallback`, `.doc-thumb-meta`, `.doc-thumb-type`,
   `.doc-thumb-name`, `.doc-thumb-date` — added to the existing inline
   `<style>` block. The legacy `.doc-list` CSS is left in place (other
   print variants may still use it).

5. **Removed the `border-bottom border-secondary` div** — searched all
   `app/Views/` for the exact pattern
   `p-3 d-flex align-items-center gap-2 border-bottom border-secondary
   border-opacity-25`. Found exactly one match in `user_layout.php`
   (the sidebar header div containing the brand icon + "پنل کاربری"
   label). Removed the entire div; the `<aside class="moj-user-sidebar">`
   now starts directly with the `<nav>`.

6. **Version bump** to 2.1.7 — `VERSION` and `README.md` updated
   (header line + final-status line).

## Files changed

| File | Change |
|------|--------|
| `VERSION` | 2.1.6 → 2.1.7 |
| `README.md` | 2.1.6 → 2.1.7 (2 occurrences) |
| `app/Views/admin/register.php` | Replaced 5 plain file inputs with v2-style `.upload-box` (7 named single + 15 product photos + 3 optional). Added `mojShowFileName` JS helper (mirror of step4.php). Updated file header docblock. |
| `app/Modules/Core/Controllers/AdminController.php` | `handleDocumentUploads()` now iterates 9 v2-style single-file fields + the `product_photos[]` array. `statusCycle()` simplified to a redirect-notice render (no cycle data passed). Updated docblocks. |
| `app/Views/admin/view_application.php` | Thumbnails now trigger a `#docViewerModal` Bootstrap modal (image inline / PDF iframe / other as download link). Removed "مشاهدهٔ چرخهٔ کامل" link. Added modal HTML + JS. |
| `app/Views/admin/dashboard.php` | Removed "چرخهٔ ۱۸ وضعیت" quick-action button. |
| `app/Views/admin/status_cycle.php` | Replaced the 18-status diagram with a "moved to /guide#status-cycle" notice + JS redirect. |
| `app/Views/admin/print_single.php` | Replaced `.doc-list` text rows with `.doc-thumbs` 5-per-row grid of 130×150px image thumbnails (or filename-only fallback for PDFs/other). Added `.doc-thumbs/.doc-thumb/.doc-thumb-fallback/.doc-thumb-meta` CSS. |
| `app/Views/user_layout.php` | Removed the sidebar header div with `border-bottom border-secondary border-opacity-25`. |

## Verification

- `php -l` passes on all 6 modified PHP files (PHP 8.4 CLI).
- `python3 tools/static_analysis.py` exits 0 — warnings unchanged
  (pre-existing false positives about `<?= $i ?>` integer loop
  counters; the new `product_photos[]` loop in `register.php` adds the
  same `<?= $i ?>` pattern that already exists in step4.php).
- `python3 tools/php_lint_simple.py` failure count is unchanged
  (17 → 17) — the bracket-counting tool gives false positives on
  templates with embedded JS/CSS; this is a known limitation.
- Manual grep confirmations:
  - `rg "p-3 d-flex align-items-center gap-2 border-bottom border-secondary border-opacity-25" app/Views/` → 0 hits.
  - `rg "status.cycle|status_cycle|status-cycle|چرخه" app/Views/` → only docblock comments, the status-change card label `<strong>…چرخهٔ ۱۸ وضعیت…</strong>` (NOT a link), and `guide.php` (the allowed location).

## Notes for the next agent

- The `/admin/status-cycle` route is intentionally kept (not removed) so
  any old bookmark or external link still resolves. The page just
  redirects to `/guide#status-cycle`. If a future phase wants to fully
  retire the route, also remove the entry from
  `app/Modules/Core/Module.php:301` and the `statusCycle()` method in
  `AdminController.php`.

- The `mojShowFileName` helper added to `admin/register.php` is a
  local copy of the one in `registration/step4.php`. If a third caller
  appears, consider promoting it to `public/assets/js/app.js`.

- The print view (`print_single.php`) does NOT load Bootstrap-Icons
  (it's a self-contained A4 print page). The new `.doc-thumb-fallback`
  uses plain text markers (`PDF` / `FILE`) instead of `<i class="bi
  bi-…">` to keep the print output offline-friendly.

- The admin register form's `$serviceTypes` dropdown still drives the
  license-title dropdown via the existing
  `/admin/license-titles/by-service/{id}` JSON endpoint — unchanged by
  this phase.
