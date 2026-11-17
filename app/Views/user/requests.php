<?php
/**
 * user/requests.php — لیست درخواست‌های کاربر (Phase 9).
 */
/** @var array $applications */
/** @var array $serviceTypes */
/** @var string $baseUrl */

$serviceTitle = function (int $id) use ($serviceTypes): string {
    return $serviceTypes[$id] ?? '—';
};
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-folder2-open"></i> درخواست‌های ثبت‌شده</span>
        <span class="badge bg-secondary"><?= count($applications) ?> مورد</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($applications)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox display-4"></i>
                <p class="mt-2 mb-3">هنوز درخواستی ثبت نکرده‌اید.</p>
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> شروع ثبت‌نام جدید
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>کد پیگیری</th>
                            <th>نوع خدمت</th>
                            <th>وضعیت</th>
                            <th>تاریخ ثبت</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $a): ?>
                            <?php
                                $st = (string)($a['current_status'] ?? '');
                                $badge = 'secondary';
                                if (in_array($st, ['صدور مجوز', 'تحویل مجوز'], true)) $badge = 'success';
                                elseif ($st === 'لغو') $badge = 'danger';
                                elseif (in_array($st, ['پیش‌نویس'], true)) $badge = 'light text-dark';
                            ?>
                            <tr>
                                <td><code class="user-select-all"><?= htmlspecialchars((string)($a['tracking_code'] ?? '—'), ENT_QUOTES) ?></code></td>
                                <td><?= htmlspecialchars($serviceTitle((int)($a['service_type_id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="small text-muted"><?= htmlspecialchars((string)($a['created_at'] ?? ''), ENT_QUOTES) ?></td>
                                <td class="text-end">
                                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>tracking/status/<?= urlencode((string)($a['tracking_code'] ?? '')) ?>"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i> پیگیری
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
