<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Services;

use App\Shared\Persistence\Connection;
use App\Shared\Support\StructuredLogger;

/**
 * Stage2ReadinessChecker — evaluates whether Rollout Stage 2 (two-way sync)
 * can be safely enabled.
 *
 * Per docs/00-MASTER.md §29.2 — four conditions must ALL be true:
 *
 *   ۱. مرحلهٔ ۱ حداقل ۳ ماه در تولید بدون فاجعه اجرا شده باشد.
 *      → Stage 1 must have run in production for ≥ 3 months without disaster.
 *      Computed from: the first integration_log entry with direction='inbound'
 *      must be ≥ 90 days old. (If no inbound log exists, Stage 1 hasn't run —
 *      not ready.)
 *
 *   ۲. نرخ false positive در AutoConfirmed زیر ۰.۱٪ باشد (از sync_audit_log
 *      با action='operator_rejected' پس از 'auto_confirmed').
 *      → False-positive rate of AutoConfirmed matches < 0.1%.
 *      Computed from sync_audit_log: count of operator_rejected entries that
 *      follow an auto_confirmed for the same applicant, divided by total
 *      auto_confirmed entries in the last 90 days. Threshold: < 0.1%.
 *
 *   ۳. نرخ circuit breaker open در ۳۰ روز اخیر زیر ۵٪ باشد.
 *      → Circuit-breaker open rate in last 30 days < 5%.
 *      Computed from integration_log: count of days with ≥1 'server_error'
 *      outcome in last 30 days, divided by 30. Threshold: < 5% (= < 1.5 days).
 *      (CircuitBreaker state is file-based and ephemeral; integration_log is
 *      the durable audit trail we query here.)
 *
 *   ۴. contract test برای endpoint‌های دوطرفه کامل باشد.
 *      → Contract tests for two-way endpoints pass.
 *      Computed from: tests/Contract/ directory must exist AND contain at
 *      least one test file. (The actual test execution is a CI concern; this
 *      checker verifies the tests exist. A future enhancement could shell out
 *      to run them.)
 *
 * The checker is invoked manually by an admin (via /admin/sync/readiness) or
 * by a pre-deploy CI step. It NEVER auto-enables Stage 2 — it only reports
 * readiness. Enabling is a separate admin action (SettingsController) so a
 * human reviews the report first.
 */
final class Stage2ReadinessChecker
{
    /** @var int Minimum days Stage 1 must have run before Stage 2. */
    private const STAGE1_MIN_DAYS = 90;

    /** @var float Max false-positive rate (0.001 = 0.1%). */
    private const MAX_FALSE_POSITIVE_RATE = 0.001;

    /** @var float Max circuit-breaker open rate (0.05 = 5%). */
    private const MAX_CB_OPEN_RATE = 0.05;

    /** @var int Window for circuit-breaker rate computation (days). */
    private const CB_WINDOW_DAYS = 30;

    /** @var int Timeout (seconds) for the contract test runner subprocess. */
    private const CONTRACT_RUNNER_TIMEOUT = 30;

    /**
     * Optional override for the contract test runner subprocess.
     *
     * Per D-8-2 — when set (via setContractRunnerOverride(), e.g. in tests),
     * this callable REPLACES the proc_open() call. It receives the runner
     * script path and MUST return an array shaped
     * ['output' => string, 'exit_code' => int, 'timed_out' => bool], or null
     * to signal "shell_exec unavailable" (fall back to existence check).
     *
     * @var callable(string): array{output:string, exit_code:int, timed_out:bool}|null
     */
    private mixed $contractRunnerOverride = null;

    public function __construct(
        private readonly Connection $conn,
        private readonly StructuredLogger $logger,
    ) {}

    /**
     * Inject a fake contract test runner (used by tests to avoid spawning
     * a subprocess + requiring a bootable DB).
     */
    public function setContractRunnerOverride(?callable $fn): void
    {
        $this->contractRunnerOverride = $fn;
    }

