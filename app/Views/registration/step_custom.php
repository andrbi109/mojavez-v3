<?php
/**
 * registration/step_custom.php — wizard custom step (rendered for any
 * wizard_step row whose view_key isn't one of step1..step4).
 *
 * Phase 14 (v1.5.0) — pays D-7-3. The wizard is data-driven: the
 * admin can define custom steps in the `wizard_steps` table. Each
 * custom step has a view_key (e.g. 'family_info') and a set of
 * form_fields rows. This view renders those fields generically via
 * the `_dynamic_fields` partial.
 *
 * Expected $data:
 *   $step          array{view_key, title, icon} — the wizard_steps row.
 *   $stepNumber    int  — 1-based step number in the wizard sequence.
 *   $totalSteps    int  — total steps in this wizard.
 *   $fields        array<int, array{...}> — the form_fields for this step.
 *   $values        array<string,string> — previously-submitted values.
 *   $baseUrl, $csrfField.
 */
/** @var array<string,mixed> $step */
/** @var int $stepNumber */
/** @var int $totalSteps */
/** @var array<int, array<string,mixed>> $fields */
/** @var array<string,string> $values */
/** @var string $baseUrl */
/** @var string $csrfField */

$step        ??= [];
$stepNumber  ??= 1;
$totalSteps  ??= 4;
$fields      ??= [];
$values      ??= [];
$baseUrl     ??= '';
$csrfField   ??= '';
$currentStep = max(1, (int)$stepNumber);
$totalSteps  = max(4, (int)$totalSteps);
require __DIR__ . '/_progress.php';
$stepTitle = (string)($step['title'] ?? 'مرحلهٔ دلخواه');
$stepIcon  = (string)($step['icon'] ?? 'bi-list-check');
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0">
                    <i class="bi <?= htmlspecialchars($stepIcon, ENT_QUOTES) ?> text-brand"></i>
                    <?= htmlspecialchars($stepTitle, ENT_QUOTES, 'UTF-8') ?>
                </h1>
            </div>
            <div class="card-body p-4">
                <form method="post"
                    action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/<?= (int)$stepNumber ?>">
                    <?= $csrfField ?>
                    <?php require __DIR__ . '/_dynamic_fields.php'; ?>
                    <div class="mt-4 d-flex justify-content-between">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/<?= max(1, (int)$stepNumber - 1) ?>"
                            class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-return-right"></i> بازگشت
                        </a>
                        <button type="submit" class="btn btn-primary">
                            مرحلهٔ بعد <i class="bi bi-arrow-left"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
