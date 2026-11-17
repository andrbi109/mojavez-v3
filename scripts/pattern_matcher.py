#!/usr/bin/env python3
"""
mojavez-v3 — scripts/pattern_matcher.py

Standalone test harness for the PatternMatcher (golden-link quorum).

Per docs/00-MASTER.md §18.5 — runs the PatternMatcher against a set of
synthetic applicant + license pairs and reports the match outcome +
matched fields. The matcher itself is PHP; this script invokes it via
the CLI and prints a human-readable report.

Usage:
    python3 scripts/pattern_matcher.py
    python3 scripts/pattern_matcher.py --json     # machine-readable output

Exit codes: 0 = all matches produced an outcome, 1 = some errored.
"""
import json
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PHP = os.environ.get("PHP", "/home/z/my-project/bin/php")


def green(msg):
    if sys.stdout.isatty():
        return f"\033[0;32m{msg}\033[0m"
    return msg


def red(msg):
    if sys.stdout.isatty():
        return f"\033[0;31m{msg}\033[0m"
    return msg


def yellow(msg):
    if sys.stdout.isatty():
        return f"\033[1;33m{msg}\033[0m"
    return msg


# Synthetic test cases. See MatchConfidence enum: AutoConfirmed=90,
# OperatorFlag=50, AutoRejected=0.
TEST_CASES = [
    {
        "label": "perfect_match_6_fields",
        "national_code": "0720593107",
        "full_name": "عبدالکریم محمدی",
        "father_name": "محمد",
        "province": "خراسان رضوی",
        "township": "تربت جام",
        "business_address": "خیابان مطهری",
        "postal_code": "9571968107",
        "license_masked_nc": "072****107",  # suffix 107 matches national_code
        "license_father": "محمد",
        "license_province": "خراسان رضوی",
        "license_township": "تربت جام",
        "license_business_address": "***** خیابان مطهری",
        "license_postal_masked": "9571968***",
        "expected": 90,  # AutoConfirmed
    },
    {
        "label": "national_code_no_extra_fields",
        "national_code": "0720593108",
        "full_name": "نام متفاوت",
        "father_name": "پدر متفاوت",
        "province": "تهران",
        "township": "تهران",
        "business_address": "",
        "postal_code": "1111111111",
        "license_masked_nc": "072****108",  # matches
        "license_father": "محد",
        "license_province": "اصفهان",
        "license_township": "کاشان",
        "license_business_address": "",
        "license_postal_masked": "",
        "expected": 50,  # OperatorFlag — national_code matches but no other fields
    },
    {
        "label": "mismatched_national_code_anchor",
        "national_code": "0720593199",
        "full_name": "عبدالکریم محمدی",
        "father_name": "محمد",
        "province": "خراسان رضوی",
        "township": "تربت جام",
        "business_address": "خیابان مطهری",
        "postal_code": "9571968107",
        "license_masked_nc": "072****777",  # suffix 777 ≠ 199 → anchor mismatch
        "license_father": "محمد",
        "license_province": "خراسان رضوی",
        "license_township": "تربت جام",
        "license_business_address": "***** خیابان مطهری",
        "license_postal_masked": "9571968***",
        "expected": 50,  # OperatorFlag — anchor mismatch but 4 other fields match → manual review
    },
]


def run_matcher(case: dict) -> dict:
    """Run the PHP PatternMatcher on a test case and return the result."""
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
$logger = new App\\Shared\\Support\\StructuredLogger('/tmp/pm.log', 'debug');
$container = new App\\Container($env, $logger);
$container->boot(getcwd());
$matcher = $container->make(App\\Modules\\NationalGateway\\Services\\PatternMatcher::class);

$license = new App\\Modules\\NationalGateway\\Domain\\License(
    requestNumber: 'PM-TEST',
    applicantName: {json.dumps(case['full_name'])},
    userImage: null,
    licenseTitle: 'کسب و کار خانگی',
    organizationTitle: 'سازمان بهداشت',
    provinceTitle: {json.dumps(case['license_province'])},
    townshipTitle: {json.dumps(case['license_township'])},
    respondedAt: date('Y-m-d H:i:s'),
    status: App\\Modules\\NationalGateway\\Domain\\Enums\\LicenseStatus::Active,
    maskedNationalCode: {json.dumps(case['license_masked_nc'])},
    fatherName: {json.dumps(case['license_father'])},
    businessAddress: {json.dumps(case['license_business_address'])},
    postalCodeMasked: {json.dumps(case['license_postal_masked'])},
);
$person = new App\\Modules\\Core\\Domain\\Person(
    id: 0,
    nationalCode: {json.dumps(case['national_code'])},
    firstName: '',
    lastName: '',
    mobile: '09150000000',
    personType: App\\Modules\\Core\\Domain\\Enums\\PersonType::Real,
    fatherName: {json.dumps(case['father_name'])},
);
$property = [
    'province' => {json.dumps(case['province'])},
    'township' => {json.dumps(case['township'])},
    'business_address' => {json.dumps(case['business_address'])},
    'postal_code' => {json.dumps(case['postal_code'])},
];
$result = $matcher->matchQuorum($license, $person, $property);
echo json_encode([
    'confidence' => $result->confidence->value,
    'fields_matched' => $result->fieldsMatched,
    'national_code_matched' => $result->nationalCodeMatched,
    'total_score' => $result->totalScore,
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
        return {"error": result.stderr[:300]}
    return json.loads(result.stdout.strip())


def main():
    json_mode = "--json" in sys.argv
    results = []
    for case in TEST_CASES:
        out = run_matcher(case)
        if "error" in out:
            results.append({"label": case["label"], "error": out["error"], "expected": case["expected"]})
        else:
            results.append({"label": case["label"], **out, "expected": case["expected"]})

    if json_mode:
        print(json.dumps(results, ensure_ascii=False, indent=2))
    else:
        print("═══════════ PatternMatcher test ═══════════")
        pass_count = 0
        fail_count = 0
        for r in results:
            label = r["label"]
            if "error" in r:
                print(red(f"  ✘ {label}: ERROR {r['error'][:100]}"))
                fail_count += 1
                continue
            actual = r.get("confidence", "")
            expected = r["expected"]
            matched_fields = r.get("fields_matched", [])
            score = r.get("total_score", 0)
            status = "✔" if actual == expected else "✘"
            color = green if actual == expected else red
            print(color(f"  {status} {label}: got={actual} expected={expected} (score={score}, fields={matched_fields})"))
            if actual == expected:
                pass_count += 1
            else:
                fail_count += 1
        print()
        print(f"PASS: {pass_count}, FAIL: {fail_count}")
    return 0 if all("error" not in r and r.get("confidence") == r.get("expected") for r in results) else 1


if __name__ == "__main__":
    sys.exit(main())
