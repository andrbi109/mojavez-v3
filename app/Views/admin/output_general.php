<?php
/** @var int $total */
/** @var array $byStatus */
/** @var array $byServiceType */
/** @var array $byLicenseState */
/** @var array $monthlyTrend */
$total          ??= 0;
$byStatus       ??= [];
$byServiceType  ??= [];
$byLicenseState ??= [];
$monthlyTrend   ??= [];
?>
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card h-100"><div class="card-body">
            <div class="display-6 text-primary"><?= number_format((int)$total) ?></div>
            <small class="text-muted">کل متقاضیان</small>
        </div></div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card h-100"><div class="card-body">
            <div class="display-6 text-success"><?= count($byStatus) ?></div>
            <small class="text-muted">وضعیت‌های متمایز</small>
        </div></div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card h-100"><div class="card-body">
            <div class="display-6 text-info"><?= count($byServiceType) ?></div>
            <small class="text-muted">انواع خدمت</small>
        </div></div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card h-100"><div class="card-body">
            <div class="display-6 text-warning"><?= count($byLicenseState) ?></div>
            <small class="text-muted">وضعیت‌های مجوز</small>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart"></i> بر اساس وضعیت فعلی</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>وضعیت</th><th>تعداد</th><th>سهم</th></tr></thead>
                    <tbody>
                    <?php foreach ($byStatus as $r):
                        $cnt = (int)$r['cnt'];
                        $pct = $total > 0 ? round($cnt * 100 / $total, 1) : 0; ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$r['label'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $cnt ?></td>
                            <td class="text-muted small"><?= htmlspecialchars((string)$pct, ENT_QUOTES, 'UTF-8') ?>٪</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($byStatus)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">داده‌ای نیست</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-award"></i> بر اساس وضعیت مجوز</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>وضعیت مجوز</th><th>تعداد</th><th>سهم</th></tr></thead>
                    <tbody>
                    <?php foreach ($byLicenseState as $r):
                        $cnt = (int)$r['cnt'];
                        $pct = $total > 0 ? round($cnt * 100 / $total, 1) : 0; ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$r['label'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $cnt ?></td>
                            <td class="text-muted small"><?= htmlspecialchars((string)$pct, ENT_QUOTES, 'UTF-8') ?>٪</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($byLicenseState)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">داده‌ای نیست</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-briefcase"></i> بر اساس نوع خدمت</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>نوع خدمت</th><th>تعداد</th><th>سهم</th></tr></thead>
                    <tbody>
                    <?php foreach ($byServiceType as $r):
                        $cnt = (int)$r['cnt'];
                        $pct = $total > 0 ? round($cnt * 100 / $total, 1) : 0; ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$r['label'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $cnt ?></td>
                            <td class="text-muted small"><?= htmlspecialchars((string)$pct, ENT_QUOTES, 'UTF-8') ?>٪</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($byServiceType)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">داده‌ای نیست</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-calendar3"></i> روند ماهانه (۱۲ ماه اخیر)</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>ماه</th><th>تعداد</th></tr></thead>
                    <tbody>
                    <?php foreach ($monthlyTrend as $r): ?>
                        <tr>
                            <td dir="ltr"><?= htmlspecialchars((string)$r['label'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int)$r['cnt'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($monthlyTrend)): ?>
                        <tr><td colspan="2" class="text-center text-muted py-3">داده‌ای نیست</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-body">
        <h3 class="h6">خروجی کامل</h3>
        <a href="/admin/report/csv" class="btn btn-success btn-sm me-2" target="_blank">
            <i class="bi bi-filetype-csv"></i> خروجی CSV همهٔ متقاضیان
        </a>
        <a href="/admin/report/pdf" class="btn btn-info btn-sm" target="_blank">
            <i class="bi bi-filetype-pdf"></i> خروجی PDF (قابل چاپ)
        </a>
    </div>
</div>
