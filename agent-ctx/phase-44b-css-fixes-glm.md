# Phase 44b — CSS / layout fixes

Task ID: `phase-44b-css-fixes`
Branch: `phase/44b-css-fixes` (off `main` @ `56f9031`)
Agent: `glm`

## Summary

Four user-reported CSS/layout issues fixed across the public + admin + user
panels. No version bump (per task spec). All changes are CSS/HTML only — no
PHP logic, no DB schema, no routes touched.

## Files modified

| File | Change |
| --- | --- |
| `public/assets/css/app.css` | Theme-toggle button restyled; `.moj-topbar` block + `.moj-content-offset-*` utilities added; admin sidebar `top`/`height` re-anchored below topbar via `--moj-topbar-h` token. |
| `app/Views/admin_layout.php` | New `.moj-topbar` at top of `<body>` (panel title + theme toggle + admin name + logout). Theme-toggle button removed from sidebar bottom; admin display-name block removed from sidebar top; the `حساب → خروج` sidebar nav group removed (logout now lives in topbar). Main-content page title row kept as-is. |
| `app/Views/user_layout.php` | New `.moj-topbar` at top of `<body>` (panel title + bell + theme toggle + display name + logout). User info card removed from sidebar (name + mobile moved to topbar); `خروج` button removed from sidebar footer. The old sticky `.moj-user-topbar` (page title + bell) replaced by a non-sticky `.moj-user-pagehead` (page title only) inside `.moj-user-main` — eliminates the sticky-on-sticky overlap that occurred when scrolling. Inline `<style>` updated: user-sidebar `top: 0` → `top: var(--moj-topbar-h)`, mobile override resets `top: auto`. Bootstrap bundle + `app.js` now included (previously missing — the new theme toggle button would not have worked without `app.js`). |
| `app/Views/user/tracking.php` | Layout rebuilt into three Bootstrap cards: (1) tracking-code form, (2) helper-hint list, (3) footer-like quick-actions card. Consistent `mb-4` spacing between cards. Single centered column (`col-12 col-md-8 col-lg-7`). |
| `app/Views/guide.php` | Added `moj-content-offset-guide` utility class to the right-column `<div class="col-lg-4">` (the status-cards sidebar). |
| `app/Views/about.php` | Added `moj-content-offset-about` utility class to the right-column `<div class="col-lg-4">` (the manager card). |

## CSS details (`public/assets/css/app.css`)

### `.moj-theme-toggle` (Task 1)

Old:
```css
.moj-theme-toggle {
    background: transparent;
    border: 1px solid var(--moj-border);
    color: var(--moj-text-muted);
    width: 38px; height: 38px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.15s;
}
```
New (per user spec):
```css
.moj-theme-toggle {
    padding: 5px 0 0 0;
    color: #fff;
    border-color: #fff;
    background: transparent;
    border: 1px solid #fff;
    width: 38px; height: 38px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.15s;
}
.moj-theme-toggle:hover {
    background-color: rgba(255, 255, 255, 0.12);
    color: #fff;
}
```
Identical look in day and night mode (white text + white border on
transparent bg). Hover gives a subtle white wash instead of switching to
the muted surface color.

### `.moj-content-offset-*` (Task 2)

```css
.moj-content-offset-guide  { margin: 0; }
.moj-content-offset-about  { margin: 0; }
@media (min-width: 992px) {
    .moj-content-offset-guide { margin: 5.5rem 0 0 0; }
    .moj-content-offset-about { margin: 3.6rem 0 0 0; }
}
@media (max-width: 991.98px) {
    .moj-content-offset-guide { margin: 2rem 0 0 0; }
    .moj-content-offset-about { margin: 1.5rem 0 0 0; }
}
@media (max-width: 575.98px) {
    .moj-content-offset-guide { margin: 1.25rem 0 0 0; }
    .moj-content-offset-about { margin: 1rem 0 0 0; }
}
```

Desktop (≥992px) uses the user-requested large top margins (5.5rem guide,
3.6rem about). Tablet shrinks to 2 / 1.5 rem; mobile shrinks further to
1.25 / 1 rem — so the right-column card never collides with the heading
row above it.

### `.moj-topbar` (Task 3)

