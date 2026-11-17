<?php
/** @var string $username */
/** @var string $displayName */
/** @var string $role */
/** @var array $errors */
/** @var string $csrfField */
?>
<h2 class="h5 mb-3"><i class="bi bi-person-plus"></i> کاربر جدید</h2>
<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<div class="card"><div class="card-body">
<form method="post" action="/admin/users">
    <?= $csrfField ?>
    <div class="mb-3">
        <label class="form-label">نام کاربری</label>
        <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" required minlength="3">
    </div>
    <div class="mb-3">
        <label class="form-label">نام نمایشی</label>
        <input type="text" class="form-control" name="display_name" value="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">رمز عبور (حداقل ۸ کاراکتر)</label>
        <input type="password" class="form-control" name="password" required minlength="8">
    </div>
    <div class="mb-3">
        <label class="form-label">نقش</label>
        <select class="form-select" name="role">
            <option value="operator" <?= $role === 'operator' ? 'selected' : '' ?>>کارشناس</option>
            <option value="sync_operator" <?= $role === 'sync_operator' ? 'selected' : '' ?>>اپراتور همگام‌سازی</option>
            <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>مدیر کل</option>
        </select>
    </div>
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> ذخیره</button>
    <a href="/admin/users" class="btn btn-outline-secondary">انصراف</a>
</form>
</div></div>
