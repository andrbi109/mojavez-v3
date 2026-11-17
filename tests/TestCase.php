<?php
declare(strict_types=1);

/**
 * Test bootstrap for mojavez-v3 unit tests.
 *
 * Loaded by tests/run.php. Defines ROOT_PATH, registers the autoloader,
 * and exposes a few test helpers.
 */

define('ROOT_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $relative = str_replace('\\', '/', $relative);
    $file = ROOT_PATH . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once ROOT_PATH . '/app/Shared/Support/Environment.php';
require_once ROOT_PATH . '/app/Shared/Support/StructuredLogger.php';

class TestCase
{
    private int $assertions = 0;
    private int $failures = 0;

    /** @var array<int,string> */
    private array $failureMessages = [];

    /** If set, the test is skipped (not counted as pass or fail). */
    protected ?string $skipReason = null;

    /**
     * Mark this test as skipped (e.g. because a required source DB isn't set up).
     * Should be called from setUp() or the start of run().
     */
    protected function markTestSkipped(string $reason): void
    {
        $this->skipReason = $reason;
    }

    public function isSkipped(): bool
    {
        return $this->skipReason !== null;
    }

    public function skipReason(): ?string
    {
        return $this->skipReason;
    }

    public function assertTrue(bool $cond, string $message = ''): void
    {
        $this->assertions++;
        if (!$cond) {
            $this->recordFailure($message ?: 'expected true, got false');
        }
    }

    public function assertFalse(bool $cond, string $message = ''): void
    {
        $this->assertions++;
        if ($cond) {
            $this->recordFailure($message ?: 'expected false, got true');
        }
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->recordFailure($message ?: sprintf(
                'expected %s, got %s',
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertEquals($expected, $actual, $message);
    }

    public function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== null) {
            $this->recordFailure($message ?: 'expected null');
        }
    }

    public function assertNotNull(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value === null) {
            $this->recordFailure($message ?: 'expected not null');
        }
    }

    public function assertGreaterThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if (!is_numeric($actual) || !is_numeric($expected) || $actual < $expected) {
            $this->recordFailure($message ?: sprintf('expected >= %s, got %s', var_export($expected, true), var_export($actual, true)));
        }
    }

    public function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if (!is_numeric($actual) || !is_numeric($expected) || $actual <= $expected) {
            $this->recordFailure($message ?: sprintf('expected > %s, got %s', var_export($expected, true), var_export($actual, true)));
        }
    }

    public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if ($needle === '' || !str_contains($haystack, $needle)) {
            $this->recordFailure($message ?: sprintf('"%s" not found in string', $needle));
        }
    }

    public function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        $this->assertions++;
        if (!array_key_exists($key, $array)) {
            $this->recordFailure($message ?: sprintf('array has no key "%s"', $key));
        }
    }

    public function assertCount(int $expected, array $actual, string $message = ''): void
    {
        $this->assertions++;
        if (count($actual) !== $expected) {
            $this->recordFailure($message ?: sprintf(
                'expected count %d, got %d',
                $expected,
                count($actual)
            ));
        }
    }

    public function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if (!is_object($actual) || !($actual instanceof $expected)) {
            $actual_class = is_object($actual) ? get_class($actual) : gettype($actual);
            $this->recordFailure($message ?: sprintf(
                'expected instance of %s, got %s',
                $expected,
                $actual_class
            ));
        }
    }

    public function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!in_array($needle, $haystack, true)) {
            $this->recordFailure($message ?: sprintf(
                '%s not found in array',
                var_export($needle, true)
            ));
        }
    }

    public function assertions(): int
    {
        return $this->assertions;
    }

    public function failures(): int
    {
        return $this->failures;
    }

    /**
     * @return array<int,string>
     */
    public function failureMessages(): array
    {
        return $this->failureMessages;
    }

    private function recordFailure(string $message): void
    {
        $this->failures++;
        $this->failureMessages[] = $message;
    }

    /**
     * Helper: create a StructuredLogger that writes to a temp path.
     */
    protected function makeLogger(string $level = 'debug'): \App\Shared\Support\StructuredLogger
    {
        $path = sys_get_temp_dir() . '/mojavez-v3-test-' . uniqid() . '.log';
        return new \App\Shared\Support\StructuredLogger($path, $level);
    }
}
