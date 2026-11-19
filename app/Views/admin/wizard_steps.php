<?php
/**
 * admin/wizard_steps.php — list + add/edit form for wizard_steps.
 *
 * Phase 17 (v1.8.0): rendered by WizardStepController::index().
 * Lists all wizard steps grouped by service_type, ordered by sort_order.
 * Each row has up/down (move), toggle active, delete buttons.
 *
 * @var array<int, array<string,mixed>>        $rows
 * @var array<int, array<string,mixed>>        $serviceTypes
 * @var array<string,mixed>|null               $editing
 * @var array<int,string>                      $errors
 * @var string $csrfField
 * @var string $baseUrl
 */
/** @var array<int, array<string,mixed>> $rows */
/** @var array<int, array<string,mixed>> $serviceTypes */
/** @var array<string,mixed>|null $editing */
/** @var array<int,string> $errors */
/** @var string $csrfField */
/** @var string $baseUrl */

$rows         ??= [];
$serviceTypes ??= [];
$editing     ??= null;
$errors       ??= [];
$csrfField    ??= '';
$baseUrl      ??= '/';

/** @return string */
$ev = static function (string $key, ?string $default = '') use ($editing): string {
    if ($editing === null) {
        return htmlspecialchars((string)$default, ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars((string)($editing[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-diagram-3"></i> مراحل ویزارد ثبت‌نام</h2>
</div>

<?php if ($errors !== []): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header">
        <i class="bi <?= $editing === null ? 'bi-plus-lg' : 'bi-pencil' ?>"></i>
        <?= $editing === null ? 'افزودن مرحله' : 'ویرایش مرحله (#' . (int)$editing['id'] . ')' ?>
    </div>
    <div class="card-body">
        <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/wizard-steps/save">
            <?= $csrfField ?>
            <?php if ($editing !== null): ?>
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">عنوان <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title"
                           value="<?= $ev('title') ?>" maxlength="150" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">کلید نمایش (view_key) <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="view_key"
                           value="<?= $ev('view_key') ?>" maxlength="64"
                           pattern="[A-Za-z][A-Za-z0-9_]{0,63}" required>
                    <div class="form-text small text-muted">
                        باید یکتا باشد. مراحل سیستمی: step1، step2، step3، step4.
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">نوع خدمت</label>
                    <select class="form-select" name="service_type_id">
                        <option value="">— (مشترک) —</option>
                        <?php foreach ($serviceTypes as $st): ?>
                            <?php $sid = (int)$st['id']; ?>
                            <option value="<?= $sid ?>"
                                <?= ((int)($editing['service_type_id'] ?? 0) === $sid) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$st['title'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">آیکون</label>
                    <input type="text" class="form-control" name="icon"
                           value="<?= $ev('icon') ?>" maxlength="50">
                </div>
                <div class="col-md-3">
                    <label class="form-label">ترتیب</label>
                    <input type="number" class="form-control" name="sort_order"
                           value="<?= $ev('sort_order', '0') ?>" min="0">
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" value="1"
                               id="ws-active" <?= ((int)($editing['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ws-active">فعال</label>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <?php if ($editing !== null): ?>
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/wizard-steps"
                       class="btn btn-outline-secondary btn-sm">انصراف</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check-lg"></i> ذخیره
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>عنوان</th>
                        <th>view_key</th>
                        <th>نوع خدمت</th>
                        <th>آیکون</th>
                        <th>ترتیب</th>
                        <th>سیستمی</th>
                        <th>وضعیت</th>
                        <th>تعداد فیلدها</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $sid = (int)$r['id'];
                            $isSystem = (int)$r['is_system'] === 1;
                            $isActive = (int)$r['is_active'] === 1;
                            $fieldCount = (int)($r['field_count'] ?? 0);
                        ?>
                        <tr>
                            <td><?= $sid ?></td>
                            <td><?= htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><code><?= htmlspecialchars((string)$r['view_key'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td class="text-muted small">
                                <?= htmlspecialchars((string)($r['service_type_title'] ?? '—') ?: '—', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-muted small"><?= htmlspecialchars((string)($r['icon'] ?? '—') ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int)$r['sort_order'] ?></td>
                            <td>
                                <?php if ($isSystem): ?>
                                    <span class="badge text-bg-info">سیستمی</span>
                                <?php else: ?>
                                    <span class="badge text-bg-light text-muted border">سفارشی</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge text-bg-success">فعال</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">غیرفعال</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $fieldCount ?></td>
                            <td class="text-end">
                                <form method="post"
                                      action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/wizard-steps/move"
                                      class="d-inline">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= $sid ?>">
                                    <button type="submit" name="direction" value="up" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-arrow-up"></i>
                                    </button>
                                    <button type="submit" name="direction" value="down" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-arrow-down"></i>
                                    </button>
                                </form>
                                <form method="post"
                                      action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/wizard-steps/toggle/<?= $sid ?>"
                                      class="d-inline">
                                    <?= $csrfField ?>
                                    <button type="submit" class="btn btn-sm btn-outline-warning"
                                            title="<?= $isActive ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?>">
                                        <i class="bi <?= $isActive ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                    </button>
                                </form>
                                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/wizard-steps?edit=<?= $sid ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if (!$isSystem): ?>
                                    <form method="post"
                                          action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/wizard-steps/delete"
                                          class="d-inline"
                                          onsubmit="return confirm('حذف این مرحله؟')">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="id" value="<?= $sid ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">هیچ مرحله‌ای ثبت نشده.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
