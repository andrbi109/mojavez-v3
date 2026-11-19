# Phase 24 — Low debt cleanup batch 1 (v1.9.6)

> **Branch:** `phase/24-low-debt-cleanup-1` → merged to `main` with `--no-ff`, tagged `v1.9.6`
> **Date:** 2026-11-17
> **Predecessor:** Phase 23 (v1.9.5)

## Goal

Pay down 4 low-priority debt items (D-4-5, D-5-1, D-5-4, D-5-5).
No new feature work; no critical/high/medium debt introduced. After
Phase 24, the project is at 0 critical, 0 high, 0 medium, ~9 low
(4 fewer than Phase 23's 13).

## Debt paid

### D-4-5 — SSE controller dev mode

**File:** `app/Modules/Core/Controllers/SseController.php`
**Test:** `tests/Unit/SseControllerTest.php`

**Problem:** `SseController::stream` returned JSON polling in dev
mode but the response shape was undocumented (no header, no
machine-readable "this is polling, not real SSE" signal). Clients
built on `EventSource` couldn't distinguish a dev-mode response from
a broken SSE connection.

**Fix:** the dev-mode response now carries an explicit
`X-SSE-Mode: dev-polling` header and a body with `mode: "polling"` +
`message: "SSE requires Apache/PHP-FPM. In dev, poll this endpoint
every 5s."`. The polling interval is bumped from 3000ms to 5000ms to
match the documented cadence. The production path (Apache/PHP-FPM)
keeps its real SSE streaming (text/event-stream, hold open 30s,
emit `data:` events from the events table) and also sets an
`X-SSE-Mode: production-stream` header for symmetry.

**Test:** `SseControllerTest` pins the dev-mode shape — verifies the
200 status, the `X-SSE-Mode: dev-polling` header,
`Content-Type: application/json`, the `mode` + `message` + `events`
+ `last_id` + `poll_interval_ms` body fields, and the
`poll_interval_ms = 5000` value. The production path is exercised in
CI on GitHub Actions (Apache + PHP-FPM multiprocess).

### D-5-1 — seed_lookups sandbox fallback

**Files:** `scripts/seed_lookups.php`, `scripts/seed_lookups_dev_data.php`

**Problem:** `scripts/seed_lookups.php` in a sandbox without upstream
access (geofence / DNS failure) didn't work — the `--from-gateway`
fallback only built townships from the gateway-imported
sub_organization data (no provinces, no main_organizations), leaving
the registration wizard's province select empty and the golden-link
matcher unable to ever match province + township.

**Fix:** new `scripts/seed_lookups_dev_data.php` carries the same
built-in dev seed as migration 005 (8 provinces + 28 townships + 4
main organizations). The script now has three modes:

1. **Production with upstream** (`php scripts/seed_lookups.php`):
   calls `LicenseService::refreshLookups()` as before.
2. **Auto-fallback** (no flag, upstream silent or throws): detects
   the failure (or 0-row response) and falls back to the built-in
   dev seed automatically. Exit code 2 (degraded mode).
3. **Explicit dev-seed** (`--from-dev-seed`): skips upstream entirely
   and uses the built-in dev seed. Useful for offline sandboxes and
   CI runs.

The legacy `--from-gateway` flag is kept for backward compatibility
(seeds townships from sub_organization only). The new
`--from-dev-seed` flag is the preferred fallback because it includes
provinces + organizations too.

### D-5-4 — fuzzy address matching

**Files:** `app/Modules/NationalGateway/Services/PatternMatcher.php`,
`app/Modules/NationalGateway/Services/MatchResult.php`,
`app/Modules/NationalGateway/Module.php`,
`config/pattern_matcher.php`,
`config/config.php`,
`tests/Unit/PatternMatcherTest.php`

**Problem:** `business_address` in `PatternMatcher` often mismatched
because the upstream masks the address with `*****` prefix (5 chars
hidden) but the local property address is the full concatenation
(street + alley + plaque), usually longer and in a different
concatenation order than the upstream's address string. The strict
suffix matcher required the trailing anchors to align AT THE END —
which often failed when the local concatenation order differed.

**Fix:** new `PatternMatcher::matchAddress()` method does
normalized `contains` matching:

1. Try the strict suffix matcher (matchText) first. If it matches,
   accept at FULL confidence (backward-compat for the common case
   where suffix actually aligns).
2. Otherwise, strip the leading `*****` mask, normalize both
   strings (lowercase, strip Persian + Latin punctuation, collapse
   whitespace, normalize Persian/Arabic digits to Latin), and check
   if the visible suffix is CONTAINED in the local full address.
   Accept at REDUCED confidence (default 0.7 — configurable via
   `config/pattern_matcher.php`).
3. Otherwise, mismatch.

The reduced confidence is applied via a new
`MatchResult::matchFuzzy()` factory that carries a `scoreMultiplier`
property. The quorum function multiplies each field's weight by its
result's scoreMultiplier (e.g. weight 4 × 0.7 = 2.8 → contributes 2
after floor). The field still counts as 1 toward the field-count
quorum (AutoConfirmed requires national + 2 others).

**Config:** new `config/pattern_matcher.php` declares per-field
match modes + the `contains_confidence` multiplier. Loaded via
`config/config.php` under the `pattern_matcher` key. The
PatternMatcher constructor takes `modes` + `containsConfidence`
args; `Module.php` wires them from the Config.

