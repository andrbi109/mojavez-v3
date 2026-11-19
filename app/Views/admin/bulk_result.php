<?php
/**
 * @var array<int,array<string,mixed>> $rows
 * @var int                            $inserted
 * @var int                            $failed
 * @var int                            $skipped
 * @var int                            $total
 * @var string                         $csrfField
 */
$esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="container-fluid py-4">
    <h3 class="fw-bold mb-3"><i class="bi bi-clipboard-check me-2"></i> گزارش ایمپورت</h3>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center border-success">
                <div class="card-body">
                    <div class="display-6 text-success"><?= (int)$inserted ?></div>
                    <small class="text-muted"><i class="bi bi-check-circle"></i> درج شد</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center border-danger">
                <div class="card-body">
                    <div class="display-6 text-danger"><?= (int)$failed ?></div>
                    <small class="text-muted"><i class="bi bi-x-circle"></i> خطا</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center border-warning">
                <div class="card-body">
                    <div class="display-6 text-warning"><?= (int)$skipped ?></div>
                    <small class="text-muted"><i class="bi bi-skip-forward"></i> skip شد</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center border-secondary">
                <div class="card-body">
                    <div class="display-6 text-secondary"><?= (int)$total ?></div>
                    <small class="text-muted"><i class="bi bi-collection"></i> کل</small>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="h6 mb-0">جدول جزئیات</h4>
        <div class="d-flex gap-2">
            <a href="/admin/bulk-import" class="btn btn-primary btn-sm"><i class="bi bi-upload"></i> ایمپورت جدید</a>
            <a href="/admin/bulk-import/missing-docs" class="btn btn-outline-info btn-sm"><i class="bi bi-folder2-open"></i> بارگذاری مدارک</a>
            <a href="/admin/applications" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list"></i> فهرست پرونده‌ها</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 70vh;">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light position-sticky top-0">
                        <tr>
                            <th>ردیف</th>
                            <th>نام</th>
                            <th>کد ملی</th>
                            <th>موبایل</th>
                            <th>کد رهگیری</th>
                            <th>حساب کاربری</th>
                            <th>وضعیت</th>
                            <th>پیام</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                            <?php
                                $status = (string)$r['status'];
                                $rowClass = match ($status) {
                                    'inserted' => 'table-success',
                                    'failed'   => 'table-danger',
                                    'skipped'  => 'table-warning',
                                    default    => '',
                                };
                                $badge = match ($status) {
                                    'inserted' => '<span class="badge text-bg-success"><i class="bi bi-check-circle-fill"></i> موفق</span>',
                                    'failed'   => '<span class="badge text-bg-danger"><i class="bi bi-x-circle-fill"></i> خطا</span>',
                                    'skipped'  => '<span class="badge text-bg-warning"><i class="bi bi-skip-forward-fill"></i> skip شد</span>',
                                    default    => '<span class="badge text-bg-secondary">' . $esc($status) . '</span>',
                                };

                                // Phase 59b — user_account outcome per row.
                                // account_status: 'created' | 'linked' | 'failed' | 'skipped' | ''
                                $accStatus = (string)($r['account_status'] ?? '');
                                $accUsername = (string)($r['username'] ?? '');
                                $accBadge = match ($accStatus) {
                                    'created' => '<span class="badge text-bg-success"><i class="bi bi-person-plus-fill"></i> ایجاد شد</span>',
                                    'linked'  => '<span class="badge text-bg-info"><i class="bi bi-link-45deg"></i> پیوند شد</span>',
                                    'failed'  => '<span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle-fill"></i> خطا</span>',
                                    'skipped' => '<span class="badge text-bg-secondary"><i class="bi bi-dash-circle"></i> —</span>',
                                    default   => '<span class="text-muted">—</span>',
                                };
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td><?= (int)$i + 1 ?></td>
                                <td><?= $esc($r['name']) ?></td>
                                <td><code><?= $esc($r['national_code']) ?></code></td>
                                <td><code><?= $esc($r['mobile']) ?></code></td>
                                <td>
                                    <?php if (!empty($r['tracking_code'])): ?>
                                        <code><?= $esc($r['tracking_code']) ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($accStatus === '' || $accStatus === 'skipped'): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <div class="d-flex flex-column gap-1">
                                            <?= $accBadge ?>
                                            <?php if ($accUsername !== ''): ?>
                                                <code dir="ltr" class="small text-muted"><?= $esc($accUsername) ?></code>
                                            <?php endif; ?>
                                            <?php if (!empty($r['account_message'])): ?>
                                                <small class="text-muted"><?= $esc($r['account_message']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $badge ?></td>
                                <td class="small text-muted"><?= $esc($r['message']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">نتیجه‌ای برای نمایش وجود ندارد.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
