-- ============================================================================
-- mojavez-v3 — Core module migration 001
-- Bootstrap marker (the `migrations` meta table is created by
-- MigrationRunner::ensureMigrationsTable() before any migration runs).
--
-- This file exists so that the MigrationRunner records Phase 1's bootstrap
-- as an applied migration in the migrations table itself (creating an
-- audit trail of when each module was first introduced on a given
-- installation).
--
-- It intentionally does NOT create any business table — those come in
-- Phase 2 (002_init_core_tables.sql through 008_*).
-- ============================================================================

-- No-op migration. Real schema work begins in Phase 2.
SELECT 1;
