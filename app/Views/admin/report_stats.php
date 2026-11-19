<?php
/** @var array $report */
/** @var string $title */
$report ??= ['title' => '', 'group_label' => '', 'total' => 0, 'rows' => []];
$groupLabel = $report['group_label'] ?? '';
$total      = (int)($report['total'] ?? 0);
$rows       = $report['rows'] ?? [];
?>
<div class="card">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h4 class="h5 fw-bold mb-0"><i class="bi bi-bar-chart"></i> آمار درخواست‌ها بر اساس <?= htmlspecialchars((string)$groupLabel, ENT_QUOTES, 'UTF-8') ?></h4>
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
                <?php foreach ($rows as $row): ?>
                    <?php
                        $label = (string)($row['label'] ?? '');
                        $count = (int)($row['count'] ?? 0);
                        $pct   = (float)($row['percentage'] ?? 0);
                    ?>
                    <tr>
                        <td>
                            <?php if ($label === '' || $label === '— (ثبت نشده)'): ?>
                                <span class="text-muted"><?= htmlspecialchars($label !== '' ? $label : '— (ثبت نشده)', ENT_QUOTES, 'UTF-8') ?></span>
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
                <?php if (empty($rows)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">داده‌ای برای نمایش وجود ندارد.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
