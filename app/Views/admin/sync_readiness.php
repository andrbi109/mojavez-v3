<?php
/**
 * admin/sync_readiness.php — Stage2ReadinessChecker report (HTML view).
 *
 * Renders the 4 §29.2 readiness conditions returned by
 * Stage2ReadinessChecker::check() as Bootstrap cards with pass/fail badges
 * + the overall `ready` flag + a hint about how to enable Stage 2 via the
 * Settings page.
 *
 * The report shape (see Stage2ReadinessChecker::check()):
 *   {
 *     ready: bool,
 *     conditions: {
 *       stage1_stable:          {passed:bool, value:string, detail:string},
 *       false_positive_rate:    {passed:bool, value:string, detail:string},
 *       circuit_breaker_rate:   {passed:bool, value:string, detail:string},
 *       contract_tests:         {passed:bool, value:string, detail:string}
 *     },
 *     summary: string
 *   }
 *
 * @var array<string,mixed> $report
 * @var string $csrfField
 * @var string $baseUrl
 */
$ready      = (bool)($report['ready'] ?? false);
$conditions = is_array($report['conditions'] ?? null) ? $report['conditions'] : [];
$summary   = (string)($report['summary'] ?? '');

$conditionMeta = [
    'stage1_stable' => [
        'label' => 'پایداری مرحلهٔ ۱',
        'icon'  => 'bi-calendar-check',
        'desc'  => 'حداقل ۳ ماه در تولید بدون فاجعه اجرا شده باشد.',
    ],
    'false_positive_rate' => [
        'label' => 'نرخ false-positive در AutoConfirmed',
        'icon'  => 'bi-shield-exclamation',
        'desc'  => 'نرخ AutoConfirmed که بعداً operator_rejected شده زیر ۰.۱٪ باشد.',
    ],
    'circuit_breaker_rate' => [
        'label' => 'نرخ circuit breaker open',
        'icon'  => 'bi-cone-striped',
        'desc'  => 'نرخ روزهای با server_error در ۳۰ روز اخیر زیر ۵٪ باشد.',
    ],
    'contract_tests' => [
        'label' => 'وجود contract test',
        'icon'  => 'bi-file-earmark-check',
        'desc'  => 'حداقل یک فایل *Test.php در tests/Contract/ وجود داشته باشد.',
    ],
];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-clipboard2-check"></i> آمادگی مرحلهٔ ۲</h2>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/settings" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-gear"></i> تنظیمات همگام‌سازی
    </a>
</div>

<div class="card mb-3">
    <div class="card-body d-flex align-items-center gap-3">
        <div class="display-6">
            <?php if ($ready): ?>
                <i class="bi bi-check-circle-fill text-success"></i>
            <?php else: ?>
                <i class="bi bi-hourglass-split text-secondary"></i>
            <?php endif; ?>
        </div>
        <div class="flex-grow-1">
            <div class="h5 mb-1">وضعیت کلی</div>
            <?php if ($ready): ?>
                <span class="badge text-bg-success fs-6">آمادهٔ فعال‌سازی</span>
            <?php else: ?>
                <span class="badge text-bg-secondary fs-6">آماده نیست</span>
            <?php endif; ?>
            <div class="text-muted small mt-2"><?= htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
<?php foreach ($conditionMeta as $key => $meta): ?>
    <?php
        $cond = is_array($conditions[$key] ?? null) ? $conditions[$key] : null;
        $passed = is_array($cond) ? (bool)($cond['passed'] ?? false) : false;
        $value  = is_array($cond) ? (string)($cond['value'] ?? '—') : '—';
        $detail = is_array($cond) ? (string)($cond['detail'] ?? '') : 'اطلاعاتی موجود نیست.';
    ?>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <i class="bi <?= htmlspecialchars($meta['icon'], ENT_QUOTES) ?> fs-4"></i>
                        <span class="h6 ms-2"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php if ($passed): ?>
                        <span class="badge text-bg-success"><i class="bi bi-check-lg"></i> برقرار</span>
                    <?php else: ?>
                        <span class="badge text-bg-danger"><i class="bi bi-x-lg"></i> برقرار نیست</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted small mb-2"><?= htmlspecialchars($meta['desc'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="mb-1">
                    <span class="text-muted small">مقدار:</span>
                    <span dir="ltr" class="fw-medium"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="small text-muted"><?= htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    اگر همهٔ شرایط برقرار است، مدیر می‌تواند از صفحهٔ <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/settings" class="alert-link">تنظیمات</a>
    با تنظیم <code>sync_direction = two_way</code> و <code>sync_two_way_enabled = 1</code>
    مرحلهٔ ۲ (همگام‌سازی دوطرفه) را فعال کند. این یک runtime switch است و نیاز به deploy ندارد.
    در صورت بروز ۳ خطای ۵xx متوالی از upstream، kill-switch به‌صورت خودکار آن را غیرفعال می‌کند (§29.3).
</div>

<div class="mt-3">
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/status" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-return-right"></i> بازگشت به وضعیت همگام‌سازی
    </a>
</div>
