<?php
/**
 * admin/fee_tariff/index.php — list all fee tariffs + fee_groups + items.
 *
 * Phase 54 (v2.2.0) — the page is restructured around a per-service ×
 * per-group matrix so the admin can enable/disable each service in each
 * of the 4 decile groups (A=1-2, B=3-4, C=5-6, D=7-10) with a checkbox.
 *
 * Sections:
 *   1. Legacy `fee_tariffs` table (per-service-type decile base_amount).
 *      Each row has edit + delete buttons. The "تعرفهٔ جدید" button at
 *      the top opens the edit form for a fresh record. Kept for
 *      backwards-compat with the per-service-type decile tariff that
 *      pre-dates fee_groups.
 *
 *   2. "تعرفهٔ پایه — سرویس‌ها و گروه‌ها" matrix section. Each row is
 *      one unique service (item_key across all fee_groups). For every
 *      fee_group, the row has a checkbox (enabled) + an amount input.
 *      Submitting the "ذخیره ماتریس" button POSTs to
 *      /admin/fee-tariff/services/matrix-save, which upserts the
 *      fee_group_items rows that are checked and deletes the ones that
 *      aren't. The "افزودن سرویس جدید" form below the matrix adds a
 *      brand-new item_key to ALL 4 groups at once (Phase 54 requirement:
 *      "When a new service is added, it should be auto-added to all 4
 *      groups (A, B, C, D)").
 *
 *   3. Per-group cards (read-only summary). For each fee_group, a card
 *      lists the items currently in that group + the per-group total
 *      (the sum FeeCalculatorService charges for an applicant whose
 *      income decile falls in the group's range). The Phase-48 inline
 *      "add item" form is removed — the matrix is now the canonical UI.
 *
 * View variables:
 *   @var array<int,array<string,mixed>> $rows          legacy fee_tariffs rows
 *   @var array<int,array<string,mixed>> $groups        fee_groups with items_total
 *   @var array<int,array<int,array<string,mixed>>> $itemsByGroup  fee_group_items grouped by fee_group_id
 *   @var array<int,array<string,mixed>> $services      Phase 54 master list of services with per-group state
 *   @var string                         $csrfField
 *   @var string                         $baseUrl
 */
/** @var array<int,array<string,mixed>> $rows */
/** @var array<int,array<string,mixed>> $groups */
/** @var array<int,array<int,array<string,mixed>>> $itemsByGroup */
/** @var array<int,array<string,mixed>> $services */
/** @var string $csrfField */
/** @var string $baseUrl */

$groups       ??= [];
$itemsByGroup ??= [];
$services     ??= [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-cash-stack"></i> تعرفهٔ هزینه</h2>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff/new" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> تعرفهٔ جدید
    </a>
</div>

<!-- Phase 54 (v2.2.0): explain how the three sections relate. -->
<div class="alert alert-info small py-2 mb-3 d-flex align-items-start gap-2">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div>
        این صفحه شامل سه بخش است:
        <strong>۱) تعرفهٔ پایهٔ قدیمی</strong> به ازای هر نوع خدمت و دهک درآمد (جدول بالا)؛
        <strong>۲) ماتریس سرویس × گروه</strong> که هر سرویس را با چک‌باکس برای هر یک از
        چهار گروه دهک (A=۱-۲، B=۳-۴، C=۵-۶، D=۷-۱۰) نمایش می‌دهد — فعال/غیرفعال
        کردن هر چک‌باکس، عضویت سرویس در آن گروه را تعیین می‌کند؛
        <strong>۳) خلاصهٔ هر گروه</strong> در پایین که مجموع مبلغ آیتم‌های فعال هر
        گروه را نشان می‌دهد. مبلغ نهایی متقاضی = مجموع آیتم‌های فعالِ گروهِ دهکِ اوست.
        روستاییانِ واجد شرایط از پرداخت معاف می‌شوند (تنظیم <code>fee_rural_auto_eligible</code>).
    </div>
</div>

