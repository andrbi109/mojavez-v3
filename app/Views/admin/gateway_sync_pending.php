<?php
/**
 * admin/gateway_sync_pending.php — HTML view of /admin/sync/pending.
 *
 * Phase 41 (v2.0.7) — converted from JSON to HTML. Renders the operator-
 * flagged matches pending review as an HTML table with approve/reject
 * buttons (POST /admin/sync/resolve).
 *
 * Each pending row from SyncAuditLogRepository::pendingOperatorReview():
 *   id, applicant_id, national_request_number, action, outcome,
 *   confidence, context (JSON), created_at.
 *
 * Expected $data:
 *   $pending  array<int,array<string,mixed>>  — rows from sync_audit_log.
 *   $limit    int  — the LIMIT applied to the query.
 *   $baseUrl, $csrfField.
 */
/** @var array<int,array<string,mixed>> $pending */
/** @var int $limit */
/** @var string $baseUrl */
/** @var string $csrfField */

$limit ??= 50;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-hourglass-split text-brand"></i> تطبیق‌های در انتظار بررسی</h2>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/status"
        class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-return-right"></i> بازگشت به وضعیت همگام‌سازی
    </a>
</div>

<?php if ($pending === []): ?>
    <div class="alert alert-success">
        <i class="bi bi-check2-circle"></i>
        هیچ تطبیقی در انتظار بررسی اپراتور نیست.
    </div>
<?php else: ?>
    <div class="alert alert-info small">
        <i class="bi bi-info-circle"></i>
        این تطبیق‌ها با سطح اطمینان ۵۰–۸۹ پرچم‌گذاری شده‌اند و نیاز به
        تأیید یا رد دستی توسط اپراتور دارند. تصمیم در <code>sync_audit_log</code>
        ثبت می‌شود.
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>متقاضی</th>
                            <th>شماره درخواست درگاه ملی</th>
                            <th>اطمینان</th>
                            <th>زمان</th>
                            <th class="text-center">اقدام</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $row):
                            $id         = (int)($row['id'] ?? 0);
                            $applicant  = (int)($row['applicant_id'] ?? 0);
                            $rn         = (string)($row['national_request_number'] ?? '');
                            $conf       = (int)($row['confidence'] ?? 0);
                            $createdAt  = (string)($row['created_at'] ?? '');
                            $confBadge  = $conf >= 80 ? 'text-bg-success'
                                       : ($conf >= 60 ? 'text-bg-warning' : 'text-bg-secondary');
                        ?>
                            <tr>
                                <td><?= $id ?></td>
                                <td>
                                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/view/<?= $applicant ?>"
                                        class="text-decoration-none">
                                        <?= $applicant ?>
                                    </a>
                                </td>
                                <td dir="ltr"><?= htmlspecialchars($rn, ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="badge <?= htmlspecialchars($confBadge, ENT_QUOTES) ?>"><?= $conf ?>٪</span>
                                </td>
                                <td dir="ltr" class="small"><?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-center">
                                    <form method="post"
                                        action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/resolve"
                                        class="d-inline-flex gap-1"
                                        onsubmit="return confirm('این تطبیق تأیید شود؟');">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="log_id" value="<?= $id ?>">
                                        <input type="hidden" name="decision" value="confirmed">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="bi bi-check2"></i> تأیید
                                        </button>
                                    </form>
                                    <form method="post"
                                        action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sync/resolve"
                                        class="d-inline-flex gap-1"
                                        onsubmit="return confirm('این تطبیق رد شود؟');">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="log_id" value="<?= $id ?>">
                                        <input type="hidden" name="decision" value="rejected">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="bi bi-x-lg"></i> رد
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-2">
        نمایش <?= count($pending) ?> رکورد (حداکثر <?= (int)$limit ?> مورد اخیر).
    </p>
<?php endif;
/* end of file — no closing ?> tag per CODING_CONVENTIONS §2.1 */
