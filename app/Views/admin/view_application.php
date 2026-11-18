<?php
/**
 * admin/view_application.php — editable applicant case view with 18-status cycle.
 *
 * Phase 29 (v1.9.11) — the admin can:
 *   1. View all applicant data (identity, property, job, documents).
 *   2. Edit fields inline (POST to /admin/update).
 *   3. Change the applicant's status via the 18-status dropdown (POST to
 *      /admin/change-status).
 *   4. Upload/delete documents.
 *   5. View the status history timeline.
 *
 * Expected $data:
 *   $applicant        Applicant  — the domain object
 *   $statusLog        array<StatusLogEntry>
 *   $allStatuses      array<array{value,label,badgeColor,order,isTerminal}>
 *   $jobDetail        ?array    — job_details row or null
 *   $propertyDetail   ?array    — property_details row or null
 *   $documents        array     — documents rows
 *   $serviceTypeTitle string    — service type title
 *   $baseUrl          string
 *   $csrfField        string
 */
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array $statusLog */
/** @var array $allStatuses */
/** @var ?array $jobDetail */
/** @var ?array $propertyDetail */
/** @var array $documents */
/** @var string $serviceTypeTitle */
/** @var string $baseUrl */
/** @var string $csrfField */

use App\Modules\Core\Domain\Applicant;
use App\Modules\Core\Domain\Enums\LicenseState;

$allStatuses  ??= [];
$jobDetail    ??= null;
$propertyDetail ??= null;
$documents    ??= [];
$serviceTypeTitle ??= '';

$currentStatus = $applicant->currentStatus;
$licenseState = $applicant->licenseState;
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h4 mb-1">پروندهٔ متقاضی</h1>
        <span class="moj-tracking-code fs-5"><?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($serviceTypeTitle !== ''): ?>
            <span class="badge text-bg-info ms-2"><?= htmlspecialchars($serviceTypeTitle, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span class="badge text-bg-<?= htmlspecialchars($currentStatus->badgeColor(), ENT_QUOTES, 'UTF-8') ?> fs-6">
            <?= htmlspecialchars($currentStatus->titleFa(), ENT_QUOTES, 'UTF-8') ?>
        </span>
        <span class="badge text-bg-<?= $licenseState === LicenseState::Issued ? 'success' : 'secondary' ?> fs-6">
            وضعیت مجوز: <?= htmlspecialchars($licenseState->titleFa(), ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>
</div>

<!-- ====== Status Change Card (18-status cycle) ====== -->
<div class="card border-primary mb-4">
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
                    <select class="form-select" id="new_status" name="new_status" required>
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
                           placeholder="دلیل تغییر وضعیت..." maxlength="500">
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="btn-change-status">
                    <i class="bi bi-check2-circle"></i> اعمال تغییر وضعیت
                </button>
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/status-cycle" class="btn btn-outline-secondary" target="_blank">
                    <i class="bi bi-diagram-3"></i> مشاهده چرخهٔ کامل
                </a>
            </div>
        </form>
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
                    <dd class="col-sm-8" dir="ltr"><?= htmlspecialchars($applicant->postalCode, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-muted">نام پدر</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars((string)($applicant->fatherName ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-muted">تاریخ تولد</dt>
                    <dd class="col-sm-8" dir="ltr"><?= htmlspecialchars((string)($applicant->birthDate ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
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
                            <label class="form-label small">کد ملی</label>
                            <input type="text" class="form-control form-control-sm" name="national_code" dir="ltr"
                                value="<?= htmlspecialchars($applicant->nationalCode, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">موبایل</label>
                            <input type="text" class="form-control form-control-sm" name="mobile" dir="ltr"
                                value="<?= htmlspecialchars($applicant->mobile, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">کد پستی</label>
                            <input type="text" class="form-control form-control-sm" name="postal_code" dir="ltr"
                                value="<?= htmlspecialchars($applicant->postalCode, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">نام پدر</label>
                            <input type="text" class="form-control form-control-sm" name="father_name"
                                value="<?= htmlspecialchars((string)($applicant->fatherName ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-12 mt-3 d-flex gap-2">
                            <button type="submit" class="btn btn-success btn-sm">
                                <i class="bi bi-check-lg"></i> ذخیره
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-cancel-edit">
                                <i class="bi bi-x-lg"></i> انصراف
                            </button>
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
                    <dd class="col-sm-7"><?= htmlspecialchars((string)$applicant->matchConfidence, ENT_QUOTES, 'UTF-8') ?></dd>
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
                    <dt class="col-sm-5 text-muted">مالکیت</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars((string)($propertyDetail['ownership_status'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-5 text-muted">آدرس</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars((string)($propertyDetail['address_street'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
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

    <!-- ====== Right column: timeline + documents ====== -->
    <div class="col-lg-7">
        <!-- Documents -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-file-earmark-text"></i> مدارک</span>
                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse"
                        data-bs-target="#upload-doc-form">
                    <i class="bi bi-upload"></i> افزودن مدرک
                </button>
            </div>
            <div class="card-body p-0">
                <div class="collapse" id="upload-doc-form">
                    <div class="p-3 bg-light border-bottom">
                        <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/add-document"
                              enctype="multipart/form-data">
                            <?= $csrfField ?>
                            <input type="hidden" name="applicant_id" value="<?= (int)$applicant->id ?>">
                            <div class="row g-2 align-items-end">
                                <div class="col-5">
                                    <label class="form-label small">نوع مدرک</label>
                                    <input type="text" class="form-control form-control-sm" name="doc_type"
                                           placeholder="national_id" required>
                                </div>
                                <div class="col-5">
                                    <label class="form-label small">فایل</label>
                                    <input type="file" class="form-control form-control-sm" name="document" required>
                                </div>
                                <div class="col-2">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-upload"></i>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <?php if (!empty($documents)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>نوع</th><th>نام فایل</th><th>تاریخ</th><th class="text-end">عملیات</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td><span class="badge text-bg-secondary"><?= htmlspecialchars((string)$doc['doc_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="small"><?= htmlspecialchars((string)$doc['original_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="small text-muted" dir="ltr"><?= htmlspecialchars((string)$doc['uploaded_at'], ENT_QUOTES) ?></td>
                                <td class="text-end">
                                    <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/delete-document"
                                          class="d-inline" onsubmit="return confirm('حذف این مدرک؟')">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
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
                <div class="table-responsive">
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
                            ?>
                            <tr>
                                <td><span class="badge text-bg-secondary"><?= htmlspecialchars($eStatus, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="small"><?= htmlspecialchars($eBy, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="small text-muted" dir="ltr"><?= htmlspecialchars($eAt, ENT_QUOTES) ?></td>
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
(function() {
    var sel = document.getElementById('new_status');
    if (!sel) return;
    sel.addEventListener('change', function() {
        var opt = sel.options[sel.selectedIndex];
        var btn = document.getElementById('btn-change-status');
        if (!opt || !opt.value) { btn.className = 'btn btn-primary'; return; }
        var color = opt.getAttribute('data-color') || 'primary';
        var terminal = opt.getAttribute('data-terminal') === '1';
        btn.className = 'btn btn-' + (terminal ? 'danger' : color);
    });
})();
</script>