<h3 class="h6 mb-2"><i class="bi bi-list-ul"></i> ۱. تعرفهٔ پایهٔ قدیمی به ازای نوع خدمت / دهک</h3>
<div class="card mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>نوع خدمت</th>
                        <th>دهک</th>
                        <th>مبلغ پایه</th>
                        <th>معتبر از</th>
                        <th>معتبر تا</th>
                        <th>وضعیت</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $isActive = (int)$r['is_active'] === 1; ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= htmlspecialchars((string)($r['service_type_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td dir="ltr"><?= (int)$r['decile_min'] ?> – <?= (int)$r['decile_max'] ?></td>
                        <td dir="ltr"><?= htmlspecialchars(number_format((int)$r['base_amount']), ENT_QUOTES, 'UTF-8') ?> <span class="text-muted small">تومان</span></td>
                        <td dir="ltr" class="small text-muted"><?= htmlspecialchars((string)($r['valid_from'] ?? '—') ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td dir="ltr" class="small text-muted"><?= htmlspecialchars((string)($r['valid_until'] ?? '—') ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($isActive): ?><span class="badge text-bg-success">فعال</span><?php else: ?><span class="badge text-bg-secondary">غیرفعال</span><?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff/<?= (int)$r['id'] ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff/<?= (int)$r['id'] ?>/delete" class="d-inline" onsubmit="return confirm('حذف این تعرفه؟')">
                                <?= $csrfField ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">هیچ تعرفه‌ای ثبت نشده.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<h3 class="h6 mb-2" id="services-matrix"><i class="bi bi-grid-3x3-gap"></i> ۲. ماتریس سرویس × گروه (تعرفهٔ پایهٔ جدید)</h3>

<?php if (empty($groups)): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle"></i>
        هنوز گروه نرخ‌نامه‌ای تعریف نشده. گروه‌ها ازطریق مهاجرت‌های پایگاه داده یا
        اسکریپت‌های seed ایجاد می‌شوند. هر گروه یک <code>code</code> یکتا،
        <code>decile_min..decile_max</code> و مجموع آیتم‌ها دارد.
    </div>
<?php else: ?>

<?php
/**
 * Phase 54 — pre-compute group headers (code + decile range) so the
 * matrix's column headers and per-row checkbox labels are consistent
 * across the matrix and the per-group cards below.
 */
$groupHeaders = [];
foreach ($groups as $g) {
    $groupHeaders[(int)$g['id']] = [
        'code'    => (string)$g['code'],
        'label'   => (string)$g['label'],
        'dec_min' => (int)$g['decile_min'],
        'dec_max' => (int)$g['decile_max'],
    ];
}
?>

<form method="post"
      action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff/services/matrix-save"
      id="services-matrix-form">
    <?= $csrfField ?>

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-grid-3x3-gap"></i>
            سرویس‌ها و گروه‌ها
            <span class="text-muted small ms-2">
                هر سطر یک سرویس (item_key) و هر ستون یک گروه دهک است. تیک زدن چک‌باکس،
                سرویس را در آن گروه فعال می‌کند؛ برداشتن تیک، آن را از گروه حذف می‌نماید.
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>item_key</th>
                            <th>برچسب خدمت</th>
                            <th>ترتیب</th>
                            <?php foreach ($groupHeaders as $gh):
                                $ghCode = htmlspecialchars($gh['code'], ENT_QUOTES, 'UTF-8');
                                $ghLabel = htmlspecialchars($gh['label'], ENT_QUOTES, 'UTF-8');
                            ?>
                                <th class="text-center" title="<?= $ghLabel ?>">
                                    <span class="badge text-bg-light text-body border" dir="ltr"><?= $ghCode ?></span>
                                    <div class="small text-muted">دهک <?= $gh['dec_min'] ?>–<?= $gh['dec_max'] ?></div>
                                </th>
                            <?php endforeach; ?>
                            <th class="text-end">حذف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($services)): ?>
                            <tr><td colspan="<?= 5 + count($groupHeaders) ?>" class="text-center text-muted py-4">
                                <i class="bi bi-inbox"></i>
                                هنوز سرویسی ثبت نشده. با فرم پایین یک سرویس جدید اضافه کنید
                                (به‌صورت خودکار در هر ۴ گروه ایجاد می‌شود).
                            </td></tr>
                        <?php endif; ?>

                        <?php $rowIdx = 0; foreach ($services as $svc):
                            $rowIdx++;
                            $svcKey   = (string)$svc['item_key'];
                            $svcLabel = (string)$svc['item_label'];
                            $svcSort  = (int)$svc['sort_order'];
                            $svcNotes = (string)$svc['notes'];
                            $keyId    = 'svc-' . htmlspecialchars($svcKey, ENT_QUOTES);
                        ?>
                        <tr>
                            <td><?= $rowIdx ?></td>
                            <td dir="ltr"><code><?= htmlspecialchars($svcKey, ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td>
                                <input type="text" class="form-control form-control-sm"
                                       name="services[<?= htmlspecialchars($svcKey, ENT_QUOTES) ?>][label]"
                                       value="<?= htmlspecialchars($svcLabel, ENT_QUOTES, 'UTF-8') ?>"
                                       required maxlength="150">
                            </td>
                            <td>
                                <input type="number" class="form-control form-control-sm"
                                       style="min-width: 70px"
                                       name="services[<?= htmlspecialchars($svcKey, ENT_QUOTES) ?>][sort]"
                                       value="<?= $svcSort ?>" min="0">
                            </td>
                            <?php foreach ($groupHeaders as $gid => $gh):
                                $gState    = $svc['groups'][$gid] ?? ['enabled' => false, 'amount' => 0, 'row_id' => null];
                                $isEnabled = (bool)$gState['enabled'];
                                $gAmount  = (int)($gState['amount'] ?? 0);
                                $cbId     = $keyId . '-g' . $gid;
                            ?>
                            <td class="text-center">
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <div class="form-check">
                                        <input class="form-check-input svc-group-enabled" type="checkbox"
                                               id="<?= $cbId ?>"
                                               name="services[<?= htmlspecialchars($svcKey, ENT_QUOTES) ?>][groups][<?= $gid ?>][enabled]"
                                               value="1"
                                               data-amount-input="<?= $keyId ?>-amt-<?= $gid ?>"
                                               <?= $isEnabled ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="<?= $cbId ?>">
                                            <span class="badge text-bg-<?= $isEnabled ? 'success' : 'secondary' ?> small" dir="ltr">
                                                <?= htmlspecialchars($gh['code'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </label>
                                    </div>
                                    <input type="number" class="form-control form-control-sm text-center"
                                           id="<?= $keyId ?>-amt-<?= $gid ?>"
                                           name="services[<?= htmlspecialchars($svcKey, ENT_QUOTES) ?>][groups][<?= $gid ?>][amount]"
                                           value="<?= $gAmount ?>" min="0" step="1"
                                           style="min-width: 95px"
                                           <?= $isEnabled ? '' : 'disabled' ?>>
                                    <div class="small text-muted">تومان</div>
                                </div>
                            </td>
                            <?php endforeach; ?>
                            <td class="text-end">
                                <form method="post"
                                      action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff/services/delete"
                                      class="d-inline"
                                      onsubmit="return confirm('سرویس «<?= htmlspecialchars($svcKey, ENT_QUOTES, 'UTF-8') ?>» از همهٔ گروه‌ها حذف شود؟');">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="item_key" value="<?= htmlspecialchars($svcKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0" title="حذف از همهٔ گروه‌ها">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php if ($svcNotes !== ''): ?>
                        <tr class="table-light">
                            <td colspan="<?= 5 + count($groupHeaders) ?>" class="small text-muted">
                                <i class="bi bi-sticky"></i>
                                یادداشت <code dir="ltr"><?= htmlspecialchars($svcKey, ENT_QUOTES, 'UTF-8') ?></code>:
                                <?= htmlspecialchars($svcNotes, ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-light d-flex align-items-center gap-2">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-check-lg"></i> ذخیرهٔ ماتریس
            </button>
            <span class="small text-muted">
                چک‌باکس‌های تیک‌خورده در پایگاه دادهٔ <code>fee_group_items</code> ذخیره می‌شوند؛
                چک‌باکس‌های تیک‌نخورده از همهٔ گروه‌ها حذف می‌گردند.
            </span>
        </div>
    </div>
</form>

<!-- Phase 54 — add a NEW service. The handler inserts the same item_key
     into every fee_group, so the new service appears in all 4 groups
     by default (the admin can then uncheck individual groups in the
     matrix above to remove it from specific groups). -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-plus-lg"></i> افزودن سرویس جدید (به‌صورت خودکار در همهٔ ۴ گروه)</div>
    <div class="card-body">
        <form method="post"
              action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff/services/add"
              class="row g-2">
            <?= $csrfField ?>
            <div class="col-md-3">
                <label class="form-label small mb-0">item_key <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm" name="item_key"
                       placeholder="مثلاً new_request" required dir="ltr"
                       pattern="[A-Za-z][A-Za-z0-9_]{0,49}" maxlength="50">
                <div class="form-text small text-muted">
                    حروف انگلیسی، عدد و زیرخط. باید با حرف شروع شود. یکتا در کل مجموعهٔ گروه‌ها.
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-0">برچسب خدمت <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm" name="item_label"
                       placeholder="مثلاً «ثبت درخواست»" required maxlength="150">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">مبلغ پیش‌فرض (تومان)</label>
                <input type="number" class="form-control form-control-sm" name="amount"
                       value="0" min="0" step="1">
                <div class="form-text small text-muted">همهٔ ۴ گروه با همین مبلغ آغاز می‌شوند.</div>
            </div>
            <div class="col-md-1">
                <label class="form-label small mb-0">ترتیب</label>
                <input type="number" class="form-control form-control-sm" name="sort_order"
                       value="0" min="0">
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg"></i> افزودن سرویس به همهٔ گروه‌ها
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<h3 class="h6 mb-2"><i class="bi bi-collection"></i> ۳. خلاصهٔ هر گروه (مجموع آیتم‌های فعال)</h3>

<?php foreach ($groups as $g):
    $gid         = (int)$g['id'];
    $gCode       = (string)$g['code'];
    $gLabel      = (string)$g['label'];
    $gActive     = (int)$g['is_active'] === 1;
    $gDecMin     = (int)$g['decile_min'];
    $gDecMax     = (int)$g['decile_max'];
    $gTotal      = (int)($g['items_total'] ?? 0);
    $gItems      = $itemsByGroup[$gid] ?? [];
    $gSort       = (int)$g['sort_order'];
?>
<div class="card mb-3" id="group-<?= $gid ?>">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-tags"></i>
        <span class="badge text-bg-light text-body border" dir="ltr"><?= htmlspecialchars($gCode, ENT_QUOTES, 'UTF-8') ?></span>
        <strong><?= htmlspecialchars($gLabel, ENT_QUOTES, 'UTF-8') ?></strong>
        <span class="text-muted small">دهک‌های <?= $gDecMin ?> تا <?= $gDecMax ?></span>
        <?php if ($gActive): ?>
            <span class="badge text-bg-success">فعال</span>
        <?php else: ?>
            <span class="badge text-bg-secondary">غیرفعال</span>
        <?php endif; ?>
        <span class="text-muted small ms-1">ترتیب: <?= $gSort ?></span>
        <span class="badge text-bg-info ms-auto" title="مجموع مبلغ آیتم‌های فعال">
            مجموع: <?= htmlspecialchars(number_format($gTotal), ENT_QUOTES, 'UTF-8') ?> تومان
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>item_key</th>
                        <th>برچسب (نام خدمت)</th>
                        <th>مبلغ</th>
                        <th>ترتیب</th>
                        <th>یادداشت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $itemSeq = 0; foreach ($gItems as $it):
                        $itemSeq++;
                        $itKey = (string)$it['item_key'];
                        $itLbl = (string)$it['item_label'];
                        $itAmt = (int)$it['amount'];
                        $itSrt = (int)$it['sort_order'];
                        $itNts = (string)($it['notes'] ?? '');
                    ?>
                    <tr>
                        <td><?= $itemSeq ?></td>
                        <td><code><?= htmlspecialchars($itKey, ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= htmlspecialchars($itLbl, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(number_format($itAmt), ENT_QUOTES, 'UTF-8') ?> <span class="text-muted small">تومان</span></td>
                        <td><?= $itSrt ?></td>
                        <td class="small text-muted"><?= $itNts !== '' ? htmlspecialchars($itNts, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($gItems)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">
                            <i class="bi bi-inbox"></i>
                            این گروه هنوز آیتمی ندارد. از ماتریس بالا چک‌باکس این گروه را
                            برای سرویس‌های مورد نظر فعال کنید.
                        </td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="3" class="text-end">مجموع آیتم‌ها</th>
                        <th dir="ltr"><?= htmlspecialchars(number_format($gTotal), ENT_QUOTES, 'UTF-8') ?> <span class="text-muted small">تومان</span></th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
(function () {
    // Phase 54 — toggle the per-group amount input's disabled state
    // when the admin (un)checks the corresponding checkbox in the
    // matrix. The server reads both fields; an unchecked + disabled
    // input is silently ignored (the amount defaults to 0 on the
    // server side anyway, and the row is deleted when enabled=0).
    document.querySelectorAll('input.svc-group-enabled[type="checkbox"]').forEach(function (cb) {
        var amtInputId = cb.getAttribute('data-amount-input');
        if (!amtInputId) return;
        var amtInput = document.getElementById(amtInputId);
        if (!amtInput) return;

        function sync() {
            amtInput.disabled = !cb.checked;
            // Visual feedback: badge color changes via the form-check
            // label's <span class="badge text-bg-...">. The CSS class
            // swap below mirrors the server-side rendering.
            var label = cb.closest('.form-check');
            if (label) {
                var badge = label.querySelector('.badge');
                if (badge) {
                    badge.classList.remove('text-bg-success', 'text-bg-secondary');
                    badge.classList.add(cb.checked ? 'text-bg-success' : 'text-bg-secondary');
                }
            }
        }

        cb.addEventListener('change', sync);
        // No need to call sync() on load — the server already renders
        // the correct initial state via the `disabled` attribute and
        // the badge class.
    });
})();
</script>
