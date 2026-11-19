<?php
/**
 * admin/license_requests.php — admin list of license access requests.
 *
 * Phase 19 (v1.9.1): rendered by LicenseController::requests(). Each
 * row shows the applicant's tracking code + name + mobile + whether the
 * request was a first-access or a re-access + whether it requires payment
 * + the amount + the status + the 72h expiry + the access_token (for
 * approved rows). Admin can approve / reject via an inline form, and
 * optionally provide a reject_reason via a small inline collapse.
 *
 * Expected $data:
 *   $rows  array<int, array<string,mixed>> — the license_access_requests
 *          rows joined with applicants.
 *   $baseUrl, $csrfField.
 *
 * @var array<int, array<string,mixed>> $rows
 * @var string $baseUrl
 * @var string $csrfField
 */

$rows      ??= [];
$baseUrl   ??= '';
$csrfField ??= '';

$badgeFor = static function (string $status): string {
    return match ($status) {
        'تأیید شد' => 'success',
        'رد شد'    => 'danger',
        default    => 'warning',
    };
};
?>
<div class="card shadow-sm">
    <div class="card-header bg-white">
        <h1 class="h5 mb-0">
            <i class="bi bi-patch-check text-brand"></i>
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
                            <th>موبایل</th>
                            <th>نوع درخواست</th>
                            <th>مبلغ</th>
                            <th>وضعیت</th>
                            <th>انقضا</th>
                            <th>توکن</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $status = (string)($r['status'] ?? '');
                            $badge = $badgeFor($status);
                            $isFirst = !empty($r['is_first_access']);
                            $hasPay = !empty($r['requires_payment']);
                            $token = (string)($r['access_token'] ?? '');
                            $expiresAt = (string)($r['expires_at'] ?? '');
                            $isExpired = $expiresAt !== '' && strtotime($expiresAt) < time();
                            $requestId = (int)$r['id'];
                            ?>
                            <tr class="<?= $status === 'در انتظار' ? 'table-warning' : '' ?>">
                                <td><?= $requestId ?></td>
                                <td>
                                    <code class="user-select-all">
                                        <?= htmlspecialchars((string)($r['tracking_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </code>
                                </td>
                                <td class="text-nowrap">
                                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/view/<?= (int)$r['applicant_id'] ?>">
                                        <?= htmlspecialchars(
                                            trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </a>
                                </td>
                                <td class="small">
                                    <?= htmlspecialchars((string)($r['mobile'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= $isFirst
                                        ? '<span class="badge text-bg-info">دسترسی نخست</span>'
                                        : '<span class="badge text-bg-secondary">دسترسی مجدد</span>' ?>
                                    <?php if ($hasPay): ?>
                                        <span class="badge text-bg-warning">نیازمند پرداخت</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $hasPay
                                        ? htmlspecialchars((string)($r['amount'] ?? '0'), ENT_QUOTES) . ' تومان'
                                        : '—' ?>
                                </td>
                                <td>
                                    <span class="badge text-bg-<?= $badge ?>">
                                        <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if (!empty($r['reject_reason'])): ?>
                                        <div class="text-danger small mt-1">
                                            <?= htmlspecialchars((string)$r['reject_reason'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="small <?= $isExpired ? 'text-danger' : 'text-muted' ?>">
                                    <?= $expiresAt !== '' ? htmlspecialchars($expiresAt, ENT_QUOTES) : '—' ?>
                                    <?php if ($isExpired): ?>
                                        <span class="badge text-bg-danger">منقضی</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($token !== ''): ?>
                                        <code class="user-select-all small" title="توکن دسترسی">
                                            <?= htmlspecialchars(substr($token, 0, 12) . '…', ENT_QUOTES, 'UTF-8') ?>
                                        </code>
                                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>license/view/<?= htmlspecialchars($token, ENT_QUOTES) ?>"
                                           target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($status === 'در انتظار'): ?>
                                        <form method="post"
                                              action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/license-requests/review"
                                              class="d-inline">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="id" value="<?= $requestId ?>">
                                            <button type="submit" name="decision" value="approve"
                                                    class="btn btn-success btn-sm"
                                                    onclick="return confirm('تأیید درخواست و صدور لینک ۷۲ ساعته؟')">
                                                <i class="bi bi-check2"></i> تأیید
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-danger btn-sm"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#reject-lic-<?= $requestId ?>"
                                                aria-expanded="false" aria-controls="reject-lic-<?= $requestId ?>">
                                            <i class="bi bi-x"></i> رد
                                        </button>
                                        <form method="post"
                                              action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/license-requests/review"
                                              class="collapse mt-2"
                                              id="reject-lic-<?= $requestId ?>">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="id" value="<?= $requestId ?>">
                                            <input type="hidden" name="decision" value="reject">
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" name="reject_reason"
                                                       placeholder="دلیل رد (اختیاری)" maxlength="255">
                                                <button type="submit" class="btn btn-danger">ثبت رد</button>
                                            </div>
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
    <div class="card-footer small text-muted">
        <i class="bi bi-info-circle"></i>
        دریافت اول تصویر مجوز رایگان است؛ هر دریافت مجدد نیازمند درخواست و
        تأیید مدیر است. لینک‌های دسترسی توکن تصادفی ۷۲ ساعته دارند.
    </div>
</div>
