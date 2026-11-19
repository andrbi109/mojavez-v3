<?php
/** @var int $cancelled */
/** @var int $prioritised */
/** @var int $progressed */
/** @var int $totalApplicants */
/** @var int $pendingReview */
/** @var int $issuedThisMonth */
/** @var array<int,array<string,mixed>> $recentRegistrations */
/** @var array<int,array<string,mixed>> $pendingPayments */
/** @var array<int,array<string,mixed>> $recentLicenses */
/** @var array<int,array<string,mixed>> $recentSyncJobs */

use App\Shared\Support\PersianDate;

$recentRegistrations ??= [];
$pendingPayments     ??= [];
$recentLicenses      ??= [];
$recentSyncJobs      ??= [];

/** Tiny helper: format a datetime (or null) into the Persian short form. */
$fmtDate = static function (?string $ts): string {
    if ($ts === null || $ts === '') {
        return '—';
    }
    try {
        return htmlspecialchars(PersianDate::format($ts), ENT_QUOTES, 'UTF-8');
    } catch (\Throwable) {
        return htmlspecialchars((string)$ts, ENT_QUOTES, 'UTF-8');
    }
};
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
<?php endif; ?>

<!-- Phase 57a — admin notifications widget. A card per event kind,
     each with a Bootstrap list-group inside so the admin can scan
     recent system activity at a glance. -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2 py-2">
                <i class="bi bi-person-plus text-primary"></i>
                <span class="small fw-semibold">ثبت‌نام‌های جدید</span>
                <span class="badge text-bg-light text-muted ms-auto"><?= count($recentRegistrations) ?></span>
            </div>
            <ul class="list-group list-group-flush small" style="max-height: 14rem; overflow-y: auto;">
                <?php if (empty($recentRegistrations)): ?>
                    <li class="list-group-item text-muted text-center py-3">موردی ثبت نشده.</li>
                <?php else: foreach ($recentRegistrations as $r): ?>
                    <li class="list-group-item px-3 py-2">
                        <div class="d-flex justify-content-between align-items-baseline gap-2">
                            <span class="fw-semibold">
                                <?= htmlspecialchars(
                                    trim((string)$r['first_name'] . ' ' . (string)$r['last_name']),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                            <span class="text-muted" style="font-size:.72rem" dir="ltr">
                                <?= $fmtDate((string)$r['created_at']) ?>
                            </span>
                        </div>
                        <div class="text-muted" style="font-size:.72rem" dir="ltr">
                            <?= htmlspecialchars((string)$r['tracking_code'], ENT_QUOTES, 'UTF-8') ?>
                            · <?= htmlspecialchars((string)$r['national_code'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2 py-2">
                <i class="bi bi-credit-card-2-front text-warning"></i>
                <span class="small fw-semibold">درخواست‌های پرداخت</span>
                <span class="badge text-bg-light text-muted ms-auto"><?= count($pendingPayments) ?></span>
            </div>
            <ul class="list-group list-group-flush small" style="max-height: 14rem; overflow-y: auto;">
                <?php if (empty($pendingPayments)): ?>
                    <li class="list-group-item text-muted text-center py-3">در انتظاری وجود ندارد.</li>
                <?php else: foreach ($pendingPayments as $p): ?>
                    <li class="list-group-item px-3 py-2">
                        <div class="d-flex justify-content-between align-items-baseline gap-2">
                            <span class="fw-semibold">
                                <?= htmlspecialchars(
                                    trim((string)$p['first_name'] . ' ' . (string)$p['last_name']),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                            <span class="text-muted" style="font-size:.72rem" dir="ltr">
                                <?= $fmtDate((string)$p['created_at']) ?>
                            </span>
                        </div>
                        <div class="text-muted" style="font-size:.72rem" dir="ltr">
                            <?= htmlspecialchars((string)$p['tracking_code'], ENT_QUOTES, 'UTF-8') ?>
                            · <?= htmlspecialchars(number_format((int)$p['amount']), ENT_QUOTES, 'UTF-8') ?> تومان
                            · <?= htmlspecialchars((string)$p['purpose'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2 py-2">
                <i class="bi bi-patch-check-fill text-success"></i>
                <span class="small fw-semibold">آپلود پروانه‌ها</span>
                <span class="badge text-bg-light text-muted ms-auto"><?= count($recentLicenses) ?></span>
            </div>
            <ul class="list-group list-group-flush small" style="max-height: 14rem; overflow-y: auto;">
                <?php if (empty($recentLicenses)): ?>
                    <li class="list-group-item text-muted text-center py-3">پروانه‌ای آپلود نشده.</li>
                <?php else: foreach ($recentLicenses as $l): ?>
                    <li class="list-group-item px-3 py-2">
                        <div class="d-flex justify-content-between align-items-baseline gap-2">
                            <span class="fw-semibold">
                                <?= htmlspecialchars(
                                    trim((string)$l['first_name'] . ' ' . (string)$l['last_name']),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                            <span class="text-muted" style="font-size:.72rem" dir="ltr">
                                <?= $fmtDate((string)$l['issued_at']) ?>
                            </span>
                        </div>
                        <div class="text-muted" style="font-size:.72rem" dir="ltr">
                            <?= htmlspecialchars((string)$l['tracking_code'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2 py-2">
                <i class="bi bi-arrow-repeat text-info"></i>
                <span class="small fw-semibold">همگام‌سازی‌های اخیر</span>
                <span class="badge text-bg-light text-muted ms-auto"><?= count($recentSyncJobs) ?></span>
            </div>
            <ul class="list-group list-group-flush small" style="max-height: 14rem; overflow-y: auto;">
                <?php if (empty($recentSyncJobs)): ?>
                    <li class="list-group-item text-muted text-center py-3">رویداد همگام‌سازی وجود ندارد.</li>
                <?php else: foreach ($recentSyncJobs as $s): ?>
                    <li class="list-group-item px-3 py-2">
                        <div class="d-flex justify-content-between align-items-baseline gap-2">
                            <span class="fw-semibold">
                                <?= htmlspecialchars((string)($s['action'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                <span class="badge text-bg-<?= ((string)($s['outcome'] ?? '') === 'success') ? 'success' : 'secondary' ?> ms-1">
                                    <?= htmlspecialchars((string)($s['outcome'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </span>
                            <span class="text-muted" style="font-size:.72rem" dir="ltr">
                                <?= $fmtDate((string)$s['created_at']) ?>
                            </span>
                        </div>
                        <div class="text-muted" style="font-size:.72rem" dir="ltr">
                            <?php if (!empty($s['national_request_number'])): ?>
                                <?= htmlspecialchars((string)$s['national_request_number'], ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                اطمینان: <?= (int)($s['confidence'] ?? 0) ?>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
</div>
