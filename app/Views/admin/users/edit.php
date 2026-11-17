<?php
/** @var array $user */
/** @var string $csrfField */
?>
<h2 class="h5 mb-3"><i class="bi bi-pencil"></i> ویرایش کاربر</h2>
<div class="card"><div class="card-body">
<form method="post" action="/admin/users/<?= (int)$user['id'] ?>">
    <?= $csrfField ?>
    <div class="mb-3">
        <label class="form-label">نام کاربری</label>
        <input type="text" class="form-control" value="<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?>" disabled>
        <div class="form-text">نام کاربری قابل تغییر نیست.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">نام نمایشی</label>
        <input type="text" class="form-control" name="display_name" value="<?= htmlspecialchars((string)$user['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">رمز عبور جدید (خالی = بدون تغییر)</label>
        <input type="password" class="form-control" name="password" minlength="8">
        <div class="form-text">برای عدم تغییر رمز، این فیلد را خالی بگذارید.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">نقش</label>
        <select class="form-select" name="role">
            <option value="operator" <?= $user['role'] === 'operator' ? 'selected' : '' ?>>کارشناس</option>
            <option value="sync_operator" <?= $user['role'] === 'sync_operator' ? 'selected' : '' ?>>اپراتور همگام‌سازی</option>
            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>مدیر کل</option>
        </select>
    </div>
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> به‌روزرسانی</button>
    <a href="/admin/users" class="btn btn-outline-secondary">انصراف</a>
</form>
</div></div>
