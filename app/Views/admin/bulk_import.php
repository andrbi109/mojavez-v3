<?php
/** @var string $error */
/** @var string $csrfField */
$error ??= '';
?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-4">
        <h3 class="h5 mb-4"><i class="bi bi-cloud-upload"></i> ایمپورت دسته‌جمعی کاربران</h3>
        <form method="post" action="/admin/bulk-import/upload" enctype="multipart/form-data">
            <?= $csrfField ?>
            <div class="mb-3">
                <label class="form-label fw-bold">فایل CSV <span class="text-danger">*</span></label>
                <input type="file" class="form-control" name="csv_file" accept=".csv" required>
                <small class="text-muted">
                    فرمت: CSV با هدر انگلیسی شامل ستون‌های
                    <code>national_code, first_name, last_name, mobile, service_type_id, postal_code</code>
                    — حداکثر ۵ مگابایت و ۱۰۰۰ ردیف.
                </small>
            </div>
            <div class="alert alert-info py-2 small">
                <strong>نمونهٔ فرمت CSV:</strong><br>
                <code>national_code,first_name,last_name,mobile,service_type_id,postal_code</code><br>
                <code>1234567890,علی,احمدی,09123456789,1,1234567890</code>
            </div>
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-upload"></i> آپلود و پیش‌نمایش</button>
            <a href="/admin/bulk-import/missing-docs" class="btn btn-outline-secondary btn-lg">لیست نیازمند مدرک</a>
        </form>
    </div>
</div>
