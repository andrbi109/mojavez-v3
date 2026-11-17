<?php
/**
 * registration/select_service.php — wizard step 0: choose service type.
 *
 * Phase 14 (v1.5.0) — pays D-7-3. This is the wizard's entry view: the
 * applicant picks one of the 5 default service types (seeded by
 * migration 009) before going to step 1. Each card links via GET to
 * /register/service/{id} (which the RegistrationController::selectService
 * resolves to step 1 — creating an in-progress applicants row).
 *
 * Expected $data:
 *   $serviceTypes  array<int, array{id, title, description}> — the
 *                  service_types rows (sorted by sort_order).
 *   $baseUrl       string — site base URL.
 *   $csrfField     string — CSRF hidden input (unused on GET but kept for
 *                  consistency with the rest of the wizard).
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

                <div class="row g-3">
                    <?php foreach ($serviceTypes as $st): ?>
                        <?php
                        $sid = (int)($st['id'] ?? 0);
                        $title = (string)($st['title'] ?? '');
                        $desc = (string)($st['description'] ?? '');
                        $authority = (string)($st['authority'] ?? '');
                        ?>
                        <div class="col-md-6">
                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/service/<?= $sid ?>"
                               class="card h-100 service-type-option text-decoration-none">
                                <div class="card-body">
                                    <h2 class="h6 mb-1">
                                        <i class="bi bi-patch-check text-brand"></i>
                                        <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
                                    </h2>
                                    <?php if ($authority !== ''): ?>
                                        <div class="small text-muted mb-2">مرجع صدور: <?= htmlspecialchars($authority, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <p class="text-muted small mb-0 mt-2"><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <div class="card-footer bg-white border-top-0 text-end">
                                    <span class="btn btn-primary btn-sm">
                                        انتخاب و شروع <i class="bi bi-arrow-left"></i>
                                    </span>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-4">
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-return-right"></i> بازگشت
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
