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
?>
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
                                                                ?>
                                                                    <div class="form-check border rounded p-2 mb-2 <?= $first ? 'border-success border-opacity-50' : '' ?>">
                                                                        <input class="form-check-input" type="radio"
                                                                               name="<?= htmlspecialchars($radioName, ENT_QUOTES) ?>"
                                                                               id="<?= htmlspecialchars($radioId, ENT_QUOTES) ?>"
                                                                               value="<?= $chId ?>"
                                                                               data-pay-info-id="<?= htmlspecialchars($infoId, ENT_QUOTES) ?>"
                                                                               <?= $first ? 'checked' : '' ?>>
                                                                        <label class="form-check-label w-100" for="<?= htmlspecialchars($radioId, ENT_QUOTES) ?>">
                                                                            <span class="fw-semibold"><?= htmlspecialchars($chTitle, ENT_QUOTES, 'UTF-8') ?></span>
                                                                            <span class="badge text-bg-light text-muted ms-1"><?= htmlspecialchars($chType, ENT_QUOTES, 'UTF-8') ?></span>
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
                                                            <div class="alert alert-info small mb-0" role="alert">
                                                                <i class="bi bi-info-circle"></i>
                                                                پس از پرداخت از طریق کانال انتخاب‌شده، رسید را از طریق پنل کاربری تحویل دهید تا مدیر بررسی و تأیید کند.
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="alert alert-info small mb-0" role="alert">
                                                                <i class="bi bi-info-circle"></i>
                                                                درگاه آنلاین پرداخت به‌زودی فعال خواهد شد. تا آن زمان می‌توانید رسید پرداخت را از طریق پنل کاربری تحویل دهید.
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <?php if (!empty($channels)): ?>
                                                            <button type="button" class="btn btn-success"
                                                                    onclick="alert('پس از پرداخت، رسید را از طریق پنل کاربری تحویل دهید. این مرحله در فاز بعد به‌صورت خودکار متصل خواهد شد.');">
                                                                <i class="bi bi-check2-circle"></i> تأیید و ادامه
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-success" disabled
                                                                    title="به‌زودی فعال خواهد شد">
                                                                <i class="bi bi-globe"></i> پرداخت آنلاین (به‌زودی)
                                                            </button>
                                                        <?php endif; ?>
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
(function () {
    'use strict';
    document.querySelectorAll('.modal[id^="payModal"]').forEach(function (modalEl) {
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
        });
    });
})();
</script>
<?php endif;
