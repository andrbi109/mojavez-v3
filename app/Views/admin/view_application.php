<?php
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array $statusLog */
use App\Modules\Core\Domain\Applicant;
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h4 mb-1">پروندهٔ متقاضی</h1>
        <span class="moj-tracking-code fs-5"><?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <span class="badge text-bg-<?= htmlspecialchars($applicant->currentStatus->badgeColor(), ENT_QUOTES, 'UTF-8') ?> fs-6">
        <?= htmlspecialchars($applicant->currentStatus->titleFa(), ENT_QUOTES, 'UTF-8') ?>
    </span>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-person-vcard"></i> اطلاعات متقاضی
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">نام</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($applicant->fullName(), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-muted">کد ملی</dt>
                    <dd class="col-sm-8" dir="ltr"><?= htmlspecialchars($applicant->nationalCode, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-muted">موبایل</dt>
                    <dd class="col-sm-8" dir="ltr"><?= htmlspecialchars($applicant->mobile, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-muted">وضعیت</dt>
                    <dd class="col-sm-8">
                        <span class="badge text-bg-<?= htmlspecialchars($applicant->currentStatus->badgeColor(), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($applicant->currentStatus->titleFa(), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </dd>
                    <dt class="col-sm-4 text-muted">وضعیت مجوز</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($applicant->licenseState->titleFa(), ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($applicant->isMatchedToNational()): ?>
        <div class="card mt-3 border-success">
            <div class="card-header bg-success-subtle d-flex align-items-center gap-2">
                <i class="bi bi-patch-check-fill text-success"></i> اتصال طلایی
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">شماره درخواست ملی</dt>
                    <dd class="col-sm-8" dir="ltr"><?= htmlspecialchars((string)$applicant->nationalLicenseRequestNumber, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-4 text-muted">اطمینان تطبیق</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars((string)$applicant->matchConfidence, ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-7">
        <div class="card h-100">
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
                            <tr>
                                <td><span class="badge text-bg-secondary"><?= htmlspecialchars($entry->statusName, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="small"><?= htmlspecialchars($entry->triggeredBy, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="small text-muted" dir="ltr"><?= htmlspecialchars($entry->createdAt, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="small"><?= htmlspecialchars((string)($entry->description ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
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
