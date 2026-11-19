<?php
/** @var array $users */
/** @var string $csrfField */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-people"></i> کاربران سیستم</h2>
    <a href="/admin/users/new" class="btn btn-primary btn-sm"><i class="bi bi-person-plus"></i> کاربر جدید</a>
</div>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>#</th><th>نام کاربری</th><th>نام نمایشی</th><th>نقش</th><th>تاریخ ایجاد</th><th class="text-end">عملیات</th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <?php $roleLabel = $u['role'] === 'admin' ? 'مدیر کل' : ($u['role'] === 'sync_operator' ? 'اپراتور همگام‌سازی' : 'کارشناس'); ?>
                    <tr>
                        <td><?= (int)$u['id'] ?></td>
                        <td><?= htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$u['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge text-bg-secondary"><?= $roleLabel ?></span></td>
                        <td class="text-muted small"><?= htmlspecialchars((string)$u['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-end">
                            <a href="/admin/users/<?= (int)$u['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/delete" class="d-inline" onsubmit="return confirm('حذف این کاربر؟')">
                                <?= $csrfField ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">هیچ کاربری ثبت نشده.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
