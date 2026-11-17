<?php
/** @var string $aboutText */
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <h1 class="h3 mb-4">
            <i class="bi bi-info-circle text-brand"></i> دربارهٔ ما
        </h1>
        <div class="card">
            <div class="card-body">
            <?php if (!empty($aboutText)): ?>
                <div class="about-content"><?= nl2br(htmlspecialchars((string)$aboutText, ENT_QUOTES, 'UTF-8')) ?></div>
            <?php else: ?>
                <p class="lead">این سامانه برای صدور مجوز صنایع دستی و مشاغل خانگی طراحی شده است.</p>
                <p>هدف ما تسهیل فرایند صدور مجوز برای متقاضیان و ارائهٔ یک سامانهٔ یکپارچه، امن و شفاف است که از ثبت‌نام تا دریافت مجوز نهایی را پوشش می‌دهد.</p>
                <p>ویژگی‌های کلیدی سامانه:</p>
                <ul>
                    <li>ثبت‌نام آنلاین ۴ مرحله‌ای ویزارد</li>
                    <li>پیگیری وضعیت پرونده با کد رهگیری یکتا</li>
                    <li>اتصال خودکار به درگاه ملی مجوزها</li>
                    <li>صدور و چاپ آنلاین مجوز</li>
                    <li>احراز هویت امن و محافظت از داده‌ها</li>
                </ul>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>
