<?php
/**
 * admin/gateway_batch.php — batch CSV search.
 *
 * Phase 62 (v2.2.8) — the page now does the actual local cache
 * lookup itself. The form POSTs the CSV to /admin/gateway/batch
 * (the controller's batchUpload() action parses it in-process +
 * looks up each row in national_licenses + stores the result in
 * the session flash). When the page is rendered next, $lastResult
 * contains the per-row matched/unmatched report and is shown
 * inline above the recent-batches table.
 *
 * The "search upstream for unmatched" button submits the
 * unmatched request_numbers via POST to /admin/gateway/batch/queue
 * (the controller's batchQueue() action enqueues a track_fetch
 * job per request_number). The jobs persist in the queue table;
 * the Worker (poor man's cron) drains them on subsequent
 * /admin/sync/status polls. The admin sees the queued count in
 * the flash message ($queueMsg).
 *
 * The recent-batches table (last 20 rows from national_batches)
 * is unchanged — it shows the historical log of CSV uploads.
 *
 * @var array<int,array<string,mixed>> $batches
 * @var array<string,mixed>|null $lastResult  Phase 62 — per-row result
 * @var string|null $queueMsg                   Phase 62 — queue ack
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

<?php if (!empty($queueMsg)): ?>
<div class="alert alert-info alert-dismissible fade show small" role="alert">
    <i class="bi bi-info-circle-fill"></i>
    <?= htmlspecialchars((string)$queueMsg, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="بستن"></button>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Upload form -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-cloud-upload"></i> آپلود فایل CSV
            </div>
            <div class="card-body">
                <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/batch"
                    enctype="multipart/form-data">
                    <?= $csrfField ?>
                    <div class="mb-3">
                        <label class="form-label small">فایل CSV</label>
                        <input class="form-control form-control-sm" type="file" name="csv" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-cloud-upload"></i> آپلود و جستجو در کش محلی
                    </button>
                </form>
                <hr>
                <div class="small text-muted">
                    <p class="mb-1"><strong>قالب فایل:</strong> هر خط یک ردیف با ستون <code>license_number</code> (شماره مجوز) یا <code>national_code</code> (کد ملی).</p>
                    <p class="mb-1">حداکثر حجم: ۵ مگابایت. فقط فایل <code>.csv</code>.</p>
                    <p class="mb-0">جستجو فقط در کش محلی انجام می‌شود — برای ردیف‌های بدون تطبیق می‌توانید با دکمهٔ «جستجوی بالادستی» یک کار در صف قرار دهید تا کارگر آن‌ها را از درگاه ملی بکشد.</p>
                </div>
                <hr>
                <div class="small text-muted">
                    <i class="bi bi-info-circle"></i>
                    برای اجرای کارگر پس‌زمینه، به داشبورد همگام‌سازی
                    (<a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/status" class="alert-link">/admin/sync/status</a>)
                    بروید تا کارگر فعال شود (هر بار باز کردن صفحه یک کار از صف را اجرا می‌کند).
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
                                <th>تطبیق‌شده</th>
                                <th>وضعیت</th>
                                <th>ایجاد</th>
                            </tr>
                        </thead>
                        <tbody>
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
                            <tr>
                                <td><?= (int)$b['id'] ?></td>
                                <td class="small"><?= htmlspecialchars((string)($b['original_filename'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int)($b['total_rows'] ?? 0) ?></td>
                                <td><?= (int)($b['matched_rows'] ?? 0) ?></td>
                                <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($st !== '' ? $st : '—', ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="small text-muted"><?= htmlspecialchars((string)($b['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($batches)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">هنوز دسته‌ای بارگذاری نشده.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (is_array($lastResult) && isset($lastResult['rows'])):
    $filename = (string)($lastResult['filename'] ?? '');
    $total   = (int)($lastResult['total']   ?? 0);
    $matched = (int)($lastResult['matched'] ?? 0);
    $unmatched = $total - $matched;
    $rows    = is_array($lastResult['rows'] ?? null) ? $lastResult['rows'] : [];
    // Collect unmatched request_numbers (only those that actually
    // had a license_number column — we can't queue a track fetch
    // without a request_number to scrape).
    $unmatchedRns = [];
    foreach ($rows as $r) {
        if (!($r['matched'] ?? false) && !empty($r['license_number'])) {
            $unmatchedRns[] = (string)$r['license_number'];
        }
    }
    $unmatchedRnList = implode("\n", $unmatchedRns);
?>
<!-- Inline batch result (Phase 62) -->
<div class="card mt-3">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-table"></i> نتیجهٔ جستجوی دسته
        <span class="text-muted small">«<?= htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') ?>»</span>
        <span class="badge text-bg-success ms-auto"><?= $matched ?> تطبیق</span>
        <span class="badge text-bg-warning"><?= $unmatched ?> بدون تطبیق</span>
        <span class="badge text-bg-secondary"><?= $total ?> کل</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 28rem; overflow-y: auto;">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>ردیف</th>
                        <th>شماره مجوز</th>
                        <th>کد ملی</th>
                        <th>وضعیت</th>
                        <th>متقاضی (در صورت تطبیق)</th>
                        <th>عنوان مجوز</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $ln     = (int)($r['line'] ?? 0);
                    $rn     = (string)($r['license_number'] ?? '');
                    $nc     = (string)($r['national_code'] ?? '');
                    $isHit  = (bool)($r['matched'] ?? false);
                    $lic    = is_array($r['license'] ?? null) ? $r['license'] : [];
                ?>
                    <tr class="<?= $isHit ? 'table-success' : 'table-warning' ?>">
                        <td class="small text-muted"><?= $ln ?></td>
                        <td class="small"><?= htmlspecialchars($rn !== '' ? $rn : '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small"><?= htmlspecialchars($nc !== '' ? $nc : '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($isHit): ?>
                                <span class="badge text-bg-success"><i class="bi bi-check-lg"></i> تطبیق</span>
                            <?php else: ?>
                                <span class="badge text-bg-warning"><i class="bi bi-x-lg"></i> بدون تطبیق</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= htmlspecialchars((string)($lic['applicant_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small"><?= htmlspecialchars((string)($lic['license_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">هیچ ردیفی در فایل نبود.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($unmatchedRns !== []): ?>
    <div class="card-footer d-flex flex-wrap align-items-center gap-2">
        <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/gateway/batch/queue"
            class="d-flex gap-2 flex-grow-1"
            onsubmit="return confirm('برای <?= count($unmatchedRns) ?> ردیف بدون تطبیق، کار جستجوی بالادستی در صف قرار گیرد؟ نتایج پس از اجرای کارگر در صفحهٔ «جستجوی پیشرفته» نمایش داده می‌شوند.');">
            <?= $csrfField ?>
            <input type="hidden" name="request_numbers" value="<?= htmlspecialchars($unmatchedRnList, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-warning btn-sm">
                <i class="bi bi-cloud-arrow-down"></i>
                جستجوی بالادستی برای <?= count($unmatchedRns) ?> ردیف بدون تطبیق
            </button>
            <span class="small text-muted ms-auto align-self-center">
                یک کار <code>track_fetch</code> به ازای هر شماره مجوز در صف قرار می‌گیرد. کارگر در صفحهٔ «وضعیت همگام‌سازی» کارها را اجرا می‌کند.
            </span>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif;
