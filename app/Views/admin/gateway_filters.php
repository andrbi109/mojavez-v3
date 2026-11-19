<?php
/**
 * admin/gateway_filters.php — saved filter management.
 *
 * List of saved filters (national_filters table) with name, criteria
 * summary (parsed from filter_data JSON), created_at, last_run_at, and
 * last_result_count. Each row has:
 *  - Apply button → redirects to /admin/gateway/search with the filter's
 *    criteria as query params.
 *  - Edit button → opens the edit modal (POST to /api/v1/filters/{id}
 *    via fetch with PUT).
 *  - Delete button → POST form to /api/v1/filters/{id} with method=DELETE
 *    (emulated via fetch).
 *
 * A "new filter" button at the top opens the same modal in create mode
 * (POST to /api/v1/filters).
 *
 * Mutations go through the existing /api/v1/filters/* REST endpoints
 * (the same ones the v2 prototype called). CSRF token is read from the
 * `<meta name="csrf-token">` element that admin_layout.php emits.
 *
 * @var array<int,array<string,mixed>> $filters
 * @var string $csrfField
 * @var string $baseUrl
 */
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-funnel"></i> فیلترهای ذخیره‌شده</h2>
    <span class="badge text-bg-secondary"><?= count($filters) ?> فیلتر</span>
    <div class="ms-auto d-flex gap-2">
        <button type="button" class="btn btn-sm btn-primary" id="btnNewFilter">
            <i class="bi bi-plus-lg"></i> فیلتر جدید
        </button>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-return-right"></i> داشبورد
        </a>
    </div>
</div>

<div class="alert alert-info small py-2 mb-3">
    <i class="bi bi-info-circle"></i>
    فیلترهای ذخیره‌شده، جستجوهای آمادهٔ درگاه ملی هستند. با «اعمال»، معیارهای فیلتر
    به‌صورت پارامترهای query به صفحهٔ جستجو ارسال می‌شوند. با «اجرا در صف»، یک کار
    جستجوی زنده در درگاه بالادستی در صف گذاشته می‌شود (نیازمند کارگر فعال).
</div>

