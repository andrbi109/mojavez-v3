<?php
/**
 * @var array<int,array<string,mixed>> $rows
 * @var int                            $totalCount
 * @var int                            $validCount
 * @var int                            $invalidCount
 * @var string                         $filename
 * @var array<int,string>              $columns
 * @var string                         $csrfField
 */
$filename     ??= '';
$columns      ??= ['national_code', 'first_name', 'last_name', 'mobile', 'service_type_id', 'postal_code'];
$esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="h5 mb-0 fw-bold"><i class="bi bi-eye me-2"></i> پیش‌نمایش ایمپورت</h3>
        <div class="text-muted small">
            فایل: <code><?= $esc($filename) ?></code>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-auto"><span class="badge text-bg-success fs-6"><i class="bi bi-check-circle"></i> معتبر: <?= (int)$validCount ?></span></div>
        <div class="col-auto"><span class="badge text-bg-danger fs-6"><i class="bi bi-x-circle"></i> نامعتبر: <?= (int)$invalidCount ?></span></div>
        <div class="col-auto"><span class="badge text-bg-secondary fs-6">کل: <?= (int)$totalCount ?></span></div>
    </div>

    <?php if ($invalidCount > 0): ?>
        <div class="alert alert-warning py-2 small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            ردیف‌های نامعتبر در زمان اجرا به‌صورت خودکار skip می‌شوند و درج نخواهند شد.
            می‌توانید با تیک زدن/برداشتن کادر کنار هر ردیف معتبر، انتخاب را محدود کنید.
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/bulk-import/execute"
          onsubmit="return confirm('ایمپورت ردیف‌های انتخاب‌شده اجرا شود؟');">
        <?= $csrfField ?>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="submit" class="btn btn-success btn-lg">
                <i class="bi bi-play-circle"></i> اجرای ایمپورت
            </button>
            <button type="button" class="btn btn-outline-success btn-lg" onclick="selectAllValid()">
                <i class="bi bi-check2-all"></i> انتخاب همهٔ معتبر
            </button>
            <button type="button" class="btn btn-outline-secondary btn-lg" onclick="deselectAll()">
                <i class="bi bi-x-lg"></i> لغو همه
            </button>
            <a href="/admin/bulk-import" class="btn btn-outline-danger btn-lg">
                <i class="bi bi-arrow-return-right"></i> انصراف
            </a>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 70vh;">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light position-sticky top-0">
                            <tr>
                                <th style="width: 40px;">✓</th>
                                <th>#</th>
                                <th>کد ملی</th>
                                <th>نام</th>
                                <th>نام خانوادگی</th>
                                <th>موبایل</th>
                                <th>نوع خدمت</th>
                                <th>کد پستی</th>
                                <th>وضعیت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <?php $rowClass = $r['valid'] ? '' : 'table-warning'; ?>
                                <tr class="<?= $rowClass ?>">
                                    <td>
                                        <?php if ($r['valid']): ?>
                                            <input type="checkbox" class="form-check-input"
                                                   name="selected_rows[]" value="<?= (int)$r['idx'] ?>" checked>
                                        <?php else: ?>
                                            <span class="text-muted" title="نامعتبر — قابل انتخاب نیست">⊘</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int)$r['idx'] + 1 ?></td>
                                    <td dir="ltr"><code><?= $esc($r['national_code']) ?></code></td>
                                    <td><?= $esc($r['first_name']) ?></td>
                                    <td><?= $esc($r['last_name']) ?></td>
                                    <td dir="ltr"><code><?= $esc($r['mobile']) ?></code></td>
                                    <td><?= $esc($r['service_type_id']) ?></td>
                                    <td dir="ltr"><code><?= $esc($r['postal_code']) ?></code></td>
                                    <td>
                                        <?php if ($r['valid']): ?>
                                            <span class="badge text-bg-success">
                                                <i class="bi bi-check-circle-fill"></i> معتبر
                                            </span>
                                        <?php else: ?>
                                            <span class="badge text-bg-danger" title="<?= $esc(implode('، ', $r['errors'])) ?>">
                                                <i class="bi bi-x-circle-fill"></i> نامعتبر
                                            </span>
                                            <div class="small text-danger mt-1">
                                                <?= $esc(implode('، ', $r['errors'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-2 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-success btn-lg">
                <i class="bi bi-play-circle"></i> اجرای ایمپورت
            </button>
            <a href="/admin/bulk-import" class="btn btn-outline-danger btn-lg">انصراف</a>
        </div>
    </form>

    <?php if ((int)$totalCount > 100): ?>
        <p class="text-muted small mt-2">
            نمایش ۱۰۰ ردیف اول از <?= (int)$totalCount ?> ردیف — کل ردیف‌ها در زمان اجرا پردازش می‌شوند.
        </p>
    <?php endif; ?>
</div>

<script>
function selectAllValid() {
    document.querySelectorAll('input[name="selected_rows[]"]').forEach(function (c) { c.checked = true; });
}
function deselectAll() {
    document.querySelectorAll('input[name="selected_rows[]"]').forEach(function (c) { c.checked = false; });
}
</script>
