<?php
/** @var array $payments */
/** @var array $filters */
/** @var int $page */
/** @var int $totalPages */
/** @var int $total */
/** @var array $stats */
/** @var array $byChannel */
/** @var array $channels */
/** @var string $message */
/** @var string $csrfField */
/** @var string $baseUrl */
$message  ??= '';
$filters  ??= ['status' => '', 'purpose' => '', 'q' => '', 'from' => '', 'to' => ''];
$stats    ??= [
    'approvedMonth' => 0, 'approvedTotal' => 0, 'approvedCount' => 0,
    'pendingCount'  => 0, 'pendingSum'  => 0,
    'rejectedCount' => 0, 'rejectedSum' => 0,
    'totalCount'    => 0, 'totalAmount' => 0,
];
/**
 * Phase 49 (v2.1.5) — small helper to build a query-string for the
 * CSV export link / pagination links / form reset, preserving the
 * current filter state.
 *
 * @param array<string,string> $overrides
 */
$qs = static function (array $overrides = []) use ($filters): string {
    $parts = [];
    foreach (array_merge($filters, $overrides) as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        $parts[] = rawurlencode($k) . '=' . rawurlencode((string)$v);
    }
    return $parts === [] ? '' : '&' . implode('&', $parts);
};
$csvQs = $qs();  // CSV export preserves the full filter state (status/purpose/q/from/to).
?>
<?php if ($message !== ''): ?>
    <div class="alert alert-info py-2"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- ===== Phase 49 (v2.1.5) — summary stat cards (restructured) =====
     Total amount | Approved (count + sum) | Pending (count + sum) | Rejected (count + sum)
     Each card uses a single primary metric + small secondary detail, so the
     admin gets the gross flow + per-status breakdown at a glance. -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-2">
                <i class="bi bi-wallet2 fs-3 text-secondary"></i>
                <div>
                    <div class="fw-bold"><?= number_format((int)$stats['totalAmount']) ?></div>
                    <small class="text-muted">مبلغ کل (<?= (int)$stats['totalCount'] ?> رسید · تومان)</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-2">
                <i class="bi bi-check-circle fs-3 text-success"></i>
                <div>
                    <div class="fw-bold"><?= (int)$stats['approvedCount'] ?></div>
                    <small class="text-muted">تأیید شده (<?= number_format((int)$stats['approvedTotal']) ?> تومان)</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-2">
                <i class="bi bi-hourglass-split fs-3 text-warning"></i>
                <div>
                    <div class="fw-bold"><?= (int)$stats['pendingCount'] ?></div>
                    <small class="text-muted">در انتظار بررسی (<?= number_format((int)$stats['pendingSum']) ?> تومان)</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-2">
                <i class="bi bi-x-circle fs-3 text-danger"></i>
                <div>
                    <div class="fw-bold"><?= (int)$stats['rejectedCount'] ?></div>
                    <small class="text-muted">رد شده (<?= number_format((int)$stats['rejectedSum']) ?> تومان)</small>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-list-ul"></i> رسیدهای پرداخت (<?= (int)$total ?>)</span>
                <a class="btn btn-sm btn-success" href="/admin/payments/csv?<?= ltrim($csvQs, '&') ?>">
                    <i class="bi bi-download"></i> خروجی CSV
                </a>
            </div>
            <div class="card-body pb-2">
                <form method="get" action="/admin/payments" class="row g-2 align-items-end">
                    <div class="col-12 col-md-6">
                        <label class="form-label small mb-1">جستجو (کد رهگیری / ملی / نام / پیگیری)</label>
                        <input type="text" class="form-control form-control-sm" name="q" value="<?= htmlspecialchars((string)$filters['q'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">وضعیت</label>
                        <select class="form-select form-select-sm" name="status">
                            <?php foreach (['' => 'همه', 'در انتظار رسید' => 'در انتظار', 'تأیید شده' => 'تأییدشده', 'رد شده' => 'ردشده'] as $k => $lbl): ?>
                                <option value="<?= htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">هدف پرداخت</label>
                        <select class="form-select form-select-sm" name="purpose">
                            <option value="">همه</option>
                            <option value="هزینهٔ ثبت‌نام" <?= $filters['purpose'] === 'هزینهٔ ثبت‌نام' ? 'selected' : '' ?>>هزینهٔ ثبت‌نام</option>
                            <option value="هزینهٔ دریافت مجدد تصویر مجوز" <?= $filters['purpose'] === 'هزینهٔ دریافت مجدد تصویر مجوز' ? 'selected' : '' ?>>دریافت مجدد تصویر مجوز</option>
                        </select>
                    </div>
                    <!-- ===== Phase 49 (v2.1.5) — date range filter ===== -->
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">از تاریخ (میلادی)</label>
                        <input type="date" class="form-control form-control-sm" name="from"
                               value="<?= htmlspecialchars((string)$filters['from'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">تا تاریخ (میلادی)</label>
                        <input type="date" class="form-control form-control-sm" name="to"
                               value="<?= htmlspecialchars((string)$filters['to'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i> فیلتر</button>
                        <a class="btn btn-outline-secondary btn-sm" href="/admin/payments">پاک کردن فیلتر</a>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th><th>متقاضی</th><th>هدف</th><th>مبلغ</th>
                                <th class="d-none d-md-table-cell">کانال</th>
                                <th>وضعیت</th><th class="text-end">عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payments as $p): ?>
                            <?php
                                $pb = match ((string)$p['status']) {
                                    'تأیید شده' => 'text-bg-success',
                                    'رد شده'  => 'text-bg-danger',
                                    default   => 'text-bg-warning',
                                };
                            ?>
                            <tr class="<?= $p['status'] === 'در انتظار رسید' ? 'table-warning' : '' ?>">
                                <td><?= (int)$p['id'] ?></td>
                                <td class="text-nowrap">
                                    <a href="/admin/view/<?= (int)$p['applicant_id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars((string)$p['first_name'] . ' ' . (string)$p['last_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                    <div><code style="font-size:.68rem"><?= htmlspecialchars((string)$p['tracking_code'], ENT_QUOTES, 'UTF-8') ?></code></div>
                                </td>
                                <td class="small"><?= htmlspecialchars((string)$p['purpose'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-nowrap"><?= number_format((int)$p['amount']) ?></td>
                                <td class="d-none d-md-table-cell small"><?= htmlspecialchars((string)($p['channel_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="badge <?= $pb ?>"><?= htmlspecialchars((string)$p['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($p['reviewed_at'])): ?>
                                        <div class="small text-muted"><?= htmlspecialchars((string)$p['reviewed_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($p['reject_reason'])): ?>
                                        <div class="small text-danger"><?= htmlspecialchars((string)$p['reject_reason'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap">
                                    <?php if ($p['status'] === 'در انتظار رسید'): ?>
                                        <!-- ===== Phase 49 (v2.1.5) — single "review" button opens a modal =====
                                             that lets the admin approve OR reject with an optional note.
                                             Previously the row had an inline approve button (no note) +
                                             a reject-only modal; consolidating into one review modal makes
                                             the UX consistent and lets the admin attach a note to either
                                             decision. The modal contains ONE <form> with two submit buttons
                                             (name="decision", value="approved"|"rejected") — only the
                                             clicked button's value is submitted, per the HTML spec. -->
                                        <button class="btn btn-sm btn-primary py-0" data-bs-toggle="modal" data-bs-target="#reviewPay<?= (int)$p['id'] ?>">
                                            <i class="bi bi-eye"></i> بررسی
                                        </button>
                                        <div class="modal fade" id="reviewPay<?= (int)$p['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <form method="post" action="/admin/payments/review"
                                                      class="modal-content"
                                                      data-review-form
                                                      data-payment-id="<?= (int)$p['id'] ?>">
                                                    <?= $csrfField ?>
                                                    <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="bi bi-eye"></i>
                                                            بررسی رسید پرداخت #<?= (int)$p['id'] ?>
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="small text-muted mb-2">
                                                            متقاضی:
                                                            <strong><?= htmlspecialchars((string)$p['first_name'] . ' ' . (string)$p['last_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                            · کد رهگیری:
                                                            <code dir="ltr"><?= htmlspecialchars((string)$p['tracking_code'], ENT_QUOTES, 'UTF-8') ?></code>
                                                        </div>
                                                        <div class="small text-muted mb-3">
                                                            مبلغ: <strong><?= number_format((int)$p['amount']) ?></strong> تومان
                                                            · هدف: <?= htmlspecialchars((string)$p['purpose'], ENT_QUOTES, 'UTF-8') ?>
                                                            <?php if (!empty($p['reference_code'])): ?>
                                                                · کد پیگیری: <code dir="ltr"><?= htmlspecialchars((string)$p['reference_code'], ENT_QUOTES, 'UTF-8') ?></code>
                                                            <?php endif; ?>
                                                        </div>
                                                        <label class="form-label">یادداشت بررسی (اختیاری برای تأیید، الزامی برای رد)</label>
                                                        <textarea name="note" class="form-control" rows="3"
                                                                  maxlength="500"
                                                                  data-review-note></textarea>
                                                        <div class="form-text">برای رد، دلیل را وارد کنید (حداکثر ۵۰۰ نویسه).</div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="submit" name="decision" value="approved"
                                                                class="btn btn-success"
                                                                data-review-approve>
                                                            <i class="bi bi-check-lg"></i> تأیید رسید
                                                        </button>
                                                        <button type="submit" name="decision" value="rejected"
                                                                class="btn btn-danger"
                                                                data-review-reject>
                                                            <i class="bi bi-x-lg"></i> رد رسید
                                                        </button>
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">بررسی‌شده توسط <?= htmlspecialchars((string)($p['reviewer_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($payments)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">پرداختی ثبت نشده است.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination pagination-sm justify-content-center mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= max(1, $page - 1) ?><?= $qs() ?>">قبلی</a>
                        </li>
                        <li class="page-item active"><span class="page-link"><?= (int)$page ?> / <?= (int)$totalPages ?></span></li>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= min($totalPages, $page + 1) ?><?= $qs() ?>">بعدی</a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-bar-chart"></i> درآمد تأییدشده بر اساس کانال</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($byChannel as $bc): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center small">
                    <span><?= htmlspecialchars((string)$bc['channel'], ENT_QUOTES, 'UTF-8') ?> <span class="badge text-bg-secondary"><?= (int)$bc['cnt'] ?></span></span>
                    <strong><?= number_format((int)$bc['total']) ?> تومان</strong>
                </li>
                <?php endforeach; ?>
                <?php if (empty($byChannel)): ?><li class="list-group-item text-muted small text-center">داده‌ای نیست</li><?php endif; ?>
            </ul>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-gear"></i> کانال‌های پرداخت</div>
            <div class="card-body">
                <?php foreach ($channels as $ch): ?>
                <div class="border rounded p-2 mb-2 d-flex justify-content-between align-items-start <?= (int)$ch['is_active'] ? '' : 'bg-light' ?>">
                    <div>
                        <strong class="small"><?= htmlspecialchars((string)$ch['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <div>
                            <span class="badge text-bg-secondary"><?= htmlspecialchars((string)$ch['channel_type'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if (!(int)$ch['is_active']): ?><span class="badge text-bg-light text-muted">غیرفعال</span><?php endif; ?>
                        </div>
                    </div>
                    <form method="post" action="/admin/payment-channels/delete" onsubmit="return confirm('کانال حذف شود؟');">
                        <?= $csrfField ?>
                        <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
                <?php endforeach; ?>

                <hr class="my-3">
                <h6 class="fw-bold small"><i class="bi bi-plus"></i> افزودن / ویرایش کانال</h6>
                <form method="post" action="/admin/payment-channels/save" id="channelForm">
                    <?= $csrfField ?>
                    <input type="hidden" name="id" id="ch_id" value="0">
                    <div class="mb-2">
                        <label class="form-label small mb-1">عنوان</label>
                        <input type="text" class="form-control form-control-sm" name="title" id="ch_title" maxlength="100" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small mb-1">نوع</label>
                            <input type="text" class="form-control form-control-sm" name="channel_type" id="ch_type" maxlength="50" required placeholder="مثلاً: کارت بانکی">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1">ترتیب</label>
                            <input type="number" class="form-control form-control-sm" name="sort_order" id="ch_sort" value="100">
                        </div>
                    </div>
                    <div class="form-check form-switch my-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="ch_active" name="is_active" value="1" checked>
                        <label class="form-check-label small" for="ch_active">فعال</label>
                    </div>
                    <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-save"></i> ذخیرهٔ کانال</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===== Phase 49 (v2.1.5) — review-modal form validation =====
     When the admin clicks "رد رسید" inside a review modal, require a
     non-empty note (the controller stores it as `reject_reason`).
     Approve does not require a note. -->
<script>
(function () {
    document.querySelectorAll('form[data-review-form]').forEach(function (form) {
        var note = form.querySelector('[data-review-note]');
        var rejectBtn = form.querySelector('[data-review-reject]');
        if (!note || !rejectBtn) return;
        rejectBtn.addEventListener('click', function (e) {
            if (note.value.trim() === '') {
                e.preventDefault();
                note.classList.add('is-invalid');
                note.focus();
                alert('برای رد رسید، وارد کردن دلیل (یادداشت) الزامی است.');
                return;
            }
            if (!window.confirm('این رسید رد شود؟')) {
                e.preventDefault();
            }
        });
        // Reset is-invalid once the admin starts typing.
        note.addEventListener('input', function () {
            note.classList.remove('is-invalid');
        });
    });
})();
</script>
