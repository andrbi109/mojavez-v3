<?php
/**
 * user/payments.php — لیست پرداخت‌های کاربر (Phase 9).
 *
 * Phase 50 (v2.1.6) — extended to surface payment requests created by
 *   the admin via AdminController::requestPayment (status='در انتظار'):
 *     - A new "توضیحات" column shows the description the admin entered
 *       in the request-payment modal (separate from the "بابت" column
 *       which shows the canonical `purpose` value).
 *     - For rows with `status === 'در انتظار'` (waiting for the user to
 *       pay), a "پرداخت" button is shown. It opens a placeholder modal
 *       saying the online-payment flow is coming in a later phase — the
 *       button is wired (data-bs-toggle/data-bs-target) so a future
 *       phase can swap the modal body for a real payment form without
 *       touching the table layout.
 *     - The amount column now shows a Toman suffix in small text.
 *     - The badge palette was extended to recognize 'در انتظار رسید'
 *       (different from the new 'در انتظار' — the former means the
 *       user has uploaded a receipt and is awaiting admin review).
 *
 * Phase 57b — the placeholder modal is now a real channel chooser.
 *   All active `payment_channels` rows are loaded by
 *   UserPanelController::payments() and rendered as radio options.
 *   Each radio shows the channel's title + type, and when selected
 *   reveals the channel's `account_info` block (card number / IBAN /
 *   gateway URL / etc.) so the user knows exactly how to pay through
 *   that channel. If no channel is configured, the modal falls back to
 *   the previous informational alert ("درگاه آنلاین به‌زودی…") so the
 *   admin can still request a payment without breaking the user UI.
 *
 * Phase 58 (v2.2.4) — the modal's "تأیید و ادامه" button (which fired
 *   an alert stub) is replaced by a real per-channel UX:
 *   - For online channels (`channel_type === 'online'`): a placeholder
 *     "انتقال به درگاه" link is shown. The actual gateway redirect lands
 *     in a later phase; the receipt will come back from the gateway
 *     callback. No upload form is shown.
 *   - For non-online channels (transfer / cash / etc.): a multipart
 *     `<form>` is shown with a file input (image/PDF), an optional
 *     description field, and a "بارگذاری رسید" submit button. The form
 *     POSTs to `POST /panel/payments/{id}/receipt`
 *     (UserPanelController::uploadReceipt) which saves the file under
 *     `storage/uploads/receipts/{payment_id}/`, sets the payments row
 *     to `status = 'در انتظار رسید'`, then redirects back to this
 *     page with a one-shot success/error flash (rendered at the top of
 *     the card by the `panel_flash` block below).
 */
/** @var array $payments */
/** @var array $channels */
/** @var string $baseUrl */
/** @var string $csrfField */

use App\Shared\Support\PersianDate;

// Phase 57b — guard the channels variable for older callers/tests
// that might render this view without passing channels (defensive —
// the controller always passes it now, but views should be robust
// against partial data).
$channels ??= [];

// Phase 58 (v2.2.4) — consume the one-shot `panel_flash` session value
// set by UserPanelController::uploadReceipt(). Same pattern as
// user/requests.php (deleteApplication / changePostalCode flashes).
// Read once + remove so a page refresh doesn't re-show the alert.
$flash = null;
if ($this->session->has('panel_flash')) {
    $raw = $this->session->get('panel_flash');
    if (is_array($raw) && isset($raw['message'], $raw['type'])) {
        $flash = $raw;
    }
    $this->session->remove('panel_flash');
}
?>
<?php if ($flash !== null): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show mb-3" role="alert">
        <i class="bi bi-info-circle"></i>
        <?= htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="بستن"></button>
    </div>
