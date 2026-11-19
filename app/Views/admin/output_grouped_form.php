<?php
/** @var array|null $report */
/** @var string $groupBy */
/** @var array $groupLabels */
/** @var string $csrfField */
$report     ??= null;
$groupBy     ??= 'province';
$groupLabels ??= [
    'province'          => 'استان',
    'city'              => 'شهر',
    'village'           => 'روستا',
    'license_title'     => 'عنوان مجوز',
    'registration_date' => 'تاریخ ثبت‌نام',
    'status'            => 'وضعیت',
    'license_state'     => 'وضعیت مجوز',
];
?>
<div class="card mb-4">
    <div class="card-body p-4">
        <h3 class="h5 mb-4"><i class="bi bi-table"></i> گزارش گروه‌بندی‌شده</h3>
        <form method="post" action="/admin/output/grouped">
            <?= $csrfField ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label">گروه‌بندی بر اساس:</label>
                    <select class="form-select" name="group_by">
                        <?php foreach ($groupLabels as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $groupBy === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-grid">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> مشاهدهٔ گزارش</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($report !== null && !empty($report['rows'])): ?>
    <?php
        $groupLabel = $report['group_label'] ?? '';
        $total = (int)($report['total'] ?? 0);
        $rows  = $report['rows'] ?? [];
    ?>
    <div class="card">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h4 class="h5 fw-bold mb-0"><i class="bi bi-bar-chart"></i> <?= htmlspecialchars((string)$report['title'], ENT_QUOTES, 'UTF-8') ?></h4>
                <span class="text-muted small">مجموع: <?= (int)$total ?> درخواست</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:55%"><?= htmlspecialchars((string)$groupLabel, ENT_QUOTES, 'UTF-8') ?></th>
                            <th style="width:15%">تعداد</th>
                            <th style="width:30%">سهم</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $label = (string)($row['label'] ?? '');
                        $count = (int)($row['count'] ?? 0);
                        $pct   = (float)($row['percentage'] ?? 0);
                    ?>
                        <tr>
                            <td>
                                <?php if ($label === '' || $label === '— (ثبت نشده)'): ?>
                                    <span class="text-muted">— (ثبت نشده)</span>
                                <?php elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $label)): ?>
                                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge text-bg-primary"><?= $count ?></span></td>
                            <td>
                                <div class="progress" style="height:10px">
                                    <div class="progress-bar" role="progressbar" style="width: <?= htmlspecialchars((string)$pct, ENT_QUOTES, 'UTF-8') ?>%" aria-valuenow="<?= htmlspecialchars((string)$pct, ENT_QUOTES, 'UTF-8') ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <small class="text-muted"><?= htmlspecialchars((string)$pct, ENT_QUOTES, 'UTF-8') ?>٪</small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php elseif ($report !== null): ?>
    <div class="card"><div class="card-body text-center text-muted py-4">
        داده‌ای برای نمایش وجود ندارد.
    </div></div>
<?php endif; ?>
<!-- end output_grouped_form -->
