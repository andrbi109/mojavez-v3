<?php
/**
 * admin/stub_coming_soon.php — placeholder view for deferred admin sections.
 *
 * Phase 10: this view is shared by the three admin sidebar links whose
 * controllers are deferred to a later release:
 *   - /admin/form-fields    → AdminController::formFields
 *   - /admin/fee-tariff     → AdminController::feeTariff
 *   - /admin/license-titles → AdminController::licenseTitles
 *
 * The view is rendered inside admin_layout.php (auto-detected because the
 * view path starts with `admin/`). It uses the same Bootstrap 5 + Vazirmatn
 * stack as the rest of the admin panel.
 *
 * Expected $data:
 *   $title       string — section title (shown in <h1> + page <title>)
 *   $icon        string — Bootstrap Icons class (e.g. 'bi-input-cursor-text')
 *   $description string — short Persian description of the planned feature
 */
/** @var string $title */
/** @var string $icon */
/** @var string $description */
/** @var string $baseUrl */
/** @var string $csrfField */

$title       = $title       ?? 'بخش';
$icon        = $icon        ?? 'bi-info-circle';
$description = $description ?? 'این بخش در حال حاضر فعال نیست.';
?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card text-center border-0 shadow-sm">
            <div class="card-body p-5">
                <div class="display-1 text-muted mb-3 opacity-50">
                    <i class="bi <?= htmlspecialchars($icon, ENT_QUOTES) ?>"></i>
                </div>
                <h2 class="h4 mb-2"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="text-muted mb-4" style="max-width: 32rem; margin-inline: auto;">
                    <?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>
                </p>

                <div class="alert alert-info d-inline-block" role="alert">
                    <i class="bi bi-info-circle"></i>
                    این بخش در حال حاضر فعال نیست.
                </div>

                <div class="mt-4">
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/dashboard"
                       class="btn btn-primary">
                        <i class="bi bi-arrow-return-right"></i>
                        بازگشت به داشبورد
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
