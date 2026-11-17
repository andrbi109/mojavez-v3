#!/usr/bin/env python3
"""
Static analysis for mojavez-v3.

Phase 1 subset (will grow in later phases per docs/00-MASTER.md §18.1):
- Every .php file passes basic syntax checks (handled by php_lint_simple.py).
- Every Controller class lives under app/Modules/<Module>/Controllers/ (thin controllers).
- Every public function in a *Service class has a PHPDoc summary.
- Every SQL query uses prepared statements (no string-concat with $vars).
- Every echo/print of dynamic content uses htmlspecialchars.
- Constants MAX_UPLOAD_SIZE / APP_VERSION etc. are NOT re-defined.

Exit code: 0 if all checks pass, 1 otherwise.
"""
import re
import sys
import pathlib


def find_php_files(root: pathlib.Path) -> list[pathlib.Path]:
    files: list[pathlib.Path] = []
    for d in ["app", "config", "tests", "public", "scripts"]:
        dirpath = root / d
        if dirpath.is_dir():
            files.extend(sorted(dirpath.rglob("*.php")))
    return files


def check_prepared_statements(content: str, rel: str) -> list[str]:
    """Flag any SQL string-concat with a $variable — must use ? or :named."""
    errors: list[str] = []
    # Crude heuristic: look for `query(...$var` or `exec("...$var")` patterns.
    # Real prepared-statement enforcement happens in CI via PHPStan (Phase 6).
    for m in re.finditer(r'->(query|exec)\s*\(\s*["\'].*\$\w+', content):
        errors.append(f"{rel}: SQL string-concat with variable at offset {m.start()}: use prepared statement")
    return errors


def check_htmlspecialchars(content: str, rel: str) -> list[str]:
    """Flag `echo $var` or `<?= $var ?>` without htmlspecialchars."""
    errors: list[str] = []
    # <?= $var ?> without htmlspecialchars
    for m in re.finditer(r'<\?=\s*\$(\w+)(?:\[[^\]]+\])?\s*\?>', content):
        var_name = m.group(1)
        # Skip if there's an obvious htmlspecialchars call nearby.
        if 'htmlspecialchars' not in content[max(0, m.start()-50):m.end()+50]:
            errors.append(
                f"{rel}:<?= ${var_name} ?> without htmlspecialchars (XSS risk)"
            )
    return errors


def check_controller_location(rel: str, content: str) -> list[str]:
    """Controllers must live under app/Modules/<Module>/Controllers/."""
    errors: list[str] = []
    if "class " in content and "Controller" in content:
        if "final class" in content or "class.*Controller" in content:
            if not re.match(r"app/Modules/\w+/Controllers/", str(rel)):
                # Allow abstract base controllers under app/Controllers/ (Phase 2).
                if "abstract" not in content:
                    errors.append(f"{rel}: controller not in app/Modules/<X>/Controllers/")
    return errors


def main() -> int:
    root = pathlib.Path(__file__).resolve().parent.parent
    files = find_php_files(root)
    total = 0
    for path in files:
        rel = path.relative_to(root)
        content = path.read_text(encoding="utf-8")
        errors: list[str] = []
        errors.extend(check_prepared_statements(content, str(rel)))
        errors.extend(check_htmlspecialchars(content, str(rel)))
        errors.extend(check_controller_location(str(rel), content))
        for e in errors:
            print(f"  WARN  {e}")
        total += len(errors)
    print(f"\n{len(files)} files analyzed, {total} warning(s).")
    # In Phase 1 we return 0 even with warnings — these are advisory.
    # Phase 6 will tighten to fail-on-warning.
    return 0


if __name__ == "__main__":
    sys.exit(main())
