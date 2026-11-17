<?php
declare(strict_types=1);

use App\Support\StructuredLogger;

require_once __DIR__ . '/../TestCase.php';

final class StructuredLoggerTest extends TestCase
{
    public function name(): string
    {
        return 'StructuredLoggerTest';
    }

    public function run(): void
    {
        // Setup: a temp log file we can read back.
        $logPath = sys_get_temp_dir() . '/mojavez-v3-logger-' . uniqid() . '.log';
        @unlink($logPath);
        $logger = new StructuredLogger($logPath, 'info');

        // 1. info() writes a JSON line with all expected fields.
        $logger->info('test message', ['_module' => 'core', 'foo' => 'bar']);
        $contents = (string)file_get_contents($logPath);
        $this->assertTrue($contents !== '', 'Log file should not be empty');
        $line = trim(explode("\n", $contents)[0]);
        $decoded = json_decode($line, true);
        $this->assertTrue(is_array($decoded), 'Log line should be valid JSON');
        $this->assertSame('info', $decoded['level'], 'Level recorded');
        $this->assertSame('test message', $decoded['message'], 'Message recorded');
        $this->assertSame('core', $decoded['module'], '_module extracted to top-level module field');
        $this->assertSame('bar', $decoded['context']['foo'], 'context.foo preserved');
        $this->assertNull($decoded['context']['_module'] ?? null, '_module removed from context');

        // 2. debug() is filtered out (minimumLevel='info').
        $logger->debug('should not appear');
        $contents = (string)file_get_contents($logPath);
        $this->assertFalse(str_contains($contents, 'should not appear'), 'debug filtered below info');

        // 3. warning/error/critical are written.
        $logger->warning('warn1');
        $logger->error('err1');
        $logger->critical('crit1');
        $contents = (string)file_get_contents($logPath);
        $this->assertTrue(str_contains($contents, 'warn1'), 'warning written');
        $this->assertTrue(str_contains($contents, 'err1'), 'error written');
        $this->assertTrue(str_contains($contents, 'crit1'), 'critical written');

        // 4. metric() emits a metric entry.
        $logger->metric('sync.cycle.duration_s', 12.4, ['module' => 'national_gateway']);
        $contents = (string)file_get_contents($logPath);
        $this->assertTrue(str_contains($contents, '"metric":"sync.cycle.duration_s"'), 'metric recorded');
        $this->assertTrue(str_contains($contents, '"value":12.4'), 'metric value recorded');

        // 5. minimumLevel() returns the configured level.
        $this->assertSame('info', $logger->minimumLevel(), 'minimumLevel accessor');

        // 6. logPath() returns the configured path.
        $this->assertSame($logPath, $logger->logPath(), 'logPath accessor');

        // 7. Constructor rejects invalid minimum level.
        $threw = false;
        try {
            new StructuredLogger(sys_get_temp_dir() . '/x.log', 'bogus');
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Constructor throws on invalid level');

        // Cleanup.
        @unlink($logPath);
    }
}

return new StructuredLoggerTest();
