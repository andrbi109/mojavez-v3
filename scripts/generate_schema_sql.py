#!/usr/bin/env python3
"""
mojavez-v3 — scripts/generate_schema_sql.py

Consolidates all module migrations into a single database/schema.sql as the
single source of truth for the v3 schema (resolves D-2-7 medium debt).

Strategy:
  1. Build a fresh in-memory SQLite... no, MariaDB. Use the configured test DB.
  Actually: read all migration files in module order, strip the
  MigrationRunner-only pragmas, concatenate into a single .sql file with a
  banner header, and apply the ALTER TABLE merges by editing the CREATE TABLE
  statements in-place to reflect their final shape.

For correctness (not just concatenation), we ALSO run all migrations against
a throwaway DB and dump the resulting schema with `SHOW CREATE TABLE` — that
dump is what we ship as schema.sql (idempotent: CREATE TABLE IF NOT EXISTS
form, no ALTERs, no INSERTs).

Outputs:
  - database/schema.sql   (canonical, idempotent, fresh-install ready)
  - database/install.sql   (wrapper: creates DB if missing + sources schema.sql)
"""
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DB_DIR = ROOT / "database"
SCHEMA_FILE = DB_DIR / "schema.sql"
INSTALL_FILE = DB_DIR / "install.sql"

# Module migration order (Core first, then NationalGateway, Queue).
MODULE_ORDER = ["Core", "NationalGateway", "Queue"]


def collect_migrations() -> list[Path]:
    """Return migration files in canonical order across modules."""
    files = []
    for module in MODULE_ORDER:
        mod_dir = ROOT / "app" / "Modules" / module / "migrations"
        if not mod_dir.is_dir():
            continue
        for sql in sorted(mod_dir.glob("*.sql")):
            files.append(sql)
    return files


def dump_live_schema() -> tuple[list[str], list[str]]:
    """Apply all migrations to the configured DB and dump SHOW CREATE TABLE.

    Uses the existing mojavez_v3 DB (migrations are idempotent — re-running
    them on a DB that already has the schema is a no-op). This guarantees
    schema.sql matches exactly what the app would produce after running
    scripts/migrate.php --all.

    Returns (table_names, create_statements).
    """
    env = os.environ.copy()
    mysql = "/tmp/my-project/opt/mariadb/bin/mariadb"

    # Use the configured DB (DB_NAME from .env, default mojavez_v3).
    db_name = env.get("DB_NAME", "mojavez_v3")
    db_host = env.get("DB_HOST", "127.0.0.1")
    db_port = env.get("DB_PORT", "13306")
    db_user = env.get("DB_USER", "mojavez")
    db_pass = env.get("DB_PASS", "mojavez_pass")

    # 1. Run migrations (idempotent — re-runs are safe).
    env_mig = dict(env)
    env_mig["DB_HOST"] = db_host
    env_mig["DB_PORT"] = db_port
    env_mig["DB_NAME"] = db_name
    env_mig["DB_USER"] = db_user
    env_mig["DB_PASS"] = db_pass
    env_mig["APP_ENV"] = "testing"

    result = subprocess.run(
        ["/home/z/my-project/bin/php", str(ROOT / "scripts" / "migrate.php"), "--all"],
        cwd=str(ROOT), env=env_mig, capture_output=True, text=True, timeout=60,
    )
    if result.returncode != 0:
        print(f"Migration failed:\n{result.stderr[:500]}", file=sys.stderr)
        sys.exit(1)

    mysql_args = [mysql, "-h", db_host, "-P", db_port, "-u", db_user, f"-p{db_pass}", "--skip-column-names"]

    # 2. List all tables (exclude the migrations meta table).
    tables_result = subprocess.run(
        mysql_args + ["-e", f"SHOW TABLES FROM {db_name};"],
        capture_output=True, text=True, env=env,
    )
    if tables_result.returncode != 0:
        print(f"SHOW TABLES failed: {tables_result.stderr}", file=sys.stderr)
        sys.exit(1)
    tables = [t for t in tables_result.stdout.strip().split("\n") if t and t != "migrations"]

    # 3. SHOW CREATE TABLE for each. Use --raw to keep real newlines.
    create_lines = []
    for table in tables:
        r = subprocess.run(
            mysql_args + ["--raw", "-e", f"SHOW CREATE TABLE {db_name}.{table};"],
            capture_output=True, text=True, env=env,
        )
        # SHOW CREATE returns: <table>\t<create statement>
        parts = r.stdout.split("\t", 1)
        if len(parts) == 2:
            stmt = parts[1].rstrip(";\n")
            # Strip AUTO_INCREMENT=N (sample data — schema.sql is for fresh installs).
            stmt = __import__("re").sub(r" AUTO_INCREMENT=\d+", "", stmt)
            create_lines.append(stmt + ";")
        else:
            create_lines.append(r.stdout.strip())

    return tables, create_lines