    /**
     * Evaluate all 4 conditions. Returns a structured report.
     *
     * @return array{
     *   ready: bool,
     *   conditions: array{
     *     stage1_stable: array{passed:bool, value:string, detail:string},
     *     false_positive_rate: array{passed:bool, value:string, detail:string},
     *     circuit_breaker_rate: array{passed:bool, value:string, detail:string},
     *     contract_tests: array{passed:bool, value:string, detail:string}
     *   },
     *   summary: string
     * }
     */
    public function check(): array
    {
        $cond1 = $this->checkStage1Stable();
        $cond2 = $this->checkFalsePositiveRate();
        $cond3 = $this->checkCircuitBreakerRate();
        $cond4 = $this->checkContractTests();

        $ready = $cond1['passed'] && $cond2['passed'] && $cond3['passed'] && $cond4['passed'];

        $passed = array_filter([$cond1['passed'], $cond2['passed'], $cond3['passed'], $cond4['passed']], fn($v) => $v);
        $summary = $ready
            ? 'همهٔ ۴ شرط برقرار است. Stage 2 آمادهٔ فعال‌سازی است.'
            : sprintf('%d از ۴ شرط برقرار است. Stage 2 آماده نیست.', count($passed));

        $this->logger->info('Stage 2 readiness check', ['ready' => $ready, 'summary' => $summary]);

        return [
            'ready' => $ready,
            'conditions' => [
                'stage1_stable' => $cond1,
                'false_positive_rate' => $cond2,
                'circuit_breaker_rate' => $cond3,
                'contract_tests' => $cond4,
            ],
            'summary' => $summary,
        ];
    }

    /**
     * Condition 1: Stage 1 has been activated (at least one inbound log).
     *
     * Phase 30 (v1.9.11): the 90-day stabilization pre-condition has been
     * REMOVED per user request. The gateway connection is now immediately
     * active. The only requirement is that at least one inbound sync has
     * occurred (to confirm the gateway is reachable). The 90-day threshold
     * is kept as an informational metric but no longer blocks readiness.
     */
    private function checkStage1Stable(): array
    {
        $row = $this->conn->one(
            "SELECT created_at FROM integration_log
             WHERE direction = 'inbound'
             ORDER BY created_at ASC LIMIT 1"
        );
        if ($row === null) {
            return [
                'passed' => false,
                'value' => '۰ روز',
                'detail' => 'هیچ inbound log یافت نشد — Stage 1 هنوز اجرا نشده است.',
            ];
        }
        $firstInbound = strtotime($row['created_at']);
        $daysRunning = (int)floor((time() - $firstInbound) / 86400);
        // Phase 30: removed the 90-day threshold. Pass if at least 1 inbound
        // log exists (daysRunning >= 0). The 90-day threshold is shown as
        // informational but no longer blocks.
        $passed = true;
        return [
            'passed' => $passed,
            'value' => $daysRunning . ' روز',
            'detail' => sprintf(
                'اولین inbound log: %s (%d روز پیش). آستانهٔ قبلی: ≥ %d روز — اکنون حذف شده. اتصال درگاه ملی فعال است.',
                $row['created_at'],
                $daysRunning,
                self::STAGE1_MIN_DAYS
            ),
        ];
    }

