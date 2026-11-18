<?php
/**
 * admin/gateway_panel.php — main gateway dashboard.
 *
 * Stats cards (total cached licenses, total searches, total matches,
 * total saved), a quick-search form (national_code / tracking_code /
 * license_number), recent-batches table, recent-matches table, and
 * 4 link tiles to the other gateway pages.
 *
 * @var array<string,mixed> $stats
 * @var array<int,array<string,mixed>> $recentBatches
 * @var array<int,array<string,mixed>> $recentMatches
 * @var int $totalSearches
 * @var int $totalMatches
 * @var string $csrfField
 * @var string $baseUrl
 */

$totalCached = (int)($stats['total'] ?? 0);
$totalSaved = (int)($stats['saved'] ?? 0);
$lastSeen = (string)($stats['last_seen_at'] ?? '');
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-cloud"></i> داشبورد درگاه ملی</h2>
    <span class="badge text-bg-secondary">ماژول NationalGateway</span>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/search" class="btn btn-sm btn-outline-primary ms-auto">
        <i class="bi bi-search"></i> جستجوی پیشرفته
    </a>
</div>

<div class="alert alert-info small py-2 mb-3">
    <i class="bi bi-info-circle"></i>
    این داشبورد آمار و فعالیت‌های اخیر درگاه ملی مجوزها را نشان می‌دهد. داده‌ها از
    کش محلی (جدول <code>national_licenses</code>) خوانده می‌شوند. برای جستجوی زنده
    از درگاه بالادستی، از صفحهٔ <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/search" class="alert-link">جستجوی پیشرفته</a>
    استفاده کنید.
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-database display-6 text-brand"></i>
                <div class="h2 mt-2 mb-0"><?= htmlspecialchars((string)$totalCached, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-muted small">مجوزهای کش‌شده</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-search display-6 text-info"></i>
                <div class="h2 mt-2 mb-0"><?= htmlspecialchars((string)$totalSearches, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-muted small">جستجوهای دسته‌جمعی</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-link-45deg display-6 text-success"></i>
                <div class="h2 mt-2 mb-0"><?= htmlspecialchars((string)$totalMatches, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-muted small">تطبیق‌های گولدن‌لینک</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-bookmark-star display-6 text-warning"></i>
                <div class="h2 mt-2 mb-0"><?= htmlspecialchars((string)$totalSaved, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-muted small">مجوزهای ذخیره‌شده</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Quick search form -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-search"></i> جستجوی سریع
            </div>
            <div class="card-body">
                <form method="get" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/search">
                    <div class="mb-2">
                        <label class="form-label small">کد ملی</label>
                        <input class="form-control form-control-sm" type="text" name="national_code" dir="ltr" placeholder="مثلاً ۰۰۱۲۳۴۵۶۷۸">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">شماره مجوز</label>
                        <input class="form-control form-control-sm" type="text" name="license_number" dir="ltr" placeholder="مثلاً ۱۲۳۴۵">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">کد رهگیری</label>
                        <input class="form-control form-control-sm" type="text" name="tracking_code" dir="ltr" placeholder="کد رهگیری درگاه">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-search"></i> جستجو
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Link tiles -->
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-signpost-split"></i> صفحات درگاه ملی
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-6">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/search" class="btn btn-outline-primary btn-sm w-100 text-start">
                            <i class="bi bi-search"></i> جستجوی پیشرفته
                            <div class="small text-muted">جستجوی چندفیلدی در کش محلی</div>
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/saved" class="btn btn-outline-primary btn-sm w-100 text-start">
                            <i class="bi bi-bookmark"></i> مجوزهای ذخیره‌شده
                            <div class="small text-muted">فهرست + خروجی CSV</div>
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/filters" class="btn btn-outline-primary btn-sm w-100 text-start">
                            <i class="bi bi-funnel"></i> فیلترهای ذخیره‌شده
                            <div class="small text-muted">مدیریت فیلترهای آماده</div>
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/batch" class="btn btn-outline-primary btn-sm w-100 text-start">
                            <i class="bi bi-files"></i> جستجوی دسته‌جمعی
                            <div class="small text-muted">آپلود CSV کدهای مجوز</div>
                        </a>
                    </div>
                </div>
                <hr class="my-3">
                <div class="small text-muted">
                    آخرین به‌روزرسانی کش:
                    <?php if ($lastSeen !== ''): ?>
                        <span dir="ltr"><?= htmlspecialchars($lastSeen, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php else: ?>
                        <span>—</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent batches -->
<div class="card mb-3">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-files"></i> جستجوهای دسته‌جمعی اخیر
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/batch" class="btn btn-sm btn-outline-secondary ms-auto">
            <i class="bi bi-arrow-left-short"></i> همه
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th><th>فایل</th><th>کل</th><th>پردازش‌شده</th><th>تطبیق‌شده</th><th>وضعیت</th><th>ایجاد</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentBatches as $b): ?>
                    <tr>
                        <td><?= (int)$b['id'] ?></td>
                        <td class="small"><?= htmlspecialchars((string)($b['original_filename'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)($b['total_rows'] ?? 0) ?></td>
                        <td><?= (int)($b['processed_rows'] ?? 0) ?></td>
                        <td><?= (int)($b['matched_rows'] ?? 0) ?></td>
                        <td>
                            <?php
                                $st = (string)($b['status'] ?? '');
                                $badge = match ($st) {
                                    'completed' => 'text-bg-success',
                                    'processing' => 'text-bg-primary',
                                    'failed' => 'text-bg-danger',
                                    default => 'text-bg-secondary',
                                };
                            ?>
                            <span class="badge <?= $badge ?>"><?= htmlspecialchars($st !== '' ? $st : '—', ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td class="small text-muted" dir="ltr"><?= htmlspecialchars((string)($b['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recentBatches)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">هنوز دسته‌ای بارگذاری نشده.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Recent matches -->
<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-link-45deg"></i> تطبیق‌های اخیر گولدن‌لینک
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>شماره مجوز</th><th>متقاضی</th><th>عنوان مجوز</th><th>سازمان</th><th>اطمینان</th><th>تاریخ تطبیق</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentMatches as $m): ?>
                    <tr>
                        <td class="small" dir="ltr"><?= htmlspecialchars((string)$m['request_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$m['applicant_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small"><?= htmlspecialchars((string)($m['license_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small"><?= htmlspecialchars((string)($m['organization_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php $conf = (int)($m['match_confidence'] ?? 0); ?>
                            <?php $cBadge = $conf >= 80 ? 'text-bg-success' : ($conf >= 50 ? 'text-bg-warning' : 'text-bg-secondary'); ?>
                            <span class="badge <?= $cBadge ?>"><?= htmlspecialchars((string)$conf, ENT_QUOTES, 'UTF-8') ?>٪</span>
                        </td>
                        <td class="small text-muted" dir="ltr"><?= htmlspecialchars((string)($m['last_seen_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recentMatches)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">هنوز تطبیقی ثبت نشده.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
