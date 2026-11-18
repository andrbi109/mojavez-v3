<?php
/** @var int $cancelled */
/** @var int $prioritised */
/** @var int $progressed */
/** @var int $totalApplicants */
/** @var int $pendingReview */
/** @var int $issuedThisMonth */
?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-folder2-open display-6 text-brand"></i>
                <div class="h2 mt-2 mb-0"><?= htmlspecialchars((string)$totalApplicants, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-muted small">کل پرونده‌ها</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-hourglass-split display-6 text-warning"></i>
                <div class="h2 mt-2 mb-0"><?= htmlspecialchars((string)$pendingReview, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-muted small">در انتظار بررسی</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-patch-check display-6 text-success"></i>
                <div class="h2 mt-2 mb-0"><?= htmlspecialchars((string)$issuedThisMonth, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-muted small">صادرشده این ماه</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-x-octagon display-6 text-danger"></i>
                <div class="h2 mt-2 mb-0"><?= htmlspecialchars((string)$cancelled, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-muted small">لغو خودکار</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-arrow-up-circle"></i> اولویت‌بندی خودکار
            </div>
            <div class="card-body">
                <div class="display-6 text-brand"><?= htmlspecialchars((string)$prioritised, ENT_QUOTES, 'UTF-8') ?></div>
                <p class="text-muted mb-0">پرونده‌هایی که در صف اولویت قرار گرفته‌اند.</p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-lightning-charge"></i> اقدامات سریع
            </div>
            <div class="card-body d-grid gap-2">
                <a href="/admin/applications" class="btn btn-outline-primary">
                    <i class="bi bi-list-ul"></i> مشاهدهٔ همهٔ درخواست‌ها
                </a>
                <a href="/admin/sync/status" class="btn btn-outline-primary">
                    <i class="bi bi-cloud-arrow-down"></i> وضعیت همگام‌سازی درگاه ملی
                </a>
            </div>
        </div>
    </div>
</div>
<?php if ((int)($progressed ?? 0) > 0): ?>
<div class="alert alert-info small">
    <i class="bi bi-info-circle"></i>
    پیشرفت خودکار: <?= htmlspecialchars((string)$progressed, ENT_QUOTES, 'UTF-8') ?>
    پروندهٔ متوقف در وضعیت پیش از صدور، یک گام به جلو حرکت داده شد.
</div>
<?php endif;

