<?php
/** @var bool $requireMobile */
?>
<h1>پیگیری پرونده</h1>
<form method="post" action="/tracking/search">
    <?= $GLOBALS['csrfField'] ?? '' ?>
    <label>کد رهگیری: <input type="text" name="tracking_code" pattern="HG-[A-Z0-9]{8}" required></label><br>
    <?php if ($requireMobile): ?>
        <label>موبایل: <input type="tel" name="mobile" pattern="09\d{9}" required></label><br>
    <?php endif; ?>
    <button type="submit">جستجو</button>
</form>
