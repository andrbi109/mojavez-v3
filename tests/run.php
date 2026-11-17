<?php
declare(strict_types=1);

/**
 * mojavez-v3 — unit test runner.
 *
 * Usage: php tests/run.php
 *
 * Loads each test file under tests/Unit/, instantiates the returned class,
 * runs it, and prints a summary.
 *
 * Exit code 0 on full pass, 1 on any failure.
 */

require_once __DIR__ . '/TestCase.php';

$unitDir = __DIR__ . '/Unit';
$testFiles = glob($unitDir . '/*Test.php') ?: [];

if ($testFiles === []) {
    fwrite(STDERR, "No test files found in $unitDir\n");
    exit(1);
}

sort($testFiles);

$totalAssertions = 0;
$totalFailures = 0;
/** @var array<int,string> $failuresByTest */
$failuresByTest = [];

foreach ($testFiles as $file) {
    $instance = require $file;
    if (!is_object($instance)) {
        fwrite(STDERR, "Test file $file did not return an object\n");
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
        fwrite(STDERR, "Test $name threw exception: " . $e->getMessage() . "\n");
        $totalFailures++;
        continue;
    }

    $assertions = method_exists($instance, 'assertions') ? $instance->assertions() : 0;
    $failures   = method_exists($instance, 'failures')   ? $instance->failures()   : 0;
    $totalAssertions += $assertions;
    $totalFailures   += $failures;

    $status = $failures === 0 ? 'PASS' : 'FAIL';
    printf("%-40s %4d assertions %4d failures  %s\n", $name, $assertions, $failures, $status);

    if ($failures > 0 && method_exists($instance, 'failureMessages')) {
        foreach ($instance->failureMessages() as $msg) {
            echo "    - $msg\n";
        }
    }
}

echo "\n";
printf("Total: %d assertions, %d failures\n", $totalAssertions, $totalFailures);
exit($totalFailures === 0 ? 0 : 1);
