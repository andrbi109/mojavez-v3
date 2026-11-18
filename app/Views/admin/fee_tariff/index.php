<?php
/**
 * admin/fee_tariff/index.php — list all fee tariffs.
 *
 * @var array<int,array<string,mixed>> $rows
 * @var string $csrfField
 * @var string $baseUrl
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-cash-stack"></i> تعرفهٔ هزینه</h2>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff/new" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> تعرفهٔ جدید
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>نوع خدمت</th>
                        <th>دهک</th>
                        <th>مبلغ پایه</th>
                        <th>معتبر از</th>
                        <th>معتبر تا</th>
                        <th>وضعیت</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $isActive = (int)$r['is_active'] === 1; ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= htmlspecialchars((string)($r['service_type_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td dir="ltr"><?= (int)$r['decile_min'] ?> – <?= (int)$r['decile_max'] ?></td>
                        <td dir="ltr"><?= htmlspecialchars(number_format((int)$r['base_amount']), ENT_QUOTES, 'UTF-8') ?> <span class="text-muted small">تومان</span></td>
                        <td dir="ltr" class="small text-muted"><?= htmlspecialchars((string)($r['valid_from'] ?? '—') ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td dir="ltr" class="small text-muted"><?= htmlspecialchars((string)($r['valid_until'] ?? '—') ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($isActive): ?><span class="badge text-bg-success">فعال</span><?php else: ?><span class="badge text-bg-secondary">غیرفعال</span><?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff/<?= (int)$r['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff/<?= (int)$r['id'] ?>/delete" class="d-inline" onsubmit="return confirm('حذف این تعرفه؟')">
                                <?= $csrfField ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">هیچ تعرفه‌ای ثبت نشده.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
