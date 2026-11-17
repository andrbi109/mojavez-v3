<?php
/**
 * registration/_progress.php — partial: wizard progress indicator.
 *
 * Phase 14 (v1.5.0) — pays D-7-3. The wizard's 4-step registration flow
 * uses this partial to render the progress bar at the top of every step
 * view. It's a Bootstrap 5 RTL progress with numbered circles.
 *
 * Expected $data:
 *   $currentStep   int   — the 1-based step number (1..4).
 *   $totalSteps    int   — total steps (default 4).
 *   $baseUrl       string — site base URL (for asset URLs).
 *
 * This is a placeholder structure for the future RegistrationController
 * (the controller itself is deferred per docs/00-MASTER.md TODO list).
 */
/** @var int $currentStep */
/** @var int $totalSteps */
/** @var string $baseUrl */

$currentStep ??= 1;
$totalSteps  ??= 4;
$baseUrl     ??= '';
$percent     = $totalSteps > 0 ? (int)round(($currentStep / $totalSteps) * 100) : 0;
?>
<div class="card mb-4" aria-label="progress wizard">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted small">مرحلهٔ <?= (int)$currentStep ?> از <?= (int)$totalSteps ?></span>
            <span class="badge bg-primary rounded-pill"><?= $percent ?>٪</span>
        </div>
        <div class="progress" role="progressbar" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100" style="height: 8px;">
            <div class="progress-bar" style="width: <?= $percent ?>%"></div>
        </div>
        <ol class="list-unstyled d-flex justify-content-between mt-3 mb-0 small text-muted">
            <?php for ($s = 1; $s <= $totalSteps; $s++): ?>
                <li class="text-center" style="flex: 1 1 0;">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle
                        <?= $s <= $currentStep ? 'bg-primary text-white' : 'bg-light text-muted border' ?>"
                        style="width: 2rem; height: 2rem; font-weight: bold;">
                        <?= $s ?>
                    </span>
                    <div class="mt-1">مرحلهٔ <?= $s ?></div>
                </li>
            <?php endfor; ?>
        </ol>
    </div>
</div>
