<?php
/** @var bool $requireMobile */
$requireMobile ??= false;
?>
<h1>ورود کاربر</h1>
<form method="post" action="/login">
    <?= $GLOBALS['csrfField'] ?? '' ?>
    <label>موبایل: <input type="tel" name="mobile" pattern="09\d{9}" required></label><br>
    <label>رمز عبور: <input type="password" name="password" minlength="8" required></label><br>
    <button type="submit">ورود</button>
</form>
<p><a href="/signup">حساب کاربری ندارید؟ ثبت‌نام کنید.</a></p>
