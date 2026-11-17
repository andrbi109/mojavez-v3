<?php
/** @var array $applicants */
/** @var string $csrfField */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-file-earmark-minus"></i> کاربران نیازمند مدرک</h2>
    <a href="/admin/bulk-import" class="btn btn-outline-primary btn-sm"><i class="bi bi-cloud-upload"></i> ایمپورت دسته‌جمعی</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 70vh;">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light position-sticky top-0">
                    <tr>
                        <th>#</th><th>کد رهگیری</th><th>نام</th><th>کد ملی</th>
                        <th>نوع خدمت</th><th>تعداد مدارک</th><th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($applicants as $a): ?>
                    <tr>
                        <td><?= (int)$a['id'] ?></td>
                        <td><code><?= htmlspecialchars((string)$a['tracking_code'], ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= htmlspecialchars((string)$a['first_name'] . ' ' . (string)$a['last_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td dir="ltr"><?= htmlspecialchars((string)$a['national_code'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small"><?= htmlspecialchars((string)($a['service_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="badge text-bg-<?= (int)$a['doc_count'] > 0 ? 'success' : 'warning' ?>">
                                <?= (int)$a['doc_count'] ?>
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
                    <tr><td colspan="7" class="text-center text-muted py-4">همهٔ متقاضیان مدرک دارند.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
