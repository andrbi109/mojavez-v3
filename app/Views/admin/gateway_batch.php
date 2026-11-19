<?php
/**
 * admin/gateway_batch.php — batch CSV search.
 *
 * CSV upload form for batch national_code / license_number search (POSTs
 * the file to /api/v1/batch/upload via fetch). Below the form, a "recent
 * batches" table lists the last 20 rows from national_batches. Each row
 * links to its status detail (fetched on click from /api/v1/batch/{id}/status).
 *
 * The actual per-row processing is delegated to the existing
 * /api/v1/batch/upload endpoint, which records the batch + enqueues per-row
 * track-fetch jobs. The UI just uploads the file and watches the progress.
 *
 * @var array<int,array<string,mixed>> $batches
 * @var string $csrfField
 * @var string $baseUrl
 */
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-files"></i> جستجوی دسته‌جمعی</h2>
    <span class="badge text-bg-secondary">ماژول NationalGateway</span>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway" class="btn btn-sm btn-outline-secondary ms-auto">
        <i class="bi bi-arrow-return-right"></i> داشبورد
    </a>
</div>

<div class="row g-3">
    <!-- Upload form -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-cloud-upload"></i> آپلود فایل CSV
            </div>
            <div class="card-body">
                <form id="uploadForm">
                    <div class="mb-3">
                        <label class="form-label small">فایل CSV</label>
                        <input class="form-control form-control-sm" type="file" id="csvFile" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" id="btnUpload">
                        <i class="bi bi-cloud-upload"></i> آپلود و شروع
                    </button>
                    <span class="small text-muted ms-2" id="uploadMsg"></span>
                </form>
                <hr>
                <div class="small text-muted">
                    <p class="mb-1"><strong>قالب فایل:</strong> هر خط یک <em>شماره مجوز</em> (request_number).</p>
                    <p class="mb-1">حداکثر حجم: ۵ مگابایت. فقط فایل <code>.csv</code>.</p>
                    <p class="mb-0">پس از آپلود، هر کد به‌صورت یک کار در صف قرار می‌گیرد و کارگر آن‌ها را از درگاه ملی می‌کشد.</p>
                </div>
                <hr>
                <div class="small text-muted">
                    <i class="bi bi-info-circle"></i>
                    برای اجرای کارگر پس‌زمینه، به داشبورد همگام‌سازی
                    (<a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/status" class="alert-link">/admin/sync/status</a>)
                    بروید تا کارگر فعال شود.
                </div>
            </div>
        </div>
    </div>

    <!-- Recent batches -->
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-list-ul"></i> دسته‌های اخیر
                <span class="badge text-bg-secondary ms-auto"><?= count($batches) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>فایل</th>
                                <th>کل</th>
                                <th>پردازش‌شده</th>
                                <th>تطبیق‌شده</th>
                                <th>وضعیت</th>
                                <th>ایجاد</th>
                                <th class="text-end">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="batchRows">
                        <?php foreach ($batches as $b): ?>
                            <?php
                                $st = (string)($b['status'] ?? '');
                                $badge = match ($st) {
                                    'completed' => 'text-bg-success',
                                    'processing' => 'text-bg-primary',
                                    'failed' => 'text-bg-danger',
                                    default => 'text-bg-secondary',
                                };
                            ?>
                            <tr data-batch="<?= (int)$b['id'] ?>">
                                <td><?= (int)$b['id'] ?></td>
                                <td class="small"><?= htmlspecialchars((string)($b['original_filename'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="cell-total"><?= (int)($b['total_rows'] ?? 0) ?></td>
                                <td class="cell-processed"><?= (int)($b['processed_rows'] ?? 0) ?></td>
                                <td class="cell-matched"><?= (int)($b['matched_rows'] ?? 0) ?></td>
                                <td class="cell-status"><span class="badge <?= $badge ?>"><?= htmlspecialchars($st !== '' ? $st : '—', ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="small text-muted"><?= htmlspecialchars((string)($b['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-info btn-watch" data-id="<?= (int)$b['id'] ?>">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($batches)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">هنوز دسته‌ای بارگذاری نشده.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Batch detail modal -->
<div class="modal fade" id="batchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-files"></i> جزئیات دسته</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-3">
            <dt class="col-sm-3">شناسهٔ دسته</dt><dd class="col-sm-9" id="bm-id">—</dd>
            <dt class="col-sm-3">فایل</dt><dd class="col-sm-9" id="bm-file">—</dd>
            <dt class="col-sm-3">کل ردیف‌ها</dt><dd class="col-sm-9" id="bm-total">—</dd>
            <dt class="col-sm-3">پردازش‌شده</dt><dd class="col-sm-9" id="bm-processed">—</dd>
            <dt class="col-sm-3">تطبیق‌شده</dt><dd class="col-sm-9" id="bm-matched">—</dd>
            <dt class="col-sm-3">وضعیت</dt><dd class="col-sm-9" id="bm-status">—</dd>
            <dt class="col-sm-3">ایجاد</dt><dd class="col-sm-9" id="bm-created">—</dd>
            <dt class="col-sm-3">تکمیل</dt><dd class="col-sm-9" id="bm-completed">—</dd>
            <dt class="col-sm-3">خطا</dt><dd class="col-sm-9" id="bm-error">—</dd>
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
    var CSRF = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';
    var API = '<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>api/v1/batch';

    function statusCls(s) {
        s = String(s || '').toLowerCase();
        if (/done|complet|success|finish/i.test(s)) return 'text-bg-success';
        if (/run|process/i.test(s)) return 'text-bg-primary';
        if (/fail|error/i.test(s)) return 'text-bg-danger';
        if (/pending|queued/i.test(s)) return 'text-bg-info';
        return 'text-bg-secondary';
    }

    function showBatch(id, b) {
        var set = function (el, v) {
            if (el) el.textContent = v && String(v) !== '' ? v : '—';
        };
        set(document.getElementById('bm-id'), b.id);
        set(document.getElementById('bm-file'), b.original_filename);
        set(document.getElementById('bm-total'), b.total_rows);
        set(document.getElementById('bm-processed'), b.processed_rows);
        set(document.getElementById('bm-matched'), b.matched_rows);
        set(document.getElementById('bm-status'), b.status);
        set(document.getElementById('bm-created'), b.created_at);
        set(document.getElementById('bm-completed'), b.completed_at);
        set(document.getElementById('bm-error'), b.error);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('batchModal')).show();
    }

    document.querySelectorAll('.btn-watch').forEach(function (b) {
        b.addEventListener('click', function () {
            var id = b.getAttribute('data-id');
            fetch(API + '/' + encodeURIComponent(id) + '/status', {
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); })
              .then(function (d) { showBatch(id, d.data || d); })
              .catch(function (e) { alert('خطا: ' + e.message); });
        });
    });

    document.getElementById('uploadForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var file = document.getElementById('csvFile').files[0];
        if (!file) return;
        var btn = document.getElementById('btnUpload');
        btn.disabled = true;
        var msg = document.getElementById('uploadMsg');
        msg.textContent = 'در حال آپلود…';
        var fd = new FormData(); fd.append('csv', file);
        fetch(API + '/upload', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              var bid = d.data ? d.data.batch_id : d.batch_id;
              msg.textContent = 'دستهٔ #' + bid + ' ساخته شد.';
              document.getElementById('csvFile').value = '';
              window.location.reload();
          }).catch(function (e) { msg.textContent = 'خطا: ' + e.message; })
          .finally(function () { btn.disabled = false; });
    });
})();
</script>
