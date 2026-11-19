<?php
/**
 * @var \App\Modules\Core\Domain\Applicant $applicant
 * @var array<string,array<int,\App\Modules\Core\Domain\Document>> $docsByType
 * @var array<int,array{key:string,label:string,required:bool}>  $docTypes
 * @var string $message
 * @var string $csrfField
 */
$message ??= '';
$esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="h5 mb-0 fw-bold">
            <i class="bi bi-upload me-2"></i> بارگذاری مدارک:
            <code><?= $esc($applicant->trackingCode) ?></code>
        </h3>
        <a href="/admin/bulk-import/missing-docs" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-return-right"></i> بازگشت به لیست
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-info py-2"><?= $esc($message) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <h4 class="h6 mb-3"><i class="bi bi-person me-1"></i> اطلاعات متقاضی</h4>
            <dl class="row mb-0 small">
                <dt class="col-sm-2">کد رهگیری</dt>
                <dd class="col-sm-10"><code><?= $esc($applicant->trackingCode) ?></code></dd>
                <dt class="col-sm-2">نام</dt>
                <dd class="col-sm-10"><?= $esc($applicant->firstName . ' ' . $applicant->lastName) ?></dd>
                <dt class="col-sm-2">کد ملی</dt>
                <dd class="col-sm-10"><code><?= $esc($applicant->nationalCode) ?></code></dd>
                <dt class="col-sm-2">موبایل</dt>
                <dd class="col-sm-10"><code><?= $esc($applicant->mobile) ?></code></dd>
            </dl>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-folder me-1"></i> مدارک موجود</div>
        <div class="card-body">
            <?php if (empty($docsByType)): ?>
                <p class="text-muted mb-0">هیچ مدرکی بارگذاری نشده.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($docsByType as $type => $docs): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div>
                                <strong><?= $esc((string)$type) ?></strong>
                                <div class="small text-muted mt-1">
                                    <?php foreach ($docs as $d): ?>
                                        <span class="badge text-bg-light text-muted me-1 mb-1">
                                            <i class="bi bi-file-earmark"></i>
                                            <?= $esc($d->fileName) ?>
                                        </span>
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
        <div class="card-header"><i class="bi bi-cloud-upload me-1"></i> بارگذاری مدارک جدید</div>
        <div class="card-body">
            <form method="post" action="/admin/bulk-import/documents/<?= urlencode($applicant->trackingCode) ?>/save" enctype="multipart/form-data">
                <?= $csrfField ?>

                <div class="row g-3">
                    <?php foreach ($docTypes as $dt): ?>
                        <?php
                            $key = (string)$dt['key'];
                            $label = (string)$dt['label'];
                            $required = (bool)$dt['required'];
                            $existingCount = count($docsByType[$key] ?? []);
                        ?>
                        <div class="col-md-6">
                            <div class="card h-100 <?= $existingCount > 0 ? 'border-success' : ($required ? 'border-warning' : '') ?>">
                                <div class="card-body">
                                    <label class="form-label fw-bold small" for="doc_<?= $esc($key) ?>">
                                        <?= $esc($label) ?>
                                        <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                                    </label>
                                    <input type="file" class="form-control form-control-sm"
                                           id="doc_<?= $esc($key) ?>"
                                           name="doc_<?= $esc($key) ?>"
                                           accept=".jpg,.jpeg,.png,.pdf">
                                    <?php if ($existingCount > 0): ?>
                                        <small class="text-success mt-1 d-block">
                                            <i class="bi bi-check-circle"></i>
                                            <?= $existingCount ?> مدرک موجود
                                        </small>
                                    <?php elseif ($required): ?>
                                        <small class="text-warning mt-1 d-block">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            مدرک الزامی — بدون مدرک
                                        </small>
                                    <?php else: ?>
                                        <small class="text-muted mt-1 d-block">
                                            <i class="bi bi-dash-circle"></i>
                                            اختیاری — بدون مدرک
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-3 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-save"></i> ذخیرهٔ مدارک
                    </button>
                    <a href="/admin/bulk-import/missing-docs" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-return-right"></i> بازگشت به لیست
                    </a>
                    <a href="/admin/view/<?= (int)$applicant->id ?>" class="btn btn-outline-info btn-lg">
                        <i class="bi bi-eye"></i> مشاهدهٔ پرونده
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