<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-list-ul"></i> فهرست فیلترها
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>برچسب</th>
                        <th>معیارها</th>
                        <th>فعال</th>
                        <th>آخرین اجرا</th>
                        <th>نتایج</th>
                        <th>ساخته‌شده</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($filters as $f): ?>
                    <?php
                        $id = (int)$f['id'];
                        $label = (string)($f['label'] ?? '');
                        $filterData = json_decode((string)($f['filter_data'] ?? '{}'), true);
                        if (!is_array($filterData)) { $filterData = []; }
                        $criteria = [];
                        foreach ($filterData as $k => $v) {
                            if (is_string($v) && $v !== '') {
                                $criteria[] = $k . '=' . $v;
                            }
                        }
                        $criteriaStr = $criteria !== [] ? htmlspecialchars(implode('، ', $criteria), ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>';
                        $isActive = (int)($f['is_active'] ?? 0) === 1;
                        $applyQuery = http_build_query($filterData);
                    ?>
                    <tr>
                        <td><?= $id ?></td>
                        <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small text-muted"><?= $criteriaStr ?></td>
                        <td>
                            <?php if ($isActive): ?>
                                <span class="badge text-bg-success">فعال</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">غیرفعال</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars((string)($f['last_run_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($f['last_result_count'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars((string)($f['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/search?<?= htmlspecialchars($applyQuery, ENT_QUOTES, 'UTF-8') ?>"
                               class="btn btn-sm btn-outline-primary" title="اعمال فیلتر در جستجو">
                                <i class="bi bi-play-fill"></i> اعمال
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-warning btn-edit-filter"
                                data-id="<?= $id ?>"
                                data-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                                data-filter="<?= htmlspecialchars(json_encode($filterData, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
                                data-active="<?= $isActive ? 1 : 0 ?>"
                                title="ویرایش">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info btn-run-filter"
                                data-id="<?= $id ?>" title="اجرا در صف">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-filter"
                                data-id="<?= $id ?>" title="حذف">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($filters)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">هنوز فیلتری ثبت نشده. روی «فیلتر جدید» بزنید.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create/Edit modal -->
<div class="modal fade" id="filterModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="filterForm">
        <div class="modal-header">
          <h5 class="modal-title" id="filterModalTitle">فیلتر جدید</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="id" id="fId">
            <div class="row g-2 mb-2">
                <div class="col-md-8">
                    <label class="form-label small">برچسب *</label>
                    <input class="form-control form-control-sm" name="label" id="fLabel" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">وضعیت</label>
                    <select class="form-select form-select-sm" name="is_active" id="fActive">
                        <option value="1">فعال</option>
                        <option value="0">غیرفعال</option>
                    </select>
                </div>
            </div>
            <h6 class="mt-3 mb-2">معیارهای جستجو</h6>
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label small">شماره مجوز</label><input class="form-control form-control-sm" data-f="license_number"></div>
                <div class="col-md-3"><label class="form-label small">نام متقاضی</label><input class="form-control form-control-sm" data-f="name"></div>
                <div class="col-md-3"><label class="form-label small">استان</label><input class="form-control form-control-sm" data-f="province_title"></div>
                <div class="col-md-3"><label class="form-label small">سازمان</label><input class="form-control form-control-sm" data-f="organization_title"></div>
                <div class="col-md-3"><label class="form-label small">تاریخ صدور (از)</label><input class="form-control form-control-sm" data-f="issue_date_start" placeholder="1405/08/21"></div>
                <div class="col-md-3"><label class="form-label small">تاریخ صدور (تا)</label><input class="form-control form-control-sm" data-f="issue_date_end" placeholder="1405/08/29"></div>
                <div class="col-md-3"><label class="form-label small">وضعیت</label>
                    <select class="form-select form-select-sm" data-f="status">
                        <option value="">— همه —</option>
                        <option value="active">فعال</option>
                        <option value="expired">منقضی</option>
                        <option value="unknown">نامشخص</option>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label small">کد ملی</label><input class="form-control form-control-sm" data-f="national_code"></div>
            </div>
            <div class="small text-muted mt-2">
                <i class="bi bi-info-circle"></i>
                معیارها به‌صورت JSON در <code>filter_data</code> ذخیره می‌شوند و توسط دور همگام‌سازی برای جستجوی زنده در درگاه بالادستی به کار می‌روند.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">انصراف</button>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> ذخیره</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
    var CSRF = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';
    var API = '<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>api/v1/filters';
    var modalEl = document.getElementById('filterModal');
    var modal = null;

    function openModal() {
        modal = modal || bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    function closeModal() {
        if (modal) modal.hide();
    }

    function setField(name, value) {
        var el = modalEl.querySelector('[data-f="' + name + '"]');
        if (el) el.value = value || '';
    }

    function openNew() {
        document.getElementById('filterForm').reset();
        document.getElementById('fId').value = '';
        document.getElementById('filterModalTitle').textContent = 'فیلتر جدید';
        openModal();
    }

    function openEdit(id, label, filterObj, isActive) {
        document.getElementById('filterForm').reset();
        document.getElementById('fId').value = id;
        document.getElementById('fLabel').value = label;
        document.getElementById('fActive').value = isActive ? '1' : '0';
        Object.keys(filterObj || {}).forEach(function (k) { setField(k, filterObj[k]); });
        document.getElementById('filterModalTitle').textContent = 'ویرایش فیلتر #' + id;
        openModal();
    }

    document.getElementById('btnNewFilter').addEventListener('click', openNew);

    document.querySelectorAll('.btn-edit-filter').forEach(function (b) {
        b.addEventListener('click', function () {
            var id = b.getAttribute('data-id');
            var label = b.getAttribute('data-label');
            var filterObj;
            try { filterObj = JSON.parse(b.getAttribute('data-filter') || '{}'); } catch (e) { filterObj = {}; }
            openEdit(id, label, filterObj, b.getAttribute('data-active') === '1');
        });
    });

    document.querySelectorAll('.btn-delete-filter').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!confirm('این فیلتر حذف شود؟')) return;
            var id = b.getAttribute('data-id');
            fetch(API + '/' + encodeURIComponent(id), {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); })
              .then(function () { window.location.reload(); })
              .catch(function (e) { alert('خطا: ' + e.message); });
        });
    });

    document.querySelectorAll('.btn-run-filter').forEach(function (b) {
        b.addEventListener('click', function () {
            var id = b.getAttribute('data-id');
            if (!confirm('این فیلتر به‌صورت زنده در درگاه بالادستی اجرا شود؟\n\n'
                + 'یک کار جستجو در صف قرار می‌گیرد و پس از اجرای کارگر، نتایج در صفحهٔ «جستجوی پیشرفته» نمایش داده می‌شوند.\n\n'
                + 'برای فعال‌سازی کارگر، به صفحهٔ «وضعیت همگام‌سازی» بروید (هر بار باز کردن آن صفحه یک کار از صف را اجرا می‌کند).')) {
                return;
            }
            fetch('<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>api/v1/admin/crawl', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ filter_id: id }),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); })
              .then(function (d) {
                  var jobId = d && d.data ? d.data.job_id : '?';
                  alert('فیلتر در صف قرار گرفت (کار #' + jobId + ').\n\n'
                      + 'پس از اجرای کارگر، نتایج در صفحهٔ «جستجوی پیشرفته» نمایش داده می‌شوند:\n'
                      + '<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/search\n\n'
                      + 'برای اجرای کارگر، به صفحهٔ «وضعیت همگام‌سازی» بروید:\n'
                      + '<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/status');
              })
              .catch(function (e) { alert('خطا: ' + e.message); });
        });
    });

    document.getElementById('filterForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var id = document.getElementById('fId').value;
        var filterObj = {};
        modalEl.querySelectorAll('[data-f]').forEach(function (el) {
            var k = el.getAttribute('data-f');
            if (el.value !== '') filterObj[k] = el.value;
        });
        var body = {
            label: document.getElementById('fLabel').value,
            filter_data: filterObj,
            is_active: parseInt(document.getElementById('fActive').value, 10) === 1 ? 1 : 0
        };
        var url = API, method = 'POST';
        if (id) { url = API + '/' + encodeURIComponent(id); method = 'PUT'; }
        fetch(url, {
            method: method,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); })
          .then(function () { closeModal(); window.location.reload(); })
          .catch(function (e) { alert('خطا: ' + e.message); });
    });
})();
</script>
