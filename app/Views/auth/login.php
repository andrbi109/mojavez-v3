<?php
/** @var bool $requireMobile */
/** @var string $csrfField */
$requireMobile ??= false;
$csrfField ??= $GLOBALS['csrfField'] ?? '';
?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <i class="bi bi-box-arrow-in-right display-5 text-brand"></i>
                    <h1 class="h4 mt-3 mb-1">ورود کاربر</h1>
                    <p class="text-muted small mb-0">وارد حساب کاربری خود شوید</p>
                </div>
                <form method="post" action="/login">
                    <?= $csrfField ?>
                    <div class="mb-3">
                        <label for="mobile" class="form-label">شماره موبایل</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-phone"></i></span>
                            <input type="tel" class="form-control" id="mobile" name="mobile"
                                   pattern="09\d{9}" placeholder="09123456789" required dir="ltr" style="text-align: right;">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">رمز عبور</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key"></i></span>
                            <input type="password" class="form-control" id="password" name="password"
                                   minlength="8" placeholder="••••••••" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-box-arrow-in-right"></i> ورود
                    </button>
                </form>
                <p class="text-center text-muted small mt-3 mb-0">
                    حساب کاربری ندارید؟ <a href="/signup" class="text-decoration-none">ثبت‌نام کنید</a>
                </p>
            </div>
        </div>
    </div>
</div>
