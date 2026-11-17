<?php
?>
<h1>ثبت‌نام کاربر</h1>
<form method="post" action="/signup">
    <?= $GLOBALS['csrfField'] ?? '' ?>
    <label>موبایل: <input type="tel" name="mobile" pattern="09\d{9}" required></label><br>
    <label>رمز عبور (حداقل ۸ کاراکتر): <input type="password" name="password" minlength="8" required></label><br>
    <button type="submit">ثبت‌نام</button>
</form>
