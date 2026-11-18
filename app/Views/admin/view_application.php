<?php
/**
 * admin/view_application.php — editable applicant case view with 18-status cycle.
 *
 * Phase 29 (v1.9.11): the admin can change the applicant's status via the
 *   18-status dropdown and edit fields inline.
 * Phase 36 (v2.0.2): port v2's full detail view — golden-link section with
 *   matched_at, document thumbnails inline, print button, color-coded
 *   status history badges, license-state section with valid_until date,
 *   and the full identity/property/job detail grid.
 *
 * Expected $data:
 *   $applicant        Applicant  — the domain object
 *   $statusLog        array<StatusLogEntry>
 *   $allStatuses      array<array{value,label,badgeColor,order,isTerminal}>
 *   $jobDetail        ?array    — job_details row or null
 *   $propertyDetail   ?array    — property_details row or null
 *   $documents        array<Document> — Document domain objects
 *   $serviceTypeTitle string
 *   $row              array     — raw applicants row (father_name, birth_date, …)
 *   $statusMeta       array    — config/statuses.php inner 'statuses' map
 *   $persianDate      PersianDate — for Jalali date formatting
 *   $baseUrl          string
 *   $csrfField        string
 *   $flashMessage     string    — Phase 50: one-shot flash banner text ('' = none)
 *   $flashKind        string    — Phase 50: 'success' | 'danger' | 'warning' | 'info'
 *   $suggestedAmount  int       — Phase 50: pre-fill for the payment modal (Toman)
 *   $licenseFile      ?LicenseFile — Phase 55: existing license_files row (null = not uploaded yet)
 *   $licenseDoc       ?Document   — Phase 55: Document row backing $licenseFile (null = none)
 */
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array $statusLog */
/** @var array $allStatuses */
/** @var ?array $jobDetail */
/** @var ?array $propertyDetail */
/** @var array<\App\Modules\Core\Domain\Document> $documents */
/** @var string $serviceTypeTitle */
/** @var array $row */
/** @var array $statusMeta */
/** @var \App\Shared\Support\PersianDate $persianDate */
/** @var string $baseUrl */
/** @var string $csrfField */
/** @var string $flashMessage */
/** @var string $flashKind */
/** @var int $suggestedAmount */
/** @var ?\App\Modules\Core\Domain\LicenseFile $licenseFile */
/** @var ?\App\Modules\Core\Domain\Document $licenseDoc */

use App\Modules\Core\Domain\Document;
use App\Modules\Core\Domain\LicenseFile;
use App\Modules\Core\Domain\Enums\LicenseState;
use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Shared\Support\PersianDate;
use App\Shared\Support\StatusBadge;

$allStatuses  ??= [];
$jobDetail    ??= null;
$propertyDetail ??= null;
$documents    ??= [];
$serviceTypeTitle ??= '';
$row          ??= [];
$statusMeta   ??= [];
$persianDate  ??= null;
$flashMessage ??= '';
$flashKind    ??= 'success';
$suggestedAmount ??= 500000;
$licenseFile  ??= null;
$licenseDoc   ??= null;

$currentStatus = $applicant->currentStatus;
$licenseState  = $applicant->licenseState;

// Phase 55 (v2.2.1) — derive two view-level flags from the current
// status so the template can decide which sections to dim/disable
// without re-implementing the cycle rules:
//   - $isTerminal: closed case — the entire page is read-only (a
//     top banner says "پرونده بسته شده است").
//   - $isLicenseIssuance: the admin has moved the applicant to
//     "صدور مجوز" and now must upload the license image. Only the
//     status-change + license-upload sections stay active; the
//     rest (edit form, document upload) is dimmed.
$isTerminal         = $currentStatus->isTerminal();
$isLicenseIssuance  = ($currentStatus === ApplicantStatus::LicenseIssuance);

// Helper: pick a Bootstrap badge color for a given status name.
//
// Phase 52 (v2.1.8) — delegates to App\Shared\Support\StatusBadge::color()
// which reads from config/statuses.php (the 18-status cycle's single
// source of truth). $statusMeta is still injected by AdminController for
// back-compat with older callers; we keep it as a fallback so a status
// that exists in the local $statusMeta map but not yet in config still
// resolves (e.g. a freshly-added enum case before the config file is
// redeployed).
$badgeColorFor = static function (string $statusName) use ($statusMeta): string {
    $color = StatusBadge::color($statusName);
    if ($color !== 'secondary') {
        return $color;
    }
    return $statusMeta[$statusName]['color'] ?? 'secondary';
};

// Helper: format a Gregorian datetime as a Jalali Y/M/D string.
//
// Phase 45 (v2.1.1) — now delegates to the static PersianDate::format()
// helper so views without an injected $persianDate instance use the
// same code path. The fallback on a missing $persianDate is retained
// only for legacy callers (the instance is now always injected by
// AdminController::viewApplication and AdminController::editApplication).
$fmtDate = static function (?string $greg) use ($persianDate): string {
    if ($greg === null || $greg === '' || $greg === '0000-00-00' || $greg === '0000-00-00 00:00:00') {
        return '—';
    }
    if ($persianDate instanceof PersianDate) {
        return $persianDate->formatGregorianDateTime($greg);
    }
    return htmlspecialchars(PersianDate::format((string)$greg), ENT_QUOTES, 'UTF-8');
};

// Helper: format a Gregorian date as Jalali Y/M/D (date-only).
$fmtDateOnly = static function (?string $greg) use ($persianDate): string {
    if ($greg === null || $greg === '' || $greg === '0000-00-00') {
        return '—';
    }
    if ($persianDate instanceof PersianDate) {
        return $persianDate->formatGregorianDateTime($greg);
    }
    return htmlspecialchars(PersianDate::format((string)$greg), ENT_QUOTES, 'UTF-8');
};

