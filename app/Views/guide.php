<?php
/**
 * guide.php — user guide (public-facing).
 *
 * Phase 41 (v2.0.7) — the 18-status cycle diagram that used to live at
 * /admin/status-cycle (a standalone admin page) was moved here as a new
 * section at the bottom ("چرخه وضعیت‌ها"). The 18 statuses are listed in
 * their workflow order with Bootstrap badges colored per status type.
 *
 * Phase 43 (v2.0.9) — adds:
 *   - $siteName: shown in the intro paragraph under the heading.
 *   - $ownerUsername: shown as a contact handle in the sidebar card.
 *   - $socialLinks: rendered in the sidebar card under the contact.
 *
 * Expected $data:
 *   $guideText     string — guide body from the settings table (optional).
 *   $siteName      string — site_name setting (with a sensible default).
 *   $ownerName     string — owner_name setting (used in the contact card).
 *   $ownerUsername string — owner_username setting (used as a contact handle).
 *   $socialLinks   array  — social-eitaa/rubika/bale link map.
 */
/** @var string $guideText */
/** @var string $siteName */
/** @var string $ownerName */
/** @var string $ownerUsername */
/** @var array<string,array{label:string,handle:string,url:string}> $socialLinks */
/** @var string $baseUrl */
/** @var string $csrfField */

$guideText ??= '';
$siteName ??= 'سامانهٔ مجوزهای صنایع دستی';
$ownerName ??= '';
$ownerUsername ??= '';
$socialLinks ??= [];

// Filter out social entries with no URL so we don't render dead anchors.
$activeSocial = [];
foreach ($socialLinks as $k => $sl) {
    if (!is_array($sl)) {
        continue;
    }
    if ((string)($sl['url'] ?? '') !== '') {
        $activeSocial[$k] = $sl;
    }
}

// The 18 applicant workflow statuses, in their workflow order (matching
// ApplicantStatus::order()). Two columns: shared statuses (all service
// types) and household-business-only statuses (service_type_id = 2).
// 'order' is the ApplicantStatus enum ordering (1..12 + 98/99 terminals
// for shared, 15/25/35/36 for household).
//
// Phase 52 (v2.1.8) — the badge colour is now resolved from
// config/statuses.php via StatusBadge::color() so it stays in sync with
// the 18-status cycle definitions. The 'badge' key is removed from the
// arrays below; the colour is computed on the fly from the status title
// (which is the enum value, the same key used by config/statuses.php).
use App\Shared\Support\StatusBadge;

