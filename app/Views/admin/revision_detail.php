<?php
/**
 * admin/revision_detail.php — admin side-by-side detail view of a
 * single revision request.
 *
 * Phase 15 (v1.6.0): rendered by RevisionController::detail(). The
 * admin sees the current applicants values next to the requested
 * changes (the payload JSON), plus approve / reject forms.
 *
 * Expected $data:
 *   $row     array<string,mixed> — the applicant_revisions row joined
 *             with the applicants row (tracking_code, first_name, etc.).
 *   $payload array<string,mixed> — the decoded JSON payload.
 *   $baseUrl, $csrfField.
 */
/** @var array<string,mixed> $row */
/** @var array<string,mixed> $payload */
/** @var string $baseUrl */
/** @var string $csrfField */

$row      ??= [];
$payload  ??= [];
$baseUrl  ??= '';
$csrfField ??= '';

$fields = [
    'first_name'    => 'نام',
    'last_name'     => 'نام خانوادگی',
    'national_code' => 'کد ملی',
    'father_name'   => 'نام پدر',
    'mobile'        => 'موبایل',
];
?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h1 class="h5 mb-0">
            <i class="bi bi-clipboard-check text-brand"></i>
            درخواست بازبینی #<?= (int)($row['id'] ?? 0) ?>
        </h1>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/revisions"
           class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-return-right"></i> بازگشت به فهرست
        </a>
    </div>
    <div class="card-body p-4">
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="text-muted small">کد رهگیری</div>
                <code class="user-select-all" dir="ltr">
                    <?= htmlspecialchars((string)($row['tracking_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </code>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">وضعیت</div>
                <span class="badge text-bg-secondary">
                    <?= htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">تاریخ درخواست</div>
                <span dir="ltr"><?= htmlspecialchars((string)($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

        <h2 class="h6 mb-3">مقایسهٔ اطلاعات فعلی با درخواست</h2>
        <div class="table-responsive mb-4">
            <table class="table table-bordered align-middle">
                <thead>
                    <tr>
                        <th>فیلد</th>
                        <th>مقدار فعلی</th>
                        <th>مقدار درخواستی</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fields as $k => $label): ?>
                        <tr>
                            <td class="text-muted"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($row[$k] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if (isset($payload[$k]) && (string)$payload[$k] !== (string)($row[$k] ?? '')): ?>
                                    <span class="text-warning-emphasis">
                                        <?= htmlspecialchars((string)($payload[$k] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($payload['note']) || !empty($row['note'])): ?>
            <div class="mb-4">
                <h2 class="h6">یادداشت کاربر</h2>
                <p class="text-muted small">
                    <?= htmlspecialchars((string)($row['note'] ?? $payload['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (!empty($row['reject_reason'])): ?>
            <div class="alert alert-warning">
                دلیل رد: <?= htmlspecialchars((string)$row['reject_reason'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ((string)($row['status'] ?? '') === 'در انتظار'): ?>
            <div class="d-flex gap-2">
                <form method="post"
                      action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/revisions/approve/<?= (int)($row['id'] ?? 0) ?>">
                    <?= $csrfField ?>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check2-circle"></i> تأیید و اعمال
                    </button>
                </form>
                <form method="post"
                      action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/revisions/reject/<?= (int)($row['id'] ?? 0) ?>">
                    <?= $csrfField ?>
                    <input type="text" class="form-control" name="reject_reason"
                           maxlength="255" placeholder="دلیل رد (اختیاری)">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle"></i> رد درخواست
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
