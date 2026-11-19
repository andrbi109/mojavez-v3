<?php
/**
 * admin/revisions.php — admin list of all revision requests.
 *
 * Phase 15 (v1.6.0): rendered by RevisionController::index(). Lists
 * every applicant_revisions row joined with the applicants table.
 *
 * Expected $data:
 *   $rows  array<int, array<string,mixed>> — the revision rows.
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
        <h1 class="h5 mb-0"><i class="bi bi-clock-history text-brand"></i> درخواست‌های بازبینی</h1>
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
                            <th>وضعیت</th>
                            <th>یادداشت</th>
                            <th>تاریخ درخواست</th>
                            <th></th>
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
                            ?>
                            <tr>
                                <td><?= (int)$r['id'] ?></td>
                                <td>
                                    <code class="user-select-all">
                                        <?= htmlspecialchars((string)($r['tracking_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </code>
                                </td>
                                <td><?= htmlspecialchars(
                                    trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?></td>
                                <td>
                                    <span class="badge text-bg-<?= $badge ?>">
                                        <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td class="text-muted small">
                                    <?= htmlspecialchars(mb_substr((string)($r['note'] ?? ''), 0, 60), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="text-muted small">
                                    <?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/revisions/view/<?= (int)$r['id'] ?>"
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-eye"></i> مشاهده
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
