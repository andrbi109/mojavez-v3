<?php
/**
 * admin/gateway_sync_status.php — HTML view of /admin/sync/status.
 *
 * Phase 41 (v2.0.7) — converted from JSON to HTML. Renders the latest
 * golden-link sync cycle summary as Bootstrap cards:
 *   - last cycle summary (started_at / ended_at)
 *   - jobs drained this poll (poor man's cron tick)
 *   - matches found (auto_confirmed + operator_flagged + auto_rejected)
 *   - conflicts / outbound two-way status
 *   - run-sync-now button (POST /admin/sync/run)
 *
 * Expected $data:
 *   $status   array{cycle_started_at:?string,cycle_ended_at:?string,
 *                  counts:array<string,int>,jobs_drained:int}
 *   $syncInfo array{direction:string,two_way_enabled:bool}
 *   $jobsRun  int  (alias of $status['jobs_drained']).
 *   $baseUrl, $csrfField.
 */
/** @var array<string,mixed> $status */
/** @var array{direction:string,two_way_enabled:bool} $syncInfo */
/** @var int $jobsRun */
/** @var string $baseUrl */
/** @var string $csrfField */

$cycleStart = $status['cycle_started_at'] ?? null;
$cycleEnd   = $status['cycle_ended_at']   ?? null;
$counts     = is_array($status['counts'] ?? null) ? $status['counts'] : [];
$jobsDrained = (int)($status['jobs_drained'] ?? $jobsRun ?? 0);

$autoConfirmed = (int)($counts['auto_confirmed'] ?? 0);
$operatorFlag  = (int)($counts['operator_flag']  ?? $counts['operator_flagged'] ?? 0);
$autoRejected  = (int)($counts['auto_rejected']  ?? 0);
$totalMatches  = $autoConfirmed + $operatorFlag + $autoRejected;

$direction   = (string)($syncInfo['direction']    ?? 'inbound');
$twoWayOn    = (bool)($syncInfo['two_way_enabled'] ?? false);

$directionFa = match ($direction) {
    'disabled' => 'غیرفعال',
    'inbound'  => 'یک‌طرفه (دریافت)',
    'two_way'  => 'دوطرفه',
    default    => $direction,
};
$directionClass = match ($direction) {
    'disabled' => 'text-bg-secondary',
    'inbound'  => 'text-bg-primary',
    'two_way'  => 'text-bg-success',
    default    => 'text-bg-secondary',
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-cloud-arrow-down text-brand"></i> وضعیت همگام‌سازی درگاه ملی</h2>
    <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/run"
        class="d-inline" onsubmit="return confirm('یک دور کامل همگام‌سازی اجرا شود؟');">
        <?= $csrfField ?>
        <button type="submit" class="btn btn-success btn-sm">
            <i class="bi bi-play-circle"></i> اجرای همگام‌سازی
        </button>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-arrow-repeat display-6 text-brand"></i>
                <div class="h2 mt-2 mb-0"><?= (int)$jobsDrained ?></div>
                <div class="text-muted small">کار تخلیه‌شده (poor man's cron)</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-patch-check display-6 text-success"></i>
                <div class="h2 mt-2 mb-0"><?= $autoConfirmed ?></div>
                <div class="text-muted small">تطبیق‌های تأیید خودکار</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-flag display-6 text-warning"></i>
                <div class="h2 mt-2 mb-0"><?= $operatorFlag ?></div>
                <div class="text-muted small">پرچم اپراتور</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-x-octagon display-6 text-danger"></i>
                <div class="h2 mt-2 mb-0"><?= $autoRejected ?></div>
                <div class="text-muted small">رد خودکار</div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-clock-history"></i> آخرین دور همگام‌سازی
    </div>
    <div class="card-body">
        <dl class="row mb-0 small">
            <dt class="col-sm-4 text-muted">شروع دور</dt>
            <dd class="col-sm-8" dir="ltr">
                <?= $cycleStart !== null ? htmlspecialchars((string)$cycleStart, ENT_QUOTES, 'UTF-8') : '—' ?>
            </dd>
            <dt class="col-sm-4 text-muted">پایان دور</dt>
            <dd class="col-sm-8" dir="ltr">
                <?= $cycleEnd !== null ? htmlspecialchars((string)$cycleEnd, ENT_QUOTES, 'UTF-8') : '—' ?>
            </dd>
            <dt class="col-sm-4 text-muted">مجموع تطبیق‌های این دور</dt>
            <dd class="col-sm-8"><?= $totalMatches ?></dd>
        </dl>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-arrow-left-right"></i> وضعیت همگام‌سازی دوطرفه (Stage 2)
    </div>
    <div class="card-body d-flex align-items-center gap-3">
        <span class="badge <?= htmlspecialchars($directionClass, ENT_QUOTES) ?> fs-6">
            <?= htmlspecialchars($directionFa, ENT_QUOTES, 'UTF-8') ?>
        </span>
        <?php if ($twoWayOn): ?>
            <span class="badge text-bg-success"><i class="bi bi-check-lg"></i> فعال</span>
        <?php else: ?>
            <span class="badge text-bg-secondary">غیرفعال</span>
        <?php endif; ?>
        <div class="text-muted small">
            همگام‌سازی دوطرفه فقط زمانی فعال است که <code>sync_direction = two_way</code>
            و <code>sync_two_way_enabled = 1</code> باشند.
        </div>
    </div>
</div>

<div class="alert alert-info small mb-0">
    <i class="bi bi-info-circle"></i>
    هر بار که این صفحه را باز می‌کنید، یک کار از صف همگام‌سازی اجرا می‌شود
    (الگوی <strong>poor man's cron</strong>). برای اجرای یک دور کامل، دکمهٔ
    «اجرای همگام‌سازی» را بزنید.
</div>

<div class="mt-3 d-flex gap-2">
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/pending"
        class="btn btn-outline-primary btn-sm">
        <i class="bi bi-hourglass-split"></i> مشاهدهٔ تطبیق‌های در انتظار بررسی
    </a>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/dashboard"
        class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-return-right"></i> بازگشت به داشبورد
    </a>
</div>
