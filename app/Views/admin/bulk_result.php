<?php
/** @var array $rows */
/** @var int $inserted */
/** @var int $failed */
/** @var int $skipped */
/** @var int $total */
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body">
            <div class="display-6 text-success"><?= (int)$inserted ?></div>
            <small>درج شد</small>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body">
            <div class="display-6 text-danger"><?= (int)$failed ?></div>
            <small>خطا</small>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body">
            <div class="display-6 text-warning"><?= (int)$skipped ?></div>
            <small>skip شد</small>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center"><div class="card-body">
            <div class="display-6 text-secondary"><?= (int)$total ?></div>
            <small>کل</small>
        </div></div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-clipboard-check"></i> گزارش ایمپورت</h2>
    <a href="/admin/bulk-import" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-return-right"></i> ایمپورت جدید</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 70vh;">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light position-sticky top-0">
                    <tr><th>ردیف</th><th>نام</th><th>کد ملی</th><th>موبایل</th><th>کد رهگیری</th><th>وضعیت</th><th>پیام</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                        $badge = match ((string)$r['status']) {
                            'inserted' => 'text-bg-success',
                            'failed'   => 'text-bg-danger',
                            default    => 'text-bg-warning',
                        };
                    ?>
                    <tr>
                        <td><?= (int)$r['idx'] + 1 ?></td>
                        <td><?= htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td dir="ltr"><?= htmlspecialchars((string)$r['national_code'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td dir="ltr"><?= htmlspecialchars((string)$r['mobile'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?php if (!empty($r['tracking_code'])): ?><code><?= htmlspecialchars((string)$r['tracking_code'], ENT_QUOTES, 'UTF-8') ?></code><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                        <td><span class="badge <?= $badge ?>"><?= htmlspecialchars((string)$r['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td class="small"><?= htmlspecialchars((string)$r['message'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
