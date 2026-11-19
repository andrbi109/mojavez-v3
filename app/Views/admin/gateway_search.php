<?php
/**
 * admin/gateway_search.php — advanced search of the national license cache.
 *
 * Multi-field search form (national_code, license_number, province,
 * organization, service_type, status) + server-rendered results table +
 * license detail modal (Bootstrap) opened by the row "view" button.
 *
 * The form GETs to /admin/gateway/search with the filter fields as query
 * params. The controller runs LicenseService::searchLocal() and passes
 * the results back to this view.
 *
 * Phase 54 (v2.2.0) — when the local cache is completely empty
 * ($cacheTotal === 0, i.e. national_licenses has 0 rows), the page shows
 * a prominent info banner with the exact text the user requested:
 * "هیچ مجوزی در کش محلی وجود ندارد. برای پر کردن کش، به صفحه وضعیت
 * همگام‌سازی بروید و دکمه اجرای همگام‌سازی را بزنید." This replaces the
 * previous "نتیجه‌ای یافت نشد — کش محلی خالی است" message that lived
 * inside the empty results row (less prominent + easy to miss).
 *
 * Phase 59a — license modal: every date field is now formatted as
 * Jalali via PersianDate::format() (responded_at, expires_at). The
 * modal previously rendered raw Gregorian "2026-11-17 18:01:49" strings
 * which were both hard to read and inconsistent with the rest of the
 * admin UI. The data-* attributes carry the Jalali strings directly so
 * the JS only needs to set textContent.
 *
 * @var array<string,mixed> $filter
 * @var array<int,array<string,mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pageSize
 * @var int $totalPages
 * @var int $cacheTotal  Phase 54 — total rows in national_licenses (unfiltered)
 * @var array<int,array{id:string,title:string}> $provinces
 * @var array<int,array{id:string,title:string}> $organizations
 * @var string $csrfField
 * @var string $baseUrl
 */

use App\Shared\Support\PersianDate;

