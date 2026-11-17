<?php
/** @var string $guideText */
?>
<h1>راهنمای کاربر</h1>
<div class="guide-content">
    <?php if (!empty($guideText)): ?>
        <?= nl2br(htmlspecialchars((string)$guideText, ENT_QUOTES, 'UTF-8')) ?>
    <?php else: ?>
        <p>راهنمای کامل در حال آماده‌سازی است. برای شروع:</p>
        <ol>
            <li>از صفحهٔ <a href="/register">ثبت‌نام</a> یک پروندهٔ جدید بسازید.</li>
            <li>کد رهگیری <code>HG-XXXXXXXX</code> را ذخیره کنید.</li>
            <li>از صفحهٔ <a href="/tracking">پیگیری</a> وضعیت پرونده را دنبال کنید.</li>
        </ol>
    <?php endif; ?>
</div>
