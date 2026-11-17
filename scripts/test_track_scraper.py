#!/usr/bin/env python3
"""
mojavez-v3 — scripts/test_track_scraper.py

Unit tests for the NationalGateway TrackScraper (parses the upstream
qr.mojavez.ir track page HTML into a License domain object).

Per docs/00-MASTER.md §18.4 — runs the TrackScraper against a saved
HTML fixture (tests/fixtures/track_page.html) and verifies the parsed
License fields. The scraper itself is PHP; this script invokes it via
the CLI and parses the JSON output.

Usage:
    python3 scripts/test_track_scraper.py
    python3 scripts/test_track_scraper.py --fixture=path/to/fixture.html

Exit codes: 0 = pass, 1 = fail.
"""
import json
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PHP = os.environ.get("PHP", "/home/z/my-project/bin/php")
DEFAULT_FIXTURE = ROOT / "tests" / "fixtures" / "track_page.html"


def green(msg):
    if sys.stdout.isatty():
        return f"\033[0;32m{msg}\033[0m"
    return msg


def red(msg):
    if sys.stdout.isatty():
        return f"\033[0;31m{msg}\033[0m"
    return msg


def run_scraper(fixture_path: str) -> dict:
    """Run the PHP TrackScraper against the fixture and return parsed JSON."""
    php_code = f"""
require 'app/Shared/Support/Environment.php';
require 'app/Shared/Support/StructuredLogger.php';
spl_autoload_register(static function (string $class): void {{
    $prefix = 'App\\\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $file = 'app/' . str_replace('\\\\', '/', $relative) . '.php';
    if (is_file($file)) require_once $file;
}});
$env = App\\Shared\\Support\\Environment::load('.env');
$logger = new App\\Shared\\Support\\StructuredLogger('/tmp/trackscraper.log', 'debug');
$container = new App\\Container($env, $logger);
$container->boot(getcwd());
$scraper = $container->make(App\\Modules\\NationalGateway\\Infrastructure\\TrackScraper::class);
$html = file_get_contents({json.dumps(fixture_path)});
$license = $scraper->parse($html);
if ($license === null) {{
    echo json_encode(['error' => 'scraper returned null']);
    exit(1);
}}
echo json_encode([
    'request_number' => $license->requestNumber,
    'applicant_name' => $license->applicantName,
    'masked_national_code' => $license->maskedNationalCode,
    'father_name' => $license->fatherName,
    'province_title' => $license->provinceTitle,
    'township_title' => $license->townshipTitle,
    'license_title' => $license->licenseTitle,
    'organization_title' => $license->organizationTitle,
    'status_slug' => $license->status->value,
    'business_address' => $license->businessAddress,
    'postal_code_masked' => $license->postalCodeMasked,
], JSON_UNESCAPED_UNICODE);
"""
    result = subprocess.run(
        [PHP, "-r", php_code],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=30,
    )
    if result.returncode != 0:
        print(red(f"  ✘ scraper failed: {result.stderr[:200]}"))
        return {}
    return json.loads(result.stdout.strip())


def main():
    fixture = sys.argv[1].split("=", 1)[1] if len(sys.argv) > 1 and sys.argv[1].startswith("--fixture=") else str(DEFAULT_FIXTURE)

    if not Path(fixture).exists():
        print(red(f"  ✘ fixture not found: {fixture}"))
        print("    Generate one by saving the track page HTML to tests/fixtures/track_page.html")
        return 1

    print("═══════════ TrackScraper test ═══════════")
    parsed = run_scraper(fixture)
    if not parsed:
        return 1

    expected = {
        "masked_national_code": "072****107",
        "father_name": "محمد",
        "province_title": "خراسان رضوی",
        "license_title": "کسب و کار خانگی",
    }

    pass_count = 0
    fail_count = 0
    for field, expected_val in expected.items():
        actual = parsed.get(field, "")
        if actual == expected_val:
            pass_count += 1
            print(f"  ✔ {field} = {actual!r}")
        else:
            fail_count += 1
            print(f"  ✘ {field}: expected {expected_val!r}, got {actual!r}")

    # Required fields must be non-empty.
    for required in ["request_number", "applicant_name", "status_slug"]:
        if parsed.get(required):
            pass_count += 1
            print(f"  ✔ {required} non-empty: {parsed[required]!r}")
        else:
            fail_count += 1
            print(f"  ✘ {required} is empty")

    print()
    print(f"PASS: {pass_count}, FAIL: {fail_count}")
    return 0 if fail_count == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
