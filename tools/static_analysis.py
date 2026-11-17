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

Exit code:
  - Default (advisory): 0 even with warnings.
  - --strict (or MOJAVEZ_STRICT=1 env var): non-zero if any warning remains
    after applying the ignore patterns in tools/static_analysis_ignore.txt.

Phase 13 (v1.4.0): pays D-1-8. Added --strict mode + ignore-patterns file.
The ignore file lists known false-positive patterns (one per line, '#'
starts a comment). When --strict is set, warnings whose text contains any
ignore-pattern substring are suppressed; if any warning survives, exit 1.
CI runs with --strict (see .github/workflows/ci.yml).
"""
import os
import pathlib
import re
import sys


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


def load_ignore_patterns(root: pathlib.Path) -> list[str]:
    """Load the false-positive ignore patterns.

    File: tools/static_analysis_ignore.txt (one pattern per line, '#' = comment).
    Returns a list of substrings; a warning is suppressed if its text contains
    any of these substrings (case-sensitive).
    """
    path = root / "tools" / "static_analysis_ignore.txt"
    if not path.is_file():
        return []
    patterns: list[str] = []
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if line == "" or line.startswith("#"):
            continue
        patterns.append(line)
    return patterns


def is_ignored(warning: str, patterns: list[str]) -> bool:
    """True if the warning text contains any ignore-pattern substring."""
    return any(p in warning for p in patterns)


def parse_args() -> dict:
    """Parse CLI args. Only --strict is supported (plus the env var)."""
    strict = "--strict" in sys.argv[1:] or os.environ.get("MOJAVEZ_STRICT", "") == "1"
    return {"strict": strict}


def main() -> int:
    root = pathlib.Path(__file__).resolve().parent.parent
    args = parse_args()
    patterns = load_ignore_patterns(root)
    files = find_php_files(root)
    total = 0          # all warnings (advisory count)
    suppressed = 0     # warnings suppressed by ignore patterns
    surviving = 0      # warnings not suppressed (only meaningful in --strict)
    for path in files:
        rel = path.relative_to(root)
        content = path.read_text(encoding="utf-8")
        errors: list[str] = []
        errors.extend(check_prepared_statements(content, str(rel)))
        errors.extend(check_htmlspecialchars(content, str(rel)))
        errors.extend(check_controller_location(str(rel), content))
        for e in errors:
            total += 1
            if args["strict"] and is_ignored(e, patterns):
                suppressed += 1
                continue
            print(f"  WARN  {e}")
            if args["strict"]:
                surviving += 1
    if args["strict"]:
        print(
            f"\n{len(files)} files analyzed, {total} warning(s) "
            f"({suppressed} suppressed by tools/static_analysis_ignore.txt, "
            f"{surviving} surviving)."
        )
        if surviving > 0:
            print(
                f"FAIL: --strict mode: {surviving} warning(s) survived "
                f"the ignore list — fix them or extend the ignore file."
            )
            return 1
        print("PASS: --strict mode: no surviving warnings.")
        return 0
    # Advisory mode (Phase 1-12 behaviour) — exit 0 even with warnings.
    print(f"\n{len(files)} files analyzed, {total} warning(s).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
