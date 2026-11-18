<?php
/**
 * admin/applicant_account_view.php — detail view of one user_account.
 *
 * Phase 42 (v2.0.8) — rendered by ApplicantAccountController::view() with:
 *   $account    array — one row from user_accounts (without the password).
 *   $applicants list<array> — rows from applicants where user_account_id = $id.
 *   $baseUrl, $csrfField.
 *
 * The page also renders the password-reset flash (set by
 * ApplicantAccountController::resetPassword() before redirecting here) —
 * the flash contains the raw new password and is consumed once.
 *
 * @var array<string,mixed> $account
 * @var array<int,array<string,mixed>> $applicants
 * @var string $baseUrl
 * @var string $csrfField
 */

$accountId = (int)($account['id'] ?? 0);
$username   = (string)($account['username'] ?? '');
$mobile     = (string)($account['mobile'] ?? '');
$email      = (string)($account['email'] ?? '');
$displayName = (string)($account['display_name'] ?? '');
$isActive   = (int)($account['is_active'] ?? 0) === 1;
$personId   = (int)($account['person_id'] ?? 0);
$failedLogin = (int)($account['failed_login_count'] ?? 0);
$lockedUntil = (string)($account['locked_until'] ?? '');
$lastLogin  = (string)($account['last_login_at'] ?? '');
$createdAt  = (string)($account['created_at'] ?? '');

