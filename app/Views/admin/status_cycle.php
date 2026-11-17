<?php
/**
 * admin/status_cycle.php — visual reference for the 18-status workflow.
 *
 * Phase 22 (v1.9.4): rendered by AdminController::statusCycle().
 * This is documentation-only — no DB queries, no forms, no mutations.
 * Reads from config/statuses.php (the Single Source of Truth for the
 * 18 statuses) and renders them as Bootstrap cards in workflow order.
 *
 * Layout:
 *   1. The 14 shared statuses (Draft → View/Print + Rejected + Cancelled)
 *      as a flow of cards with arrows showing forward progression.
 *      Terminal statuses (Rejected, Cancelled, LicenseDelivery,
 *      ViewAndPrint) are visually marked.
 *   2. The 4 household-only statuses (service_type_id=2) in a separate
 *      card group, showing the alternate chain (15 → 25 → 35 → 36).
 *   3. A legend explaining the manual/auto flag and pre_issue flag.
 *
 * Each card shows: order number, Persian title, badge color, manual
 * flag, pre_issue flag, terminal flag.
 *
 * @var array<string,array<string,mixed>> $shared     14 shared statuses, sorted by order.
 * @var array<string,array<string,mixed>> $household  4 household-only statuses, sorted by order.
 * @var array<int,string>                 $terminal   Terminal status slugs.
 * @var array<int,string>                 $preIssue  Pre-issue status slugs.
 * @var string $csrfField
 * @var string $baseUrl
 */
/** @var array<string,array<string,mixed>> $shared */
/** @var array<string,array<string,mixed>> $household */
/** @var array<int,string> $terminal */
/** @var array<int,string> $preIssue */
/** @var string $csrfField */
/** @var string $baseUrl */

$shared    ??= [];
$household ??= [];
$terminal  ??= [];
$preIssue  ??= [];
$csrfField ??= '';
$baseUrl   ??= '/';

// Map a bootstrap colour name to a badge class. The 'color' field in
// config/statuses.php uses Bootstrap 5 colour names (secondary, info,
// primary, warning, success, dark, danger) which all map to
// `text-bg-<color>` badge classes (Bootstrap 5.2+).
$badgeClass = static function (string $color): string {
    $allowed = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];
    return in_array($color, $allowed, true) ? 'text-bg-' . $color : 'text-bg-secondary';
};