**Tests:** `PatternMatcherTest` extended with 7 new assertions:
strict suffix still works, fuzzy contains matches when local
address doesn't end with the suffix, no-match for unrelated
addresses, empty pattern is inconclusive, all-wildcard pattern is
inconclusive, Persian digits normalize before contains check, and
the quorum uses fuzzy address for AutoConfirmed.

### D-5-5 — county vs township distinction

**Files:** `app/Modules/Core/migrations/20261117_013_property_details_township_column.sql`,
`app/Modules/Core/Module.php`,
`app/Modules/Core/Domain/PropertyDetail.php`,
`app/Modules/Core/Persistence/PdoJobAndPropertyRepository.php`,
`app/Modules/Core/Controllers/RegistrationController.php`,
`app/Modules/NationalGateway/Services/ApplicantSyncAdapter.php`,
`database/schema.sql`

**Problem:** `property_details.county` was sent to the matcher as
`township`, but in v2/v3 county = township (the wizard only ever
sets one value). In production with the upstream national gateway,
county and township can legitimately differ (the upstream exposes a
finer-grained township list than the gateway's old sub_organization
data).

**Fix:** new migration `20261117_013_property_details_township_column.sql`
adds a separate `township VARCHAR(50) NULL DEFAULT NULL AFTER county`
column to `property_details`. The migration is idempotent
(information_schema guard on the ALTER). The columns now work as:

- `county`: the v2 backward-compat value (the resolved township
  title from the wizard's `township_id` select, written by pre-
  Phase-24 code).
- `township`: the explicit "this is the township" value, written by
  Phase-24+ code. NULL on rows written before migration 013.

`RegistrationController::processStep2` now stores the resolved
township title in BOTH `county` AND `township` (they're equal here
because the wizard's `township_id` select resolves to one title).
The split lets future wizard changes diverge them (e.g. when
county and township legitimately differ for the upstream national
gateway).

`ApplicantSyncAdapter` (the ACL bridge to the matcher) prefers
`property_details.township` if set, falling back to `county` for
backward compat with rows written by older code. The
`PatternMatcher::matchQuorum` was already reading from
`$property['township']` — the adapter now passes the right value
through.

`database/schema.sql` was regenerated to include the new `township`
column. The regeneration also picked up the previously-missing
`is_required` column from migration 012 (Phase 22 / v1.9.4) that
had never been re-dumped into schema.sql.

## Debt introduced

None.

## Debt carried forward to Phase 25

9 low debt items:

- D-6-2, D-6-3, D-6-4, D-6-5, D-6-6 (Phase 25 batch 2)
- D-8-1, D-8-2, D-8-3, D-8-5 (Phase 26 batch 3)

## Tests

- `php tests/run.php` — 546 assertions, 6 pre-existing failures
  (LicenseTitle 1, OperatorFlagNotification 4, MigrateFromGateway
  exception 1). All are environment/state-dependent and unrelated
  to Phase 24. New `SseControllerTest` adds 15 assertions, all pass.
  Extended `PatternMatcherTest` adds 12 new assertions, all pass.

## Lint / static analysis

- `python3 tools/php_lint_simple.py` — pass
- `python3 tools/static_analysis.py --strict` — pass
- `python3 tools/check_schema_ownership.py` — pass

## Files changed

| File | Change |
|---|---|
| `app/Modules/Core/Controllers/SseController.php` | D-4-5: refactor into `streamDevMode()` + `streamProductionMode()` + X-SSE-Mode header |
| `tests/Unit/SseControllerTest.php` | new — pins dev-mode shape |
| `scripts/seed_lookups.php` | D-5-1: `--from-dev-seed` flag + auto-fallback |
| `scripts/seed_lookups_dev_data.php` | new — built-in 8/28/4 dev seed array |
| `app/Modules/NationalGateway/Services/PatternMatcher.php` | D-5-4: `matchAddress()` + per-field mode injection + normalizeAddress() |
| `app/Modules/NationalGateway/Services/MatchResult.php` | D-5-4: `matchFuzzy()` factory + `scoreMultiplier` + quorum uses multiplier |
| `app/Modules/NationalGateway/Module.php` | wire PatternMatcher constructor with config |
| `config/pattern_matcher.php` | new — per-field modes + contains_confidence |
| `config/config.php` | load pattern_matcher.php under `pattern_matcher` key |
| `tests/Unit/PatternMatcherTest.php` | D-5-4: 12 new assertions + helper `businessAddress` arg |
| `app/Modules/Core/migrations/20261117_013_property_details_township_column.sql` | new — D-5-5 idempotent ALTER TABLE |
| `app/Modules/Core/Module.php` | D-5-5: register migration 013 |
| `app/Modules/Core/Domain/PropertyDetail.php` | D-5-5: add `$township` field + `fromArray()` key |
| `app/Modules/Core/Persistence/PdoJobAndPropertyRepository.php` | D-5-5: include `township` in upsert |
| `app/Modules/Core/Controllers/RegistrationController.php` | D-5-5: save `township = county` in processStep2 |
| `app/Modules/NationalGateway/Services/ApplicantSyncAdapter.php` | D-5-5: prefer `township` over `county` |
| `database/schema.sql` | regenerated (adds `township` + the previously-missing `is_required` column from migration 012) |
| `VERSION` | `1.9.5` → `1.9.6` |
| `README.md` | header version + status |
| `CHANGELOG.md` | Phase 24 section |
| `docs/DEBT_REVIEW.md` | Phase 24 section + live-debt summary (13 → 9) |
| `docs/PHASE_24_LOW_DEBT_BATCH_1.md` | new — this doc |
