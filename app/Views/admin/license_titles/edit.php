<?php
/**
 * admin/license_titles/edit.php — shared create/edit form for a license title.
 *
 * Variable is named `$title_` (with trailing underscore) to avoid colliding
 * with the layout's `$title` page-title string.
 *
 * @var array<string,mixed>           $title_
 * @var array<int,array<string,mixed>> $serviceTypes
 * @var array<int,string>            $errors
 * @var string                        $csrfField
 * @var string                        $baseUrl
 */
$isEdit = !empty($title_['id']);
?>
<h2 class="h5 mb-3">
    <i class="bi bi-<?= $isEdit ? 'pencil' : 'plus-lg' ?>"></i>
    <?= $isEdit ? 'ویرایش عنوان مجوز' : 'عنوان مجوز جدید' ?>
</h2>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card"><div class="card-body">
<form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/license-titles<?= $isEdit ? '/' . (int)$title_['id'] : '' ?>">
    <?= $csrfField ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">عنوان مجوز <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="title"
                   value="<?= htmlspecialchars((string)($title_['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                   required maxlength="150">
        </div>
        <div class="col-md-3">
            <label class="form-label">طبقه‌بندی</label>
            <input type="text" class="form-control" name="category"
                   value="<?= htmlspecialchars((string)($title_['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                   maxlength="100">
        </div>
        <div class="col-md-3">
            <label class="form-label">نوع خدمت</label>
            <select class="form-select" name="service_type_id">
                <option value="">—</option>
                <?php foreach ($serviceTypes as $st): ?>
                    <option value="<?= (int)$st['id'] ?>" <?= (int)($title_['service_type_id'] ?? 0) === (int)$st['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$st['title'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">خالی = همهٔ انواع خدمت.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label">ترتیب</label>
            <input type="number" class="form-control" name="sort_order" dir="ltr"
                   value="<?= (int)($title_['sort_order'] ?? 0) ?>">
        </div>
        <div class="col-md-9 d-flex align-items-end pt-1">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="lt_act" <?= !empty($title_['is_active']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="lt_act">فعال</label>
            </div>
        </div>
    </div>
    <div class="mt-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> ذخیره</button>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/license-titles" class="btn btn-outline-secondary">انصراف</a>
    </div>
</form>
</div></div>