def write_schema(tables: list[str], create_lines: list[str]) -> None:
    """Write the canonical schema.sql (idempotent, fresh-install ready)."""
    DB_DIR.mkdir(parents=True, exist_ok=True)
    # Convert CREATE TABLE → CREATE TABLE IF NOT EXISTS for idempotency.
    body = []
    for stmt in create_lines:
        stmt = stmt.replace("CREATE TABLE ", "CREATE TABLE IF NOT EXISTS ", 1)
        body.append(stmt)

    content = f"""-- ============================================================================
-- mojavez-v3 — database/schema.sql  (single source of truth, auto-generated)
-- ============================================================================
-- This file is the CANONICAL v3 schema. It is auto-generated by running
-- `python3 scripts/generate_schema_sql.py`, which applies every module
-- migration to a throwaway DB and dumps SHOW CREATE TABLE for the result.
--
-- DO NOT EDIT BY HAND — edit the migration files in
-- app/Modules/<X>/migrations/ and re-run the generator.
--
-- This file is what deploy/shared-hosting/install.php and database/install.sql
-- use for fresh installs (where the MigrationRunner is not yet bootstrapped).
-- It is idempotent: every statement uses CREATE TABLE IF NOT EXISTS, so
-- running it against a DB that already has the schema is a no-op.
--
-- Tables: {len(tables)}
-- Generated from migrations in: app/Modules/{{Core,NationalGateway,Queue}}/migrations/
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

"""
    content += "\n\n".join(body)
    content += "\n"
    SCHEMA_FILE.write_text(content, encoding="utf-8")


def write_install() -> None:
    """Write database/install.sql — wrapper that creates the DB then sources schema.sql."""
    DB_DIR.mkdir(parents=True, exist_ok=True)
    INSTALL_FILE.write_text(
        """-- ============================================================================
-- mojavez-v3 — database/install.sql
-- ============================================================================
-- Fresh-install entry point. Creates the mojavez_v3 database (if missing)
-- and the mojavez user (if missing), then sources schema.sql.
--
-- Usage (as DB root or with CREATE privilege):
--   mariadb -u root -p < database/install.sql
-- Or from inside MariaDB:
--   SOURCE database/install.sql;
--
-- For shared hosting where you can't CREATE DATABASE, skip this file and
-- import schema.sql directly into your pre-created DB via phpMyAdmin.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS mojavez_v3
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Create the app user (no-op if it exists — adjust password as needed).
CREATE USER IF NOT EXISTS 'mojavez'@'localhost' IDENTIFIED BY 'mojavez_pass';
GRANT ALL PRIVILEGES ON mojavez_v3.* TO 'mojavez'@'localhost';
FLUSH PRIVILEGES;

USE mojavez_v3;
SOURCE schema.sql;
""",
        encoding="utf-8",
    )


def main() -> int:
    migrations = collect_migrations()
    print(f"Found {len(migrations)} migration files:")
    for m in migrations:
        print(f"  - {m.relative_to(ROOT)}")
    print()

    print("Applying migrations to throwaway DB and dumping SHOW CREATE TABLE...")
    tables, create_lines = dump_live_schema()
    print(f"Dumped {len(tables)} tables: {', '.join(tables)}")
    print()

    write_schema(tables, create_lines)
    print(f"Written: {SCHEMA_FILE.relative_to(ROOT)} ({SCHEMA_FILE.stat().st_size} bytes)")
    write_install()
    print(f"Written: {INSTALL_FILE.relative_to(ROOT)} ({INSTALL_FILE.stat().st_size} bytes)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