<?php endif; ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-credit-card-2-front"></i> پرداخت‌های من</span>
        <span class="badge bg-secondary"><?= count($payments) ?> مورد</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($payments)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-receipt display-4"></i>
                <p class="mt-2 mb-0">پرداختی ثبت نشده است.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>شماره</th>
                            <th>بابت</th>
                            <th>مبلغ</th>
                            <th>وضعیت</th>
                            <th>تاریخ</th>
                            <th>توضیحات</th>
                            <th class="text-end">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p):
                            $status = (string)($p['status'] ?? '');
                            // Phase 50 — badge palette now distinguishes:
                            //   'در انتظار'         → warning  (admin requested, awaiting user payment)
                            //   'در انتظار رسید'   → info     (user uploaded receipt, awaiting admin review)
                            //   'تأیید شده'/'approved'/'پرداخت‌شده' → success
                            //   'رد شده'/'rejected' → danger
                            $badge = match ($status) {
                                'approved', 'پرداخت‌شده', 'تأیید شده' => 'success',
                                'rejected', 'رد شده'   => 'danger',
                                'در انتظار'             => 'warning',
                                'در انتظار رسید'         => 'info',
                                'pending'                => 'warning',
                                default                  => 'secondary',
                            };
                            $statusLabel = $status !== '' ? $status : '—';
                            if ($status === 'در انتظار') {
                                $statusLabel = 'در انتظار پرداخت';
                            } elseif ($status === 'در انتظار رسید') {
                                $statusLabel = 'در انتظار بررسی رسید';
                            }
                            $amount = (int)($p['amount'] ?? 0);
                            $description = (string)($p['description'] ?? '');
                            $canPay = ($status === 'در انتظار');
                            $payModalId = 'payModal' . (int)($p['id'] ?? 0);
                        ?>
                            <tr class="<?= $canPay ? 'table-warning' : '' ?>">
                                <td>#<?= (int)($p['id'] ?? 0) ?></td>
                                <td><?= htmlspecialchars((string)($p['purpose'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-nowrap"><?= number_format($amount) ?> <small class="text-muted">تومان</small></td>
                                <td><span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="small text-muted"><?= htmlspecialchars(PersianDate::format((string)($p['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="small">
                                    <?php if ($description !== ''): ?>
                                        <?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ($canPay): ?>
                                        <button type="button" class="btn btn-sm btn-success py-0"
                                                data-bs-toggle="modal"
                                                data-bs-target="#<?= htmlspecialchars($payModalId, ENT_QUOTES) ?>">
                                            <i class="bi bi-credit-card"></i> پرداخت
                                        </button>
                                        <!-- Phase 57b — real channel chooser modal.
                                             All active payment_channels are rendered
                                             as radio options. Selecting one reveals
                                             its account_info (card number / IBAN /
                                             gateway URL) inline. If no channel is
                                             configured, the modal falls back to an
                                             informational alert (same copy as the
                                             Phase-50 placeholder). -->
                                        <div class="modal fade" id="<?= htmlspecialchars($payModalId, ENT_QUOTES) ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="bi bi-credit-card"></i>
                                                            پرداخت درخواست #<?= (int)($p['id'] ?? 0) ?>
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="mb-2">
                                                            مبلغ قابل پرداخت:
                                                            <strong><?= number_format($amount) ?></strong>
                                                            <small class="text-muted">تومان</small>
                                                        </p>
                                                        <p class="mb-3 text-muted small">
                                                            بابت: <?= htmlspecialchars((string)($p['purpose'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                                        </p>
                                                        <?php if ($description !== ''): ?>
                                                            <p class="mb-3 text-muted small">
                                                                توضیح مدیر: <?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>
                                                            </p>
                                                        <?php endif; ?>

                                                        <?php if (!empty($channels)): ?>
                                                            <h6 class="small text-uppercase text-muted mb-2">
                                                                <i class="bi bi-list-check"></i> انتخاب کانال پرداخت
                                                            </h6>
                                                            <div class="mb-2" role="radiogroup" aria-label="کانال پرداخت">
                                                                <?php foreach ($channels as $idx => $ch):
                                                                    $chId = (int)($ch['id'] ?? 0);
                                                                    $chTitle = (string)($ch['title'] ?? '');
                                                                    $chType = (string)($ch['channel_type'] ?? '');
                                                                    $chInfo = (string)($ch['account_info'] ?? '');
                                                                    $radioName = 'pay_channel_' . (int)($p['id'] ?? 0);
                                                                    $radioId = $radioName . '_' . $chId;
                                                                    $infoId = $radioId . '_info';
                                                                    $first = ($idx === 0);
                                                                    // Phase 58 (v2.2.4) — channel_type is a
                                                                    // free-form varchar; the seed uses
                                                                    // 'online' / 'transfer' / 'cash'.
                                                                    // The modal's bottom region (gateway
                                                                    // placeholder vs. receipt upload form)
                                                                    // is toggled by the JS change handler
                                                                    // based on this data-channel-type
                                                                    // attribute. Matching is case-insensitive
                                                                    // so an admin-typed "Online" still
                                                                    // routes to the gateway placeholder
                                                                    // rather than the receipt upload form.
                                                                ?>
                                                                    <div class="form-check border rounded p-2 mb-2 <?= $first ? 'border-success border-opacity-50' : '' ?>">
                                                                        <input class="form-check-input" type="radio"
                                                                               name="<?= htmlspecialchars($radioName, ENT_QUOTES) ?>"
                                                                               id="<?= htmlspecialchars($radioId, ENT_QUOTES) ?>"
                                                                               value="<?= $chId ?>"
                                                                               data-pay-info-id="<?= htmlspecialchars($infoId, ENT_QUOTES) ?>"
                                                                               data-channel-type="<?= htmlspecialchars($chType, ENT_QUOTES) ?>"
                                                                               <?= $first ? 'checked' : '' ?>>
                                                                        <label class="form-check-label w-100" for="<?= htmlspecialchars($radioId, ENT_QUOTES) ?>">
                                                                            <span class="fw-semibold"><?= htmlspecialchars($chTitle, ENT_QUOTES, 'UTF-8') ?></span>
                                                                            <span class="badge text-bg-light text-muted ms-1"><?= htmlspecialchars($chType !== '' ? $chType : '—', ENT_QUOTES, 'UTF-8') ?></span>
                                                                        </label>
                                                                        <div id="<?= htmlspecialchars($infoId, ENT_QUOTES) ?>"
                                                                             class="pay-channel-info small text-muted mt-2 ps-4 <?= $first ? '' : 'd-none' ?>"
                                                                             style="white-space:pre-wrap;line-height:1.5;">
                                                                            <?php if ($chInfo !== ''): ?>
                                                                                <?= htmlspecialchars($chInfo, ENT_QUOTES, 'UTF-8') ?>
                                                                            <?php else: ?>
                                                                                <span class="text-muted">(اطلاعات حساب برای این کانال ثبت نشده است.)</span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>

                                                            <?php
                                                            // Phase 58 (v2.2.4) — the modal body's bottom region
                                                            // switches between two mutually-exclusive blocks
                                                            // based on the selected channel's `channel_type`:
                                                            //   - .pay-channel-online-actions  → shown only for
                                                            //     online channels (placeholder gateway link).
                                                            //   - .pay-receipt-form            → shown only for
                                                            //     non-online channels (multipart upload form).
                                                            // The first radio is checked by default, so the
                                                            // initial visibility is set server-side below based
                                                            // on its $isOnline flag (kept in sync with the JS
                                                            // change handler at the bottom of this file).
                                                            $firstChannelIsOnline = false;
                                                            if (isset($channels[0])) {
                                                                $firstChannelIsOnline = strtolower((string)($channels[0]['channel_type'] ?? '')) === 'online';
                                                            }
                                                            ?>
                                                            <div class="pay-channel-online-actions mt-3 <?= $firstChannelIsOnline ? '' : 'd-none' ?>"
                                                                 data-online-actions>
                                                                <div class="alert alert-info small mb-2" role="alert">
                                                                    <i class="bi bi-info-circle"></i>
                                                                    پس از انتقال به درگاه بانکی، رسید پرداخت به‌صورت خودکار در سیستم ثبت خواهد شد و نیاز به بارگذاری دستی نیست.
                                                                </div>
                                                                <a href="#" class="btn btn-success w-100"
                                                                   onclick="return false;"
                                                                   aria-disabled="true"
                                                                   title="اتصال به درگاه در فاز بعد فعال خواهد شد">
                                                                    <i class="bi bi-globe"></i> انتقال به درگاه پرداخت
                                                                </a>
                                                            </div>

                                                            <form method="post"
                                                                  action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/payments/<?= (int)($p['id'] ?? 0) ?>/receipt"
                                                                  enctype="multipart/form-data"
                                                                  class="pay-receipt-form mt-3 <?= $firstChannelIsOnline ? 'd-none' : '' ?>"
                                                                  data-receipt-form>
                                                                <?= $csrfField ?>
                                                                <div class="mb-2">
                                                                    <label class="form-label small mb-1">
                                                                        فایل رسید (تصویر یا PDF، حداکثر ۲ مگابایت)
                                                                    </label>
                                                                    <input type="file"
                                                                           name="receipt"
                                                                           class="form-control form-control-sm"
                                                                           accept="image/jpeg,image/png,image/webp,application/pdf"
                                                                           required>
                                                                </div>
                                                                <div class="mb-2">
                                                                    <label class="form-label small mb-1">توضیحات (اختیاری)</label>
                                                                    <textarea name="description"
                                                                              class="form-control form-control-sm"
                                                                              rows="2"
                                                                              maxlength="500"
                                                                              placeholder="مثلاً: شماره کارت مبدأ، تاریخ و ساعت پرداخت"></textarea>
                                                                </div>
                                                                <button type="submit" class="btn btn-success w-100">
                                                                    <i class="bi bi-cloud-upload"></i> بارگذاری رسید
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <div class="alert alert-info small mb-0" role="alert">
                                                                <i class="bi bi-info-circle"></i>
                                                                درگاه آنلاین پرداخت به‌زودی فعال خواهد شد. تا آن زمان می‌توانید رسید پرداخت را از طریق پنل کاربری تحویل دهید.
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">بستن</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($payments)): ?>
<script>
// Phase 57b — wire up the channel radios in every payModal. When the
// user selects a radio, hide every .pay-channel-info block in this
// modal and show the one tied to the selected radio (the data-pay-info-id
// attribute carries the id of the info block to reveal). Initial state
// (first radio checked, first info block visible) is set by the
// server-side template above.
//
// Phase 58 (v2.2.4) — the change handler now ALSO toggles the
// bottom-of-modal region between two mutually-exclusive blocks:
//   - .pay-channel-online-actions  (placeholder gateway link) — shown
//     only when the selected channel's data-channel-type === 'online'.
//   - .pay-receipt-form            (multipart upload form) — shown for
//     every other channel_type (transfer / cash / etc.). The form POSTs
//     to /panel/payments/{id}/receipt; the controller saves the uploaded
//     file, updates the payments row to status='در انتظار رسید', and
//     redirects back to /panel/payments with a flash.
(function () {
    'use strict';
    document.querySelectorAll('.modal[id^="payModal"]').forEach(function (modalEl) {
        function updateActionBlock(selectedRadio) {
            var onlineActions = modalEl.querySelector('[data-online-actions]');
            var receiptForm   = modalEl.querySelector('[data-receipt-form]');
            if (!onlineActions || !receiptForm) return;
            var isOnline = selectedRadio
                && String(selectedRadio.dataset.channelType || '').toLowerCase() === 'online';
            if (isOnline) {
                onlineActions.classList.remove('d-none');
                receiptForm.classList.add('d-none');
            } else {
                onlineActions.classList.add('d-none');
                receiptForm.classList.remove('d-none');
            }
        }

        modalEl.addEventListener('change', function (event) {
            var target = event.target;
            if (!target || target.type !== 'radio' || !target.dataset.payInfoId) return;
            // Hide every pay-channel-info inside this modal, then
            // reveal the one matching the selected radio.
            modalEl.querySelectorAll('.pay-channel-info').forEach(function (info) {
                info.classList.add('d-none');
            });
            var show = modalEl.querySelector('#' + target.dataset.payInfoId);
            if (show) show.classList.remove('d-none');
            // Toggle the online-actions / receipt-form blocks based on
            // the newly-selected channel's type.
            updateActionBlock(target);
        });

        // Initialise the bottom block on first render — the server-side
        // template sets the initial d-none class on both blocks based on
        // the first radio's channel_type, but a defensive re-sync here
        // keeps the JS the single source of truth (the server render can
        // drift if someone reorders the channels array).
        var firstRadio = modalEl.querySelector('input[type="radio"][data-channel-type]');
        if (firstRadio) {
            updateActionBlock(firstRadio);
        }
    });
})();
</script>
<?php endif;
