<?php
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array $statusLog */
/** @var ?\App\Modules\Core\Domain\JobDetail $jobDetail */
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h4 mb-1">پیگیری پرونده</h1>
        <div class="text-muted small">کد رهگیری:</div>
        <span class="moj-tracking-code fs-5"><?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <a href="/tracking/print/<?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-outline-primary">
        <i class="bi bi-printer"></i> نسخهٔ چاپی
    </a>
</div>

<div class="row g-4">
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-person-vcard"></i> اطلاعات متقاضی
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">نام</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars($applicant->fullName(), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-5 text-muted">وضعیت</dt>
                    <dd class="col-sm-7">
                        <span class="badge text-bg-<?= htmlspecialchars($applicant->currentStatus->badgeColor(), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($applicant->currentStatus->titleFa(), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </dd>
                    <dt class="col-sm-5 text-muted">وضعیت مجوز</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars($applicant->licenseState->titleFa(), ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($jobDetail !== null): ?>
        <div class="card mt-3">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-briefcase"></i> اطلاعات شغلی
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">رشته/عنوان</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars($jobDetail->licenseTitle, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-5 text-muted">سابقهٔ کار</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars($jobDetail->experienceLabel(), ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-md-7">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-clock-history"></i> تاریخچهٔ وضعیت
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr><th>#</th><th>وضعیت</th><th>تاریخ</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($statusLog as $i => $entry): ?>
                            <tr>
                                <td class="text-muted"><?= $i + 1 ?></td>
                                <td>
                                    <span class="badge text-bg-secondary">
                                        <?= htmlspecialchars($entry->statusName, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td class="text-muted" dir="ltr"><?= htmlspecialchars($entry->createdAt, ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($statusLog)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">تاریخچه‌ای ثبت نشده است.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
