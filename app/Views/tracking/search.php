<?php
/** @var bool $requireMobile */
/** @var string $csrfField */
$csrfField ??= $GLOBALS['csrfField'] ?? '';
?>
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <i class="bi bi-search display-5 text-brand"></i>
                    <h1 class="h4 mt-3 mb-1">پیگیری پرونده</h1>
                    <p class="text-muted small mb-0">کد رهگیری را وارد کنید</p>
                </div>
                <form method="post" action="/tracking/search">
                    <?= $csrfField ?>
                    <div class="mb-3">
                        <label for="tracking_code" class="form-label">کد رهگیری</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-hash"></i></span>
                            <input type="text" class="form-control" id="tracking_code" name="tracking_code"
                                   pattern="HG-[A-Z0-9]{8}" placeholder="HG-XXXXXXXX" required
                                   style="text-align: right;">
                        </div>
                        <div class="form-text">قالب کد: HG به دنبال ۸ نویسهٔ حرف/رقم</div>
                    </div>
                    <?php if (!empty($requireMobile)): ?>
                    <div class="mb-3">
                        <label for="mobile" class="form-label">شماره موبایل</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-phone"></i></span>
                            <input type="tel" class="form-control" id="mobile" name="mobile"
                                   pattern="09\d{9}" placeholder="09123456789" required style="text-align: right;">
                        </div>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> جستجو
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
