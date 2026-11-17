<?php
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array $docsByType */
/** @var array $docTypes */
/** @var string $message */
/** @var string $csrfField */
$message ??= '';
?>
<?php if ($message !== ''): ?>
    <div class="alert alert-info py-2"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-person"></i> بارگذاری مدارک</h2>
    <a href="/admin/bulk-import/missing-docs" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-return-right"></i> بازگشت</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h3 class="h6">اطلاعات متقاضی</h3>
        <dl class="row mb-0 small">
            <dt class="col-sm-2">کد رهگیری</dt><dd class="col-sm-10"><code><?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></code></dd>
            <dt class="col-sm-2">نام</dt><dd class="col-sm-10"><?= htmlspecialchars($applicant->firstName . ' ' . $applicant->lastName, ENT_QUOTES, 'UTF-8') ?></dd>
            <dt class="col-sm-2">کد ملی</dt><dd class="col-sm-10" dir="ltr"><?= htmlspecialchars($applicant->nationalCode, ENT_QUOTES, 'UTF-8') ?></dd>
            <dt class="col-sm-2">موبایل</dt><dd class="col-sm-10" dir="ltr"><?= htmlspecialchars($applicant->mobile, ENT_QUOTES, 'UTF-8') ?></dd>
        </dl>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-folder"></i> مدارک موجود</div>
    <div class="card-body">
        <?php if (empty($docsByType)): ?>
            <p class="text-muted mb-0">هیچ مدرکی بارگذاری نشده.</p>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($docsByType as $type => $docs): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div>
                            <strong><?= htmlspecialchars((string)$type, ENT_QUOTES, 'UTF-8') ?></strong>
                            <div class="small text-muted">
                                <?php foreach ($docs as $d): ?>
                                    <span class="badge text-bg-light text-muted me-1"><?= htmlspecialchars($d->fileName, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <span class="badge text-bg-secondary"><?= count($docs) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-upload"></i> بارگذاری مدرک جدید</div>
    <div class="card-body">
        <form method="post" action="/admin/bulk-import/documents/<?= urlencode($applicant->trackingCode) ?>/save" enctype="multipart/form-data">
            <?= $csrfField ?>
            <input type="hidden" name="applicant_id" value="<?= (int)$applicant->id ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">نوع مدرک</label>
                    <select class="form-select" name="doc_type">
                        <?php foreach ($docTypes as $dt): ?>
                            <option value="<?= htmlspecialchars($dt['key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dt['label'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">فایل</label>
                    <input type="file" class="form-control" name="document_file" accept=".jpg,.jpeg,.png,.pdf" required>
                    <small class="text-muted">پسوندهای مجاز: jpg, png, pdf — حداکثر ۲ مگابایت.</small>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-success"><i class="bi bi-upload"></i> بارگذاری</button>
                </div>
            </div>
        </form>
    </div>
</div>
