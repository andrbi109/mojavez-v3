<?php
/**
 * tracking/revision.php — user-side revision request form.
 *
 * Phase 15 (v1.6.0): rendered by RevisionController::form(). The user
 * sees a side-by-side form pre-filled with their current applicant data
 * and can request changes to identity fields (name, national code,
 * father name, mobile) plus a free-text note for the admin reviewer.
 *
 * Expected $data:
 *   $applicant    \App\Modules\Core\Domain\Applicant — the applicant row.
 *   $trackingCode string — the tracking code.
 *   $baseUrl      string — site base URL.
 *   $csrfField    string — CSRF hidden input.
 */
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var string $trackingCode */
/** @var string $baseUrl */
/** @var string $csrfField */

$trackingCode ??= '';
$baseUrl     ??= '';
$csrfField   ??= '';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0">
                    <i class="bi bi-pencil-square text-brand"></i>
                    درخواست بازبینی اطلاعات
                </h1>
            </div>
            <div class="card-body p-4">
                <p class="text-muted">
                    اگر اطلاعاتی که در زمان ثبت‌نام وارد کرده‌اید نادرست است، می‌توانید
                    با این فرم درخواست بازبینی بدهید. درخواست شما پس از بررسی مدیر سامانه
                    اعمال می‌شود.
                </p>
                <p class="small">
                    کد رهگیری: <code class="user-select-all"><?= htmlspecialchars($trackingCode, ENT_QUOTES, 'UTF-8') ?></code>
                </p>

                <form method="post"
                      action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>tracking/revision/submit">
                    <?= $csrfField ?>
                    <input type="hidden" name="tracking_code"
                           value="<?= htmlspecialchars($trackingCode, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="first_name" class="form-label">نام</label>
                            <input type="text" class="form-control" id="first_name" name="first_name"
                                   maxlength="50"
                                   value="<?= htmlspecialchars($applicant->firstName, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="last_name" class="form-label">نام خانوادگی</label>
                            <input type="text" class="form-control" id="last_name" name="last_name"
                                   maxlength="50"
                                   value="<?= htmlspecialchars($applicant->lastName, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="national_code" class="form-label">کد ملی</label>
                            <input type="text" class="form-control" id="national_code" name="national_code"
                                   pattern="[0-9]{10}" maxlength="10"
                                   value="<?= htmlspecialchars($applicant->nationalCode, ENT_QUOTES, 'UTF-8') ?>"
                                   style="text-align: right;">
                        </div>
                        <div class="col-md-6">
                            <label for="father_name" class="form-label">نام پدر</label>
                            <input type="text" class="form-control" id="father_name" name="father_name"
                                   maxlength="50"
                                   value="<?= htmlspecialchars((string)($applicant->fullName() ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="mobile" class="form-label">موبایل</label>
                            <input type="tel" class="form-control" id="mobile" name="mobile"
                                   pattern="09\d{9}" maxlength="11"
                                   value="<?= htmlspecialchars($applicant->mobile, ENT_QUOTES, 'UTF-8') ?>"
                                   style="text-align: right;">
                        </div>
                        <div class="col-12">
                            <label for="note" class="form-label">توضیح (یادداشت برای مدیر)</label>
                            <textarea class="form-control" id="note" name="note" rows="3"
                                      maxlength="500"
                                      placeholder="تغییر موردنظر را شرح دهید…"></textarea>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-between">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>tracking/status/<?= rawurlencode($trackingCode) ?>"
                           class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-return-right"></i> بازگشت
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send"></i> ثبت درخواست
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