// Render a single status card. Helper kept local — no global functions
// (CODING_CONVENTIONS §2 forbids define() and global helpers).
$renderCard = static function (string $slug, array $meta) use ($terminal, $preIssue, $badgeClass): void {
    $order     = (int)($meta['order'] ?? 0);
    $color     = (string)($meta['color'] ?? 'secondary');
    $manual    = (bool)($meta['manual'] ?? true);
    $isTerm    = in_array($slug, $terminal, true);
    $isPreIss  = in_array($slug, $preIssue, true);
    $services  = $meta['services'] ?? [];
    $isHouse   = is_array($services) && in_array(2, $services, true);
    ?>
    <div class="card h-100 shadow-sm" style="min-width: 14rem;">
        <div class="card-header <?= htmlspecialchars($badgeClass($color), ENT_QUOTES) ?> d-flex align-items-center justify-content-between">
            <span class="badge <?= htmlspecialchars($badgeClass($color), ENT_QUOTES) ?> fs-6">
                <?= htmlspecialchars((string)$slug, ENT_QUOTES, 'UTF-8') ?>
            </span>
            <small class="opacity-75" dir="ltr">#<?= htmlspecialchars((string)$order, ENT_QUOTES, 'UTF-8') ?></small>
        </div>
        <div class="card-body p-3 small">
            <ul class="list-unstyled mb-0 lh-lg">
                <li>
                    <i class="bi bi-hash"></i>
                    ترتیب: <span dir="ltr"><?= htmlspecialchars((string)$order, ENT_QUOTES, 'UTF-8') ?></span>
                </li>
                <li>
                    <i class="bi bi-palette"></i>
                    رنگ: <span dir="ltr"><?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?></span>
                </li>
                <li>
                    <?php if ($manual): ?>
                        <i class="bi bi-hand-index"></i>
                        <span class="badge text-bg-light text-body border">دستی</span>
                    <?php else: ?>
                        <i class="bi bi-robot"></i>
                        <span class="badge text-bg-info">خودکار</span>
                    <?php endif; ?>
                </li>
                <li>
                    <?php if ($isPreIss): ?>
                        <i class="bi bi-hourglass-split"></i>
                        <span class="badge text-bg-warning">پیش از صدور</span>
                    <?php else: ?>
                        <i class="bi bi-patch-check"></i>
                        <span class="badge text-bg-success">پس از صدور</span>
                    <?php endif; ?>
                </li>
                <li>
                    <?php if ($isTerm): ?>
                        <i class="bi bi-flag-fill"></i>
                        <span class="badge text-bg-danger">پایانی</span>
                    <?php else: ?>
                        <i class="bi bi-arrow-repeat"></i>
                        <span class="badge text-bg-light text-muted border">غیرپایانی</span>
                    <?php endif; ?>
                </li>
                <?php if ($isHouse): ?>
                <li>
                    <i class="bi bi-house-door"></i>
                    <span class="badge text-bg-primary">مشاغل خانگی</span>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <?php
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-diagram-3"></i> چرخهٔ ۱۸ وضعیت گردش‌کار</h2>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/dashboard"
       class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-return-right"></i> بازگشت به داشبورد
    </a>
</div>

<p class="text-muted small mb-4">
    این صفحه یک مرجع مستندات برای چرخهٔ ۱۸ وضعیت گردش‌کار است.
    انتقال‌های دستی توسط مدیر/کارشناس، انتقال خودکار به جلو (AutoCancelService::autoProgress)
    و انتقال خودکار به انتها (cancelExpired) همگی از همین چرخه تبعیت می‌کنند.
    ترتیب نمایش بر اساس فیلد <code dir="ltr">order</code> در <code dir="ltr">config/statuses.php</code> است.
</p>

<h3 class="h6 mb-3"><i class="bi bi-collection"></i> ۱۴ وضعیت مشترک (همهٔ انواع خدمت)</h3>
<div class="d-flex flex-wrap align-items-stretch gap-2 mb-4">
    <?php
    $sharedSlugs = array_keys($shared);
    $i = 0;
    foreach ($shared as $slug => $meta):
        $renderCard($slug, $meta);
        $i++;
        // Arrow between cards (not after the last).
        if ($i < count($sharedSlugs)):
        ?>
        <div class="d-flex align-items-center text-muted" aria-hidden="true">
            <i class="bi bi-arrow-left fs-4"></i>
        </div>
        <?php
        endif;
    endforeach;
    ?>
</div>

<h3 class="h6 mb-3"><i class="bi bi-house-door"></i> ۴ وضعیت ویژهٔ مشاغل خانگی (service_type_id=2)</h3>
<p class="text-muted small">
    این وضعیت‌ها فقط برای متقاضیان نوع خدمت ۲ (مشاغل خانگی) به‌کار می‌روند و از
    سامانهٔ <code dir="ltr">smkh.mcls.gov.ir</code> منبع می‌گیرند. در زنجیرهٔ مشترک
    بین «تأیید نهایی» و «صدور مجوز» جای می‌گیرند.
</p>
<div class="d-flex flex-wrap align-items-stretch gap-2 mb-4">
    <?php
    $householdSlugs = array_keys($household);
    $i = 0;
    foreach ($household as $slug => $meta):
        $renderCard($slug, $meta);
        $i++;
        if ($i < count($householdSlugs)):
        ?>
        <div class="d-flex align-items-center text-muted" aria-hidden="true">
            <i class="bi bi-arrow-left fs-4"></i>
        </div>
        <?php
        endif;
    endforeach;
    ?>
</div>

<h3 class="h6 mb-3"><i class="bi bi-info-circle"></i> راهنمای نشان‌ها</h3>
<div class="card">
    <div class="card-body small">
        <ul class="mb-0 lh-lg">
            <li>
                <span class="badge text-bg-light text-body border">دستی</span>
                وضعیت توسط مدیر یا کارشناس به‌صورت دستی قابل تنظیم است
                (<code dir="ltr">AdminController::changeStatus</code>).
            </li>
            <li>
                <span class="badge text-bg-info">خودکار</span>
                وضعیت توسط سامانه به‌صورت خودکار تنظیم می‌شود
                (مانند «مشاهده و چاپ» که پس از تحویل، توسط کنش کاربر فعال می‌شود).
            </li>
            <li>
                <span class="badge text-bg-warning">پیش از صدور</span>
                وضعیت پیش از صدور مجوز — مشمول لغو خودکار پس از ۱۴ روز
                (<code dir="ltr">AutoCancelService::cancelExpired</code>) و
                پیشرفت خودکار پس از ۷ روز
                (<code dir="ltr">AutoCancelService::autoProgress</code>).
            </li>
            <li>
                <span class="badge text-bg-success">پس از صدور</span>
                وضعیت پس از صدور مجوز — دیگر مشمول لغو یا پیشرفت خودکار نیست.
            </li>
            <li>
                <span class="badge text-bg-danger">پایانی</span>
                وضعیت پایانی — پرونده بسته می‌شود و انتقال به وضعیت دیگر
                (جز «لغو» و «رد درخواست») مجاز نیست.
            </li>
            <li>
                <span class="badge text-bg-primary">مشاغل خانگی</span>
                وضعیت ویژهٔ متقاضیان نوع خدمت ۲ — از زنجیرهٔ مشترک جدا است
                اما با همان منطق نقش‌محور در <code dir="ltr">config/workflow.php</code>
                انتقال می‌یابد.
            </li>
        </ul>
    </div>
</div>