// Phase 50 (v2.1.6) — flash banner shown once after requestPayment()
// redirects back here. Maps the controller-set kind to a Bootstrap
// alert class (defaults to success on a malformed kind).
$flashKindClass = match ($flashKind) {
    'success' => 'alert-success',
    'danger'  => 'alert-danger',
    'warning' => 'alert-warning',
    'info'    => 'alert-info',
    default   => 'alert-success',
};
?>
<?php if ($flashMessage !== ''): ?>
<div class="alert <?= $flashKindClass ?> alert-dismissible fade show mb-4" role="alert">
    <i class="bi bi-<?= $flashKind === 'success' ? 'check-circle' : ($flashKind === 'danger' ? 'exclamation-triangle' : 'info-circle') ?>"></i>
    <?= htmlspecialchars((string)$flashMessage, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="بستن"></button>
</div>
<?php endif; ?>

<?php if ($isTerminal): ?>
<!-- Phase 55 (v2.2.1) — closed-case banner. When the applicant's
     current_status is terminal (رد درخواست / لغو / تحویل مجوز /
     تأیید نهایی دریافت کد رهگیری) the entire page is locked down.
     The status-change form, the license-upload form, the inline
     edit form, and the document upload form are all server-side
     disabled (HTML disabled attrs) so the page is read-only even
     before JS runs. The print button stays active (it opens a new
     tab — no write). -->
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
    <div>
        <strong>پرونده بسته شده است.</strong>
        وضعیت فعلی پرونده «<strong><?= htmlspecialchars($currentStatus->titleFa(), ENT_QUOTES, 'UTF-8') ?></strong>»
        است و امکان تغییر اطلاعات، بارگذاری مدرک یا تغییر وضعیت وجود ندارد.
    </div>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h4 mb-1">پروندهٔ متقاضی</h1>
        <span class="moj-tracking-code fs-5"><?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($serviceTypeTitle !== ''): ?>
            <span class="badge text-bg-info ms-2"><?= htmlspecialchars($serviceTypeTitle, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <span class="badge text-bg-<?= htmlspecialchars($currentStatus->badgeColor(), ENT_QUOTES, 'UTF-8') ?> fs-6">
            <?= htmlspecialchars($currentStatus->titleFa(), ENT_QUOTES, 'UTF-8') ?>
        </span>
        <span class="badge text-bg-<?= htmlspecialchars($licenseState->badgeColor(), ENT_QUOTES, 'UTF-8') ?> fs-6">
            وضعیت مجوز: <?= htmlspecialchars($licenseState->titleFa(), ENT_QUOTES, 'UTF-8') ?>
        </span>
        <!-- Phase 36: print button — opens /admin/output/single/{trackingCode} in new tab -->
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/output/single/<?= htmlspecialchars(rawurlencode($applicant->trackingCode), ENT_QUOTES, 'UTF-8') ?>"
           target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-printer"></i> نسخهٔ چاپی
        </a>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applications"
           class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-right"></i> بازگشت
        </a>
    </div>
</div>

<!-- ====== Status Change Card (18-status cycle) ======
     Phase 55 (v2.2.1) — the entire card is server-side disabled when
     the applicant's current_status is terminal. Even though the
     server's changeStatus() controller rejects terminal transitions,
     locking the form here gives a clear visual signal that the case
     is closed (no point letting the admin pick a new status from a
     disabled dropdown). -->
<div class="card border-primary mb-4<?= $isTerminal ? ' moj-card-locked' : '' ?>">
    <div class="card-header bg-primary-subtle d-flex align-items-center gap-2">
        <i class="bi bi-arrow-repeat text-primary"></i>
        <strong>تغییر وضعیت پرونده (چرخهٔ ۱۸ وضعیت)</strong>
    </div>
    <div class="card-body">
        <form id="status-change-form" method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/change-status">
            <?= $csrfField ?>
            <input type="hidden" name="applicant_id" value="<?= (int)$applicant->id ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">وضعیت فعلی</label>
                    <div class="form-control-plaintext">
                        <span class="badge text-bg-<?= htmlspecialchars($currentStatus->badgeColor(), ENT_QUOTES, 'UTF-8') ?> fs-6">
                            <?= htmlspecialchars($currentStatus->titleFa(), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <small class="text-muted ms-2">(ترتیب: <?= $currentStatus->order() ?>)</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <label for="new_status" class="form-label">انتخاب وضعیت جدید</label>
                    <select class="form-select" id="new_status" name="new_status" required<?= $isTerminal ? ' disabled' : '' ?>>
                        <option value="">— انتخاب کنید —</option>
                        <?php foreach ($allStatuses as $s): ?>
                            <?php $isCurrent = $s['value'] === $currentStatus->value; ?>
                            <option value="<?= htmlspecialchars($s['value'], ENT_QUOTES, 'UTF-8') ?>"
                                <?= $isCurrent ? 'disabled' : '' ?>
                                data-color="<?= htmlspecialchars($s['badgeColor'], ENT_QUOTES) ?>"
                                data-terminal="<?= $s['isTerminal'] ? '1' : '0' ?>">
                                <?= htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8') ?>
                                <?= $s['isTerminal'] ? ' (پایانی)' : '' ?>
                                <?= $isCurrent ? ' (فعلی)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="status_description" class="form-label">توضیح (اختیاری)</label>
                    <input type="text" class="form-control" id="status_description" name="description"
                           placeholder="دلیل تغییر وضعیت..." maxlength="500"<?= $isTerminal ? ' disabled' : '' ?>>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2 flex-wrap align-items-center">
                <button type="submit" class="btn btn-primary" id="btn-change-status"<?= $isTerminal ? ' disabled' : '' ?>>
                    <i class="bi bi-check2-circle"></i> اعمال تغییر وضعیت
                </button>
            </div>
        </form>

        <!-- Phase 50 (v2.1.6) — payment request button + modal.
             The button no longer POSTs directly (the previous flow
             returned JSON, which the browser rendered as raw text).
             Instead it opens a Bootstrap modal (data-bs-toggle/target)
             with a small form: applicant name (read-only), suggested
             amount (pre-filled from settings, editable), description
             (free-form), and submit/cancel buttons. The form POSTs to
             /admin/request-payment with applicant_id + amount +
             description, and the controller redirects back to this
             page (showing a flash banner) — so the admin never sees
             raw JSON. Lives inside the same card body so it's visually
             grouped with the status change section but OUTSIDE the
             status-change-form (forms cannot be nested in HTML).
             Phase 55 (v2.2.1) — disabled when the case is terminal. -->
        <div class="mt-3 border-top pt-3">
            <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#requestPaymentModal"<?= $isTerminal ? ' disabled' : '' ?>>
                <i class="bi bi-cash"></i> درخواست پرداخت
            </button>

            <div class="modal fade" id="requestPaymentModal" tabindex="-1" aria-labelledby="requestPaymentModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/request-payment" class="modal-content" id="request-payment-form">
                        <?= $csrfField ?>
                        <input type="hidden" name="applicant_id" value="<?= (int)$applicant->id ?>">
                        <div class="modal-header">
                            <h5 class="modal-title" id="requestPaymentModalLabel">
                                <i class="bi bi-cash-coin"></i>
                                ثبت درخواست پرداخت
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Applicant name (read-only context) -->
                            <div class="mb-3">
                                <label class="form-label small text-muted mb-1">متقاضی</label>
                                <div class="form-control-plaintext fw-bold">
                                    <?= htmlspecialchars($applicant->fullName(), ENT_QUOTES, 'UTF-8') ?>
                                    <span class="text-muted small ms-1">(کد پیگیری: <?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?>)</span>
                                </div>
                            </div>
                            <!-- Suggested amount (editable, pre-filled from settings) -->
                            <div class="mb-3">
                                <label for="payment-amount" class="form-label">مبلغ (تومان)</label>
                                <div class="input-group">
                                    <input type="text" id="payment-amount" name="amount"
                                           class="form-control text-end"
                                           dir="ltr"
                                           inputmode="numeric"
                                           pattern="[0-9]+"
                                           required
                                           value="<?= (int)$suggestedAmount ?>"
                                           min="1"
                                           maxlength="12">
                                    <span class="input-group-text small">تومان</span>
                                </div>
                                <div class="form-text small">
                                    مبلغ پیشنهادی از تنظیمات سامانه خوانده شده است؛ در صورت نیاز آن را ویرایش کنید.
                                </div>
                            </div>
                            <!-- Free-form description -->
                            <div class="mb-2">
                                <label for="payment-description" class="form-label">توضیحات (اختیاری)</label>
                                <textarea id="payment-description" name="description"
                                          class="form-control" rows="3" maxlength="500"
                                          placeholder="مثلاً: هزینهٔ ثبت‌نام دورهٔ فنی‌وحرفه‌ای رشتهٔ نجاری..."></textarea>
                                <div class="form-text small">حداکثر ۵۰۰ نویسه. این توضیح برای کاربر در صفحهٔ پرداخت‌هایش نمایش داده می‌شود.</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-check2-circle"></i> ثبت درخواست پرداخت
                            </button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ====== Phase 55 (v2.2.1): License image upload card ======
     The license upload form. POSTs to /admin/license/upload with
     applicant_id + license_image (file). After Phase 55 the
     controller redirects back here with a flash banner instead of
     returning JSON, so the page reloads showing the new thumbnail.

     Visibility:
       - Hidden by default (the wrapper has style="display:none"
         unless $isLicenseIssuance).
       - JS at the bottom of the page shows the card when the admin
         selects "صدور مجوز" in the #new_status dropdown (preview
         before submitting the status change).
       - When the current_status is already "صدور مجوز", the card
         is rendered visible server-side so the admin can upload
         without first re-selecting the status.
       - When the current_status is terminal the card stays hidden
         (the entire page is locked down anyway). -->
<div class="card mb-4 border-success" id="license-upload-card"
     <?= $isLicenseIssuance ? '' : 'style="display:none"' ?>>
    <div class="card-header bg-success-subtle d-flex align-items-center gap-2">
        <i class="bi bi-patch-check-fill text-success"></i>
        <strong>بارگذاری تصویر مجوز</strong>
    </div>
    <div class="card-body">
        <?php if ($licenseFile !== null && $licenseDoc !== null): ?>
            <!-- Phase 55 (v2.2.1) — already-uploaded license thumbnail.
                 Reuses the same docViewerModal pattern as the
                 documents section below. Clicking the thumbnail opens
                 the inline viewer; re-uploading via the form below
                 replaces the documents row + the license_files FK. -->
            <div class="mb-3 pb-3 border-bottom">
                <p class="text-muted small mb-2">
                    <i class="bi bi-check-circle-fill text-success"></i>
                    تصویر مجوز بارگذاری‌شده
                    (<?= htmlspecialchars($fmtDate($licenseDoc->uploadedAt), ENT_QUOTES, 'UTF-8') ?>):
                </p>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <a href="#" class="d-block text-decoration-none"
                       data-bs-toggle="modal" data-bs-target="#docViewerModal"
                       data-doc-id="<?= (int)$licenseDoc->id ?>"
                       data-doc-is-image="<?= $licenseDoc->isImage() ? '1' : '0' ?>"
                       data-doc-is-pdf="<?= $licenseDoc->isPdf() ? '1' : '0' ?>"
                       data-doc-name="<?= htmlspecialchars($licenseDoc->originalName, ENT_QUOTES, 'UTF-8') ?>"
                       data-doc-label="تصویر مجوز"
                       data-doc-url="<?= htmlspecialchars($baseUrl . 'document/inline/' . (int)$licenseDoc->id, ENT_QUOTES) ?>">
                        <?php if ($licenseDoc->isImage()): ?>
                            <img src="<?= htmlspecialchars($baseUrl . 'document/thumb/' . (int)$licenseDoc->id, ENT_QUOTES) ?>"
                                 class="rounded"
                                 style="height:120px;width:auto;max-width:160px;object-fit:cover;border:1px solid var(--bs-border-color, #e2e8f0);"
                                 alt="تصویر مجوز">
                        <?php elseif ($licenseDoc->isPdf()): ?>
                            <div class="p-3 rounded d-inline-block"
                                 style="background:var(--bs-surface-2, #f1f5f9);color:var(--bs-danger, #dc3545)">
                                <i class="bi bi-file-earmark-pdf" style="font-size:3rem"></i>
                            </div>
                        <?php else: ?>
                            <div class="p-3 rounded d-inline-block"
                                 style="background:var(--bs-surface-2, #f1f5f9);color:var(--bs-secondary, #6c757d)">
                                <i class="bi bi-file-earmark-text" style="font-size:3rem"></i>
                            </div>
                        <?php endif; ?>
                    </a>
                    <div class="small">
                        <div class="text-muted text-truncate d-block" dir="ltr" style="max-width:280px"
                             title="<?= htmlspecialchars($licenseDoc->originalName, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($licenseDoc->originalName, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="text-muted mt-1">
                            برای جایگزینی، فایل جدید را در فرم زیر بارگذاری کنید.
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- License image upload form. The file input name
             'license_image' matches the controller's
             $request->file('license_image') call. The accept attribute
             restricts the file picker to JPEG/PNG/PDF (the controller
             re-validates the actual MIME via detectMime()). -->
        <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/license/upload"
              enctype="multipart/form-data" id="license-upload-form">
            <?= $csrfField ?>
            <input type="hidden" name="applicant_id" value="<?= (int)$applicant->id ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label for="license_image" class="form-label small">
                        فایل تصویر مجوز
                        (jpg / png / pdf — حداکثر ۱۰ مگابایت)
                    </label>
                    <input type="file" class="form-control" id="license_image"
                           name="license_image"
                           accept="image/jpeg,image/png,application/pdf" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-upload"></i>
                        <?= ($licenseFile !== null) ? 'بارگذاری مجدد تصویر مجوز' : 'بارگذاری تصویر مجوز' ?>
                    </button>
                </div>
            </div>
            <div class="form-text small mt-2">
                پس از بارگذاری، تصویر در همین بخش نمایش داده می‌شود و در صورت وجود
                درخواست دسترسی در انتظار، به‌طور خودکار تأیید خواهد شد.
            </div>
        </form>
    </div>
</div>

<!-- ====== Phase 55 (v2.2.1): "Everything else" wrapper ======
     The container below holds all sections OTHER than the status-change
     card and the license-upload card (which must stay active when the
     current_status is "صدور مجوز"). When the case is terminal OR in
     the license-issuance state, the wrapper gets the
     `moj-sections-disabled` class so the inline edit form + the
     document upload form + the delete-document buttons are all
     visually dimmed and unclickable. The CSS for that class lives in
     the <style> block at the bottom of this file. -->
<div id="moj-applicant-detail-sections"
     class="<?= ($isTerminal || $isLicenseIssuance) ? 'moj-sections-disabled' : '' ?>">

<!-- ====== Phase 36: License State section ====== -->
<div class="card mb-4 border-<?= htmlspecialchars($licenseState->badgeColor(), ENT_QUOTES, 'UTF-8') ?>">
    <div class="card-header d-flex align-items-center gap-2 bg-<?= htmlspecialchars($licenseState->badgeColor(), ENT_QUOTES, 'UTF-8') ?>-subtle">
        <i class="bi bi-patch-check text-<?= htmlspecialchars($licenseState->badgeColor(), ENT_QUOTES, 'UTF-8') ?>"></i>
        <strong>وضعیت مجوز</strong>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4 text-muted">وضعیت مجوز</dt>
            <dd class="col-sm-8">
                <span class="badge text-bg-<?= htmlspecialchars($licenseState->badgeColor(), ENT_QUOTES, 'UTF-8') ?> fs-6">
                    <?= htmlspecialchars($licenseState->titleFa(), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </dd>
            <dt class="col-sm-4 text-muted">اعتبار تا</dt>
            <dd class="col-sm-8">
                <?php if ($licenseState === LicenseState::Issued && $applicant->licenseValidUntil !== null): ?>
                    <span class="badge text-bg-success" dir="ltr"><?= htmlspecialchars($fmtDateOnly($applicant->licenseValidUntil), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($applicant->hasValidLicense()): ?>
                        <span class="badge text-bg-success ms-2"><i class="bi bi-check-circle"></i> معتبر</span>
                    <?php else: ?>
                        <span class="badge text-bg-warning ms-2"><i class="bi bi-clock"></i> منقضی‌شده</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </dd>
        </dl>
    </div>
</div>

<div class="row g-4">
    <!-- ====== Left column: applicant info (editable) ====== -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person-vcard"></i> اطلاعات متقاضی</span>
                <button class="btn btn-sm btn-outline-primary" id="btn-edit-applicant" type="button">
                    <i class="bi bi-pencil"></i> ویرایش
                </button>
            </div>
            <div class="card-body">
                <!-- Read-only view -->
                <dl class="row mb-0" id="applicant-readonly">
                    <dt class="col-sm-4 text-muted">نام</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($applicant->fullName(), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-muted">کد ملی</dt>
                    <dd class="col-sm-8" dir="ltr"><?= htmlspecialchars($applicant->nationalCode, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-muted">موبایل</dt>
                    <dd class="col-sm-8" dir="ltr"><?= htmlspecialchars($applicant->mobile, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-muted">کد پستی</dt>
                    <dd class="col-sm-8" dir="ltr"><?= htmlspecialchars((string)($applicant->postalCode ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-muted">نام پدر</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars((string)($row['father_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-muted">تاریخ تولد</dt>
                    <dd class="col-sm-8" dir="ltr"><?= htmlspecialchars($fmtDateOnly($row['birth_date'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-muted">وضعیت تأهل</dt>
                    <dd class="col-sm-8">
                        <?= htmlspecialchars((string)($row['marital_status'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                        <?php if (($row['marital_status'] ?? '') === 'متأهل'): ?>
                            <small class="text-muted ms-1">— <?= (int)($row['children_count'] ?? 0) ?> فرزند</small>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-sm-4 text-muted">وضعیت جسمانی</dt>
                    <dd class="col-sm-8">
                        <?= htmlspecialchars((string)($row['physical_condition'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                        <?php if (($row['physical_condition'] ?? '') === 'معلولیت' && !empty($row['disability_details'])): ?>
                            <small class="text-muted ms-1">— <?= htmlspecialchars((string)$row['disability_details'], ENT_QUOTES, 'UTF-8') ?></small>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-sm-4 text-muted">دهک درآمدی</dt>
                    <dd class="col-sm-8"><?= isset($row['income_decile']) && $row['income_decile'] !== null ? (int)$row['income_decile'] : '—' ?></dd>
                    <dt class="col-sm-4 text-muted">پرونده قضایی / وام / اقساط</dt>
                    <dd class="col-sm-8">
                        <?= htmlspecialchars((string)($row['has_court_case'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> /
                        <?= htmlspecialchars((string)($row['has_active_loan'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> /
                        <?= htmlspecialchars((string)($row['has_overdue_installments'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                    </dd>
                    <dt class="col-sm-4 text-muted">نوع خدمت</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($serviceTypeTitle, ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>

                <!-- Editable form (hidden by default) -->
                <form id="applicant-edit-form" method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/update" class="d-none">
                    <?= $csrfField ?>
                    <input type="hidden" name="applicant_id" value="<?= (int)$applicant->id ?>">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small">نام</label>
                            <input type="text" class="form-control form-control-sm" name="first_name"
                                value="<?= htmlspecialchars($applicant->firstName, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">نام خانوادگی</label>
                            <input type="text" class="form-control form-control-sm" name="last_name"
                                value="<?= htmlspecialchars($applicant->lastName, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">کد ملی <small class="text-muted">(غیرقابل ویرایش)</small></label>
                            <input type="text" class="form-control form-control-sm" name="national_code" dir="ltr"
                                value="<?= htmlspecialchars($applicant->nationalCode, ENT_QUOTES, 'UTF-8') ?>" disabled>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">موبایل</label>
                            <input type="text" class="form-control form-control-sm" name="mobile" dir="ltr"
                                value="<?= htmlspecialchars($applicant->mobile, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">کد پستی</label>
                            <input type="text" class="form-control form-control-sm" name="postal_code" dir="ltr"
                                value="<?= htmlspecialchars((string)($applicant->postalCode ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">نام پدر</label>
                            <input type="text" class="form-control form-control-sm" name="father_name"
                                value="<?= htmlspecialchars((string)($row['father_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">وضعیت تأهل</label>
                            <select class="form-select form-select-sm" name="marital_status">
                                <option value="">—</option>
                                <option value="مجرد" <?= ($row['marital_status'] ?? '') === 'مجرد' ? 'selected' : '' ?>>مجرد</option>
                                <option value="متأهل" <?= ($row['marital_status'] ?? '') === 'متأهل' ? 'selected' : '' ?>>متأهل</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">تعداد فرزندان</label>
                            <input type="number" class="form-control form-control-sm" name="children_count" min="0"
                                value="<?= (int)($row['children_count'] ?? 0) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">وضعیت جسمانی</label>
                            <select class="form-select form-select-sm" name="physical_condition">
                                <option value="سالم" <?= ($row['physical_condition'] ?? '') === 'سالم' ? 'selected' : '' ?>>سالم</option>
                                <option value="معلولیت" <?= ($row['physical_condition'] ?? '') === 'معلولیت' ? 'selected' : '' ?>>معلولیت</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">توضیحات معلولیت</label>
                            <input type="text" class="form-control form-control-sm" name="disability_details"
                                value="<?= htmlspecialchars((string)($row['disability_details'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">دهک درآمدی</label>
                            <input type="number" class="form-control form-control-sm" name="income_decile" min="1" max="10"
                                value="<?= htmlspecialchars((string)($row['income_decile'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">پرونده قضایی</label>
                            <select class="form-select form-select-sm" name="has_court_case">
                                <option value="خیر" <?= ($row['has_court_case'] ?? 'خیر') === 'خیر' ? 'selected' : '' ?>>خیر</option>
                                <option value="بله" <?= ($row['has_court_case'] ?? '') === 'بله' ? 'selected' : '' ?>>بله</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">وام فعال</label>
                            <select class="form-select form-select-sm" name="has_active_loan">
                                <option value="خیر" <?= ($row['has_active_loan'] ?? 'خیر') === 'خیر' ? 'selected' : '' ?>>خیر</option>
                                <option value="بله" <?= ($row['has_active_loan'] ?? '') === 'بله' ? 'selected' : '' ?>>بله</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">اقساط معوقه</label>
                            <select class="form-select form-select-sm" name="has_overdue_installments">
                                <option value="خیر" <?= ($row['has_overdue_installments'] ?? 'خیر') === 'خیر' ? 'selected' : '' ?>>خیر</option>
                                <option value="بله" <?= ($row['has_overdue_installments'] ?? '') === 'بله' ? 'selected' : '' ?>>بله</option>
                            </select>
                        </div>
                        <div class="col-12 mt-3 d-flex gap-2">
                            <button type="submit" class="btn btn-success btn-sm">
                                <i class="bi bi-check-lg"></i> ذخیره
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-cancel-edit">
                                <i class="bi bi-x-lg"></i> انصراف
                            </button>
                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/edit/<?= (int)$applicant->id ?>"
                               class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-pencil-square"></i> ویرایش کامل
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($applicant->isMatchedToNational()): ?>
        <div class="card mt-3 border-success">
            <div class="card-header bg-success-subtle d-flex align-items-center gap-2">
                <i class="bi bi-patch-check-fill text-success"></i> اتصال طلایی
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">شماره درخواست ملی</dt>
                    <dd class="col-sm-7" dir="ltr"><?= htmlspecialchars((string)$applicant->nationalLicenseRequestNumber, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-5 text-muted">اطمینان تطبیق</dt>
                    <dd class="col-sm-7">
                        <?php $conf = (int)($applicant->matchConfidence ?? 0); ?>
                        <span class="badge text-bg-<?= $conf >= 80 ? 'success' : ($conf >= 50 ? 'warning' : 'secondary') ?>">
                            <?= $conf ?>٪
                        </span>
                    </dd>
                    <dt class="col-sm-5 text-muted">تاریخ تطبیق</dt>
                    <dd class="col-sm-7" dir="ltr"><?= htmlspecialchars($fmtDate($applicant->matchedAt), ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($propertyDetail !== null): ?>
        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-geo-alt"></i> اطلاعات ملک</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-5 text-muted">استان</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars((string)($propertyDetail['province'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-5 text-muted">شهرستان</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars((string)($propertyDetail['county'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-5 text-muted">شهر</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars((string)($propertyDetail['city'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                    <?php if (!empty($propertyDetail['district']) || !empty($propertyDetail['rural_district']) || !empty($propertyDetail['village'])): ?>
                    <dt class="col-sm-5 text-muted">بخش / دهستان / روستا</dt>
                    <dd class="col-sm-7">
                        <?= htmlspecialchars(implode(' / ', array_filter([
                            (string)($propertyDetail['district'] ?? ''),
                            (string)($propertyDetail['rural_district'] ?? ''),
                            (string)($propertyDetail['village'] ?? ''),
                        ])), ENT_QUOTES, 'UTF-8') ?>
                    </dd>
                    <?php endif; ?>
                    <dt class="col-sm-5 text-muted">مالکیت</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars((string)($propertyDetail['ownership_status'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-5 text-muted">آدرس</dt>
                    <dd class="col-sm-7">
                        <?= htmlspecialchars(implode('، ', array_filter([
                            (string)($propertyDetail['address_street'] ?? ''),
                            (string)($propertyDetail['address_alley'] ?? ''),
                            !empty($propertyDetail['address_plaque']) ? 'پلاک ' . $propertyDetail['address_plaque'] : '',
                        ])), ENT_QUOTES, 'UTF-8') ?>
                    </dd>
                    <dt class="col-sm-5 text-muted">شناسهٔ قبض برق</dt>
                    <dd class="col-sm-7" dir="ltr"><?= htmlspecialchars((string)($propertyDetail['electricity_bill_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($jobDetail !== null): ?>
        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-briefcase"></i> اطلاعات شغلی</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-5 text-muted">عنوان مجوز</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars((string)($jobDetail['license_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-5 text-muted">سابقه (ماه)</dt>
                    <dd class="col-sm-7"><?= (int)($jobDetail['experience_months'] ?? 0) ?></dd>
                    <dt class="col-sm-5 text-muted">نحوه آموزش</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars((string)($jobDetail['training_method'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ====== Right column: documents + timeline ====== -->
    <div class="col-lg-7">
        <!-- Documents -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-file-earmark-text"></i> مدارک
                    <span class="badge text-bg-secondary ms-1"><?= count($documents) ?></span>
                </span>
                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse"
                        data-bs-target="#upload-doc-form">
                    <i class="bi bi-upload"></i> افزودن مدرک
                </button>
            </div>
            <div class="card-body">
                <div class="collapse mb-3" id="upload-doc-form">
                    <div class="p-3 bg-light border rounded">
                        <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/add-document"
                              enctype="multipart/form-data">
                            <?= $csrfField ?>
                            <input type="hidden" name="applicant_id" value="<?= (int)$applicant->id ?>">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label small">نوع مدرک</label>
                                    <select class="form-select form-select-sm" name="doc_type">
                                        <option value="optional">سایر</option>
                                        <option value="national_card">کارت ملی</option>
                                        <option value="birth_cert">شناسنامه</option>
                                        <option value="ownership_doc">مدرک مالکیت</option>
                                        <option value="vocational_cert">گواهی فنی‌وحرفه‌ای</option>
                                        <option value="education_degree">مدرک تحصیلی</option>
                                        <option value="product_photos">تصویر محصولات</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">فایل (jpg/png/pdf — حداکثر ۲MB)</label>
                                    <input type="file" class="form-control form-control-sm" name="document_file"
                                           accept=".jpg,.jpeg,.png,.pdf" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-upload"></i> بارگذاری
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (!empty($documents)): ?>
                <!-- Phase 36: document thumbnails inline (image thumbnail or PDF icon).
                     Phase 51 (v2.1.7): thumbnails now open a Bootstrap modal
                     (image inline, PDF in iframe, other as download link)
                     instead of jumping to /document/inline/{id} in a new tab. -->
                <div class="row g-3">
                    <?php foreach ($documents as $doc): ?>
                        <?php \assert($doc instanceof Document); ?>
                        <?php
                        $docLabels = [
                            'national_card'    => 'کارت ملی',
                            'birth_cert'       => 'شناسنامه',
                            'ownership_doc'    => 'مدرک مالکیت',
                            'lease_agreement'  => 'اجاره‌نامه',
                            'vocational_cert'  => 'گواهی فنی‌وحرفه‌ای',
                            'education_degree' => 'مدرک تحصیلی',
                            'product_photos'   => 'تصویر محصولات',
                            'optional'         => 'سایر',
                        ];
                        $docLabel = $docLabels[$doc->docType] ?? $doc->docType;
                        $docThumbUrl = $baseUrl . 'document/thumb/' . (int)$doc->id;
                        $docInlineUrl = $baseUrl . 'document/inline/' . (int)$doc->id;
                        ?>
                        <div class="col-6 col-md-4 col-xl-3">
                            <div class="card h-100 p-2 text-center" style="border-style:dashed;">
                                <a href="#"
                                   class="d-block text-decoration-none"
                                   data-bs-toggle="modal"
                                   data-bs-target="#docViewerModal"
                                   data-doc-id="<?= (int)$doc->id ?>"
                                   data-doc-is-image="<?= $doc->isImage() ? '1' : '0' ?>"
                                   data-doc-is-pdf="<?= $doc->isPdf() ? '1' : '0' ?>"
                                   data-doc-name="<?= htmlspecialchars($doc->originalName, ENT_QUOTES, 'UTF-8') ?>"
                                   data-doc-label="<?= htmlspecialchars($docLabel, ENT_QUOTES, 'UTF-8') ?>"
                                   data-doc-url="<?= htmlspecialchars($docInlineUrl, ENT_QUOTES) ?>">
                                    <?php if ($doc->isImage()): ?>
                                        <img src="<?= htmlspecialchars($docThumbUrl, ENT_QUOTES) ?>"
                                             class="rounded mb-1" style="height:90px;width:100%;object-fit:cover;"
                                             alt="<?= htmlspecialchars($docLabel, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php elseif ($doc->isPdf()): ?>
                                        <div class="py-3" style="color:var(--bs-danger, #dc3545)">
                                            <i class="bi bi-file-earmark-pdf" style="font-size:2.1rem"></i>
                                        </div>
                                    <?php else: ?>
                                        <div class="py-3" style="color:var(--bs-secondary, #6c757d)">
                                            <i class="bi bi-file-earmark-text" style="font-size:2.1rem"></i>
                                        </div>
                                    <?php endif; ?>
                                </a>
                                <span class="badge text-bg-info mb-1" style="font-size:.68rem"><?= htmlspecialchars($docLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                <small class="text-muted text-truncate d-block" dir="ltr"
                                       title="<?= htmlspecialchars($doc->originalName, ENT_QUOTES, 'UTF-8') ?>"
                                       style="font-size:.7rem"><?= htmlspecialchars($doc->originalName, ENT_QUOTES, 'UTF-8') ?></small>
                                <small class="text-muted" style="font-size:.68rem" dir="ltr"><?= htmlspecialchars($fmtDate($doc->uploadedAt), ENT_QUOTES, 'UTF-8') ?></small>
                                <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/delete-document"
                                      class="mt-1" onsubmit="return confirm('حذف این مدرک؟')">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="document_id" value="<?= (int)$doc->id ?>">
                                    <input type="hidden" name="applicant_id" value="<?= (int)$applicant->id ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger w-100" style="font-size:.7rem">
                                        <i class="bi bi-trash"></i> حذف
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center text-muted py-4">مدارکی ثبت نشده است.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status timeline -->
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-clock-history"></i> تاریخچهٔ وضعیت
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:480px;overflow-y:auto">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr><th>وضعیت</th><th>توسط</th><th>تاریخ</th><th>توضیح</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($statusLog as $entry): ?>
                            <?php
                            $eStatus = is_array($entry) ? ($entry['status_name'] ?? $entry['statusName'] ?? '—') : ($entry->statusName ?? '—');
                            $eBy = is_array($entry) ? ($entry['triggered_by'] ?? $entry['triggeredBy'] ?? '—') : ($entry->triggeredBy ?? '—');
                            $eAt = is_array($entry) ? ($entry['created_at'] ?? $entry['createdAt'] ?? '') : ($entry->createdAt ?? '');
                            $eDesc = is_array($entry) ? ($entry['description'] ?? '') : ($entry->description ?? '');
                            $eColor = $badgeColorFor((string)$eStatus);
                            // Override for non-status log entries like 'حذف مدرک'.
                            if (str_contains((string)$eStatus, 'حذف')) {
                                $eColor = 'danger';
                            } elseif (str_contains((string)$eStatus, 'افزودن') || str_contains((string)$eStatus, 'بارگذاری')) {
                                $eColor = 'info';
                            } elseif (str_contains((string)$eStatus, 'ویرایش')) {
                                $eColor = 'secondary';
                            }
                            ?>
                            <tr>
                                <td><span class="badge text-bg-<?= htmlspecialchars($eColor, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($eStatus, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="small">
                                    <?= htmlspecialchars($eBy, ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($eBy === 'auto_sync'): ?>
                                        <i class="bi bi-link-45deg text-success ms-1" title="اتصال طلایی"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted" dir="ltr"><?= htmlspecialchars($fmtDate($eAt), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="small"><?= htmlspecialchars((string)$eDesc, ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($statusLog)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">تاریخچه‌ای ثبت نشده است.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ====== Phase 55 (v2.2.1): close the "everything else" wrapper.
     The opening <div id="moj-applicant-detail-sections"> is right
     above the License State section. -->
</div>

<!-- ====== Phase 51 (v2.1.7): Document viewer modal ====== -->
<!-- Clicking a document thumbnail opens this modal. JS swaps the body:
     - image → <img class="img-fluid">
     - PDF   → <iframe height="500px">
     - other → a download link + file name.
     The src for both <img> and <iframe> is the existing /document/inline/{id}
     route (logged-in, ownership-checked). -->
<div class="modal fade" id="docViewerModal" tabindex="-1"
     aria-labelledby="docViewerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="docViewerModalLabel">
                    <i class="bi bi-file-earmark"></i>
                    <span id="docViewerModalTitle">مشاهدهٔ مدرک</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
            </div>
            <div class="modal-body p-0" id="docViewerModalBody"
                 style="background:#f8f9fa;min-height:320px;">
                <!-- filled by JS on show.bs.modal -->
            </div>
            <div class="modal-footer d-flex justify-content-between align-items-center">
                <a id="docViewerModalDownload" class="btn btn-outline-secondary btn-sm"
                   href="#" download>
                    <i class="bi bi-download"></i> دانلود
                </a>
                <span class="text-muted small text-truncate d-none d-md-inline" id="docViewerModalName" style="max-width:60%"></span>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">بستن</button>
            </div>
        </div>
    </div>
</div>

<!-- ====== Phase 55 (v2.2.1): inline styles for the disabled/dim
     state. The .moj-sections-disabled class is applied to the
     "everything else" wrapper (#moj-applicant-detail-sections)
     when the case is terminal OR in the "صدور مجوز" state. The
     .moj-card-locked class is a small visual hint applied to the
     status-change card when the case is terminal (the inputs are
     already disabled server-side; this just dims the header a bit).
     Keeping these styles inline (rather than in app.css) so the
     phase's behaviour is self-contained and easy to grep. -->
<style>
.moj-sections-disabled {
    opacity: 0.55;
    pointer-events: none;
    filter: grayscale(35%);
}
.moj-sections-disabled .card {
    box-shadow: none !important;
}
.moj-sections-disabled .btn,
.moj-sections-disabled a[data-bs-toggle="modal"] {
    cursor: not-allowed;
}
/* Status-change card gets a slightly muted header when the case is
   closed (the inputs are server-side disabled; this is a visual
   hint that the form is locked). */
.moj-card-locked .card-header {
    opacity: 0.7;
}
.moj-card-locked .form-select,
.moj-card-locked .form-control,
.moj-card-locked .btn {
    cursor: not-allowed;
}
</style>

<script>
// Toggle edit form
(function() {
    var btnEdit = document.getElementById('btn-edit-applicant');
    var btnCcl = document.getElementById('btn-cancel-edit');
    var ro = document.getElementById('applicant-readonly');
    var fm = document.getElementById('applicant-edit-form');
    if (btnEdit) btnEdit.addEventListener('click', function() {
        ro.classList.add('d-none');
        fm.classList.remove('d-none');
    });
    if (btnCcl) btnCcl.addEventListener('click', function() {
        ro.classList.remove('d-none');
        fm.classList.add('d-none');
    });
})();

// Status change confirmation — show the target status badge color
// + Phase 55 (v2.2.1) — also toggle the license-upload section
// visibility based on the selected status. The card is shown when
// the admin picks "صدور مجوز" (preview) and hidden otherwise
// (including when a terminal status like "تحویل مجوز" is picked —
// picking a terminal status closes the case, so the upload form
// is irrelevant).
(function() {
    var sel = document.getElementById('new_status');
    if (!sel) return;
    var uploadCard = document.getElementById('license-upload-card');
    function syncUploadCard(value) {
        if (!uploadCard) return;
        // The Persian string 'صدور مجوز' is the enum value for
        // ApplicantStatus::LicenseIssuance. Matched verbatim because
        // the option's value attribute carries the enum's Persian
        // string (see ApplicantStatus enum + the foreach loop above).
        uploadCard.style.display = (value === 'صدور مجوز') ? '' : 'none';
    }
    sel.addEventListener('change', function() {
        var opt = sel.options[sel.selectedIndex];
        var btn = document.getElementById('btn-change-status');
        if (!opt || !opt.value) {
            if (btn) btn.className = 'btn btn-primary';
            syncUploadCard('');
            return;
        }
        var color = opt.getAttribute('data-color') || 'primary';
        var terminal = opt.getAttribute('data-terminal') === '1';
        if (btn) btn.className = 'btn btn-' + (terminal ? 'danger' : color);
        syncUploadCard(opt.value);
    });
})();

// Phase 50 (v2.1.6) — payment modal amount field UX:
//  - Strip non-digits as the admin types (so a paste of "500,000" or
//    Persian digits cleans up to "500000").
//  - Show a thousands-separated preview below the input so the admin
//    sees the human-readable amount before submitting.
//  - On submit, confirm before POSTing (the controller is idempotent —
//    it just creates a new payments row — but a confirm gives the
//    admin a chance to back out, matching the old UX).
(function() {
    var form = document.getElementById('request-payment-form');
    if (!form) return;
    var amountInput = document.getElementById('payment-amount');
    var hint = amountInput ? amountInput.closest('.mb-3').querySelector('.form-text') : null;

    function toPersianDigits(s) {
        var map = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        return String(s).replace(/[0-9]/g, function (d) { return map[+d]; });
    }

    function refreshHint() {
        if (!hint) return;
        var raw = (amountInput.value || '').replace(/[^0-9]/g, '');
        if (raw === '') { hint.textContent = 'مبلغ پیشنهادی از تنظیمات سامانه خوانده شده است؛ در صورت نیاز آن را ویرایش کنید.'; return; }
        var n = parseInt(raw, 10);
        if (isNaN(n) || n <= 0) { hint.textContent = 'مبلغ نامعتبر است.'; return; }
        hint.textContent = 'مبلغ واردشده: ' + toPersianDigits(n.toLocaleString('en-US')) + ' تومان.';
    }

    if (amountInput) {
        amountInput.addEventListener('input', function () {
            var cleaned = (amountInput.value || '').replace(/[^0-9]/g, '');
            if (cleaned !== amountInput.value) amountInput.value = cleaned;
            refreshHint();
        });
        refreshHint();
    }

    form.addEventListener('submit', function (e) {
        var raw = (amountInput && amountInput.value || '').replace(/[^0-9]/g, '');
        var n = parseInt(raw, 10);
        if (isNaN(n) || n <= 0) {
            e.preventDefault();
            alert('لطفاً یک مبلغ معتبر (عدد مثبت) وارد کنید.');
            if (amountInput) amountInput.focus();
            return;
        }
        if (!window.confirm('درخواست پرداخت ' + toPersianDigits(n.toLocaleString('en-US')) + ' تومان برای این متقاضی ثبت شود؟')) {
            e.preventDefault();
        }
    });
})();

// Phase 51 (v2.1.7) — document viewer modal.
// Clicking a thumbnail (which carries data-doc-* attributes) opens
// #docViewerModal. We swap the modal body to:
//   - image  → <img class="img-fluid"> inline (scaled to fit width)
//   - PDF    → <iframe width="100%" height="500px"> (browser PDF viewer)
//   - other  → a centered download link + file name
// The download footer is always wired to /document/inline/{id} so the
// admin can save the file regardless of type. On hide we clear the
// body so the previous iframe does not keep loading in the background.
(function() {
    var modal = document.getElementById('docViewerModal');
    if (!modal) return;
    var body = document.getElementById('docViewerModalBody');
    var titleEl = document.getElementById('docViewerModalTitle');
    var nameEl = document.getElementById('docViewerModalName');
    var dl = document.getElementById('docViewerModalDownload');

    function escAttr(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        var url = trigger.dataset.docUrl || '';
        var isImage = trigger.dataset.docIsImage === '1';
        var isPdf = trigger.dataset.docIsPdf === '1';
        var name = trigger.dataset.docName || '';
        var label = trigger.dataset.docLabel || '';
        var safeUrl = escAttr(url);
        var safeName = escAttr(name);
        var safeLabel = escAttr(label);

        if (titleEl) titleEl.textContent = label ? (label + ' — ' + name) : name;
        if (nameEl) nameEl.textContent = name;

        if (isImage) {
            body.innerHTML =
                '<img src="' + safeUrl + '" class="img-fluid w-100 d-block mx-auto" ' +
                'style="background:#fff" alt="' + safeLabel + '">';
        } else if (isPdf) {
            body.innerHTML =
                '<iframe src="' + safeUrl + '" title="' + safeLabel + '" ' +
                'style="width:100%;height:500px;border:0;background:#fff;"></iframe>';
        } else {
            body.innerHTML =
                '<div class="p-5 text-center text-muted">' +
                  '<i class="bi bi-file-earmark-text" style="font-size:4rem"></i>' +
                  '<p class="mt-3 mb-0" dir="ltr">' + safeName + '</p>' +
                  '<p class="small mb-0">نمایش پیش‌نمایش برای این نوع فایل پشتیبانی نمی‌شود — ' +
                  'برای دانلود روی دکمهٔ «دانلود» بزنید.</p>' +
                '</div>';
        }

        if (dl) {
            dl.href = url;
            dl.setAttribute('download', name);
        }
    });

    modal.addEventListener('hidden.bs.modal', function () {
        if (body) body.innerHTML = '';
        if (dl) { dl.href = '#'; dl.removeAttribute('download'); }
        if (titleEl) titleEl.textContent = 'مشاهدهٔ مدرک';
        if (nameEl) nameEl.textContent = '';
    });
})();
</script>