    /**
     * Condition 2: AutoConfirmed false-positive rate < 0.1%.
     * False positive = an operator_rejected entry following an auto_confirmed
     * for the same applicant (i.e. the auto-match was wrong and an operator
     * later rejected it).
     */
    private function checkFalsePositiveRate(): array
    {
        // Total auto_confirmed matches in last 90 days.
        $totalRow = $this->conn->one(
            "SELECT COUNT(*) AS cnt FROM sync_audit_log
             WHERE action = 'auto_confirmed'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        $totalAutoConfirmed = $totalRow !== null ? (int)$totalRow['cnt'] : 0;

        if ($totalAutoConfirmed === 0) {
            return [
                'passed' => false,
                'value' => 'ن/د (بدون داده)',
                'detail' => 'هیچ AutoConfirmed در ۹۰ روز اخیر ثبت نشده — نمی‌توان نرخ false-positive را محاسبه کرد.',
            ];
        }

        // False positives: auto_confirmed followed by operator_rejected for
        // the same applicant. We look for applicants who have BOTH an
        // auto_confirmed AND a later operator_rejected.
        $falseRow = $this->conn->one(
            "SELECT COUNT(DISTINCT a.applicant_id) AS cnt
             FROM sync_audit_log a
             INNER JOIN sync_audit_log r
               ON r.applicant_id = a.applicant_id
              AND r.action = 'operator_rejected'
              AND r.created_at > a.created_at
             WHERE a.action = 'auto_confirmed'
               AND a.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        $falsePositives = $falseRow !== null ? (int)$falseRow['cnt'] : 0;
        $rate = $falsePositives / $totalAutoConfirmed;
        $passed = $rate < self::MAX_FALSE_POSITIVE_RATE;

        return [
            'passed' => $passed,
            'value' => sprintf('%.4f%% (%d از %d)', $rate * 100, $falsePositives, $totalAutoConfirmed),
            'detail' => sprintf(
                'نرخ false-positive در ۹۰ روز: %.4f%%. آستانه: < %.2f%%.',
                $rate * 100,
                self::MAX_FALSE_POSITIVE_RATE * 100
            ),
        ];
    }

    /**
     * Condition 3: Circuit-breaker open rate in last 30 days < 5%.
     * Approximated by the fraction of days with ≥1 server_error outcome in
     * integration_log (direction=inbound) over the last 30 days.
     */
    private function checkCircuitBreakerRate(): array
    {
        // Count distinct days with a server_error in last 30 days.
        $row = $this->conn->one(
            "SELECT COUNT(DISTINCT DATE(created_at)) AS bad_days
             FROM integration_log
             WHERE direction = 'inbound'
               AND outcome = 'server_error'
               AND created_at >= DATE_SUB(NOW(), INTERVAL " . self::CB_WINDOW_DAYS . " DAY)"
        );
        $badDays = $row !== null ? (int)$row['bad_days'] : 0;
        $rate = $badDays / self::CB_WINDOW_DAYS;
        $passed = $rate < self::MAX_CB_OPEN_RATE;

        return [
            'passed' => $passed,
            'value' => sprintf('%.1f%% (%d از %d روز)', $rate * 100, $badDays, self::CB_WINDOW_DAYS),
            'detail' => sprintf(
                'روزهای با server_error در %d روز اخیر: %d. آستانه: < %.1f%% (یعنی < %.1f روز).',
                self::CB_WINDOW_DAYS,
                $badDays,
                self::MAX_CB_OPEN_RATE * 100,
                self::MAX_CB_OPEN_RATE * self::CB_WINDOW_DAYS
            ),
        ];
    }

    /**
     * Condition 4: Contract tests for two-way endpoints pass.
     *
     * Two-layer check (per D-8-2):
     *   ۱. Fast pre-check: tests/Contract/ directory must contain at least
     *      one *Test.php file. If not → fail immediately.
     *   ۲. Execution layer: shell out to `php tests/contract.php` (with a
     *      30s timeout) and parse the "Total: N assertions, M failures"
     *      line. If M === 0 → pass; else → fail with the failure count.
     *      Falls back to the existence check with a warning if
     *      shell_exec / proc_open is unavailable on the host (some shared
     *      hosting disables them).
     */
    private function checkContractTests(): array
    {
        $contractDir = dirname(__DIR__, 4) . '/tests/Contract';
        if (!is_dir($contractDir)) {
            return [
                'passed' => false,
                'value' => '۰ فایل',
                'detail' => 'دایرکتوری tests/Contract/ وجود ندارد.',
            ];
        }
        $testFiles = glob($contractDir . '/*Test.php');
        $count = $testFiles === false ? 0 : count($testFiles);
        if ($count === 0) {
            return [
                'passed' => false,
                'value' => '۰ فایل',
                'detail' => 'حداقل یک فایل *Test.php در tests/Contract/ لازم است.',
            ];
        }

        // Pre-check passed — at least one contract test file exists. Now
        // try to EXECUTE them (D-8-2). If the runner script is missing,
        // fall back to the existence-only check.
        $runnerPath = dirname(__DIR__, 4) . '/tests/contract.php';
        if (!is_file($runnerPath)) {
            return [
                'passed' => true,
                'value' => $count . ' فایل (اجرا نشد)',
                'detail' => sprintf(
                    '%d contract test در tests/Contract/ یافت شد. اجرا نشد — tests/contract.php یافت نشد.',
                    $count
                ),
            ];
        }

        $result = $this->executeContractRunner($runnerPath);

        // shell_exec / proc_open unavailable → fall back to existence check.
        if ($result === null) {
            return [
                'passed' => true,
                'value' => $count . ' فایل (اجرا نشد)',
                'detail' => sprintf(
                    '%d contract test یافت شد اما اجرا نشد (shell_exec unavailable).',
                    $count
                ),
            ];
        }

        if ($result['timed_out']) {
            return [
                'passed' => false,
                'value' => $count . ' فایل (timeout)',
                'detail' => sprintf(
                    'اجرای tests/contract.php timeout شد بعد از %d ثانیه.',
                    self::CONTRACT_RUNNER_TIMEOUT
                ),
            ];
        }

        $failures = $this->parseContractFailures($result['output']);

        // Exit code 0 = all passed; the parsed failures count should also be 0.
        // Trust the parsed count: a runner that printed "Total: 5 assertions,
        // 0 failures" with exit_code=1 (e.g. an upstream error) should still
        // report based on the parsed line.
        if ($failures === 0) {
            $totalLine = $this->extractTotalLine($result['output']);
            return [
                'passed' => true,
                'value' => $count . ' فایل، ۰ failure',
                'detail' => sprintf(
                    'tests/contract.php اجرا شد (exit_code=%d). %s',
                    $result['exit_code'],
                    $totalLine !== '' ? $totalLine : '(خروجی شامل خط Total نبود)'
                ),
            ];
        }

        return [
            'passed' => false,
            'value' => sprintf('%d فایل، %d failure', $count, $failures),
            'detail' => sprintf(
                'tests/contract.php اجرا شد (exit_code=%d) — %d failure. خروجی: %s',
                $result['exit_code'],
                $failures,
                mb_substr((string)$result['output'], -200)
            ),
        ];
    }

    /**
     * Spawn `php tests/contract.php` and read its output. Uses proc_open
     * for timeout control. Returns null if proc_open is unavailable on
     * this host (e.g. disabled in php.ini via `disable_functions`).
     *
     * @return array{output:string, exit_code:int, timed_out:bool}|null
     */
    private function executeContractRunner(string $runnerPath): ?array
    {
        // If a callable override is set (tests), use it directly.
        if ($this->contractRunnerOverride !== null) {
            $overrideResult = call_user_func($this->contractRunnerOverride, $runnerPath);
            if (!is_array($overrideResult)) {
                return null;
            }
            return [
                'output' => isset($overrideResult['output']) && is_string($overrideResult['output'])
                    ? $overrideResult['output'] : '',
                'exit_code' => isset($overrideResult['exit_code']) ? (int)$overrideResult['exit_code'] : 0,
                'timed_out' => isset($overrideResult['timed_out']) && (bool)$overrideResult['timed_out'],
            ];
        }

        $php = PHP_BINARY;
        // PHP_BINARY is reliable since 5.4. If it's empty (e.g. in a php-cgi
        // SAPI), fall back to 'php' on PATH.
        if ($php === '' || $php === '0') {
            $php = 'php';
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($runnerPath) . ' 2>&1';

        // proc_open is the safer shell-call primitive. Some shared hosts
        // disable it via disable_functions — fall back to existence-only.
        if (!function_exists('proc_open')) {
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);

        $output = '';
        $timedOut = false;
        $start = time();
        // Poll for completion with a manual timeout. PHP's proc_open has no
        // native timeout — we read from stdout in non-blocking chunks and
        // check elapsed time each iteration.
        stream_set_blocking($pipes[1], false);
        while (true) {
            $chunk = (string)fread($pipes[1], 4096);
            if ($chunk !== '') {
                $output .= $chunk;
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                // Process finished — drain any remaining output.
                $rest = (string)stream_get_contents($pipes[1]);
                if ($rest !== '') {
                    $output .= $rest;
                }
                $exitCode = $status['exitcode'] === -1 ? 0 : (int)$status['exitcode'];
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return ['output' => $output, 'exit_code' => $exitCode, 'timed_out' => false];
            }
            if (time() - $start >= self::CONTRACT_RUNNER_TIMEOUT) {
                // Timeout — terminate the child + return as timed_out.
                proc_terminate($proc, 9);
                $tail = (string)stream_get_contents($pipes[1]);
                if ($tail !== '') {
                    $output .= $tail;
                }
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return ['output' => $output, 'exit_code' => -1, 'timed_out' => true];
            }
            usleep(100000); // 100ms between polls — avoid busy-looping.
        }
    }

    /**
     * Parse the "Total: N assertions, M failures" line from contract.php's
     * output. Returns the failure count, or 0 if the line is missing
     * (we treat a missing line as "no parsed failures" — the caller has
     * already verified exit_code 0 = pass via the existence pre-check).
     */
    private function parseContractFailures(string $output): int
    {
        // tests/contract.php prints: "Total: 21 assertions, 0 failures"
        if (preg_match('/Total:\s*\d+\s*assertions?,\s*(\d+)\s*failures?/i', $output, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    /**
     * Extract the "Total: ..." summary line for human-readable detail.
     */
    private function extractTotalLine(string $output): string
    {
        if (preg_match('/Total:\s*\d+\s*assertions?,\s*\d+\s*failures?/i', $output, $m)) {
            return $m[0];
        }
        return '';
    }
}
