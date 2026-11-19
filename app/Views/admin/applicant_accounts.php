<?php
/**
 * admin/applicant_accounts.php — list of user_accounts (applicant logins).
 *
 * Phase 42 (v2.0.8) — these are the accounts that applicants use to log in
 * to /panel/*, NOT the admin `users` table (which is managed by
 * UsersController at /admin/users). This page is the admin-side
 * counterpart: it lets an admin list, search, inspect, enable/disable,
 * and reset the password of any applicant login account.
 *
 * Rendered by ApplicantAccountController::index() with:
 *   $rows   list<array{id,username,mobile,email,display_name,is_active,
 *                    created_at,last_login_at}>
 *   $q     string  — current search query (mobile OR username LIKE).
 *   $page  int     — current 1-based page number.
 *   $pages int     — total number of pages.
 *   $total int     — total row count for the current filter.
 *   $baseUrl, $csrfField.
 *
 * @var array<int,array<string,mixed>> $rows
 * @var string $q
 * @var int    $page
 * @var int    $pages
 * @var int    $total
 * @var string $baseUrl
 * @var string $csrfField
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-person-lines-fill text-brand"></i> حساب‌های متقاضیان</h2>
    <span class="text-muted small">مجموع: <?= (int)$total ?> حساب</span>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applicant-accounts" class="row g-2 align-items-center">
            <div class="col-sm-8 col-md-6">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"
                        class="form-control" placeholder="جستجو بر اساس موبایل یا نام کاربری"
                        aria-label="جستجوی حساب متقاضی">
                </div>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-funnel"></i> جستجو
                </button>
                <?php if ($q !== ''): ?>
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applicant-accounts" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-lg"></i> پاک کردن
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>نام کاربری</th>
                        <th>موبایل</th>
                        <th>ایمیل</th>
                        <th>نام نمایشی</th>
                        <th>وضعیت</th>
                        <th>ورود آخر</th>
                        <th>ایجاد</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $isActive   = (int)$r['is_active'] === 1;
                    $username   = (string)($r['username'] ?? '');
                    $email      = (string)($r['email'] ?? '');
                    $displayName = (string)($r['display_name'] ?? '');
                    $lastLogin  = (string)($r['last_login_at'] ?? '');
                    $createdAt  = (string)($r['created_at'] ?? '');
                    $id         = (int)$r['id'];
                ?>
                    <tr>
                        <td><?= $id ?></td>
                        <td>
                            <?= $username !== '' ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td><?= htmlspecialchars((string)$r['mobile'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?= $email !== '' ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
                            <?= $displayName !== '' ? htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
                            <?php if ($isActive): ?>
                                <span class="badge text-bg-success">فعال</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">غیرفعال</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?= $lastLogin !== '' ? htmlspecialchars($lastLogin, ENT_QUOTES, 'UTF-8') : '—' ?>
                        </td>
                        <td class="small text-muted">
                            <?= $createdAt !== '' ? htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') : '—' ?>
                        </td>
                        <td class="text-end">
                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applicant-accounts/<?= $id ?>"
                                class="btn btn-sm btn-outline-primary" title="جزئیات">
                                <i class="bi bi-eye"></i>
                            </a>
                            <form method="post"
                                action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applicant-accounts/<?= $id ?>/toggle"
                                class="d-inline"
                                onsubmit="return confirm('<?= $isActive ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?> این حساب؟');">
                                <?= $csrfField ?>
                                <button type="submit"
                                    class="btn btn-sm btn-outline-<?= $isActive ? 'warning' : 'success' ?>"
                                    title="<?= $isActive ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?>">
                                    <i class="bi bi-<?= $isActive ? 'pause-fill' : 'play-fill' ?>"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">
                        <?php if ($q !== ''): ?>
                            هیچ حسابی منطبق با «<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>» یافت نشد.
                        <?php else: ?>
                            هیچ حساب متقاضی‌ای ثبت نشده.
                        <?php endif; ?>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($pages > 1): ?>
    <nav aria-label="صفحه‌بندی" class="mt-3">
        <ul class="pagination justify-content-center">
            <?php
            $buildUrl = function (int $p) use ($baseUrl, $q): string {
                $qs = [];
                if ($q !== '') {
                    $qs[] = 'q=' . urlencode($q);
                }
                $qs[] = 'page=' . $p;
                return htmlspecialchars($baseUrl, ENT_QUOTES) . 'admin/applicant-accounts?' . implode('&', $qs);
            };
            ?>
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $buildUrl(max(1, $page - 1)) ?>" aria-label="قبلی">&laquo;</a>
            </li>
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= $buildUrl($p) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $buildUrl(min($pages, $page + 1)) ?>" aria-label="بعدی">&raquo;</a>
            </li>
        </ul>
    </nav>
<?php endif; ?>

<p class="text-muted small mt-2">
    صفحهٔ <?= (int)$page ?> از <?= (int)$pages ?> — نمایش <?= count($rows) ?> ردیف از <?= (int)$total ?>.
</p>

<div class="mt-3">
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/dashboard"
        class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-return-right"></i> بازگشت به داشبورد
    </a>
</div>
