<?php
/**
 * admin/sms.php — SMS templates + user-submitted SMS entries.
 *
 * Phase 17 (v1.8.0): rendered by SmsController::index().
 * Two cards:
 *   (a) SMS templates table (add/edit form at top + list of templates)
 *   (b) Recent user_sms_entries (paginated 50, filterable by status).
 *
 * @var array<int, array<string,mixed>>        $templates
 * @var array<int, array<string,mixed>>        $entries
 * @var string                                 $statusFilter
 * @var array<string,mixed>|null               $editing
 * @var string $csrfField
 * @var string $baseUrl
 */
/** @var array<int, array<string,mixed>> $templates */
/** @var array<int, array<string,mixed>> $entries */
/** @var string $statusFilter */
/** @var array<string,mixed>|null $editing */
/** @var string $csrfField */
/** @var string $baseUrl */

$templates    ??= [];
$entries       ??= [];
$statusFilter ??= 'در انتظار';
$editing       ??= null;
$csrfField     ??= '';
$baseUrl       ??= '/';

/** @return string */
$ev = static function (string $key, ?string $default = '') use ($editing): string {
    if ($editing === null) {
        return htmlspecialchars((string)$default, ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars((string)($editing[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};

$badgeFor = static function (string $status): string {
    return match ($status) {
        'در انتظار'   => 'warning',
        'تأیید شده'   => 'success',
        'رد شده'      => 'danger',
        default       => 'secondary',
    };
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-chat-dots"></i> مدیریت پیامک‌ها</h2>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <i class="bi <?= $editing === null ? 'bi-plus-lg' : 'bi-pencil' ?>"></i>
                <?= $editing === null ? 'افزودن الگوی پیامک' : 'ویرایش الگو (#' . (int)$editing['id'] . ')' ?>
            </div>
            <div class="card-body">
                <form method="post"
                      action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sms/save-template">
                    <?= $csrfField ?>
                    <?php if ($editing !== null): ?>
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">عنوان الگو <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title"
                               value="<?= $ev('title') ?>" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">متن نمونه</label>
                        <textarea class="form-control" name="sample_text" rows="4"
                                  maxlength="2000"><?= $ev('sample_text') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">وضعیت مقصد</label>
                        <input type="text" class="form-control" name="target_status"
                               value="<?= $ev('target_status') ?>" maxlength="100"
                               placeholder="مثلاً: تأیید اولیه، احراز نشانی، ...">
                        <div class="form-text small text-muted">
                            پس از تأیید پیامک، وضعیت پرونده به این مقدار تغییر می‌یابد (در فاز آینده).
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" name="is_active" value="1"
                               id="sms-active" <?= ((int)($editing['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="sms-active">فعال</label>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <?php if ($editing !== null): ?>
                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sms"
                               class="btn btn-outline-secondary btn-sm">انصراف</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check-lg"></i> ذخیره
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-list-ul"></i> الگوها</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>عنوان</th><th>وضعیت مقصد</th><th>وضعیت</th><th class="text-end">عملیات</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $t): ?>
                                <?php
                                    $tid = (int)$t['id'];
                                    $isActive = (int)$t['is_active'] === 1;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$t['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="small text-muted">
                                        <?= htmlspecialchars((string)($t['target_status'] ?? '—') ?: '—', ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td>
                                        <?php if ($isActive): ?>
                                            <span class="badge text-bg-success">فعال</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">غیرفعال</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sms?edit=<?= $tid ?>"
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="post"
                                              action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sms/delete-template"
                                              class="d-inline"
                                              onsubmit="return confirm('حذف این الگو؟ در صورت وجود ورودی، غیرفعال می‌شود.')">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="id" value="<?= $tid ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($templates === []): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">هیچ الگویی ثبت نشده.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-inbox"></i> پیامک‌های ثبت‌شده توسط متقاضیان</span>
                <ul class="nav nav-pills">
                    <?php foreach (['در انتظار', 'تأیید شده', 'رد شده', ''] as $sf): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $statusFilter === $sf ? 'active' : '' ?>"
                               href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sms?status=<?= htmlspecialchars(rawurlencode($sf), ENT_QUOTES) ?>">
                                <?= $sf === '' ? 'همه' : htmlspecialchars($sf, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="card-body p-0">
                <?php if ($entries === []): ?>
                    <div class="p-4 text-muted text-center">هیچ پیامکی ثبت نشده است.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>متقاضی</th>
                                    <th>الگو</th>
                                    <th>کد پیامک</th>
                                    <th>وضعیت</th>
                                    <th>تاریخ</th>
                                    <th class="text-end">عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entries as $e): ?>
                                    <?php
                                        $entryId = (int)$e['id'];
                                        $status = (string)$e['status'];
                                        $badge = $badgeFor($status);
                                        $isPending = $status === 'در انتظار';
                                    ?>
                                    <tr>
                                        <td><?= $entryId ?></td>
                                        <td>
                                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/view/<?= (int)$e['applicant_id'] ?>">
                                                <?= htmlspecialchars(
                                                    trim((string)$e['first_name'] . ' ' . (string)$e['last_name']),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </a>
                                            <div class="text-muted small">
                                                <?= htmlspecialchars((string)$e['tracking_code'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </td>
                                        <td class="small">
                                            <?= htmlspecialchars((string)$e['template_title'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($e['target_status'])): ?>
                                                <div class="text-muted small">
                                                    → <?= htmlspecialchars((string)$e['target_status'], ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <?= htmlspecialchars((string)($e['sms_code'] ?? '—') ?: '—', ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-<?= $badge ?>">
                                                <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td class="text-muted small">
                                            <?= htmlspecialchars((string)$e['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($isPending): ?>
                                                <form method="post"
                                                      action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sms/review-entry"
                                                      class="d-inline">
                                                    <?= $csrfField ?>
                                                    <input type="hidden" name="entry_id" value="<?= $entryId ?>">
                                                    <input type="hidden" name="review_status" value="approved">
                                                    <button type="submit" class="btn btn-sm btn-outline-success"
                                                            onclick="return confirm('تأیید این پیامک؟')">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                        data-bs-toggle="collapse"
                                                        data-bs-target="#sms-reject-<?= $entryId ?>"
                                                        aria-expanded="false" aria-controls="sms-reject-<?= $entryId ?>">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                                <form method="post"
                                                      action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/sms/review-entry"
                                                      class="collapse mt-2"
                                                      id="sms-reject-<?= $entryId ?>">
                                                    <?= $csrfField ?>
                                                    <input type="hidden" name="entry_id" value="<?= $entryId ?>">
                                                    <input type="hidden" name="review_status" value="rejected">
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" class="form-control" name="note"
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
    </div>
</div>
