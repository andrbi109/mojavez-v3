<?php
/**
 * user/requests.php — لیست درخواست‌های کاربر (Phase 9).
 *
 * Phase 46 (v2.1.2) — three additions on top of the Phase-9 list:
 *   1. One-shot flash message (consumed from `panel_flash` session key)
 *      rendered as a Bootstrap alert at the top — surfaces success/error
 *      feedback after a delete or postal-code change redirect.
 *   2. Per-row "حذف درخواست" button (a POST form to
 *      /panel/requests/{id}/delete) — only rendered for rows whose
 *      current_status is in ['پیش‌نویس', 'تکمیل اطلاعات'] (the two
 *      deletable pre-issue statuses per the Phase-46 spec). Other rows
 *      show a muted "—" placeholder so the column stays aligned.
 *   3. Per-row inline "تغییر کد پستی" form (POST /panel/change-postal-code
 *      with hidden tracking_code + a 10-digit text input). The form is
 *      collapsed under a toggle button (Bootstrap collapse) so the row
 *      height stays compact by default.
 *
 * View variables:
 *   $applications  array<int,array<string,mixed>>  — applicants rows
 *   $serviceTypes  array<int,string>               — {id => title} lookup
 *   $baseUrl       string
 *   $csrfField     string                            — injected by Controller::render()
 */
/** @var array $applications */
/** @var array $serviceTypes */
/** @var string $baseUrl */
/** @var string $csrfField */

use App\Shared\Support\PersianDate;

$serviceTitle = function (int $id) use ($serviceTypes): string {
    return $serviceTypes[$id] ?? '—';
};

// Phase 46 — consume the one-shot `panel_flash` session value if present.
// Set by UserPanelController::flash() (deleteApplication / changePostalCode).
// Read once + remove so a page refresh doesn't re-show the alert.
$flash = null;
if ($this->session->has('panel_flash')) {
    $raw = $this->session->get('panel_flash');
    if (is_array($raw) && isset($raw['message'], $raw['type'])) {
        $flash = $raw;
    }
    $this->session->remove('panel_flash');
}

// The two deletable pre-issue statuses per Phase-46 spec.
$deletableStatuses = ['پیش‌نویس', 'تکمیل اطلاعات'];
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
        <span><i class="bi bi-folder2-open"></i> درخواست‌های ثبت‌شده</span>
        <span class="badge bg-secondary"><?= count($applications) ?> مورد</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($applications)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox display-4"></i>
                <p class="mt-2 mb-3">هنوز درخواستی ثبت نکرده‌اید.</p>
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> شروع ثبت‌نام جدید
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>کد پیگیری</th>
                            <th>نوع خدمت</th>
                            <th>وضعیت</th>
                            <th>تاریخ ثبت</th>
                            <th>تغییر کد پستی</th>
                            <th>اقدامات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $a): ?>
                            <?php
                                $st = (string)($a['current_status'] ?? '');
                                $badge = 'secondary';
                                if (in_array($st, ['صدور مجوز', 'تحویل مجوز'], true)) $badge = 'success';
                                elseif ($st === 'لغو') $badge = 'danger';
                                elseif (in_array($st, ['پیش‌نویس'], true)) $badge = 'light text-dark';
                                $canDelete = in_array($st, $deletableStatuses, true);
                                $appId = (int)($a['id'] ?? 0);
                                $trackingCode = (string)($a['tracking_code'] ?? '');
                                // Per-row collapse id so multiple rows don't collide.
                                $collapseId = 'postal-collapse-' . $appId;
                            ?>
                            <tr>
                                <td><code class="user-select-all"><?= htmlspecialchars($trackingCode, ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td><?= htmlspecialchars($serviceTitle((int)($a['service_type_id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="small text-muted"><?= htmlspecialchars(PersianDate::format((string)($a['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ($trackingCode !== ''): ?>
                                        <button class="btn btn-sm btn-outline-primary" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>"
                                                aria-expanded="false" aria-controls="<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-pencil-square"></i> درخواست تغییر
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 flex-wrap">
                                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>tracking/status/<?= urlencode($trackingCode) ?>"
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> پیگیری
                                        </a>
                                        <?php if ($canDelete): ?>
                                            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/requests/<?= (int)$appId ?>/delete" class="d-inline">
                                                <?= $csrfField ?>
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('این درخواست حذف شود؟ این عمل قابل بازگشت نیست.')">
                                                    <i class="bi bi-trash"></i> حذف
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php if ($trackingCode !== ''): ?>
                                <tr class="collapse" id="<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>">
                                    <td colspan="6" class="bg-light">
                                        <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/change-postal-code"
                                              class="row g-2 align-items-end needs-validation" novalidate>
                                            <?= $csrfField ?>
                                            <input type="hidden" name="tracking_code"
                                                   value="<?= htmlspecialchars($trackingCode, ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="col-auto">
                                                <label class="form-label small text-muted mb-1">کد پستی جدید</label>
                                                <input type="text" name="new_postal_code"
                                                       class="form-control form-control-sm"
                                                       pattern="\d{10}" inputmode="numeric"
                                                       placeholder="۱۰ رقم"
                                                       maxlength="10" required
                                                       style="direction: ltr; text-align: right;">
                                            </div>
                                            <div class="col-auto">
                                                <button type="submit" class="btn btn-outline-primary btn-sm">
                                                    <i class="bi bi-check2-circle"></i> ثبت درخواست
                                                </button>
                                            </div>
                                            <div class="col-auto">
                                                <small class="text-muted">پس از تأیید مدیر، کد پستی به‌روز خواهد شد.</small>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
