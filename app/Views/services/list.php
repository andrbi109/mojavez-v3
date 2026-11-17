<?php
/**
 * services/list.php — public list of service types as Bootstrap cards.
 *
 * Phase 15 (v1.6.0): rendered by ServiceController::index(). Lists the
 * active service_types as Bootstrap cards with title, description, and
 * a "انتخاب و شروع ثبت‌نام" button that POSTs to /services/{id}/purchase
 * (which requires login + redirects to /register/service/{id}).
 *
 * Expected $data:
 *   $serviceTypes  array<int, array{id, title, description, ...}> — the
 *                  service_types rows (sorted by sort_order).
 *   $baseUrl       string — site base URL.
 *   $csrfField     string — CSRF hidden input.
 */
/** @var array<int, array<string,mixed>> $serviceTypes */
/** @var string $baseUrl */
/** @var string $csrfField */

$serviceTypes ??= [];
$baseUrl      ??= '';
$csrfField    ??= '';
?>
<section class="py-4">
    <div class="container">
        <header class="text-center mb-5">
            <h1 class="h2 mb-2">
                <i class="bi bi-grid-3x3-gap text-brand"></i>
                خدمات صدور مجوز
            </h1>
            <p class="text-muted col-lg-7 mx-auto">
                یکی از انواع خدمات زیر را انتخاب کنید تا فرم ویزارد ۴ مرحله‌ای ثبت‌نام آغاز شود.
            </p>
        </header>

        <?php if ($serviceTypes === []): ?>
            <div class="alert alert-info text-center">
                در حال حاضر هیچ نوع خدمتی فعال نیست. بعداً تلاش کنید.
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($serviceTypes as $st): ?>
                    <?php
                    $sid   = (int)($st['id'] ?? 0);
                    $title = (string)($st['title'] ?? '');
                    $desc  = (string)($st['description'] ?? '');
                    $auth  = (string)($st['authority'] ?? '');
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm">
                            <div class="card-body d-flex flex-column p-4">
                                <h2 class="h5 card-title">
                                    <i class="bi bi-patch-check text-brand"></i>
                                    <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
                                </h2>
                                <?php if ($auth !== ''): ?>
                                    <div class="small text-muted mb-2">
                                        مرجع صدور: <?= htmlspecialchars($auth, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                                <p class="card-text text-muted small flex-grow-1">
                                    <?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <form method="post"
                                      action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>services/<?= $sid ?>/purchase"
                                      class="mt-3">
                                    <?= $csrfField ?>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-pencil-square"></i>
                                        انتخاب و شروع ثبت‌نام
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="text-center mt-5">
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-return-right"></i> بازگشت به خانه
            </a>
        </div>
    </div>
</section>
