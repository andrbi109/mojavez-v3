<?php
/**
 * admin/license_requests.php — admin list of license access requests.
 *
 * Phase 15 (v1.6.0): rendered by LicenseController::requests(). Each
 * row shows the applicant's tracking code + name + whether the request
 * was a first-access or a re-access + whether it requires payment +
 * the status. Admin can approve / reject via a small inline form.
 *
 * Expected $data:
 *   $rows  array<int, array<string,mixed>> — the license_access_requests
 *          rows joined with applicants.
 *   $baseUrl, $csrfField.
 */
/** @var array<int, array<string,mixed>> $rows */
/** @var string $baseUrl */
/** @var string $csrfField */

$rows      ??= [];
$baseUrl   ??= '';
$csrfField ??= '';
?>
<div class="card shadow-sm">
    <div class="card-header bg-white">
        <h1 class="h5 mb-0">
            <i class="bi bi-shield-lock text-brand"></i>
            درخواست‌های دسترسی به تصویر مجوز
        </h1>
    </div>
    <div class="card-body p-0">
        <?php if ($rows === []): ?>
            <div class="p-4 text-muted">هیچ درخواستی ثبت نشده است.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>کد رهگیری</th>
                            <th>متقاضی</th>
                            <th>نوع درخواست</th>
                            <th>مبلغ</th>
                            <th>وضعیت</th>
                            <th>انقضا</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $status = (string)($r['status'] ?? '');
                            $badge = match ($status) {
                                'تأیید شد' => 'success',
                                'رد شد'    => 'danger',
                                default    => 'warning',
                            };
                            $isFirst = !empty($r['is_first_access']);
                            $hasPay = !empty($r['requires_payment']);
                            ?>
                            <tr>
                                <td><?= (int)$r['id'] ?></td>
                                <td>
                                    <code class="user-select-all" dir="ltr">
                                        <?= htmlspecialchars((string)($r['tracking_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </code>
                                </td>
                                <td><?= htmlspecialchars(
                                    trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?></td>
                                <td>
                                    <?= $isFirst ? '<span class="badge text-bg-info">دسترسی نخست</span>' : '<span class="badge text-bg-secondary">دسترسی مجدد</span>' ?>
                                    <?php if ($hasPay): ?>
                                        <span class="badge text-bg-warning">نیازمند پرداخت</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $hasPay ? htmlspecialchars((string)($r['amount'] ?? '0'), ENT_QUOTES) . ' تومان' : '—' ?></td>
                                <td>
                                    <span class="badge text-bg-<?= $badge ?>">
                                        <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td class="text-muted small" dir="ltr">
                                    <?= htmlspecialchars((string)($r['expires_at'] ?? '—'), ENT_QUOTES) ?>
                                </td>
                                <td>
                                    <?php if ($status === 'در انتظار'): ?>
                                        <form method="post"
                                              action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/license-requests/review"
                                              class="d-flex gap-1">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" name="decision" value="approve"
                                                    class="btn btn-success btn-sm">
                                                <i class="bi bi-check2"></i> تأیید
                                            </button>
                                            <button type="submit" name="decision" value="reject"
                                                    class="btn btn-danger btn-sm">
                                                <i class="bi bi-x"></i> رد
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">بررسی شده</span>
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
