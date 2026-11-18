<?php
/**
 * admin/fee_tariff/index.php — list all fee tariffs + fee_groups + items.
 *
 * Phase 48 (v2.1.4): the page now has two sections.
 *
 *   1. The legacy `fee_tariffs` table (per-service-type decile-based
 *      base_amount). Each row has edit + delete buttons. The "تعرفهٔ جدید"
 *      button at the top opens the edit form for a fresh record.
 *
 *   2. The new "نرخ‌نامهٔ گروهی" section: for every `fee_groups` row, a card
 *      is rendered with:
 *        - the group's code, label, decile range, is_active status
 *        - the group's fee_group_items (each item = one concrete service
 *          / sub-service, e.g. "ثبت درخواست", "تغییر کد پستی",
 *          "صدور مجدد مجوز")
 *        - the sum of all item amounts (the total charged by
 *          FeeCalculatorService for an applicant in this decile group)
 *        - an inline "add item" form that posts to
 *          /admin/fee-tariff/items/save. The form's fields use data-*
 *          attributes instead of `name=`; a small JS handler builds the
 *          `items[<key>][...]` request body before submit (PHP parses
 *          nested form-encoded array keys into a nested array, which is
 *          exactly what `itemsSave()` expects).
 *
 * View variables:
 *   @var array<int,array<string,mixed>> $rows          legacy fee_tariffs rows
 *   @var array<int,array<string,mixed>> $groups        fee_groups with items_total
 *   @var array<int,array<string,mixed>> $itemsByGroup  fee_group_items grouped by fee_group_id (keyed by group id)
 *   @var string                         $csrfField
 *   @var string                         $baseUrl
 */
/** @var array<int,array<string,mixed>> $rows */
/** @var array<int,array<string,mixed>> $groups */
/** @var array<int,array<int,array<string,mixed>>> $itemsByGroup */
/** @var string $csrfField */
/** @var string $baseUrl */

$groups       ??= [];
$itemsByGroup ??= [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-cash-stack"></i> تعرفهٔ هزینه</h2>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff/new" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> تعرفهٔ جدید
    </a>
</div>

<!-- Phase 48 (v2.1.4): explain how the two sections relate. -->
<div class="alert alert-info small py-2 mb-3 d-flex align-items-start gap-2">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div>
        این صفحه شامل دو بخش است:
        <strong>۱) تعرفهٔ پایه</strong> به ازای هر نوع خدمت و دهک درآمد (جدول بالا)؛
        <strong>۲) نرخ‌نامهٔ گروهی</strong> که هر «گروه دهک» را با «آیتم‌های خدمت»
        (مانند «ثبت درخواست»، «تغییر کد پستی»، «صدور مجدد مجوز») نمایش می‌دهد.
        مبلغ نهایی که متقاضی پرداخت می‌کند، <strong>مجموع مبلغ آیتم‌های گروه</strong>
        اوست (ستون «مجموع» در هر کارت گروه). روستاییانِ واجد شرایط از پرداخت معاف
        می‌شوند (تنظیم <code>fee_rural_auto_eligible</code>).
    </div>
</div>

<h3 class="h6 mb-2"><i class="bi bi-list-ul"></i> ۱. تعرفهٔ پایه به ازای نوع خدمت / دهک</h3>
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

<h3 class="h6 mb-2"><i class="bi bi-collection"></i> ۲. نرخ‌نامهٔ گروهی (گروه‌های دهک + آیتم‌های خدمت)</h3>

<?php if (empty($groups)): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle"></i>
        هنوز گروه نرخ‌نامه‌ای تعریف نشده. گروه‌ها ازطریق مهاجرت‌های پایگاه داده یا
        اسکریپت‌های seed ایجاد می‌شوند. هر گروه یک <code>code</code> یکتا،
        <code>decile_min..decile_max</code> و مجموع آیتم‌ها دارد.
    </div>
