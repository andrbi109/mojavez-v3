<?php
/**
 * admin/form_fields/edit.php — shared create/edit form for a form field.
 *
 * The same view is used for both the create and edit actions (Laravel-style
 * resource pattern). For create, $field is a blank record. For edit, it
 * is the row fetched from form_fields.
 *
 * @var array<string,mixed>           $field
 * @var array<int,array<string,mixed>> $serviceTypes
 * @var array<int,array<string,mixed>> $wizardSteps
 * @var array<int,string>            $allowedTypes
 * @var array<int,string>            $errors
 * @var string                        $csrfField
 * @var string                        $baseUrl
 */
$isEdit = !empty($field['id']);
?>
<h2 class="h5 mb-3">
    <i class="bi bi-<?= $isEdit ? 'pencil' : 'plus-lg' ?>"></i>
    <?= $isEdit ? 'ویرایش فیلد' : 'فیلد جدید' ?>
</h2>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card"><div class="card-body">
<form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/form-fields<?= $isEdit ? '/' . (int)$field['id'] : '' ?>">
    <?= $csrfField ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">کلید فیلد <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="field_key"
                   value="<?= htmlspecialchars((string)($field['field_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                   required maxlength="64" pattern="[a-zA-Z][a-zA-Z0-9_]{0,63}">
            <div class="form-text">با حرف انگلیسی شروع شود؛ فقط حرف، عدد و زیرخط. باید یکتا باشد.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label">برچسب فیلد <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="field_label"
                   value="<?= htmlspecialchars((string)($field['field_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                   required maxlength="150">
        </div>
        <div class="col-md-4">
            <label class="form-label">نوع فیلد</label>
            <select class="form-select" name="field_type">
                <?php foreach ($allowedTypes as $t): ?>
                    <option value="<?= htmlspecialchars($t, ENT_QUOTES) ?>" <?= ($field['field_type'] ?? 'text') === $t ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">نوع خدمت</label>
            <select class="form-select" name="service_type_id">
                <option value="">—</option>
                <?php foreach ($serviceTypes as $st): ?>
                    <option value="<?= (int)$st['id'] ?>" <?= (int)($field['service_type_id'] ?? 0) === (int)$st['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$st['title'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">گام ویزارد</label>
            <select class="form-select" name="wizard_step_id">
                <option value="">—</option>
                <?php foreach ($wizardSteps as $ws): ?>
                    <option value="<?= (int)$ws['id'] ?>" <?= (int)($field['wizard_step_id'] ?? 0) === (int)$ws['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$ws['title'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">گزینه‌ها (JSON)</label>
            <textarea class="form-control" name="field_options" rows="3"
                      placeholder='{"items":["گزینهٔ ۱","گزینهٔ ۲"]}'><?= htmlspecialchars((string)($field['field_options'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            <div class="form-text">برای select/radio/checkbox — یک آرایهٔ JSON.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label">قواعد اعتبارسنجی (JSON)</label>
            <textarea class="form-control" name="validation_rules" rows="3"
                      placeholder='{"max_length":100}'><?= htmlspecialchars((string)($field['validation_rules'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            <div class="form-text">مانند <code>{"max_length":100,"min_length":3,"pattern":"^[0-9]+$"}</code>.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label">ترتیب</label>
            <input type="number" class="form-control" name="sort_order"
                   value="<?= (int)($field['sort_order'] ?? 0) ?>">
        </div>
        <div class="col-md-8 d-flex align-items-end gap-4 pt-1">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_required" value="1" id="ff_req" <?= !empty($field['is_required']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ff_req">اجباری</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_system" value="1" id="ff_sys" <?= !empty($field['is_system']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ff_sys">سیستمی</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="ff_act" <?= !empty($field['is_active']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ff_act">فعال</label>
            </div>
        </div>
    </div>
    <div class="mt-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> ذخیره</button>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/form-fields" class="btn btn-outline-secondary">انصراف</a>
    </div>
</form>
</div></div>
