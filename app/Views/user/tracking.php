<?php
/**
 * user/tracking.php — فرم پیگیری درخواست در پنل کاربر (Phase 9).
 *
 * Phase 44b — layout rebuilt so the form, helper list, and quick-action
 * footer each occupy their own Bootstrap card with consistent vertical
 * spacing (`mb-4`). Previously the form, hint, and footer links were
 * cramped together in a single card and the hint text could visually
 * collide with the bell icon / page header on small screens.
 * The new layout:
 *   - Card #1: tracking-code form (the main entry).
 *   - Card #2: helper list (where to find your tracking code).
 *   - Card #3 (footer-like): quick links to requests / new registration,
 *     kept inside the content flow so it never overlaps the panel topbar
 *     or any sibling section.
 *
 * این ویو فقط یک فرم ساده است که به /tracking/search پست می‌شود (مسیر موجود
 * از Phase 2). کاربر می‌تواند بدون خروج از پنل، درخواستش را پیگیری کند.
 */
/** @var string $baseUrl */
/** @var string $csrfField */
?>
<div class="row justify-content-center g-3">
    <div class="col-12 col-md-8 col-lg-7">

        <!-- Card 1: tracking form -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-search"></i>
                <span>پیگیری درخواست</span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    کد پیگیری که هنگام ثبت درخواست دریافت کرده‌اید را وارد کنید.
                </p>
                <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>tracking/search">
                    <?= $csrfField ?>
                    <div class="mb-3">
                        <label for="tracking_code" class="form-label">کد پیگیری</label>
                        <input type="text" class="form-control form-control-lg" id="tracking_code"
                               name="tracking_code" placeholder="مثال: MV-1403-12345" required
                               inputmode="text" autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> پیگیری
                    </button>
                </form>
            </div>
        </div>

        <!-- Card 2: helper hint -->
        <div class="card shadow-sm mb-4">
            <div class="card-body py-3">
                <div class="small text-muted mb-2">
                    <i class="bi bi-info-circle"></i>
                    کد پیگیری را می‌توانید در صفحهٔ
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/requests">درخواست‌های من</a>
                    نیز مشاهده کنید.
                </div>
                <ul class="list-unstyled mb-0 small text-muted">
                    <li class="mb-1"><i class="bi bi-chevron-left"></i> کد پیگیری هنگام ثبت درخواست به شما نمایش داده شده است.</li>
                    <li class="mb-1"><i class="bi bi-chevron-left"></i> کد پیگیری از طریق پیامک نیز برای شما ارسال می‌شود.</li>
                    <li><i class="bi bi-chevron-left"></i> در صورت گم شدن کد پیگیری، با پشتیبانی تماس بگیرید.</li>
                </ul>
            </div>
        </div>

        <!-- Card 3: quick actions (footer-like, but inside content flow) -->
        <div class="card shadow-sm">
            <div class="card-body py-3">
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/requests"
                       class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-folder2-open"></i> درخواست‌های من
                    </a>
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register"
                       class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg"></i> ثبت درخواست جدید
                    </a>
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-house"></i> بازگشت به پیشخوان
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>
