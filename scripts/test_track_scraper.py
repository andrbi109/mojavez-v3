#!/usr/bin/env python3
"""
mojavez-v3 — scripts/test_track_scraper.py

Unit tests for the NationalGateway TrackScraper (parses the upstream
qr.mojavez.ir track page HTML into a License domain object).

Per docs/00-MASTER.md §18.4 — runs the TrackScraper against a saved
HTML fixture (tests/fixtures/track_page.html) and verifies the parsed
fields. The scraper itself is PHP; this script invokes it via the CLI
and parses the JSON output.

Phase 25 (v1.9.7) — D-6-3: the fixture is now a SYNTHETIC track page
(tests/fixtures/track_page.html) that mimics the structure of
qr.mojavez.ir's /track/{request_number} page (Next.js App Router with
RSC flight payload). The previous approach required a real HAR capture
from inside Iran — the synthetic fixture lets the test run anywhere
(not just from inside Iran). The PHP-native sibling test
(tests/Unit/TrackScraperTest.php) still uses the real HAR fixture for
end-to-end regression, so we keep both.

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
    """Run the PHP TrackScraper against the fixture and return parsed JSON.

    Constructs a stub HttpClient that returns the fixture HTML as if it
    were the upstream track-page response, then calls
    TrackScraper::fetch('I17981871', false) — which returns the parsed
    array. The advanced-data API call is stubbed to return ERROR so
    specialized_items comes back as an empty list.
    """
    php_code = r"""
require_once 'app/Shared/Support/Environment.php';
require_once 'app/Shared/Support/StructuredLogger.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $file = 'app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require_once $file;
});

final class StubHttp implements App\Modules\NationalGateway\Infrastructure\HttpClientInterface {
    private string $html;
    public function __construct(string $path) { $this->html = (string)file_get_contents($path); }
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $contentType = null): App\Modules\NationalGateway\Infrastructure\HttpResponse {
        if (str_contains($url, '/advanced-data')) {
            return new App\Modules\NationalGateway\Infrastructure\HttpResponse(
                200,
                '{"data":{"biServices":{"advanceData":{"Status":"ERROR","Result":[]}}}}',
                [],
                0.0
            );
        }
        return new App\Modules\NationalGateway\Infrastructure\HttpResponse(
            200,
            $this->html,
            ['content-type' => 'text/html; charset=utf-8'],
            0.1
        );
    }
}

$logger = new App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/trackscraper-test.log', 'debug');
$stub = new StubHttp(__FIXTURE_PATH__);
$scraper = new App\Modules\NationalGateway\Infrastructure\TrackScraper(
    $stub,
    'https://qr.mojavez.ir',
    $logger
);
$result = $scraper->fetch('I17981871', false);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
"""
    php_code = php_code.replace("__FIXTURE_PATH__", json.dumps(fixture_path))
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
    try:
        return json.loads(result.stdout.strip())
    except json.JSONDecodeError as e:
        print(red(f"  ✘ scraper returned non-JSON: {e}"))
        print(f"    stdout: {result.stdout[:200]!r}")
        return {}


def main():
    fixture = sys.argv[1].split("=", 1)[1] if len(sys.argv) > 1 and sys.argv[1].startswith("--fixture=") else str(DEFAULT_FIXTURE)

    if not Path(fixture).exists():
        print(red(f"  ✘ fixture not found: {fixture}"))
        print("    The synthetic fixture lives at tests/fixtures/track_page.html")
        print("    (committed by Phase 25 / D-6-3 — see docs/PHASE_25_LOW_DEBT_BATCH_2.md)")
        return 1

    print("═══════════ TrackScraper test ═══════════")
    parsed = run_scraper(fixture)
    if not parsed:
        return 1

    # The synthetic fixture is a minimal Next.js App Router page that
    # carries the RSC flight payload with the license data object,
    # applicant identity (label→value), business location (label→value),
    # and the business-history table. Expected values are pinned so
    # any regression in the scraper breaks this test.
    expected = {
        "father_name": "محمد",
        "national_id": "072****107",
        "business_province": "خراسان رضوی",
        "business_township": "تربت جام",
        "postal_code": "9571968***",
        "license_title": "کسب و کار خانگی",
        "isic_code": "131202",
        "isic_title": "نساجی سنتی",
        "issuance_type": "ثبت محور",
        "business_address": "استان خراسان رضوی، شهرستان تربت جام",
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

    # Status object — verify the slug, title, and id.
    status = parsed.get("status") or {}
    for field, expected_val in (("status_id", "2.24"), ("status_title", "معتبر"), ("status_slug", "active")):
        actual = str(status.get(field, ""))
        if actual == expected_val:
            pass_count += 1
            print(f"  ✔ status.{field} = {actual!r}")
        else:
            fail_count += 1
            print(f"  ✘ status.{field}: expected {expected_val!r}, got {actual!r}")

    # Required fields must be present / non-empty.
    required_nonempty = [
        "request_number",
        "tracking_code",
        "issuing_authority",
        "supervisory_authorities",
    ]
    for field in required_nonempty:
        if parsed.get(field):
            pass_count += 1
            print(f"  ✔ {field} non-empty: {parsed[field]!r}")
        else:
            fail_count += 1
            print(f"  ✘ {field} is empty")

    # found must be True; error must be None.
    if parsed.get("found") is True:
        pass_count += 1
        print("  ✔ found = True")
    else:
        fail_count += 1
        print(f"  ✘ found: expected True, got {parsed.get('found')!r}")
    if parsed.get("error") is None:
        pass_count += 1
        print("  ✔ error = None")
    else:
        fail_count += 1
        print(f"  ✘ error: expected None, got {parsed.get('error')!r}")

    # Mobile is publicly empty on the upstream track page → null in result.
    if parsed.get("mobile") is None:
        pass_count += 1
        print("  ✔ mobile = None (publicly empty on upstream)")
    else:
        fail_count += 1
        print(f"  ✘ mobile: expected None, got {parsed.get('mobile')!r}")

    # Business history table — one row expected.
    history = parsed.get("business_history") or []
    if len(history) == 1:
        pass_count += 1
        print(f"  ✔ business_history has 1 row")
        row = history[0]
        for k, v in (("operation", "صدور مجوز"), ("date", "1405-08-17"), ("index", "1")):
            if str(row.get(k, "")) == v:
                pass_count += 1
                print(f"  ✔ history[0].{k} = {v!r}")
            else:
                fail_count += 1
                print(f"  ✘ history[0].{k}: expected {v!r}, got {row.get(k)!r}")
    else:
        fail_count += 1
        print(f"  ✘ business_history: expected 1 row, got {len(history)}")

    # specialized_items is [] because the advanced-data API was stubbed to ERROR.
    if parsed.get("specialized_items") == []:
        pass_count += 1
        print("  ✔ specialized_items = [] (advanced-data stubbed)")
    else:
        fail_count += 1
        print(f"  ✘ specialized_items: expected [], got {parsed.get('specialized_items')!r}")

    print()
    print(f"PASS: {pass_count}, FAIL: {fail_count}")
    return 0 if fail_count == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
