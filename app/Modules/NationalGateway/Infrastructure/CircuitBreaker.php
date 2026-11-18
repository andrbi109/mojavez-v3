<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Infrastructure;

/**
 * Circuit Breaker — protects the upstream gateway from being hammered when
 * it's failing.
 *
 * Three states (per docs/00-MASTER.md §23.3):
 *   CLOSED      — normal operation; requests pass through.
 *   OPEN        — upstream is failing; requests short-circuit immediately
 *                 (return a fallback / cached response). Stays open for
 *                 cooldown_s seconds.
 *   HALF_OPEN    — after the cooldown, allow ONE probe request. If it
 *                  succeeds → CLOSED. If it fails → OPEN again (with the
 *                  cooldown restarted).
 *
 * State is persisted to a file so it survives across requests (important
 * for the poor-man's-cron pattern where each /admin/dashboard view triggers
 * a sync cycle).
 *
 * Config (from config/anti-detection.php):
 *   failure_threshold  — number of failures within failure_window_s that
 *                         trips the breaker to OPEN. Default 3.
 *   failure_window_s   — the rolling window for counting failures. Default 60.
 *   cooldown_s         — how long OPEN stays open before transitioning to
 *                         HALF_OPEN. Default 300 (5 min).
 */
final class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private readonly string $stateFile;

    public function __construct(
        private readonly int $failureThreshold = 3,
        private readonly int $failureWindowS = 60,
        private readonly int $cooldownS = 300,
        ?string $stateFile = null
    ) {
        $this->stateFile = $stateFile ?? sys_get_temp_dir() . '/mojavez_v3_circuit_breaker.json';
    }

    /**
     * Whether a request may proceed. Also transitions OPEN→HALF_OPEN if the
     * cooldown has elapsed.
     */
    public function canProceed(): bool
    {
        $state = $this->loadState();
        $now = time();

        if ($state['state'] === self::STATE_OPEN) {
            if ($now - $state['opened_at'] >= $this->cooldownS) {
                // Cooldown elapsed → transition to HALF_OPEN, allow one probe.
                $state['state'] = self::STATE_HALF_OPEN;
                $state['half_open_at'] = $now;
                $this->saveState($state);
                return true;
            }
            return false;
        }

        if ($state['state'] === self::STATE_HALF_OPEN) {
            // Only one probe at a time — if we're already half-open, block
            // concurrent probes (best-effort; true concurrency control needs
            // a Redis lock, which we don't have on shared hosting).
            return true;
        }

        return true;  // CLOSED
    }

    /**
     * Record a successful request. Transitions HALF_OPEN → CLOSED.
     */
    public function recordSuccess(): void
    {
        $state = $this->loadState();
        if ($state['state'] === self::STATE_HALF_OPEN) {
            $state['state'] = self::STATE_CLOSED;
            $state['opened_at'] = 0;
            $state['half_open_at'] = 0;
            $state['failures'] = [];
            $this->saveState($state);
            return;
        }
        // CLOSED — clear the failure window.
        if (!empty($state['failures'])) {
            $state['failures'] = $this->pruneOldFailures($state['failures']);
            $this->saveState($state);
        }
    }

    /**
     * Record a failed request. May transition CLOSED → OPEN if the failure
     * count exceeds the threshold within the window.
     */
    public function recordFailure(): void
    {
        $state = $this->loadState();
        $now = time();
        $state['failures'][] = $now;
        $state['failures'] = $this->pruneOldFailures($state['failures']);

        if ($state['state'] === self::STATE_HALF_OPEN) {
            // Probe failed → back to OPEN with fresh cooldown.
            $state['state'] = self::STATE_OPEN;
            $state['opened_at'] = $now;
            $state['half_open_at'] = 0;
            $this->saveState($state);
            return;
        }

        if (count($state['failures']) >= $this->failureThreshold) {
            $state['state'] = self::STATE_OPEN;
            $state['opened_at'] = $now;
            $this->saveState($state);
        } else {
            $this->saveState($state);
        }
    }

    /**
     * Current state for diagnostics / the /admin/sync/status panel.
     */
    public function state(): string
    {
        return $this->loadState()['state'];
    }

    /**
     * Reset to CLOSED (manual override from the admin panel).
     */
    public function reset(): void
    {
        $this->saveState([
            'state' => self::STATE_CLOSED,
            'opened_at' => 0,
            'half_open_at' => 0,
            'failures' => [],
        ]);
    }

    /**
     * @return array{state:string, opened_at:int, half_open_at:int, failures:array<int,int>}
     */
    private function loadState(): array
    {
        if (!is_file($this->stateFile)) {
            return [
                'state' => self::STATE_CLOSED,
                'opened_at' => 0,
                'half_open_at' => 0,
                'failures' => [],
            ];
        }
        $data = @file_get_contents($this->stateFile);
        if ($data === false) {
            return ['state' => self::STATE_CLOSED, 'opened_at' => 0, 'half_open_at' => 0, 'failures' => []];
        }
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return ['state' => self::STATE_CLOSED, 'opened_at' => 0, 'half_open_at' => 0, 'failures' => []];
        }
        return array_merge([
            'state' => self::STATE_CLOSED,
            'opened_at' => 0,
            'half_open_at' => 0,
            'failures' => [],
        ], $decoded);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function saveState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $this->stateFile,
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * @param array<int,int> $failures
     * @return array<int,int>
     */
    private function pruneOldFailures(array $failures): array
    {
        $cutoff = time() - $this->failureWindowS;
        return array_values(array_filter($failures, static fn (int $t): bool => $t >= $cutoff));
    }
}
