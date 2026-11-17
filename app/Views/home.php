<?php
/** @var string $siteName */
/** @var string $siteDescription */
?>
<section class="hero">
    <h1><?= htmlspecialchars((string)$siteName, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="lead"><?= htmlspecialchars((string)$siteDescription, ENT_QUOTES, 'UTF-8') ?></p>
</section>

<section class="services-cards">
    <h2>خدمات</h2>
    <ul>
        <li><a href="/register">ثبت‌نام متقاضی</a></li>
        <li><a href="/tracking">پیگیری پرونده</a></li>
        <li><a href="/guide">راهنمای کاربر</a></li>
    </ul>
</section>

<section class="auth-cards">
    <p><a href="/login">ورود کاربر</a> · <a href="/admin/login">ورود مدیر</a></p>
</section>
