<?php
/**
 * user/profile.php — پروفایل کاربر (Phase 9, read-only).
 *
 * ویرایش پروفایل و آپلود آواتار در v1.3 فعال می‌شود (DEBT_REVIEW D-9-1).
 * این ویو فعلاً فقط اطلاعات حساب را نمایش می‌دهد.
 */
/** @var array $account */
/** @var int $unreadCount */
/** @var string $baseUrl */

$displayName = (string)($account['username'] ?? $account['mobile'] ?? '—');
$mobile = (string)($account['mobile'] ?? '—');
$initial = mb_substr($displayName, 0, 1) ?: '؟';
$createdAt = (string)($account['created_at'] ?? '—');
$lastLogin = (string)($account['last_login_at'] ?? '—');
?>
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-person-circle"></i> اطلاعات حساب کاربری</div>
            <div class="card-body">
                <div class="d-flex align-items-center mb-4">
                    <div class="moj-user-avatar" style="width:64px;height:64px;font-size:1.5rem;">
                        <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="ms-3">
                        <h4 class="mb-0"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h4>
                        <span class="text-muted small">کاربر پنل</span>
                    </div>
                </div>

                <dl class="row mb-0">
                    <dt class="col-sm-4 small text-muted">نام نمایشی</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-sm-4 small text-muted">شماره موبایل</dt>
                    <dd class="col-sm-8" dir="ltr"><?= htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-sm-4 small text-muted">تاریخ عضویت</dt>
                    <dd class="col-sm-8 small"><?= htmlspecialchars($createdAt, ENT_QUOTES) ?></dd>

                    <dt class="col-sm-4 small text-muted">آخرین ورود</dt>
                    <dd class="col-sm-8 small"><?= $lastLogin !== '—' ? htmlspecialchars($lastLogin, ENT_QUOTES) : 'بدون ثبت' ?></dd>
                </dl>
            </div>
            <div class="card-footer bg-light">
                <div class="d-flex align-items-center">
                    <i class="bi bi-info-circle text-muted"></i>
                    <span class="small text-muted ms-2">
                        ویرایش پروفایل و بارگذاری تصویر در نسخهٔ ۱.۳ فعال خواهد شد
                        (<a href="https://github.com/andrbi109/mojavez-v3" class="text-decoration-none">DEBT_REVIEW D-9-1</a>).
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>