```css
.moj-topbar {
    display: flex; justify-content: space-between; align-items: center;
    padding: 0.5rem 1rem;
    background: var(--moj-topbar-bg, #0f2942);
    color: #fff;
    min-height: 48px;
    position: sticky; top: 0; z-index: 1000;
    gap: 0.75rem; flex-wrap: wrap;
}
.moj-topbar a, .moj-topbar button { color: #fff; }
```
Plus child selectors for `.moj-topbar-title`, `.moj-topbar-actions`,
`.moj-topbar-user`, `.moj-topbar-logout`. The topbar uses the navy
`#0f2942` background (matches the existing user-sidebar gradient) and
sits above every panel page in a sticky position.

### `--moj-topbar-h` token + sidebar re-anchor

A `--moj-topbar-h: 48px` token is defined on `:root, [data-bs-theme="dark"]`
so it survives theme toggles. Both sidebars are re-anchored to start
*below* the topbar so they never slide under it:

- Admin sidebar (sticky): `top: 0` → `top: var(--moj-topbar-h)`;
  `height: 100vh` → `height: calc(100vh - var(--moj-topbar-h))`.
- User sidebar (fixed, inline style in `user_layout.php`):
  `top: 0` → `top: var(--moj-topbar-h)`; mobile override resets to
  `top: auto` (sidebar becomes static on mobile anyway).
- Admin body: `min-height: 100vh` → `min-height: calc(100vh - var(--moj-topbar-h))`.
- User body: same `min-height: calc(100vh - var(--moj-topbar-h))`.

## Layout behavior

### Admin panel (`/admin/*`)
- Sticky top topbar (panel title + theme + admin name + logout), full width.
- Sticky sidebar with brand + nav groups (no more theme toggle at the
  bottom, no more admin-name block at the top, no more `حساب → خروج`
  group).
- Main column: page-title row (h1 + version span) unchanged; page
  content below.

### User panel (`/panel/*`)
- Sticky top topbar (panel title + bell + theme + user name + logout),
  full width.
- Fixed sidebar with brand + nav links + footer actions (شروع ثبت‌نام
  + بازگشت به سایت). The user-info card (avatar + name + mobile) was
  removed; the name now lives in the topbar. The خروج button was
  removed; logout now lives in the topbar.
- Main column: non-sticky `.moj-user-pagehead` (page title only) at the
  top, content `.p-4` below. Removing sticky from the page-level header
  eliminates the previous overlap with the new sticky panel-level
  topbar that the user reported on `/panel/tracking`.

### Public site (`/`, `/services`, `/tracking`, `/guide`, `/about`)
- The `.moj-theme-toggle` button in the public `.moj-header` now renders
  with the unified white style — works on the teal gradient header just
  as well as on the navy topbar.

## tracking.php layout rebuild (Task 4)

Before: a single centered card containing the form + a horizontal rule
+ a one-line hint. No real overlap, but the user reported the layout
felt cramped and the (non-existent) "footer" was visually merging with
the form.

After: three stacked cards with `mb-4` between them:
1. **Form card** — same `tracking_code` input + `btn-primary` submit
   button, but now in its own card with a proper `card-header`.
2. **Helper-hint card** — short bullet list explaining where to find
   the tracking code (registration page, SMS, support).
3. **Quick-actions card** — three small buttons (`درخواست‌های من`,
   `ثبت درخواست جدید`, `بازگشت به پیشخوان`) acting as an in-content
   footer. Kept inside the content flow so it never overlaps the
   sticky top topbar.

## Verification

- No PHP available in the sandbox (no `php` binary), so `php -l` could
  not be run. Syntax was verified by careful manual re-reading of all
  six modified files; all PHP tags balance, all `<?= htmlspecialchars(...)`
  calls have matching parentheses.
- The inline `<style>` block in `user_layout.php` references
  `var(--moj-topbar-h)`, which is defined on `:root` in `app.css` —
  loaded before the inline block — so the variable resolves.
- `app.js` is now included in `user_layout.php` (was missing). Without
  it, the new theme-toggle button in the user topbar would render but
  clicking it would do nothing. Bootstrap bundle is also now included
  so collapse / dropdown / modal interactions work in the user panel.

## No version bump

Per task spec, `VERSION`, `CHANGELOG.md`, and any version constant
were not touched. The commit message explicitly notes
`No version bump.`
