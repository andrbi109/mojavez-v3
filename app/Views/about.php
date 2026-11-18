<?php
/**
 * about.php — about page (public).
 *
 * Phase 43 (v2.0.9) — ports ALL v2 settings used by v2's about.php:
 *   - $aboutTitle: page heading (was hardcoded "دربارهٔ ما").
 *   - $aboutContent: main body text (key `about_content`, NOT `about_text`
 *     which was a v3 typo from Phase 2; HomeController::about() was fixed
 *     in the same phase to read the correct key).
 *   - $ownerName + $ownerUsername + $ownerImage: shown in a dedicated
 *     "مدیر سامانه" card to the right of the about content.
 *   - $socialLinks: rendered as a social-bar below the owner card.
 *
 * All variables are optional with sensible defaults so the page never
 * white-screens if the admin hasn't filled them in yet.
 */
/** @var string $siteName */
/** @var string $aboutTitle */
/** @var string $aboutContent */
/** @var string $ownerName */
/** @var string $ownerUsername */
/** @var string $ownerImage */
/** @var array<string,array{label:string,handle:string,url:string}> $socialLinks */

$siteName ??= 'سامانهٔ مجوز';
$aboutTitle ??= 'دربارهٔ ما';
$aboutContent ??= '';
$ownerName ??= '';
$ownerUsername ??= '';
$ownerImage ??= '';
$socialLinks ??= [];

$activeSocial = [];
foreach ($socialLinks as $k => $sl) {
    if (!is_array($sl)) {
        continue;
    }
    if ((string)($sl['url'] ?? '') !== '') {
        $activeSocial[$k] = $sl;
    }
}

$ownerImageAbs  = $ownerImage !== '' ? (is_file(ROOT_PATH . '/' . $ownerImage) ? $ownerImage : '') : '';
$showOwnerCard  = $ownerName !== '' || $ownerUsername !== '' || $ownerImageAbs !== '';
?>
<div class="row">
    <div class="<?= $showOwnerCard ? 'col-lg-8' : 'col-lg-8 offset-lg-2' ?>">
        <h1 class="h3 mb-4">
            <i class="bi bi-info-circle text-brand"></i>
            <?= htmlspecialchars((string)$aboutTitle, ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <div class="card">
            <div class="card-body">
            <?php if ($aboutContent !== ''): ?>
                <div class="about-content"><?= nl2br(htmlspecialchars((string)$aboutContent, ENT_QUOTES, 'UTF-8')) ?></div>
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

    <?php if ($showOwnerCard): ?>
    <div class="col-lg-4 moj-content-offset-about">
        <div class="card text-center">
            <div class="card-header">
                <i class="bi bi-person-badge"></i> مدیر سامانه
            </div>
            <div class="card-body">
                <?php if ($ownerImageAbs !== ''): ?>
                    <img src="<?= htmlspecialchars(($baseUrl ?? '/') . $ownerImageAbs, ENT_QUOTES) ?>"
                         alt="تصویر مدیر"
                         class="rounded-circle mb-3"
                         style="width: 120px; height: 120px; object-fit: cover;">
                <?php else: ?>
                    <i class="bi bi-person-circle display-1 text-muted mb-3 d-block"></i>
                <?php endif; ?>
                <?php if ($ownerName !== ''): ?>
                    <h2 class="h5 mb-1"><?= htmlspecialchars((string)$ownerName, ENT_QUOTES, 'UTF-8') ?></h2>
                <?php endif; ?>
                <?php if ($ownerUsername !== ''): ?>
                    <p class="text-muted small mb-2" dir="ltr">@<?= htmlspecialchars((string)$ownerUsername, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if (!empty($activeSocial)): ?>
                <div class="d-flex flex-wrap justify-content-center gap-2 mt-3">
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
    </div>
    <?php endif; ?>
</div>
