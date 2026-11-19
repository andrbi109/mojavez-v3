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
 */
/** @var array $payments */
/** @var string $baseUrl */
/** @var string $csrfField */

use App\Shared\Support\PersianDate;
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
                                                data-bs-target="#payModal<?= (int)($p['id'] ?? 0) ?>">
                                            <i class="bi bi-credit-card"></i> پرداخت
                                        </button>
                                        <!-- Phase 50 placeholder modal — to be replaced by a real
                                             payment form (gateway redirect / receipt upload) in a
                                             later phase. For now it just tells the user the online
                                             payment flow is coming and offers a receipt-upload
                                             button (also a placeholder). -->
                                        <div class="modal fade" id="payModal<?= (int)($p['id'] ?? 0) ?>" tabindex="-1" aria-hidden="true">
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
                                                        <p class="mb-2 text-muted small">
                                                            بابت: <?= htmlspecialchars((string)($p['purpose'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                                        </p>
                                                        <?php if ($description !== ''): ?>
                                                            <p class="mb-2 text-muted small">
                                                                توضیح مدیر: <?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>
                                                            </p>
                                                        <?php endif; ?>
                                                        <div class="alert alert-info small mb-0" role="alert">
                                                            <i class="bi bi-info-circle"></i>
                                                            می‌توانید رسید پرداخت را از طریق پنل کاربری تحویل دهید.
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-success" disabled
                                                                title="غیرفعال">
                                                            <i class="bi bi-globe"></i> پرداخت آنلاین
                                                        </button>
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
