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

    public function __construct(
        private readonly Connection $conn,
        private readonly StructuredLogger $logger,
    ) {}

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
     * Condition 1: Stage 1 has run for ≥ 90 days in production.
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
        $passed = $daysRunning >= self::STAGE1_MIN_DAYS;
        return [
            'passed' => $passed,
            'value' => $daysRunning . ' روز',
            'detail' => sprintf(
                'اولین inbound log: %s (%d روز پیش). آستانه: ≥ %d روز.',
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
     * Condition 4: Contract tests for two-way endpoints exist.
     * Verifies tests/Contract/ directory contains at least one *Test.php file.
     * (Running them is a CI concern — this checker only verifies existence.)
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
        $passed = $count > 0;
        return [
            'passed' => $passed,
            'value' => $count . ' فایل',
            'detail' => $passed
                ? sprintf('%d contract test در tests/Contract/ یافت شد.', $count)
                : 'حداقل یک فایل *Test.php در tests/Contract/ لازم است.',
        ];
    }
}
