<?php
/** @var array $rows */
/** @var int $total */
/** @var int $page */
/** @var int $pageSize */

use App\Shared\Support\PersianDate;
use App\Shared\Support\StatusBadge;
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-folder2-open"></i> درخواست‌ها
        </span>
        <span class="text-muted small"><?= htmlspecialchars((string)$total, ENT_QUOTES, 'UTF-8') ?> رکورد · صفحهٔ <?= htmlspecialchars((string)$page, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>کد رهگیری</th>
                        <th>نام</th>
                        <th>کد ملی</th>
                        <th>وضعیت</th>
                        <th>وضعیت مجوز</th>
                        <th>تاریخ ثبت</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><span class="moj-tracking-code"><?= htmlspecialchars((string)$row['tracking_code'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string)$row['first_name'] . ' ' . (string)$row['last_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['national_code'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge text-bg-<?= htmlspecialchars(StatusBadge::color((string)$row['current_status']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$row['current_status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string)$row['license_state'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-muted small"><?= htmlspecialchars(PersianDate::format((string)$row['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-end">
                            <a href="/admin/view/<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i> مشاهده
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">هیچ درخواستی یافت نشد.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    $totalPages = max(1, (int)ceil($total / max(1, $pageSize)));
    if ($totalPages > 1):
    ?>
    <div class="card-footer">
        <nav>
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= max(1, $page - 1) ?>">قبلی</a>
                </li>
                <li class="page-item active"><span class="page-link"><?= (int)$page ?> / <?= $totalPages ?></span></li>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= min($totalPages, $page + 1) ?>">بعدی</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
