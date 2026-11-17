<?php
/** @var string $siteName */
/** @var string $siteDescription */
?>
<section class="hero text-center py-5 mb-4">
    <div class="py-4">
        <i class="bi bi-patch-check-fill display-1 text-brand mb-3"></i>
        <h1 class="display-5 fw-bold mb-3"><?= htmlspecialchars((string)$siteName, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="lead text-muted col-lg-7 mx-auto mb-4"><?= htmlspecialchars((string)$siteDescription, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="d-flex justify-content-center gap-2 flex-wrap">
            <a href="<?= htmlspecialchars($baseUrl ?? '/', ENT_QUOTES) ?>register" class="btn btn-primary btn-lg">
                <i class="bi bi-pencil-square"></i> ثبت‌نام متقاضی
            </a>
            <a href="<?= htmlspecialchars($baseUrl ?? '/', ENT_QUOTES) ?>tracking" class="btn btn-outline-primary btn-lg">
                <i class="bi bi-search"></i> پیگیری پرونده
            </a>
        </div>
    </div>
</section>

<section class="services-cards mb-5">
    <h2 class="text-center mb-4">خدمات</h2>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card h-100 text-center">
                <div class="card-body">
                    <i class="bi bi-pencil-square display-6 text-brand mb-3"></i>
                    <h3 class="h5 card-title">ثبت‌نام متقاضی</h3>
                    <p class="card-text text-muted">فرم ویزارد ۴ مرحله‌ای — هویت، محل کسب، اطلاعات شغل و مدارک.</p>
                    <a href="<?= htmlspecialchars($baseUrl ?? '/', ENT_QUOTES) ?>register" class="btn btn-outline-primary btn-sm">شروع ثبت‌نام</a>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 text-center">
                <div class="card-body">
                    <i class="bi bi-search display-6 text-brand mb-3"></i>
                    <h3 class="h5 card-title">پیگیری پرونده</h3>
                    <p class="card-text text-muted">با کد رهگیری، وضعیت ۱۸ مرحله‌ای درخواست خود را ببینید.</p>
                    <a href="/tracking" class="btn btn-outline-primary btn-sm">پیگیری</a>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 text-center">
                <div class="card-body">
                    <i class="bi bi-question-circle display-6 text-brand mb-3"></i>
                    <h3 class="h5 card-title">راهنمای کاربر</h3>
                    <p class="card-text text-muted">راهنمای کامل مراحل ثبت‌نام، پیگیری و دریافت مجوز.</p>
                    <a href="/guide" class="btn btn-outline-primary btn-sm">مشاهدهٔ راهنما</a>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="auth-cards text-center py-4 border-top">
    <p class="text-muted mb-0">
        <a href="/login" class="text-decoration-none">ورود کاربر</a>
        <span class="mx-2">·</span>
        <a href="/admin/login" class="text-decoration-none">ورود مدیر</a>
    </p>
</section>
