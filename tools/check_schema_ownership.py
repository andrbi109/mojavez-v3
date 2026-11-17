#!/usr/bin/env python3
"""
Schema ownership matrix check for mojavez-v3.

Per docs/SCHEMA_OWNERSHIP.md §5: every migration that touches a table owned
by another module must fail. CI runs this check before `php scripts/migrate.php --all`.

This Phase 1 version declares the full 35-table ownership matrix (from
docs/SCHEMA_OWNERSHIP.md §2) so future migrations are validated from day one.
"""
import re
import sys
import pathlib

OWNERSHIP = {
    # Core-owned tables
    "users": "Core",
    "persons": "Core",
    "applicants": "Core",
    "service_types": "Core",
    "job_details": "Core",
    "property_details": "Core",
    "documents": "Core",
    "document_types": "Core",
    "status_log": "Core",
    "postal_code_changes": "Core",
    "applicant_field_values": "Core",
    "license_access_requests": "Core",
    "payments": "Core",
    "payment_audit_log": "Core",
    "license_files": "Core",
    "user_sms_entries": "Core",
    "applicant_revisions": "Core",
    "user_notifications": "Core",
    "fee_tariffs": "Core",
    "sessions": "Core",
    "settings": "Core",
    "persian_date_cache": "Core",
    "email_queue": "Core",

    # NationalGateway-owned tables
    "national_licenses": "NationalGateway",
    "national_lookups": "NationalGateway",
    "sync_audit_log": "NationalGateway",

    # Shared-owned tables
    "integration_log": "Shared",

    # Api-owned tables
    "api_keys": "Api",
    "api_access_log": "Api",

    # Reporting-owned tables
    "report_runs": "Reporting",

    # Queue-owned (meta) tables
    "jobs": "Queue",
    "batches": "Queue",
    "events": "Queue",
    "worker_state": "Queue",

    # MigrationRunner meta table
    "migrations": "Shared",
}


def module_of_migration_path(path: pathlib.Path) -> str:
    """For app/Modules/<Module>/migrations/foo.sql, return <Module>."""
    # path = .../app/Modules/<Module>/migrations/foo.sql
    parts = path.parts
    if "Modules" in parts:
        idx = parts.index("Modules")
        return parts[idx + 1] if idx + 1 < len(parts) else "?"
    return "?"


def tables_touched(sql: str) -> list[str]:
    """Extract table names from CREATE TABLE / ALTER TABLE statements."""
    tables: list[str] = []
    # CREATE TABLE [IF NOT EXISTS] `?name`?
    for m in re.finditer(
        r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?",
        sql, re.IGNORECASE,
    ):
        tables.append(m.group(1))
    # ALTER TABLE `?name`?
    for m in re.finditer(
        r"ALTER\s+TABLE\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?",
        sql, re.IGNORECASE,
    ):
        tables.append(m.group(1))
    # DROP TABLE [IF EXISTS] `?name`?
    for m in re.finditer(
        r"DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?",
        sql, re.IGNORECASE,
    ):
        tables.append(m.group(1))
    return tables


def check_migration(path: pathlib.Path) -> list[str]:
    """Returns a list of error messages for this migration file."""
    module = module_of_migration_path(path)
    errors: list[str] = []
    sql = path.read_text(encoding="utf-8")
    for table in tables_touched(sql):
        owner = OWNERSHIP.get(table)
        if owner is None:
            # Unknown table — soft warning only.
            continue
        if owner.lower() != module.lower():
            errors.append(
                f"{path}: table '{table}' is owned by module "
                f"'{owner}', not '{module}'"
            )
    return errors


def main() -> int:
    root = pathlib.Path(__file__).resolve().parent.parent
    migrations_dir = root / "app" / "Modules"
    if not migrations_dir.is_dir():
        print("no modules dir found", file=sys.stderr)
        return 0

    migration_files = sorted(migrations_dir.glob("*/migrations/*.sql"))
    if not migration_files:
        print("OK: no migrations yet (Phase 1 bootstrap marker expected)")
        return 0

    all_errors: list[str] = []
    for path in migration_files:
        errors = check_migration(path)
        all_errors.extend(errors)
        rel = path.relative_to(root)
        if errors:
            for e in errors:
                print(f"  FAIL  {e}")
        else:
            print(f"  PASS  {rel}")

    if all_errors:
        print(f"\n{len(all_errors)} ownership violation(s).", file=sys.stderr)
        return 1

    print(f"\n{len(migration_files)} migration(s) checked — all respect the ownership matrix.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
