<?php
/**
 * admin/fee_tariff/edit.php — shared create/edit form for a fee tariff.
 *
 * @var array<string,mixed>           $tariff
 * @var array<int,array<string,mixed>> $serviceTypes
 * @var array<int,string>            $errors
 * @var string                        $csrfField
 * @var string                        $baseUrl
 */
$isEdit = !empty($tariff['id']);
?>
<h2 class="h5 mb-3">
    <i class="bi bi-<?= $isEdit ? 'pencil' : 'plus-lg' ?>"></i>
    <?= $isEdit ? 'ویرایش تعرفه' : 'تعرفهٔ جدید' ?>
</h2>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card"><div class="card-body">
<form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff<?= $isEdit ? '/' . (int)$tariff['id'] : '' ?>">
    <?= $csrfField ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">نوع خدمت</label>
            <select class="form-select" name="service_type_id">
                <option value="">—</option>
                <?php foreach ($serviceTypes as $st): ?>
                    <option value="<?= (int)$st['id'] ?>" <?= (int)($tariff['service_type_id'] ?? 0) === (int)$st['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$st['title'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">خالی = تعرفهٔ کلی (همهٔ انواع خدمت).</div>
        </div>
        <div class="col-md-3">
            <label class="form-label">دهک حداقل <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="decile_min" min="1" max="10" required
                   value="<?= (int)($tariff['decile_min'] ?? 1) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">دهک حداکثر <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="decile_max" min="1" max="10" required
                   value="<?= (int)($tariff['decile_max'] ?? 10) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">مبلغ پایه (تومان) <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="base_amount" min="0" step="1" required
                   value="<?= (int)($tariff['base_amount'] ?? 0) ?>">
            <div class="form-text">عدد صحیح به تومان. بدون ممیز.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label">معتبر از</label>
            <input type="date" class="form-control" name="valid_from"
                   value="<?= htmlspecialchars((string)($tariff['valid_from'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">معتبر تا</label>
            <input type="date" class="form-control" name="valid_until"
                   value="<?= htmlspecialchars((string)($tariff['valid_until'] ?? ''), ENT_QUOTES) ?>">
        </div>
        <div class="col-12">
            <label class="form-label">یادداشت</label>
            <textarea class="form-control" name="notes" rows="2" maxlength="500"><?= htmlspecialchars((string)($tariff['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="ft_act" <?= !empty($tariff['is_active']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ft_act">فعال</label>
            </div>
        </div>
    </div>
    <div class="mt-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> ذخیره</button>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff" class="btn btn-outline-secondary">انصراف</a>
    </div>
</form>
</div></div>