$cycleShared = [
    ['order' => 1,  'title' => 'پیش‌نویس',                'icon' => 'bi-pencil'],
    ['order' => 2,  'title' => 'تکمیل اطلاعات',           'icon' => 'bi-clipboard-check'],
    ['order' => 3,  'title' => 'احراز نشانی',             'icon' => 'bi-geo-alt'],
    ['order' => 4,  'title' => 'بررسی کارشناسی رشته',      'icon' => 'bi-search'],
    ['order' => 5,  'title' => 'ثبت درخواست صدور',         'icon' => 'bi-send', 'note' => 'آغاز شمارش ۱۴ روزه'],
    ['order' => 6,  'title' => 'بارگذاری مدارک به سازمان', 'icon' => 'bi-upload'],
    ['order' => 7,  'title' => 'درخواست اصلاح مدارک',       'icon' => 'bi-exclamation-triangle'],
    ['order' => 8,  'title' => 'تأیید اولیه',              'icon' => 'bi-check2-square'],
    ['order' => 9,  'title' => 'تأیید نهایی',              'icon' => 'bi-check2-all'],
    ['order' => 10, 'title' => 'صدور مجوز',               'icon' => 'bi-patch-check'],
    ['order' => 11, 'title' => 'تحویل مجوز',               'icon' => 'bi-box-seam', 'terminal' => true],
    ['order' => 12, 'title' => 'مشاهده و چاپ',             'icon' => 'bi-printer'],
    ['order' => 98, 'title' => 'رد درخواست',               'icon' => 'bi-x-octagon', 'terminal' => true],
    ['order' => 99, 'title' => 'لغو',                       'icon' => 'bi-ban',        'terminal' => true],
];
$cycleHousehold = [
    ['order' => 15, 'title' => 'تطبیق آدرس با ثبت احوال',          'icon' => 'bi-house-check'],
    ['order' => 25, 'title' => 'دریافت تصویر مجوز',                'icon' => 'bi-image'],
    ['order' => 35, 'title' => 'تأیید اولیه در سامانه مشاغل خانگی', 'icon' => 'bi-check2-square'],
    ['order' => 36, 'title' => 'تأیید نهایی دریافت کد رهگیری',     'icon' => 'bi-check2-all', 'terminal' => true],
];
// Compute the Bootstrap badge colour from the config map for each row.
foreach ($cycleShared as $k => $s) {
    $cycleShared[$k]['badge'] = StatusBadge::color((string)$s['title']);
}
foreach ($cycleHousehold as $k => $s) {
    $cycleHousehold[$k]['badge'] = StatusBadge::color((string)$s['title']);
}
?>
<div class="row">
    <div class="col-lg-8">
        <h1 class="h3 mb-2">
            <i class="bi bi-question-circle text-brand"></i> راهنمای کاربر
        </h1>
        <p class="text-muted small mb-4">
            <i class="bi bi-building"></i>
            راهنمای کامل سامانهٔ <?= htmlspecialchars((string)$siteName, ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="card">
            <div class="card-body">
            <?php if (!empty($guideText)): ?>
                <div class="guide-content"><?= nl2br(htmlspecialchars((string)$guideText, ENT_QUOTES, 'UTF-8')) ?></div>
            <?php else: ?>
                <p class="lead text-muted">راهنمای کامل در حال آماده‌سازی است. برای شروع:</p>
                <ol class="list-group list-group-numbered">
                    <li class="list-group-item">از صفحهٔ <a href="<?= htmlspecialchars($baseUrl ?? '/', ENT_QUOTES) ?>signup" class="text-decoration-none">ثبت‌نام</a> یک پروندهٔ جدید بسازید.</li>
                    <li class="list-group-item">کد رهگیری <span class="moj-tracking-code">HG-XXXXXXXX</span> را ذخیره کنید.</li>
                    <li class="list-group-item">از صفحهٔ <a href="<?= htmlspecialchars($baseUrl ?? '/', ENT_QUOTES) ?>tracking" class="text-decoration-none">پیگیری</a> وضعیت پرونده را دنبال کنید.</li>
                    <li class="list-group-item">پس از صدور مجوز، آن را به‌صورت آنلاین دریافت و چاپ کنید.</li>
                </ol>
            <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4 moj-content-offset-guide">
        <div class="card mb-3">
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
        <?php if ($ownerName !== '' || $ownerUsername !== '' || !empty($activeSocial)): ?>
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-telephone"></i> تماس با مدیر سامانه
            </div>
            <div class="card-body">
                <?php if ($ownerName !== ''): ?>
                    <p class="mb-1"><i class="bi bi-person-badge"></i>
                        <?= htmlspecialchars((string)$ownerName, ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
                <?php if ($ownerUsername !== ''): ?>
                    <p class="text-muted small mb-2" dir="ltr">
                        <i class="bi bi-at"></i> @<?= htmlspecialchars((string)$ownerUsername, ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($activeSocial)): ?>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <?php foreach ($activeSocial as $k => $sl): ?>
                        <a href="<?= htmlspecialchars((string)$sl['url'], ENT_QUOTES) ?>"
                           target="_blank" rel="noopener noreferrer"
                           class="btn btn-sm btn-outline-secondary"
                           title="<?= htmlspecialchars((string)$sl['label'], ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-chat-dots"></i>
                            <?= htmlspecialchars((string)$sl['label'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== چرخه وضعیت‌ها (۱۸ وضعیت) ===== -->
<!-- Phase 41 (v2.0.7) — moved from /admin/status-cycle (standalone admin page,
     now removed) to the bottom of the public guide page. The 18 statuses
     mirror App\Modules\Core\Domain\Enums\ApplicantStatus. -->
<section class="mt-5" id="status-cycle">
    <h2 class="h4 mb-3">
        <i class="bi bi-diagram-3 text-brand"></i> چرخه وضعیت‌ها
    </h2>
    <p class="text-muted small mb-4">
        هر پرونده در سامانه از میان ۱۸ وضعیت ممکن عبور می‌کند. چهارده وضعیت
        مشترک برای همهٔ انواع خدمات است و چهار وضعیت اضافی مختص مشاغل خانگی
        (نوع خدمت ۲) است که از سامانهٔ smkh.mcls.gov.ir پیگیری می‌شوند.
        وضعیت‌های پایانی با تیک <i class="bi bi-flag-fill text-success"></i>
        نشان داده شده‌اند — پس از رسیدن به آن‌ها، پرونده بسته می‌شود.
    </p>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-list-ol"></i> وضعیت‌های مشترک (۱۴ وضعیت)
                </div>
                <div class="card-body">
                    <ol class="list-group list-group-numbered mb-0">
                        <?php foreach ($cycleShared as $s): ?>
                            <li class="list-group-item d-flex align-items-center gap-2">
                                <i class="bi <?= htmlspecialchars($s['icon'], ENT_QUOTES) ?> text-<?= htmlspecialchars($s['badge'], ENT_QUOTES) ?>"></i>
                                <span class="badge text-bg-<?= htmlspecialchars($s['badge'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if (!empty($s['note'])): ?>
                                    <span class="text-muted small">— <?= htmlspecialchars($s['note'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                                <?php if (!empty($s['terminal'])): ?>
                                    <i class="bi bi-flag-fill text-success ms-auto" title="وضعیت پایانی"></i>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-house-heart"></i> مشاغل خانگی (۴ وضعیت اضافی)
                </div>
                <div class="card-body">
                    <ol class="list-group list-group-numbered mb-0">
                        <?php foreach ($cycleHousehold as $s): ?>
                            <li class="list-group-item d-flex align-items-center gap-2">
                                <i class="bi <?= htmlspecialchars($s['icon'], ENT_QUOTES) ?> text-<?= htmlspecialchars($s['badge'], ENT_QUOTES) ?>"></i>
                                <span class="badge text-bg-<?= htmlspecialchars($s['badge'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if (!empty($s['terminal'])): ?>
                                    <i class="bi bi-flag-fill text-success ms-auto" title="وضعیت پایانی"></i>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info mt-4 mb-0 small">
        <i class="bi bi-info-circle"></i>
        در صورت عبور از مرحلهٔ «ثبت درخواست صدور» (مرحلهٔ ۵ مشترک) و سپری شدن
        بیش از ۱۴ روز بدون صدور مجوز، پرونده به‌صورت خودکار به وضعیت
        <span class="badge text-bg-danger">لغو</span> منتقل می‌شود. پرونده‌هایی
        که ثبت‌نامشان بیش از ۱۲ روز طول کشیده با اولویت بالاتر رسیدگی می‌شوند.
    </div>
</section>
