<?php
/**
 * user/payments.php — لیست پرداخت‌های کاربر (Phase 9).
 */
/** @var array $payments */
/** @var string $baseUrl */
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <?php
                                $status = (string)($p['status'] ?? '');
                                $badge = match ($status) {
                                    'approved', 'پرداخت‌شده' => 'success',
                                    'rejected' => 'danger',
                                    'pending', 'در انتظار' => 'warning',
                                    default => 'secondary',
                                };
                                $amount = (int)($p['amount'] ?? 0);
                            ?>
                            <tr>
                                <td>#<?= (int)($p['id'] ?? 0) ?></td>
                                <td><?= htmlspecialchars((string)($p['purpose'] ?? ($p['description'] ?? '—')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format($amount) ?> <small class="text-muted">ریال</small></td>
                                <td><span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($status ?: '—', ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="small text-muted"><?= htmlspecialchars((string)($p['created_at'] ?? ''), ENT_QUOTES) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