// Consume the password-reset flash if present.
$flash = null;
if ($this->session->has('applicant_account_flash')) {
    $raw = $this->session->get('applicant_account_flash');
    if (is_array($raw) && (int)($raw['account_id'] ?? 0) === $accountId) {
        $flash = $raw;
    }
    $this->session->remove('applicant_account_flash');
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0">
        <i class="bi bi-person-badge text-brand"></i>
        حساب متقاضی #<?= $accountId ?>
    </h2>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applicant-accounts" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-return-right"></i> بازگشت به فهرست
    </a>
</div>

<?php if ($flash !== null): ?>
    <div class="alert alert-warning" role="alert">
        <i class="bi bi-key-fill"></i>
        رمز عبور جدید برای این حساب با موفقیت تنظیم شد. این رمز را یک‌بار
        به متقاضی اطلاع دهید (پیامک، تماس و...) و سپس این پیام را نادیده بگیرید —
        پس از بارگذاری مجدد صفحه دیگر نمایش داده نمی‌شود.
        <hr>
        <dl class="row mb-0 small">
            <dt class="col-sm-3">موبایل حساب</dt>
            <dd class="col-sm-9" dir="ltr"><?= htmlspecialchars((string)$flash['mobile'], ENT_QUOTES, 'UTF-8') ?></dd>
            <dt class="col-sm-3">نام کاربری</dt>
            <dd class="col-sm-9" dir="ltr">
                <?= $flash['username'] !== '' && $flash['username'] !== null
                    ? htmlspecialchars((string)$flash['username'], ENT_QUOTES, 'UTF-8')
                    : '<span class="text-muted">—</span>' ?>
            </dd>
            <dt class="col-sm-3">رمز عبور جدید</dt>
            <dd class="col-sm-9">
                <code class="user-select-all fs-5" dir="ltr"><?= htmlspecialchars((string)$flash['password'], ENT_QUOTES, 'UTF-8') ?></code>
            </dd>
        </dl>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-info-circle"></i> مشخصات حساب
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-4 text-muted">شناسهٔ حساب</dt>
                    <dd class="col-sm-8"><?= $accountId ?></dd>

                    <dt class="col-sm-4 text-muted">شناسهٔ شخص</dt>
                    <dd class="col-sm-8">
                        <?= $personId > 0 ? (int)$personId : '<span class="text-muted">—</span>' ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">نام کاربری</dt>
                    <dd class="col-sm-8" dir="ltr">
                        <?= $username !== '' ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">موبایل</dt>
                    <dd class="col-sm-8" dir="ltr"><?= htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-sm-4 text-muted">ایمیل</dt>
                    <dd class="col-sm-8" dir="ltr">
                        <?= $email !== '' ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">نام نمایشی</dt>
                    <dd class="col-sm-8">
                        <?= $displayName !== '' ? htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">وضعیت</dt>
                    <dd class="col-sm-8">
                        <?php if ($isActive): ?>
                            <span class="badge text-bg-success">فعال</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">غیرفعال</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">ورودهای ناموفق</dt>
                    <dd class="col-sm-8"><?= $failedLogin ?></dd>

                    <dt class="col-sm-4 text-muted">قفل تا</dt>
                    <dd class="col-sm-8" dir="ltr">
                        <?= $lockedUntil !== '' ? htmlspecialchars($lockedUntil, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">آخرین ورود</dt>
                    <dd class="col-sm-8" dir="ltr">
                        <?= $lastLogin !== '' ? htmlspecialchars($lastLogin, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">تاریخ ایجاد</dt>
                    <dd class="col-sm-8" dir="ltr">
                        <?= $createdAt !== '' ? htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-shield-lock"></i> عملیات مدیریت
            </div>
            <div class="card-body d-flex flex-column gap-3">
                <div>
                    <h6 class="text-muted small mb-1">فعال/غیرفعال‌سازی ورود</h6>
                    <p class="text-muted small mb-2">
                        غیرفعال‌سازی مانع ورود متقاضی به پنل می‌شود. به‌محض فعال‌سازی
                        مجدد، ورود با همان موبایل/نام کاربری ممکن می‌شود.
                    </p>
                    <form method="post"
                        action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applicant-accounts/<?= $accountId ?>/toggle"
                        onsubmit="return confirm('<?= $isActive ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?> ورود این حساب؟');">
                        <?= $csrfField ?>
                        <button type="submit"
                            class="btn btn-sm btn-<?= $isActive ? 'outline-warning' : 'outline-success' ?>">
                            <i class="bi bi-<?= $isActive ? 'pause-fill' : 'play-fill' ?>"></i>
                            <?= $isActive ? 'غیرفعال‌سازی ورود' : 'فعال‌سازی ورود' ?>
                        </button>
                    </form>
                </div>

                <div class="border-top pt-3">
                    <h6 class="text-muted small mb-1">بازنشانی رمز عبور</h6>
                    <p class="text-muted small mb-2">
                        رمز عبور جدیدی به‌صورت تصادفی تولید و در پایگاه داده هش
                        می‌شود. رمز خام یک‌بار پس از بازنشانی در این صفحه نمایش
                        داده می‌شود تا به متقاضی اطلاع دهید.
                    </p>
                    <form method="post"
                        action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/applicant-accounts/<?= $accountId ?>/reset-password"
                        onsubmit="return confirm('رمز عبور این حساب بازنشانی شود؟');">
                        <?= $csrfField ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-key"></i> بازنشانی رمز عبور
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-folder2-open"></i> درخواست‌های پیوندی
        </span>
        <span class="text-muted small"><?= count($applicants) ?> درخواست</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>کد پیگیری</th>
                        <th>نام</th>
                        <th>موبایل</th>
                        <th>کد ملی</th>
                        <th>وضعیت</th>
                        <th>مجوز درگاه ملی</th>
                        <th>تاریخ ثبت</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($applicants as $a):
                    $applicantId = (int)$a['id'];
                    $tracking    = (string)($a['tracking_code'] ?? '');
                    $firstName   = (string)($a['first_name'] ?? '');
                    $lastName    = (string)($a['last_name'] ?? '');
                    $aMobile     = (string)($a['mobile'] ?? '');
                    $natCode     = (string)($a['national_code'] ?? '');
                    $status      = (string)($a['current_status'] ?? '');
                    $licenseState = (string)($a['license_state'] ?? '');
                    $rn          = (string)($a['national_license_request_number'] ?? '');
                    $aCreated    = (string)($a['created_at'] ?? '');
                ?>
                    <tr>
                        <td><?= $applicantId ?></td>
                        <td dir="ltr"><?= htmlspecialchars($tracking, ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?= htmlspecialchars($firstName . ' ' . $lastName, ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td dir="ltr"><?= htmlspecialchars($aMobile, ENT_QUOTES, 'UTF-8') ?></td>
                        <td dir="ltr">
                            <?= $natCode !== '' ? htmlspecialchars($natCode, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
                            <?php if ($status !== ''): ?>
                                <span class="badge text-bg-info"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                            <?php if ($licenseState !== ''): ?>
                                <span class="badge text-bg-secondary"><?= htmlspecialchars($licenseState, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </td>
                        <td dir="ltr">
                            <?= $rn !== '' ? htmlspecialchars($rn, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td dir="ltr" class="small text-muted">
                            <?= $aCreated !== '' ? htmlspecialchars($aCreated, ENT_QUOTES, 'UTF-8') : '—' ?>
                        </td>
                        <td class="text-end">
                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/view/<?= $applicantId ?>"
                                class="btn btn-sm btn-outline-primary" title="مشاهدهٔ درخواست">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($applicants)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">
                        این حساب هنوز به هیچ درخواستی پیوند نشده.
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
