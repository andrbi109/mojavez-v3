<?php
/**
 * @var string $error
 * @var string $csrfField
 * @var array<int,string> $columns
 */
$error   ??= '';
$columns ??= ['national_code', 'first_name', 'last_name', 'mobile', 'service_type_id', 'postal_code'];
$esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="container-fluid py-4">
    <h3 class="fw-bold mb-3"><i class="bi bi-cloud-upload me-2"></i> ایمپورت دسته‌جمعی کاربران</h3>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2"><?= $esc($error) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body p-4">
            <form method="post" action="/admin/bulk-import/upload" enctype="multipart/form-data">
                <?= $csrfField ?>

                <div class="mb-3">
                    <label class="form-label fw-bold" for="csv_file">
                        فایل CSV <span class="text-danger">*</span>
                    </label>
                    <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                    <div class="form-text">
                        فرمت: فایل CSV با هدر انگلیسی شامل ستون‌های زیر — حداکثر ۵ مگابایت و ۱۰۰۰ ردیف.
                    </div>
                </div>

                <div class="alert alert-info py-3 small">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <strong><i class="bi bi-info-circle me-1"></i> ستون‌های الزامی:</strong>
                        <?php foreach ($columns as $col): ?>
                            <code class="bg-light px-1 rounded"><?= $esc($col) ?></code>
                        <?php endforeach; ?>
                    </div>
                    <div class="mb-2">
                        <strong>نمونهٔ هدر:</strong>
                        <code class="d-block bg-light px-2 py-1 rounded mt-1">
                            <?= $esc(implode(',', $columns)) ?>
                        </code>
                    </div>
                    <div>
                        <strong>نمونهٔ ردیف:</strong>
                        <code class="d-block bg-light px-2 py-1 rounded mt-1">
                            1234567890,علی,احمدی,09123456789,1,1234567890
                        </code>
                    </div>
                </div>

                <div class="alert alert-warning py-2 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>نکات:</strong>
                    <ul class="mb-0 mt-1 ps-3">
                        <li>کد ملی باید ۱۰ رقم باشد، در غیر این صورت ردیف skip می‌شود.</li>
                        <li>موبایل باید با <code>09</code> شروع شود و ۱۱ رقم باشد.</li>
                        <li>کد پستی باید ۱۰ رقم باشد.</li>
                        <li>ردیف‌هایی که کد ملی‌شان قبلاً در دیتابیس موجود است skip می‌شوند.</li>
                    </ul>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-upload"></i> آپلود و پیش‌نمایش
                    </button>
                    <a href="/admin/bulk-import/template" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-filetype-csv"></i> دانستن قالب CSV
                    </a>
                    <a href="/admin/bulk-import/missing-docs" class="btn btn-outline-info btn-lg">
                        <i class="bi bi-folder2-open"></i> لیست نیازمند مدرک
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
