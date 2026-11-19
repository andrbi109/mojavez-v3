<?php
/**
 * admin/postal_requests.php — list of postal-code change requests.
 *
 * Phase 17 (v1.8.0): rendered by AdminController::postalRequests().
 * Lists `postal_code_changes` rows joined with applicants, filtered
 * by status (default: 'در انتظار'). Each pending row has Approve /
 * Reject buttons. Reject opens a small inline form for the reason.
 *
 * @var array<int, array<string,mixed>> $rows
 * @var string $statusFilter
 * @var int    $pendingCount
 * @var string $csrfField
 * @var string $baseUrl
 */
/** @var array<int, array<string,mixed>> $rows */
/** @var string $statusFilter */
/** @var int $pendingCount */
/** @var string $csrfField */
/** @var string $baseUrl */

$rows         ??= [];
$statusFilter ??= 'در انتظار';
$pendingCount ??= 0;
$csrfField    ??= '';
$baseUrl      ??= '/';

$badgeFor = static function (string $status): string {
    return match ($status) {
        'در انتظار' => 'warning',
        'تأیید'    => 'success',
        'رد'        => 'danger',
        default     => 'secondary',
    };
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-mailbox"></i> درخواست‌های تغییر کد پستی</h2>
    <span class="text-muted small">
        <?= htmlspecialchars((string)$pendingCount, ENT_QUOTES, 'UTF-8') ?> درخواست در انتظار
    </span>
</div>

<ul class="nav nav-pills mb-3">
    <?php foreach (['در انتظار', 'تأیید', 'رد', ''] as $sf): ?>
        <li class="nav-item">
            <a class="nav-link <?= $statusFilter === $sf ? 'active' : '' ?>"
               href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/postal-requests?status=<?= htmlspecialchars(rawurlencode($sf), ENT_QUOTES) ?>">
                <?= $sf === '' ? 'همه' : htmlspecialchars($sf, ENT_QUOTES, 'UTF-8') ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="card">
    <div class="card-body p-0">
        <?php if ($rows === []): ?>
            <div class="p-4 text-muted text-center">هیچ درخواستی یافت نشد.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>کد رهگیری</th>
                            <th>متقاضی</th>
                            <th>کد پستی قدیم</th>
                            <th>کد پستی جدید</th>
                            <th>وضعیت</th>
                            <th>تاریخ درخواست</th>
                            <th class="text-end">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $status = (string)($r['status'] ?? '');
                                $badge = $badgeFor($status);
                                $applicantId = (int)$r['applicant_id'];
                                $requestId = (int)$r['id'];
                            ?>
                            <tr>
                                <td><?= $requestId ?></td>
                                <td>
                                    <code class="user-select-all">
                                        <?= htmlspecialchars((string)$r['tracking_code'], ENT_QUOTES, 'UTF-8') ?>
                                    </code>
                                </td>
                                <td>
                                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/view/<?= $applicantId ?>">
                                        <?= htmlspecialchars(
                                            trim((string)$r['first_name'] . ' ' . (string)$r['last_name']),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars((string)$r['old_postal_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars((string)$r['new_postal_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <td>
                                    <span class="badge text-bg-<?= $badge ?>">
                                        <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if ($status === 'رد' && !empty($r['reject_reason'])): ?>
                                        <div class="text-muted small mt-1">
                                            <?= htmlspecialchars((string)$r['reject_reason'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small">
                                    <?= htmlspecialchars((string)$r['requested_at'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($status === 'در انتظار'): ?>
                                        <form method="post"
                                              action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/postal-requests/approve"
                                              class="d-inline">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success"
                                                    onclick="return confirm('تأیید درخواست و اعمال کد پستی جدید؟')">
                                                <i class="bi bi-check-lg"></i> تأیید
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#reject-<?= $requestId ?>"
                                                aria-expanded="false" aria-controls="reject-<?= $requestId ?>">
                                            <i class="bi bi-x-lg"></i> رد
                                        </button>
                                        <form method="post"
                                              action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/postal-requests/reject"
                                              class="collapse mt-2"
                                              id="reject-<?= $requestId ?>">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" name="reason"
                                                       placeholder="دلیل رد (اختیاری)" maxlength="255">
                                                <button type="submit" class="btn btn-danger">ثبت رد</button>
                                            </div>
                                        </form>
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
