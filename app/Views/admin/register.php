<?php
/**
 * admin/register.php — manual registration form for an admin.
 *
 * Phase 17 (v1.8.0): rendered by AdminController::registerForm().
 * The admin fills in the 7 fields (first_name, last_name,
 * national_code, mobile, service_type_id, postal_code, address) and
 * POSTs to /admin/register — AdminController::registerProcess creates
 * the applicants row at current_status='تأیید اولیه' with a CSPRNG
 * tracking code HG-XXXXXXXX, then redirects to /admin/view/{id}.
 *
 * @var array<int, array<string,mixed>> $serviceTypes
 * @var array<int,string>  $errors
 * @var array<string,mixed> $form  submitted values from a failed POST
 * @var string $csrfField
 * @var string $baseUrl
 */
/** @var array<int, array<string,mixed>> $serviceTypes */
/** @var array<int,string> $errors */
/** @var array<string,mixed> $form */
/** @var string $csrfField */
/** @var string $baseUrl */

$serviceTypes ??= [];
$errors       ??= [];
$form         ??= [];
$csrfField    ??= '';
$baseUrl      ??= '/';

/**
 * Echo a previously-submitted value safely.
 */
$formValue = static function (string $key) use ($form): string {
    return htmlspecialchars((string)($form[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-person-plus"></i> ثبت‌نام دستی متقاضی</h2>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applications" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-right"></i> بازگشت
    </a>
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

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/register">
            <?= $csrfField ?>
            <fieldset class="mb-4">
                <legend class="h6 mb-3">اطلاعات هویتی</legend>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">نام <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="first_name"
                               value="<?= $formValue('first_name') ?>"
                               maxlength="50" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">نام خانوادگی <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="last_name"
                               value="<?= $formValue('last_name') ?>"
                               maxlength="50" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">کد ملی <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="national_code"
                               value="<?= $formValue('national_code') ?>"
                               maxlength="10" pattern="\d{10}" required dir="ltr">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">شماره تلفن همراه <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="mobile"
                               value="<?= $formValue('mobile') ?>"
                               maxlength="11" pattern="09\d{9}" placeholder="09XXXXXXXXX" required dir="ltr">
                    </div>
                </div>
            </fieldset>

            <fieldset class="mb-4">
                <legend class="h6 mb-3">اطلاعات خدمت</legend>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">نوع خدمت <span class="text-danger">*</span></label>
                        <select class="form-select" name="service_type_id" required>
                            <option value="">انتخاب کنید...</option>
                            <?php foreach ($serviceTypes as $st): ?>
                                <?php $sid = (int)$st['id']; ?>
                                <option value="<?= $sid ?>"
                                    <?= ((string)($form['service_type_id'] ?? '') === (string)$sid) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$st['title'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </fieldset>

            <fieldset class="mb-4">
                <legend class="h6 mb-3">نشانی</legend>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">کد پستی <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="postal_code"
                               value="<?= $formValue('postal_code') ?>"
                               maxlength="10" pattern="\d{10}" required dir="ltr">
                    </div>
                    <div class="col-12">
                        <label class="form-label">نشانی</label>
                        <textarea class="form-control" name="address" rows="3"
                                  maxlength="255"><?= $formValue('address') ?></textarea>
                        <div class="form-text small text-muted">
                            نشانی در جدول <code>property_details</code> ذخیره می‌شود (ستون <code>address_street</code>).
                        </div>
                    </div>
                </div>
            </fieldset>

            <div class="d-flex justify-content-end gap-2">
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applications" class="btn btn-outline-secondary">انصراف</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> ثبت متقاضی
                </button>
            </div>
        </form>
    </div>
</div>
