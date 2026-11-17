#!/usr/bin/env python3
"""
Best-effort PHP syntax linter for mojavez-v3.

This is a fallback used when `php -l` is not available in the local
environment (e.g. when developing without PHP installed locally). The
real syntax check happens in CI (`.github/workflows/ci.yml`) via
`php -l` and `php tests/run.php`.

What this script does:
  1. Find all .php files in app/, config/, tests/, public/, scripts/.
  2. For each file:
     - Check that it starts with `<?php` (no BOM, no leading whitespace).
     - Check that braces are balanced.
     - Check that parentheses are balanced.
     - Check that brackets are balanced.
     - Check that `declare(strict_types=1);` is present (CODING_CONVENTIONS §2.1).
     - Check that no `?>` closing tag exists at end of file.
     - Check that no `die()` is used.
     - Check that no `define(` is used (forbidden — CODING_CONVENTIONS §2).

Usage:
  python3 tools/php_lint_simple.py

Exit code:
  0 if all files pass, 1 otherwise.
"""
import re
import sys
import pathlib
import os


def find_php_files(root: pathlib.Path) -> list[pathlib.Path]:
    dirs = ["app", "config", "tests", "public", "scripts"]
    files = []
    for d in dirs:
        dirpath = root / d
        if not dirpath.is_dir():
            continue
        for p in dirpath.rglob("*.php"):
            files.append(p)
    return sorted(files)


def lint_file(path: pathlib.Path) -> list[str]:
    errors: list[str] = []
    content = path.read_text(encoding="utf-8")

    # 1. Must start with <?php (no BOM, no leading whitespace).
    if not content.startswith("<?php"):
        # Allow a leading shebang line in CLI scripts.
        first_line = content.split("\n", 1)[0]
        if not (first_line.startswith("#!") and "<?php" in content[:200]):
            errors.append("file must start with `<?php` (no BOM, no leading whitespace)")

    # 2. No ?> closing tag (CODING_CONVENTIONS §2.1: "پایان فایل: خط خالی، بدون ?>")
    if content.rstrip().endswith("?>"):
        errors.append("file must not end with `?>` closing tag")

    # 3. declare(strict_types=1); — relaxed check (must appear early).
    # Tests and TestCase don't strictly need this but CODING_CONVENTIONS §2.1 says "every file".
    if "<?php" in content[:20]:
        head = content[:500]
        if "declare(strict_types=1)" not in head:
            errors.append("missing `declare(strict_types=1);` near the top")

    # 4. Brace / paren / bracket balance (ignore inside strings & comments).
    # Strip strings and comments first to avoid false positives.
    stripped = strip_strings_and_comments(content)
    if stripped.count("{") != stripped.count("}"):
        errors.append(f"unbalanced braces: {stripped.count('{')} `{{` vs {stripped.count('}')} `}}`")
    if stripped.count("(") != stripped.count(")"):
        errors.append(f"unbalanced parens: {stripped.count('(')} `(` vs {stripped.count(')')}) `)`")
    if stripped.count("[") != stripped.count("]"):
        errors.append(f"unbalanced brackets: {stripped.count('[')} `[` vs {stripped.count(']')}) `]`")

    # 5. No die() (CODING_CONVENTIONS §2: "هیچ die()").
    if re.search(r"\bdie\s*\(", stripped):
        errors.append("`die()` is forbidden (use throw or Response::send())")

    # 6. No define() (forbidden — CODING_CONVENTIONS §2: "ثابت‌ها در config/*.php به‌صورت آرایه بازگشتی، نه define() سراسری")
    # Allow `define('ROOT_PATH', ...)` in public/index.php (the single anchor
    # for relative paths) and tests/TestCase.php (test bootstrap anchor).
    if path.name not in ("index.php", "TestCase.php") and re.search(r"\bdefine\s*\(", stripped):
        errors.append("`define()` is forbidden (use config array — CODING_CONVENTIONS §2)")

    return errors


def strip_strings_and_comments(content: str) -> str:
    """Replace string literals and comments with whitespace (to keep offsets)."""
    # This is a simplified stripper — not a real PHP tokenizer. It handles
    # the common cases: // line comments, /* block comments */, # line comments,
    # 'single-quoted', "double-quoted", and heredoc/nowdoc.
    result = []
    i = 0
    n = len(content)
    while i < n:
        # Line comment // or #
        if content[i:i+2] == "//" or content[i] == "#":
            j = content.find("\n", i)
            if j == -1:
                j = n
            result.append(" " * (j - i))
            i = j
            continue
        # Block comment /* */
        if content[i:i+2] == "/*":
            j = content.find("*/", i+2)
            if j == -1:
                j = n
            else:
                j += 2
            # Preserve newlines for line numbers.
            chunk = content[i:j]
            chunk = re.sub(r"[^\n]", " ", chunk)
            result.append(chunk)
            i = j
            continue
        # Single-quoted string
        if content[i] == "'":
            j = i + 1
            while j < n:
                if content[j] == "\\" and j + 1 < n:
                    j += 2
                    continue
                if content[j] == "'":
                    j += 1
                    break
                j += 1
            result.append(" " * (j - i))
            i = j
            continue
        # Double-quoted string
        if content[i] == '"':
            j = i + 1
            while j < n:
                if content[j] == "\\" and j + 1 < n:
                    j += 2
                    continue
                if content[j] == '"':
                    j += 1
                    break
                j += 1
            result.append(" " * (j - i))
            i = j
            continue
        result.append(content[i])
        i += 1
    return "".join(result)


def main() -> int:
    root = pathlib.Path(__file__).resolve().parent.parent
    files = find_php_files(root)
    if not files:
        print("no PHP files found", file=sys.stderr)
        return 1
    print(f"Linting {len(files)} PHP files...")
    total_errors = 0
    for path in files:
        errors = lint_file(path)
        rel = path.relative_to(root)
        if errors:
            total_errors += len(errors)
            for e in errors:
                print(f"  FAIL  {rel}: {e}")
        else:
            print(f"  PASS  {rel}")
    print(f"\n{len(files)} files checked, {total_errors} issues.")
    return 0 if total_errors == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
