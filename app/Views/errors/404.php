<?php
/**
 * errors/404.php — content-only 404 page (Phase 10).
 *
 * Rendered by public/index.php when RouteNotFoundException is thrown. The
 * view is wrapped by layout.php (the public site layout with navbar + footer).
 *
 * Expected $data (extracted to vars before require):
 *   $siteName        string
 *   $siteDescription string
 *   $baseUrl         string
 *
 * The view is intentionally a content-only fragment (no <html>/<head>/<body>)
 * because layout.php supplies those. This is the same convention used by
 * home.php, guide.php, about.php, tracking/search.php, etc.
 *
 * If rendering fails for any reason, the front controller catches the
 * Throwable and falls back to a bare "<h1>۴۰۴</h1>" string so the 404 page
 * itself never 500s.
 */
/** @var string $siteName */
/** @var string $siteDescription */
/** @var string $baseUrl */

$siteName        = $siteName        ?? 'سامانهٔ مجوز';
$siteDescription = $siteDescription ?? '';
$baseUrl         = $baseUrl         ?? '/';
?>

<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5 text-center">
        <div class="card shadow-sm border-0">
            <div class="card-body p-5">
                <div class="display-1 text-brand mb-2">۴۰۴</div>
                <h1 class="h4 mb-3">صفحهٔ مورد نظر یافت نشد</h1>
                <p class="text-muted mb-4">
                    آدرسی که وارد کردید در سامانه وجود ندارد، یا از سیستم حذف شده است.
                    لطفاً نشانی را بررسی کنید یا از منوی اصلی استفاده کنید.
                </p>
                <div class="d-flex justify-content-center gap-2 flex-wrap">
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>" class="btn btn-primary">
                        <i class="bi bi-house"></i> بازگشت به خانه
                    </a>
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>tracking" class="btn btn-outline-primary">
                        <i class="bi bi-search"></i> پیگیری پرونده
                    </a>
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>guide" class="btn btn-outline-secondary">
                        <i class="bi bi-question-circle"></i> راهنما
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
