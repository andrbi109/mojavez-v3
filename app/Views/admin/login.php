<?php
?>
<h1>ورود مدیر</h1>
<form method="post" action="/admin/login">
    <?= $GLOBALS['csrfField'] ?? '' ?>
    <label>نام کاربری: <input type="text" name="username" required></label><br>
    <label>رمز عبور: <input type="password" name="password" required></label><br>
    <button type="submit">ورود</button>
</form>
