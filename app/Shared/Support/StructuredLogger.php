<?php
declare(strict_types=1);

namespace App\Shared\Support;

use RuntimeException;

/**
 * JSON line-by-line structured logger.
 *
 * Each log entry is a single JSON object written to storage/logs/app-YYYY-MM-DD.log:
 *   {
 *     "timestamp":   "2026-11-17T10:30:00+03:30",
 *     "level":       "info",
 *     "message":     "Applicant registered",
 *     "context":     {"applicant_id": 42, "tracking_code": "HG-ABC12345"},
 *     "request_id":  "req_abc123",
 *     "module":      "core"
 *   }
 *
 * Why JSON lines (not free-form text):
 *  - greppable with `jq`
 *  - ingestible by ELK / Loki / Vector without parsing
 *  - `_module` context lets us filter by module in mixed pipelines
 *
 * Design rules (from docs/CODING_CONVENTIONS.md §7):
 *  - Always pass `_module` in context for cross-module traceability.
 *  - Levels ordered: debug < info < warning < error < critical.
 *  - Below minimum level → silently dropped (no I/O).
 *
 * Robustness:
 *  - If the log directory does not exist or is not writable, the entry is
 *    silently dropped (never crash the app on a logging failure — but
 *    a one-time warning is emitted to the PHP error log via error_log()).
 *  - File writes use LOCK_EX to be safe under concurrent PHP-FPM workers.
 *
 * Future (Phase 6): add metric aggregation + log shipping hooks.
 */
final class StructuredLogger
{
    public const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    /** Monotonic counter for the metrics helper. */
    private int $metricBufferCount = 0;

    private bool $dirWarned = false;

    public function __construct(
        private readonly string $logPath,
        private readonly string $minimumLevel = 'info'
    ) {
        if (!in_array($minimumLevel, self::LEVELS, true)) {
            throw new RuntimeException("Invalid log level: $minimumLevel");
        }
    }

    /**
     * Log a message at the given level with optional structured context.
     *
     * @param string               $level   One of self::LEVELS.
     * @param string               $message Human-readable summary.
     * @param array<string,mixed>  $context Structured context. Pass `_module`
     *                                      for module-tagged filtering.
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            // Unknown level — coerce to 'info' rather than crash.
            $level = 'info';
        }
        if (array_search($level, self::LEVELS, true) < array_search($this->minimumLevel, self::LEVELS, true)) {
            return;
        }

        $module = null;
        if (array_key_exists('_module', $context)) {
            $module = $context['_module'];
            unset($context['_module']);
        }

        $entry = [
            'timestamp'  => date('c'),
            'level'      => $level,
            'message'    => $message,
            'context'    => $context,
            'request_id' => $_SERVER['X_REQUEST_ID'] ?? null,
            'module'     => $module,
        ];

        $this->write($entry);
    }

    /** @param array<string,mixed> $entry */
    private function write(array $entry): void
    {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_writable($dir)) {
            if (!$this->dirWarned) {
                @error_log("StructuredLogger: log dir not writable: $dir");
                $this->dirWarned = true;
            }
            return;
        }
        $line = json_encode(
            $entry,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($line === false) {
            return;
        }
        // LOCK_EX — concurrent workers (PHP-FPM) must not interleave lines.
        @file_put_contents($this->logPath, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * Emit a metric as a special info-level log entry.
     *
     * Metrics are flushed as JSON objects with the shape:
     *   {"timestamp": ..., "level": "info", "message": "metric",
     *    "context": {"metric": "sync.cycle.duration_s", "value": 12.4,
     *                "tags": {"module": "national_gateway"}}}
     *
     * Future: replace with a real metrics pipeline (StatsD / Prometheus
     * exporter) in Phase 6.
     *
     * @param array<string,string> $tags
     */
    public function metric(string $name, float $value, array $tags = []): void
    {
        $this->log('info', 'metric', [
            'metric' => $name,
            'value'  => $value,
            'tags'   => $tags,
        ]);
        $this->metricBufferCount++;
    }

    public function metricCount(): int
    {
        return $this->metricBufferCount;
    }

    /**
     * The path this logger writes to. Useful for diagnostics & tests.
     */
    public function logPath(): string
    {
        return $this->logPath;
    }

    public function minimumLevel(): string
    {
        return $this->minimumLevel;
    }
}