<?php endif; ?>

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
                    <?php foreach ($gItems as $it):
                        $itId  = (int)$it['id'];
                        $itKey = (string)$it['item_key'];
                        $itLbl = (string)$it['item_label'];
                        $itAmt = (int)$it['amount'];
                        $itSrt = (int)$it['sort_order'];
                        $itNts = (string)($it['notes'] ?? '');
                    ?>
                    <tr>
                        <td><?= $itId ?></td>
                        <td dir="ltr"><code><?= htmlspecialchars($itKey, ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= htmlspecialchars($itLbl, ENT_QUOTES, 'UTF-8') ?></td>
                        <td dir="ltr"><?= htmlspecialchars(number_format($itAmt), ENT_QUOTES, 'UTF-8') ?> <span class="text-muted small">تومان</span></td>
                        <td dir="ltr"><?= $itSrt ?></td>
                        <td class="small text-muted"><?= $itNts !== '' ? htmlspecialchars($itNts, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($gItems)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">
                            <i class="bi bi-inbox"></i>
                            هنوز آیتمی برای این گروه ثبت نشده. با فرم زیر یک آیتم اضافه کنید.
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

    <!-- Phase 48: inline "add new fee_group_item" form. Posts to
         itemsSave() with `items[<key>][...]` so the existing bulk-upsert
         handler accepts it. JS below builds the nested name attributes
         from the user-typed item_key before submit. -->
    <div class="card-footer bg-light">
        <form method="post"
              action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff/items/save"
              class="fee-item-add-form row g-2 align-items-end"
              data-group-id="<?= $gid ?>">
            <?= $csrfField ?>
            <input type="hidden" name="group_id" value="<?= $gid ?>">
            <div class="col-md-3">
                <label class="form-label small mb-0">item_key <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm fee-item-key"
                       placeholder="مثلاً postal_code_change"
                       required dir="ltr" pattern="[A-Za-z][A-Za-z0-9_]{0,49}" maxlength="50">
                <div class="form-text small text-muted">حروف انگلیسی، عدد و زیرخط. یکتا در داخل همین گروه.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-0">برچسب خدمت <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm fee-item-label"
                       placeholder="مثلاً «تغییر کد پستی»" required maxlength="150">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">مبلغ (تومان)</label>
                <input type="number" class="form-control form-control-sm fee-item-amount"
                       value="0" min="0" step="1" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">ترتیب</label>
                <input type="number" class="form-control form-control-sm fee-item-sort"
                       value="0" min="0">
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg"></i> افزودن
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script>
(function () {
    // For each "add fee_group_item" form: on submit, read the user-typed
    // item_key + label + amount + sort + notes (if any), build the
    // `items[<key>][...]` field names that itemsSave() expects, then
    // submit as a regular form POST. We use a hidden temp form so we
    // don't have to manipulate the visible form's DOM.
    document.querySelectorAll('form.fee-item-add-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var keyEl   = form.querySelector('.fee-item-key');
            var labelEl = form.querySelector('.fee-item-label');
            var amtEl   = form.querySelector('.fee-item-amount');
            var sortEl  = form.querySelector('.fee-item-sort');
            if (!keyEl || !labelEl || !amtEl || !sortEl) { return; }

            var key = keyEl.value.trim();
            var label = labelEl.value.trim();
            if (key === '' || label === '') {
                alert('item_key و برچسب خدمت الزامی است.');
                return;
            }
            // Reject keys that don't match the same pattern the server enforces.
            if (!/^[A-Za-z][A-Za-z0-9_]{0,49}$/.test(key)) {
                alert('item_key باید با حرف انگلیسی شروع شود و فقط شامل حروف، عدد و زیرخط باشد (حداکثر ۵۰ نویسه).');
                return;
            }

            var csrfEl = form.querySelector('input[name="_csrf"]');
            var groupId = form.getAttribute('data-group-id');
            var action = form.getAttribute('action');

            var hidden = document.createElement('form');
            hidden.method = 'POST';
            hidden.action = action;
            hidden.style.display = 'none';

            function addField(name, value) {
                var i = document.createElement('input');
                i.type = 'hidden';
                i.name = name;
                i.value = value;
                hidden.appendChild(i);
            }
            addField('group_id', groupId);
            addField('items[' + key + '][label]', label);
            addField('items[' + key + '][amount]', String(parseInt(amtEl.value, 10) || 0));
            addField('items[' + key + '][sort]',  String(parseInt(sortEl.value, 10) || 0));
            if (csrfEl) { addField('_csrf', csrfEl.value); }

            document.body.appendChild(hidden);
            hidden.submit();
        });
    });
})();
</script>
