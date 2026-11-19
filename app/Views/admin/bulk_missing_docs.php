<?php
/**
 * @var array<int,array<string,mixed>> $applicants
 * @var string                         $csrfField
 */
$esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="h5 mb-0 fw-bold"><i class="bi bi-folder2-open me-2"></i> کاربران نیازمند مدرک</h3>
        <a href="/admin/bulk-import" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-cloud-upload"></i> ایمپورت دسته‌جمعی
        </a>
    </div>

    <div class="alert alert-info py-2 small">
        <i class="bi bi-info-circle me-1"></i>
        این فهرست تنها متقاضیانی را نشان می‌دهد که هیچ مدرکی بارگذاری نکرده‌اند (LIMIT ۵۰۰).
        روی «بارگذاری مدرک» کلیک کنید تا فرم بارگذاری ۵ نوع مدرک استاندارد باز شود.
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 70vh;">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light position-sticky top-0">
                        <tr>
                            <th>#</th>
                            <th>کد رهگیری</th>
                            <th>نام</th>
                            <th>کد ملی</th>
                            <th>نوع خدمت</th>
                            <th>تعداد مدارک</th>
                            <th>وضعیت</th>
                            <th class="text-end">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applicants as $a): ?>
                            <?php
                                $dc = (int)($a['doc_count'] ?? 0);
                                if ($dc >= 5) { $st = 'کامل';      $cls = 'success'; }
                                elseif ($dc > 0) { $st = 'ناقص';   $cls = 'warning'; }
                                else             { $st = 'بدون مدرک'; $cls = 'danger'; }
                            ?>
                            <tr class="table-<?= $cls ?>">
                                <td><?= (int)$a['id'] ?></td>
                                <td><code><?= $esc($a['tracking_code']) ?></code></td>
                                <td><?= $esc((string)$a['first_name'] . ' ' . (string)$a['last_name']) ?></td>
                                <td><code><?= $esc($a['national_code']) ?></code></td>
                                <td class="small"><?= $esc((string)($a['service_title'] ?? '—')) ?></td>
                                <td>
                                    <span class="badge text-bg-<?= $cls ?>"><?= $dc ?></span>
                                </td>
                                <td>
                                    <span class="badge text-bg-<?= $cls ?>">
                                        <?php if ($cls === 'success'): ?><i class="bi bi-check-circle-fill"></i><?php elseif ($cls === 'warning'): ?><i class="bi bi-exclamation-triangle-fill"></i><?php else: ?><i class="bi bi-x-circle-fill"></i><?php endif; ?>
                                        <?= $esc($st) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="/admin/bulk-import/documents/<?= urlencode((string)$a['tracking_code']) ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-upload"></i> بارگذاری مدرک
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($applicants)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">همهٔ متقاضیان مدرک دارند.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
