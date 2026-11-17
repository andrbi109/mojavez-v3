<?php
/**
 * user/tracking.php — فرم پیگیری درخواست در پنل کاربر (Phase 9).
 *
 * این ویو فقط یک فرم ساده است که به /tracking/search پست می‌شود (مسیر موجود
 * از Phase 2). کاربر می‌تواند بدون خروج از پنل، درخواستش را پیگیری کند.
 */
/** @var string $baseUrl */
/** @var string $csrfField */
?>
<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card shadow-sm">
            <div class="card-header"><i class="bi bi-search"></i> پیگیری درخواست</div>
            <div class="card-body">
                <p class="text-muted small">
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
                <hr>
                <div class="small text-muted">
                    <i class="bi bi-info-circle"></i>
                    کد پیگیری را می‌توانید در صفحهٔ <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/requests">درخواست‌های من</a> نیز مشاهده کنید.
                </div>
            </div>
        </div>
    </div>
</div>
