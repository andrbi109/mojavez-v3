# Changelog — mojavez-v3

All notable changes to mojavez-v3 will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

`APP_VERSION` in `config/config.php`, the `VERSION` file, and the `README.md`
header all read from a single source of truth — this file's latest release tag.
This is the fix for the version three-way split seen in v2 (see
`docs/03-strategy-and-risk.md` §2.3).

## [Unreleased]

### Added — Phase 1 (Core & Architecture)
- Repository scaffold: `README.md`, `LICENSE` (MIT), `.gitignore`,
  `.editorconfig`, `.gitattributes`, `VERSION` (`0.1.0`), `CHANGELOG.md`.
- Engineered documentation bundle ported into `docs/` (00-MASTER, 01-v2-ref,
  02-gateway-ref, 03-strategy, 04-comparison, SCHEMA_OWNERSHIP,
  CODING_CONVENTIONS, integration-contract, DEBT_REVIEW).
- `app/Modules/ModuleInterface` and `app/Modules/ModuleLoader` — the formal
  module contract (`name`, `version`, `register`, `boot`, `routes`,
  `migrations`, `subscribers`, `ownedTables`).
- `app/Shared/Support/StructuredLogger` — JSON line-by-line structured logs
  with `_module` context, levels, `metric()`, and `request_id`.
- `app/Shared/Support/Environment` — `.env` loader (no Composer).
- `app/Shared/Support/Config` — array-returning config files (no `define()`).
- `app/Shared/Support/Normalizer`, `PersianDate`, `Hash`, `Validator`,
  `CsvReader` (ported & extended from both predecessors).
- `app/Container` — manual DI with autowire + memoized singletons + factory
  closures + setter injection (for cyclic dependency resolution, fix 003-01).
- `app/Shared/Persistence/Connection` — MySQL PDO memoized singleton,
  `EMULATE_PREPARES=false`, `+00:00` timezone.
- `app/Shared/Persistence/MigrationRunner` — scans `app/Modules/*/migrations/`,
  uses `migrations` table with `checksum`, atomic per migration.
- `app/Http` layer — `Request`, `Response`, `Router` (first-match, `/?` regex),
  `Session`, `Csrf` (always on, fix S1), `Exceptions/*`.
- `public/index.php` front controller + security headers + IP blocklist +
  `/health`, `/check-cancel`, `/check-sync`.
- `public/.htaccess` three-layer (root / public / uploads).
- `database/schema.sql` reference + `database/install.sql` (idempotent,
  auto-generated from schema.sql).
- First migration `app/Modules/Core/migrations/20261117_001_create_migrations_table.sql`
  + `app/Modules/Core/Module.php`.
- Unit tests for core utilities (Container, Router, Normalizer, StructuredLogger).
- `.github/workflows/ci.yml` — GitHub Actions pipeline.

## [0.1.0] — Phase 1 release

Initial scaffold release. Phase 1 (Core & Architecture) complete: modular
kernel, DI container, migration runner, structured logger, HTTP layer, MySQL
connection, security headers, `/health` endpoint.
