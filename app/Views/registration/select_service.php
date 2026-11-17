<?php
/**
 * registration/select_service.php — wizard step 0: choose service type.
 *
 * Phase 14 (v1.5.0) — pays D-7-3. This is the wizard's entry view: the
 * applicant picks one of the 5 default service types (seeded by
 * migration 009) before going to step 1. The selection is sent as a
 * POST to /register/service (which the future RegistrationController
 * will resolve to step 1).
 *
 * Expected $data:
 *   $serviceTypes  array<int, array{id, title, description}> — the
 *                  service_types rows (sorted by sort_order).
 *   $baseUrl       string — site base URL.
 *   $csrfField     string — CSRF hidden input.
 *
 * The view is rendered inside `layout.php` (auto-detected because the
 * view path starts with `registration/` — falls back to the public site
 * layout). Bootstrap 5 RTL + Vazirmatn + Bootstrap Icons.
 */
/** @var array<int, array<string,mixed>> $serviceTypes */
/** @var string $baseUrl */
/** @var string $csrfField */

$serviceTypes ??= [];
$csrfField    ??= '';
$baseUrl      ??= '';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0"><i class="bi bi-grid-3x3-gap text-brand"></i> انتخاب نوع خدمت</h1>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-4">برای شروع ثبت‌نام، نوع مجوز مورد نظر خود را انتخاب کنید.</p>

                <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/service">
                    <?= $csrfField ?>
                    <div class="row g-3">
                        <?php foreach ($serviceTypes as $st): ?>
                            <?php
                            $sid = (int)($st['id'] ?? 0);
                            $title = (string)($st['title'] ?? '');
                            $desc = (string)($st['description'] ?? '');
                            ?>
                            <div class="col-md-6">
                                <label class="card h-100 service-type-option" for="st_<?= $sid ?>">
                                    <div class="card-body">
                                        <div class="form-check">
                                            <input type="radio" class="form-check-input"
                                                name="service_type_id" id="st_<?= $sid ?>"
                                                value="<?= $sid ?>" required>
                                            <label class="form-check-label h6 mb-1" for="st_<?= $sid ?>">
                                                <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
                                            </label>
                                        </div>
                                        <p class="text-muted small mb-0 mt-2"><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4 d-flex justify-content-between">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>" class="btn btn-outline-secondary">
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
