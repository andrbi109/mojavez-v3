<?php
declare(strict_types=1);

/**
 * mojavez-v3 — contract test runner.
 *
 * Usage: php tests/contract.php
 *
 * Loads each test file under tests/Contract/, instantiates the returned class,
 * runs it, and prints a summary. Contract tests verify the bidirectional
 * sync contract (§29.1 Stage 2): payload shape, idempotency keys, audit trail
 * fields, conflict resolution labels — independent of the actual HTTP push.
 *
 * This runner is invoked by CI (.github/workflows/ci.yml) and by the
 * Stage2ReadinessChecker's condition 4 (which verifies tests/Contract/ exists
 * and contains at least one *Test.php file).
 *
 * Exit code 0 on full pass, 1 on any failure.
 */

require_once __DIR__ . '/TestCase.php';

$contractDir = __DIR__ . '/Contract';
$testFiles = glob($contractDir . '/*Test.php') ?: [];

if ($testFiles === []) {
    fwrite(STDERR, "No contract test files found in $contractDir\n");
    exit(1);
}

sort($testFiles);

$totalAssertions = 0;
$totalFailures = 0;
$failuresByTest = [];

foreach ($testFiles as $file) {
    $instance = require $file;
    if (!is_object($instance)) {
        fwrite(STDERR, "Contract test file $file did not return an object\n");
        exit(1);
    }
    $name = method_exists($instance, 'name') ? $instance->name() : basename($file);

    try {
        if (method_exists($instance, 'setUp')) {
            $instance->setUp();
        }
        $instance->run();
        if (method_exists($instance, 'tearDown')) {
            $instance->tearDown();
        }
    } catch (\Throwable $e) {
        // Mark as a single failure with the exception message.
        $failuresByTest[] = $name . ': ' . $e->getMessage();
        $totalFailures++;
    }

    $totalAssertions += method_exists($instance, 'assertions') ? $instance->assertions() : 0;
    $failures = method_exists($instance, 'failures') ? $instance->failures() : 0;
    $totalFailures += $failures;

    $status = $failures === 0 ? 'PASS' : 'FAIL';
    printf("%-40s %4d assertions %3d failures  %s\n", $name, method_exists($instance, 'assertions') ? $instance->assertions() : 0, $failures, $status);
}

echo "\n";
foreach ($failuresByTest as $f) {
    fwrite(STDERR, "    - $f\n");
}
echo "Total: $totalAssertions assertions, $totalFailures failures\n";

exit($totalFailures === 0 ? 0 : 1);
