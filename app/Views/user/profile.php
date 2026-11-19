<?php
/**
 * user/profile.php — پروفایل کاربر (Phase 12, editable).
 *
 * فاز ۱۲ (v1.3.0): فرم ویرایش پروفایل + فرم بارگذاری تصویر آواتار با
 * AJAX + بازخورد درون‌خطی. POST /panel/profile برای فیلدها، POST
 * /panel/avatar برای تصویر (multipart/form-data).
 *
 * توجه: فقط `username` و `mobile` ذخیره می‌شوند. فیلدهای `email` و
 * `display_name` معتبر می‌شوند ولی به‌دلیل نبود ستون در `user_accounts`
 * ذخیره نمی‌شوند.
 */
/** @var array $account */
/** @var int $unreadCount */
/** @var string $baseUrl */
/** @var string $csrfField */
/** @var string|null $avatarUrl */

$csrfField ??= '';
$avatarUrl ??= null;

$username   = (string)($account['username'] ?? '');
$mobile     = (string)($account['mobile'] ?? '');
$displayName = (string)($account['username'] ?? $account['mobile'] ?? '—');
$initial    = mb_substr($displayName, 0, 1) ?: '؟';
$createdAt  = (string)($account['created_at'] ?? '—');
$lastLogin  = (string)($account['last_login_at'] ?? '—');
$csrfToken  = $this->csrf->token();
?>
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-person-circle"></i> اطلاعات حساب کاربری</div>
            <div class="card-body">
                <div class="d-flex align-items-center mb-4">
                    <?php if ($avatarUrl !== null): ?>
                        <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>"
                             alt="تصویر پروفایل"
                             class="moj-user-avatar"
                             style="width:64px;height:64px;border-radius:50%;object-fit:cover;">
                    <?php else: ?>
                        <div class="moj-user-avatar" style="width:64px;height:64px;font-size:1.5rem;border-radius:50%;background:linear-gradient(135deg,#14b8a6,#0d9488);color:#fff;display:flex;align-items:center;justify-content:center;">
                            <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>
                    <div class="ms-3">
                        <h4 class="mb-0"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h4>
                        <span class="text-muted small">کاربر پنل</span>
                    </div>
                </div>

                <dl class="row mb-0 small">
                    <dt class="col-sm-4 text-muted">تاریخ عضویت</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($createdAt, ENT_QUOTES) ?></dd>

                    <dt class="col-sm-4 text-muted">آخرین ورود</dt>
                    <dd class="col-sm-8"><?= $lastLogin !== '—' ? htmlspecialchars($lastLogin, ENT_QUOTES) : 'بدون ثبت' ?></dd>
                </dl>
            </div>
        </div>

        <!-- آواتار -->
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-image"></i> تصویر پروفایل</div>
            <div class="card-body">
                <p class="text-muted small">
                    تصویر باید JPEG، PNG یا WEBP باشد و حداکثر ۲ مگابایت. تصویر
                    به‌صورت خودکار به مربع ۲۵۶×۲۵۶ تغییر اندازه داده می‌شود.
                </p>
                <form id="avatar-form" method="post" action="/panel/avatar" enctype="multipart/form-data">
                    <?= $csrfField ?>
                    <div class="mb-3">
                        <input type="file" name="avatar" id="avatar-file"
                               class="form-control"
                               accept="image/jpeg,image/png,image/webp" required>
                    </div>
                    <button type="submit" class="btn btn-primary" id="avatar-submit">
                        <i class="bi bi-upload"></i> بارگذاری
                    </button>
                </form>
            </div>
        </div>

        <!-- پروفایل -->
        <div class="card">
            <div class="card-header"><i class="bi bi-pencil"></i> ویرایش پروفایل</div>
            <div class="card-body">
                <form id="profile-form" method="post" action="/panel/profile">
                    <?= $csrfField ?>

                    <div class="mb-3">
                        <label for="username" class="form-label">نام کاربری</label>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                               minlength="3" maxlength="50"
                               pattern="[a-zA-Z0-9_.]+"
                               placeholder="مثال: ali.reza"
                               dir="ltr" style="text-align:right;">
                        <div class="form-text">۳ تا ۵۰ نویسه: حروف کوچک انگلیسی، عدد، زیرخط و نقطه.</div>
                    </div>

                    <div class="mb-3">
                        <label for="mobile" class="form-label">شماره موبایل</label>
                        <input type="tel" class="form-control" id="mobile" name="mobile"
                               value="<?= htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8') ?>"
                               pattern="09\d{9}" maxlength="11"
                               placeholder="09123456789"
                               dir="ltr" style="text-align:right;" required>
                        <div class="form-text">شماره موبایل به‌عنوان شناسهٔ ورود استفاده می‌شود.</div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">
                            ایمیل
                        </label>
                        <input type="email" class="form-control" id="email" name="email"
                               value=""
                               maxlength="254"
                               placeholder="example@domain.com"
                               dir="ltr" style="text-align:right;">
                    </div>

                    <div class="mb-3">
                        <label for="display_name" class="form-label">
                            نام نمایشی
                        </label>
                        <input type="text" class="form-control" id="display_name" name="display_name"
                               value=""
                               maxlength="100"
                               placeholder="نام کامل شما">
                    </div>

                    <button type="submit" class="btn btn-primary" id="profile-submit">
                        <i class="bi bi-check2-circle"></i> ذخیرهٔ تغییرات
                    </button>
                </form>
            </div>
        </div>

        <div id="profile-feedback" class="mt-3" role="status" aria-live="polite"></div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var feedback = document.getElementById('profile-feedback');

    function showFeedback(type, html) {
        // type: 'success' | 'danger' | 'warning'
        var cls = type === 'success' ? 'alert-success'
                : type === 'danger'  ? 'alert-danger'
                : 'alert-warning';
        feedback.innerHTML = '<div class="alert ' + cls + ' alert-dismissible fade show" role="alert">'
            + html
            + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="بستن"></button>'
            + '</div>';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }

    // ----- profile form -----
    var profileForm = document.getElementById('profile-form');
    if (profileForm) {
        profileForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = document.getElementById('profile-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال ذخیره…';

            fetch('/panel/profile', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                body: new FormData(profileForm)
            }).then(function (res) {
                return res.json().then(function (j) {
                    return { status: res.status, json: j };
                });
            }).then(function (r) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check2-circle"></i> ذخیرهٔ تغییرات';
                if (r.status >= 200 && r.status < 300 && r.json.success) {
                    var msg = 'تغییرات با موفقیت ذخیره شد.';
                    // Update the username/mobile inputs with the normalised
                    // values returned by the server (so the user sees what
                    // was actually persisted).
                    if (r.json.clean && r.json.clean.username !== undefined) {
                        var u = document.getElementById('username');
                        if (u) u.value = r.json.clean.username === null ? '' : r.json.clean.username;
                    }
                    if (r.json.clean && r.json.clean.mobile !== undefined) {
                        var m = document.getElementById('mobile');
                        if (m) m.value = r.json.clean.mobile;
                    }
                    showFeedback('success', escapeHtml(msg));
                } else {
                    var errs = (r.json && r.json.errors) || [];
                    var html = errs.length > 0
                        ? errs.map(escapeHtml).join('<br>')
                        : 'ذخیرهٔ تغییرات ناموفق بود.';
                    showFeedback('danger', html);
                }
            }).catch(function (err) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check2-circle"></i> ذخیرهٔ تغییرات';
                showFeedback('danger', 'خطای ارتباط با سرور.');
            });
        });
    }

    // ----- avatar form -----
    var avatarForm = document.getElementById('avatar-form');
    if (avatarForm) {
        avatarForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = document.getElementById('avatar-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال بارگذاری…';

            fetch('/panel/avatar', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                body: new FormData(avatarForm)
            }).then(function (res) {
                return res.json().then(function (j) {
                    return { status: res.status, json: j };
                });
            }).then(function (r) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-upload"></i> بارگذاری';
                if (r.status >= 200 && r.status < 300 && r.json.success) {
                    showFeedback('success', 'تصویر پروفایل با موفقیت بارگذاری شد.');
                    avatarForm.reset();
                    // Optionally reload the page to refresh the avatar
                    // (the file is served by a future /panel/avatar/file
                    // route; for now the letter avatar is shown until
                    // reload).
                    setTimeout(function () { window.location.reload(); }, 800);
                } else {
                    var e = (r.json && r.json.error) ? r.json.error
                           : ((r.json && r.json.errors) ? r.json.errors.join('<br>') : 'barname-error');
                    showFeedback('danger', escapeHtml(e));
                }
            }).catch(function () {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-upload"></i> بارگذاری';
                showFeedback('danger', 'خطای ارتباط با سرور.');
            });
        });
    }
})();
</script>
