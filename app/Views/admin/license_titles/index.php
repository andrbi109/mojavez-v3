<?php
/**
 * admin/license_titles/index.php — list all license titles.
 *
 * @var array<int,array<string,mixed>> $rows
 * @var string $csrfField
 * @var string $baseUrl
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-award"></i> عناوین مجوز</h2>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/license-titles/new" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> عنوان جدید
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>عنوان</th>
                        <th>طبقه‌بندی</th>
                        <th>نوع خدمت</th>
                        <th>ترتیب</th>
                        <th>وضعیت</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $isActive = (int)$r['is_active'] === 1; ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['category'] ?? '—') ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['service_type_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)$r['sort_order'] ?></td>
                        <td>
                            <?php if ($isActive): ?><span class="badge text-bg-success">فعال</span><?php else: ?><span class="badge text-bg-secondary">غیرفعال</span><?php endif; ?>
                        </td>
                        <td class="text-end">
                            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/license-titles/<?= (int)$r['id'] ?>/toggle" class="d-inline">
                                <?= $csrfField ?>
                                <button type="submit" class="btn btn-sm btn-outline-<?= $isActive ? 'warning' : 'success' ?>" title="تغییر وضعیت">
                                    <i class="bi bi-<?= $isActive ? 'pause-fill' : 'play-fill' ?>"></i>
                                </button>
                            </form>
                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/license-titles/<?= (int)$r['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/license-titles/<?= (int)$r['id'] ?>/delete" class="d-inline" onsubmit="return confirm('حذف این عنوان؟')">
                                <?= $csrfField ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">هیچ عنوان مجوزی ثبت نشده.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
