<?php
/** @var string $csrfField */
$csrfField ??= $GLOBALS['csrfField'] ?? '';
?>
<!doctype html>
<html lang="fa" dir="rtl" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="theme-color" content="#0f766e">
    <title>ورود مدیر — پنل مدیریت</title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/vendor/vazirmatn/css/vazirmatn.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="moj-admin-body" style="align-items: center; justify-content: center;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5 col-lg-4">
                <div class="card shadow">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <i class="bi bi-shield-lock display-5 text-brand"></i>
                            <h1 class="h4 mt-3 mb-1">ورود مدیر</h1>
                            <p class="text-muted small mb-0">پنل مدیریت سامانهٔ مجوز</p>
                        </div>
                        <form method="post" action="/admin/login">
                            <?= $csrfField ?>
                            <div class="mb-3">
                                <label for="username" class="form-label">نام کاربری</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                    <input type="text" class="form-control" id="username" name="username"
                                           placeholder="admin" required autocomplete="username">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">رمز عبور</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-key"></i></span>
                                    <input type="password" class="form-control" id="password" name="password"
                                           placeholder="••••••••" required autocomplete="current-password">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-box-arrow-in-right"></i> ورود
                            </button>
                        </form>
                        <p class="text-center text-muted small mt-3 mb-0">
                            <a href="/" class="text-decoration-none">بازگشت به صفحهٔ اصلی</a>
                        </p>
                    </div>
                </div>
                <p class="text-center text-muted small mt-3">
                    نسخهٔ <?= htmlspecialchars(\App\Shared\Support\Environment::version(), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
        </div>
    </div>
</div>
<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js" defer></script>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