$cacheTotal ??= 0;
$q = static function (string $key) use ($filter): string {
    return htmlspecialchars((string)($filter[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
// Phase 59a — pre-format dates as Jalali for the row buttons' data-*
// attributes so the modal JS only needs textContent assignment.
$jDate = static function (?string $greg): string {
    if ($greg === null || $greg === '') {
        return '';
    }
    $formatted = PersianDate::format($greg);
    return $formatted === '—' ? '' : $formatted;
};
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-search"></i> جستجوی پیشرفتهٔ درگاه ملی</h2>
    <span class="badge text-bg-secondary">کش محلی</span>
    <div class="ms-auto d-flex gap-2">
        <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/run"
            class="d-inline"
            onsubmit="return confirm('یک دور کامل همگام‌سازی اجرا شود؟ پس از پایان، نتایج در همین صفحه نمایش داده می‌شوند.');">
            <?= $csrfField ?>
            <button type="submit" class="btn btn-success btn-sm">
                <i class="bi bi-play-circle"></i> اجرای همگام‌سازی
            </button>
        </form>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/status" class="btn btn-sm btn-outline-info">
            <i class="bi bi-cloud-arrow-down"></i> وضعیت همگام‌سازی
        </a>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-return-right"></i> داشبورد
        </a>
    </div>
</div>

<!-- Phase 48 (v2.1.4): explicit help on how the cache gets populated. -->
<div class="alert alert-info small py-2 mb-3 d-flex align-items-start gap-2">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div>
        این صفحه فقط مجوزهای <strong>کش‌شده</strong> در درگاه محلی را جستجو می‌کند. برای
        پر کردن کش، به صفحهٔ <a class="alert-link" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/status">«وضعیت همگام‌سازی»</a>
        بروید و دکمهٔ «اجرای همگام‌سازی» را بزنید (یا از دکمهٔ سبز همین صفحه استفاده کنید).
        پس از اجرای همگام‌سازی، نتایج در این صفحه نمایش داده می‌شوند.
        <span class="text-muted d-block mt-1">
            <i class="bi bi-arrow-repeat"></i>
            هر بار که صفحهٔ وضعیت همگام‌سازی را باز می‌کنید، یک کار از صف تخلیه می‌شود
            (الگوی poor man's cron)؛ برای اجرای یک دور کامل، دکمهٔ «اجرای همگام‌سازی» را بزنید.
        </span>
    </div>
</div>

<?php if ($cacheTotal === 0): ?>
<!-- Phase 54 (v2.2.0) — the cache is completely empty. Show a prominent
     info banner with the exact message the user requested so the admin
     immediately knows where to go instead of staring at "no results". -->
<div class="alert alert-info d-flex align-items-start gap-2 mb-3" role="alert">
    <i class="bi bi-database-slash fs-4 mt-1"></i>
    <div>
        <strong>هیچ مجوزی در کش محلی وجود ندارد.</strong>
        برای پر کردن کش، به صفحهٔ
        <a class="alert-link" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/status">«وضعیت همگام‌سازی»</a>
        بروید و دکمهٔ «اجرای همگام‌سازی» را بزنید.
        <div class="small text-muted mt-1">
            می‌توانید همین حالا از دکمهٔ سبز «اجرای همگام‌سازی» در بالای همین صفحه هم
            استفاده کنید — پس از پایان دور، نتایج به‌طور خودکار در این جدول نمایش داده می‌شوند.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filter form -->
<div class="card mb-3">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-funnel"></i> فیلتر جستجو
    </div>
    <div class="card-body">
        <form method="get" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/search" class="row g-2">
            <div class="col-md-3">
                <label class="form-label small">کد ملی</label>
                <input class="form-control form-control-sm" type="text" name="national_code" value="<?= $q('national_code') ?>" placeholder="۰۰۱۲۳۴۵۶۷۸">
            </div>
            <div class="col-md-3">
                <label class="form-label small">شماره مجوز</label>
                <input class="form-control form-control-sm" type="text" name="license_number" value="<?= $q('license_number') ?>" placeholder="۱۲۳۴۵">
            </div>
            <div class="col-md-3">
                <label class="form-label small">نام متقاضی</label>
                <input class="form-control form-control-sm" type="text" name="name" value="<?= $q('name') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small">استان</label>
                <select class="form-select form-select-sm" name="province_title">
                    <option value="">— همه —</option>
                    <?php foreach ($provinces as $p): ?>
                        <option value="<?= htmlspecialchars((string)$p['id'], ENT_QUOTES, 'UTF-8') ?>" <?= ($filter['province_title'] ?? '') === (string)$p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$p['title'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">سازمان</label>
                <select class="form-select form-select-sm" name="organization_title">
                    <option value="">— همه —</option>
                    <?php foreach ($organizations as $o): ?>
                        <option value="<?= htmlspecialchars((string)$o['id'], ENT_QUOTES, 'UTF-8') ?>" <?= ($filter['organization_title'] ?? '') === (string)$o['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$o['title'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">تاریخ صدور (از)</label>
                <input class="form-control form-control-sm" type="text" name="issue_date_start" value="<?= $q('issue_date_start') ?>" placeholder="1405/08/21" pattern="\d{4}/\d{1,2}/\d{1,2}">
            </div>
            <div class="col-md-3">
                <label class="form-label small">تاریخ صدور (تا)</label>
                <input class="form-control form-control-sm" type="text" name="issue_date_end" value="<?= $q('issue_date_end') ?>" placeholder="1405/08/29" pattern="\d{4}/\d{1,2}/\d{1,2}">
            </div>
            <div class="col-md-3">
                <label class="form-label small">وضعیت</label>
                <select class="form-select form-select-sm" name="status">
                    <option value="">— همه —</option>
                    <option value="active" <?= ($filter['status'] ?? '') === 'active' ? 'selected' : '' ?>>فعال</option>
                    <option value="expired" <?= ($filter['status'] ?? '') === 'expired' ? 'selected' : '' ?>>منقضی</option>
                    <option value="unknown" <?= ($filter['status'] ?? '') === 'unknown' ? 'selected' : '' ?>>نامشخص</option>
                </select>
            </div>
            <div class="col-12 d-flex gap-2 mt-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-search"></i> جستجو
                </button>
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/search" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-eraser"></i> پاک‌سازی
                </a>
                <span class="small text-muted ms-auto align-self-center">
                    <?= htmlspecialchars((string)$total, ENT_QUOTES, 'UTF-8') ?> نتیجه · صفحهٔ <?= htmlspecialchars((string)$page, ENT_QUOTES, 'UTF-8') ?> از <?= htmlspecialchars((string)$totalPages, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </form>
    </div>
</div>

<!-- Results -->
<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-list-ul"></i> نتایج
        <span class="badge text-bg-secondary ms-auto"><?= htmlspecialchars((string)$total, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>شماره مجوز</th>
                        <th>متقاضی</th>
                        <th>عنوان مجوز</th>
                        <th>سازمان</th>
                        <th>استان</th>
                        <th>وضعیت</th>
                        <th>تطبیق</th>
                        <th>ذخیره</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                        $st = (string)($r['status_slug'] ?? '');
                        $stBadge = match ($st) {
                            'active' => 'text-bg-success',
                            'expired' => 'text-bg-warning',
                            default => 'text-bg-secondary',
                        };
                        $stLabel = htmlspecialchars((string)($r['status_title'] ?? $st), ENT_QUOTES, 'UTF-8');
                        $conf = (int)($r['match_confidence'] ?? 0);
                        $cBadge = $conf >= 80 ? 'text-bg-success' : ($conf >= 50 ? 'text-bg-warning' : 'text-bg-secondary');
                        $matched = (int)($r['matched_applicant_id'] ?? 0);
                        $isSaved = (int)($r['is_saved'] ?? 0) === 1;
                        $licenseId = (int)($r['id'] ?? 0);
                    ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars((string)$r['request_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$r['applicant_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small"><?= htmlspecialchars((string)($r['license_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small"><?= htmlspecialchars((string)($r['organization_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small"><?= htmlspecialchars((string)($r['province_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge <?= $stBadge ?>"><?= $stLabel !== '' ? $stLabel : '—' ?></span></td>
                        <td>
                            <?php if ($matched > 0): ?>
                                <span class="badge <?= $cBadge ?>"><?= htmlspecialchars((string)$conf, ENT_QUOTES, 'UTF-8') ?>٪</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isSaved): ?>
                                <button type="button"
                                    class="btn btn-sm btn-success btn-toggle-save"
                                    data-id="<?= $licenseId ?>"
                                    data-saved="1"
                                    title="حذف از ذخیره‌شده‌ها">
                                    <i class="bi bi-bookmark-star-fill"></i>
                                </button>
                            <?php else: ?>
                                <button type="button"
                                    class="btn btn-sm btn-outline-secondary btn-toggle-save"
                                    data-id="<?= $licenseId ?>"
                                    data-saved="0"
                                    title="افزودن به ذخیره‌شده‌ها">
                                    <i class="bi bi-bookmark"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button type="button"
                                class="btn btn-sm btn-outline-info btn-view-license"
                                data-rn="<?= htmlspecialchars((string)$r['request_number'], ENT_QUOTES, 'UTF-8') ?>"
                                data-applicant="<?= htmlspecialchars((string)$r['applicant_name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-title="<?= htmlspecialchars((string)($r['license_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-org="<?= htmlspecialchars((string)($r['organization_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-province="<?= htmlspecialchars((string)($r['province_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-township="<?= htmlspecialchars((string)($r['township_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-status="<?= $stLabel ?>"
                                data-responded="<?= htmlspecialchars($jDate((string)($r['responded_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                                data-father="<?= htmlspecialchars((string)($r['father_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-national="<?= htmlspecialchars((string)($r['masked_national_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-address="<?= htmlspecialchars((string)($r['business_address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-isic="<?= htmlspecialchars((string)($r['isic_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-isic-title="<?= htmlspecialchars((string)($r['isic_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-expires="<?= htmlspecialchars($jDate((string)($r['expires_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                                data-supervisors="<?= htmlspecialchars((string)($r['supervisory_authorities'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-conf="<?= htmlspecialchars((string)$conf, ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-eye"></i> مشاهده
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            <div class="d-flex flex-column align-items-center gap-2">
                                <span>
                                    <i class="bi bi-inbox"></i>
                                    <?php if ($cacheTotal === 0): ?>
                                        نتیجه‌ای یافت نشد — کش محلی خالی است.
                                    <?php else: ?>
                                        نتیجه‌ای یافت نشد — فیلتر بیش‌ازحد سخت‌گیرانه است
                                        (مجوزی با این معیارها در کش وجود ندارد).
                                    <?php endif; ?>
                                </span>
                                <span class="small">
                                    <?php if ($cacheTotal === 0): ?>
                                        برای پر کردن کش از درگاه ملی، دکمهٔ «اجرای همگام‌سازی» را بزنید
                                        یا به صفحهٔ <a class="link-primary" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/status">وضعیت همگام‌سازی</a> بروید.
                                    <?php else: ?>
                                        فیلترها را سست‌تر کنید (مثلاً استان یا وضعیت را روی «همه» بگذارید)
                                        و دوباره جستجو کنید.
                                    <?php endif; ?>
                                </span>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer">
        <nav>
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php
                    $baseQ = $filter;
                    unset($baseQ['page']);
                    $qStr = http_build_query($baseQ);
                    $link = static function (int $p) use ($qStr, $baseUrl): string {
                        $sep = $qStr !== '' ? '&' : '';
                        return htmlspecialchars($baseUrl, ENT_QUOTES) . 'admin/gateway/search?' . $qStr . $sep . 'page=' . $p;
                    };
                ?>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $link(max(1, $page - 1)) ?>">قبلی</a>
                </li>
                <li class="page-item active"><span class="page-link"><?= (int)$page ?> / <?= (int)$totalPages ?></span></li>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $link(min($totalPages, $page + 1)) ?>">بعدی</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- License detail modal -->
<div class="modal fade" id="gatewayLicenseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-patch-check"></i> جزئیات مجوز</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-3">
            <dt class="col-sm-3">شماره مجوز</dt><dd class="col-sm-9" id="glm-rn">—</dd>
            <dt class="col-sm-3">متقاضی</dt><dd class="col-sm-9" id="glm-applicant">—</dd>
            <dt class="col-sm-3">عنوان مجوز</dt><dd class="col-sm-9" id="glm-title">—</dd>
            <dt class="col-sm-3">سازمان</dt><dd class="col-sm-9" id="glm-org">—</dd>
            <dt class="col-sm-3">استان / شهرستان</dt><dd class="col-sm-9" id="glm-region">—</dd>
            <dt class="col-sm-3">وضعیت</dt><dd class="col-sm-9" id="glm-status">—</dd>
            <dt class="col-sm-3">تاریخ پاسخ</dt><dd class="col-sm-9" id="glm-responded">—</dd>
            <dt class="col-sm-3">نام پدر</dt><dd class="col-sm-9" id="glm-father">—</dd>
            <dt class="col-sm-3">کد ملی (ماسک‌شده)</dt><dd class="col-sm-9" id="glm-national">—</dd>
            <dt class="col-sm-3">نشانی کسب‌وکار</dt><dd class="col-sm-9" id="glm-address">—</dd>
            <dt class="col-sm-3">کد آیسیک</dt><dd class="col-sm-9" id="glm-isic">—</dd>
            <dt class="col-sm-3">عنوان آیسیک</dt><dd class="col-sm-9" id="glm-isic-title">—</dd>
            <dt class="col-sm-3">تاریخ انقضا</dt><dd class="col-sm-9" id="glm-expires">—</dd>
            <dt class="col-sm-3">مراجع نظارتی</dt><dd class="col-sm-9" id="glm-supervisors">—</dd>
            <dt class="col-sm-3">اطمینان تطبیق</dt><dd class="col-sm-9" id="glm-conf">—</dd>
        </dl>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">بستن</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    document.querySelectorAll('.btn-view-license').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var set = function (id, v) {
                var el = document.getElementById(id);
                if (el) el.textContent = v && String(v) !== '' ? v : '—';
            };
            set('glm-rn',        btn.getAttribute('data-rn'));
            set('glm-applicant', btn.getAttribute('data-applicant'));
            set('glm-title',     btn.getAttribute('data-title'));
            set('glm-org',       btn.getAttribute('data-org'));
            var prov = btn.getAttribute('data-province');
            var tow  = btn.getAttribute('data-township');
            set('glm-region', (prov || tow) ? (prov + ' / ' + tow) : '');
            set('glm-status',    btn.getAttribute('data-status'));
            set('glm-responded', btn.getAttribute('data-responded'));
            set('glm-father',    btn.getAttribute('data-father'));
            set('glm-national',  btn.getAttribute('data-national'));
            set('glm-address',   btn.getAttribute('data-address'));
            var isic = btn.getAttribute('data-isic');
            set('glm-isic',      isic);
            set('glm-isic-title', btn.getAttribute('data-isic-title'));
            set('glm-expires',   btn.getAttribute('data-expires'));
            set('glm-supervisors', btn.getAttribute('data-supervisors'));
            var conf = btn.getAttribute('data-conf');
            set('glm-conf',      conf && conf !== '0' ? (conf + '٪') : '—');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('gatewayLicenseModal')).show();
        });
    });

    // Phase 62 — save / unsave toggle per row. POSTs to
    // /api/v1/licenses/{id}/save (to mark saved) or DELETEs the same
    // URL (to unmark). The button's class + icon swap on success so
    // the admin sees the new state immediately without a page reload.
    var CSRF = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';
    var API_BASE = '<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>api/v1/licenses';
    document.querySelectorAll('.btn-toggle-save').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            var saved = btn.getAttribute('data-saved') === '1';
            if (!id || id === '0') return;
            // Disable while in-flight so the admin can't double-click.
            btn.disabled = true;
            var url = API_BASE + '/' + encodeURIComponent(id) + '/save';
            var method = saved ? 'DELETE' : 'POST';
            fetch(url, {
                method: method,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': CSRF,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); })
              .then(function () {
                  // Swap the button state. The visual swap avoids a full
                  // page reload but keeps the row's other state intact.
                  if (saved) {
                      btn.classList.remove('btn-success');
                      btn.classList.add('btn-outline-secondary');
                      btn.querySelector('i').classList.remove('bi-bookmark-star-fill');
                      btn.querySelector('i').classList.add('bi-bookmark');
                      btn.setAttribute('data-saved', '0');
                      btn.setAttribute('title', 'افزودن به ذخیره‌شده‌ها');
                  } else {
                      btn.classList.remove('btn-outline-secondary');
                      btn.classList.add('btn-success');
                      btn.querySelector('i').classList.remove('bi-bookmark');
                      btn.querySelector('i').classList.add('bi-bookmark-star-fill');
                      btn.setAttribute('data-saved', '1');
                      btn.setAttribute('title', 'حذف از ذخیره‌شده‌ها');
                  }
              })
              .catch(function (e) { alert('خطا در ذخیره‌سازی: ' + e.message); })
              .finally(function () { btn.disabled = false; });
        });
    });
})();
</script>
