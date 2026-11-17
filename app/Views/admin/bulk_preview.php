<?php
/** @var array $rows */
/** @var int $totalCount */
/** @var int $validCount */
/** @var int $invalidCount */
/** @var string $filename */
/** @var array $columns */
/** @var string $csrfField */
$filename     ??= '';
$columns      ??= ['national_code', 'first_name', 'last_name', 'mobile', 'service_type_id', 'postal_code'];
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="h5 mb-0"><i class="bi bi-eye"></i> پیش‌نمایش ایمپورت</h2>
    <span class="text-muted small">
        فایل: <code><?= htmlspecialchars((string)$filename, ENT_QUOTES, 'UTF-8') ?></code>
        · <?= (int)$totalCount ?> ردیف
        · <span class="text-success">معتبر: <?= (int)$validCount ?></span>
        · <span class="text-danger">نامعتبر: <?= (int)$invalidCount ?></span>
    </span>
</div>

<?php if ($invalidCount > 0): ?>
    <div class="alert alert-warning py-2 small">
        ردیف‌های نامعتبر در زمان اجرا skip می‌شوند (درج نم‌شوند).
    </div>
<?php endif; ?>

<form method="post" action="/admin/bulk-import/execute"
      onsubmit="return confirm('ایمپورت <?= (int)$validCount ?> ردیف معتبر اجرا شود؟');">
    <?= $csrfField ?>
    <button type="submit" class="btn btn-success mb-3"><i class="bi bi-play-circle"></i> اجرای ایمپورت (<?= (int)$validCount ?> ردیف معتبر)</button>
</form>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <?php foreach ($columns as $col): ?>
                            <th><?= htmlspecialchars((string)$col, ENT_QUOTES, 'UTF-8') ?></th>
                        <?php endforeach; ?>
                        <th>وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr class="<?= $r['valid'] ? '' : 'table-warning' ?>">
                        <td><?= (int)$r['idx'] + 1 ?></td>
                        <td dir="ltr"><?= htmlspecialchars((string)$r['national_code'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$r['first_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$r['last_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td dir="ltr"><?= htmlspecialchars((string)$r['mobile'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$r['service_type_id'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td dir="ltr"><?= htmlspecialchars((string)$r['postal_code'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($r['valid']): ?>
                                <span class="badge text-bg-success">معتبر</span>
                            <?php else: ?>
                                <span class="badge text-bg-danger" title="<?= htmlspecialchars(implode('، ', $r['errors']), ENT_QUOTES, 'UTF-8') ?>">نامعتبر</span>
                                <div class="small text-muted"><?= htmlspecialchars(implode('، ', $r['errors']), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php if ((int)$totalCount > 100): ?>
    <p class="text-muted small mt-2">نمایش ۱۰۰ ردیف اول از <?= (int)$totalCount ?> ردیف — کل ردیف‌ها در زمان اجرا پردازش می‌شوند.</p>
<?php endif; ?>
<!-- end bulk_preview -->
