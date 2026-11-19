<?php
/**
 * admin/gateway_saved.php — cached national licenses.
 *
 * Phase 59a — this page lists every row in national_licenses
 * (the local cache populated by the sync cycle), not just the
 * rows the admin has explicitly marked is_saved = 1. Each row's
 * "view" button opens the license detail modal (same shape as
 * gateway_search.php). Optional status filter dropdown (all /
 * active / expired / unknown) and a CSV export button (links to
 * the existing /api/v1/licenses/export endpoint).
 *
 * Phase 59a — license modal: every date field is now formatted as
 * Jalali via PersianDate::format() (responded_at in the table
 * column, plus responded_at + expires_at in the modal). The
 * match_confidence badge is now also shown in the saved modal,
 * matching the search modal.
 *
 * @var string $statusFilter
 * @var array<int,array<string,mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pageSize
 * @var int $totalPages
 * @var string $csrfField
 * @var string $baseUrl
 */

use App\Shared\Support\PersianDate;

// Phase 59a — pre-format dates as Jalali for the row buttons' data-*
// attributes so the modal JS only needs textContent assignment.
$jDate = static function (?string $greg): string {
    if ($greg === null || $greg === '') {
        return '—';
    }
    return PersianDate::format($greg);
};
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-database"></i> مجوزهای کش‌شده از درگاه ملی</h2>
    <span class="badge text-bg-secondary"><?= htmlspecialchars((string)$total, ENT_QUOTES, 'UTF-8') ?> رکورد</span>
    <div class="ms-auto d-flex gap-2">
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>api/v1/licenses/export?format=csv" class="btn btn-sm btn-outline-success">
            <i class="bi bi-filetype-csv"></i> خروجی CSV
        </a>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-return-right"></i> داشبورد
        </a>
    </div>
</div>

<!-- Status filter -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/saved" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small">فیلتر بر اساس وضعیت</label>
                <select class="form-select form-select-sm" name="status">
                    <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>— همه —</option>
                    <option value="active"  <?= $statusFilter === 'active'  ? 'selected' : '' ?>>فعال (صادرشده)</option>
                    <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>منقضی</option>
                    <option value="unknown" <?= $statusFilter === 'unknown' ? 'selected' : '' ?>>نامشخص / در انتظار</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel"></i> اعمال فیلتر
                </button>
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/saved" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-eraser"></i> پاک‌سازی
                </a>
            </div>
            <div class="col-md-6 text-end small text-muted align-self-center">
                صفحهٔ <?= htmlspecialchars((string)$page, ENT_QUOTES, 'UTF-8') ?> از <?= htmlspecialchars((string)$totalPages, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)$total, ENT_QUOTES, 'UTF-8') ?> رکورد
            </div>
        </form>
    </div>
</div>

<!-- Cached licenses table -->
<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-list-ul"></i> فهرست مجوزهای کش‌شده از درگاه ملی
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
                        <th>تاریخ پاسخ</th>
                        <th>وضعیت</th>
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
                    ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars((string)$r['request_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$r['applicant_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small"><?= htmlspecialchars((string)($r['license_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small"><?= htmlspecialchars((string)($r['organization_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small"><?= htmlspecialchars((string)($r['province_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($jDate((string)($r['responded_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge <?= $stBadge ?>"><?= $stLabel !== '' ? $stLabel : '—' ?></span></td>
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
                    <tr><td colspan="8" class="text-center text-muted py-4">هیچ مجوزی در کش محلی وجود ندارد. برای پر کردن کش، یک دور همگام‌سازی اجرا کنید (صفحهٔ «وضعیت همگام‌سازی»).</td></tr>
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
                    $qStr = $statusFilter !== '' ? ('status=' . urlencode($statusFilter) . '&') : '';
                    $link = static function (int $p) use ($qStr, $baseUrl): string {
                        return htmlspecialchars($baseUrl, ENT_QUOTES) . 'admin/gateway/saved?' . $qStr . 'page=' . $p;
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
            set('glm-isic',      btn.getAttribute('data-isic'));
            set('glm-isic-title', btn.getAttribute('data-isic-title'));
            set('glm-expires',   btn.getAttribute('data-expires'));
            set('glm-supervisors', btn.getAttribute('data-supervisors'));
            var conf = btn.getAttribute('data-conf');
            set('glm-conf',      conf && conf !== '0' ? (conf + '٪') : '—');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('gatewayLicenseModal')).show();
        });
    });
})();
</script>
