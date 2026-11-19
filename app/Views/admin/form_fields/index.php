<?php
/**
 * admin/form_fields/index.php — list all wizard form fields.
 *
 * @var array<int,array<string,mixed>> $rows
 * @var string $csrfField
 * @var string $baseUrl
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-input-cursor-text"></i> فیلدهای فرم</h2>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/form-fields/new" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> فیلد جدید
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>کلید</th>
                        <th>برچسب</th>
                        <th>نوع</th>
                        <th>نوع خدمت</th>
                        <th>گام ویزارد</th>
                        <th>وضعیت</th>
                        <th>ترتیب</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                        $isActive = (int)$r['is_active'] === 1;
                        $isRequired = (int)$r['is_required'] === 1;
                        $isSystem = (int)$r['is_system'] === 1;
                    ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= htmlspecialchars((string)$r['field_key'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$r['field_label'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge text-bg-secondary"><?= htmlspecialchars((string)$r['field_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string)($r['service_type_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($r['wizard_step_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($isActive): ?><span class="badge text-bg-success">فعال</span><?php else: ?><span class="badge text-bg-secondary">غیرفعال</span><?php endif; ?>
                            <?php if ($isRequired): ?><span class="badge text-bg-warning">اجباری</span><?php endif; ?>
                            <?php if ($isSystem): ?><span class="badge text-bg-info">سیستمی</span><?php endif; ?>
                        </td>
                        <td><?= (int)$r['sort_order'] ?></td>
                        <td class="text-end">
                            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/form-fields/move" class="d-inline">
                                <?= $csrfField ?>
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" name="direction" value="up"   class="btn btn-sm btn-outline-secondary" title="بالا"><i class="bi bi-arrow-up"></i></button>
                                <button type="submit" name="direction" value="down" class="btn btn-sm btn-outline-secondary" title="پایین"><i class="bi bi-arrow-down"></i></button>
                            </form>
                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/form-fields/<?= (int)$r['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/form-fields/<?= (int)$r['id'] ?>/delete" class="d-inline" onsubmit="return confirm('حذف این فیلد؟')">
                                <?= $csrfField ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">هیچ فیلدی تعریف نشده.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
