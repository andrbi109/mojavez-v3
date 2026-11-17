<?php
/** @var string $guideText */
?>
<div class="row">
    <div class="col-lg-8">
        <h1 class="h3 mb-4">
            <i class="bi bi-question-circle text-brand"></i> راهنمای کاربر
        </h1>
        <div class="card">
            <div class="card-body">
            <?php if (!empty($guideText)): ?>
                <div class="guide-content"><?= nl2br(htmlspecialchars((string)$guideText, ENT_QUOTES, 'UTF-8')) ?></div>
            <?php else: ?>
                <p class="lead text-muted">راهنمای کامل در حال آماده‌سازی است. برای شروع:</p>
                <ol class="list-group list-group-numbered">
                    <li class="list-group-item">از صفحهٔ <a href="/signup" class="text-decoration-none">ثبت‌نام</a> یک پروندهٔ جدید بسازید.</li>
                    <li class="list-group-item">کد رهگیری <span class="moj-tracking-code">HG-XXXXXXXX</span> را ذخیره کنید.</li>
                    <li class="list-group-item">از صفحهٔ <a href="/tracking" class="text-decoration-none">پیگیری</a> وضعیت پرونده را دنبال کنید.</li>
                    <li class="list-group-item">پس از صدور مجوز، آن را به‌صورت آنلاین دریافت و چاپ کنید.</li>
                </ol>
            <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-info-circle"></i> وضعیت‌های پرونده
            </div>
            <div class="card-body">
                <p class="text-muted small">هر پرونده از ۱۸ وضعیت ممکن عبور می‌کند. مهم‌ترین‌ها:</p>
                <ul class="list-unstyled mb-0 small">
                    <li class="mb-1"><span class="badge text-bg-secondary">پیش‌نویس</span> شروع ثبت‌نام</li>
                    <li class="mb-1"><span class="badge text-bg-primary">احراز نشانی</span> بررسی نشانی</li>
                    <li class="mb-1"><span class="badge text-bg-info">بررسی کارشناسی</span> بررسی رشته</li>
                    <li class="mb-1"><span class="badge text-bg-success">صدور مجوز</span> صدور نهایی</li>
                    <li class="mb-1"><span class="badge text-bg-danger">رد درخواست</span> پایان مسیر</li>
                </ul>
            </div>
        </div>
    </div>
</div>
