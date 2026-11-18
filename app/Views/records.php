<?php
/**
 * records.php — list the user's own applicant records.
 *
 * Phase 15 (v1.6.0): rendered by RecordsController::form() and
 * RecordsController::verify(). Shows a mobile verification form first;
 * after verification, lists every applicants row whose user_account_id
 * matches the current user, with the service_type title + tracking code
 * + status + a link to /tracking/status/{code}.
 *
 * Expected $data:
 *   $account   array<string,mixed> — current user account row.
 *   $records   array<int,array<string,mixed>> — verified: list of records.
 *   $verified  bool — true if the mobile has been verified (POST verify).
 *   $error     string — non-empty if verify() rejected the mobile.
 *   $baseUrl   string — site base URL.
 *   $csrfField string — CSRF hidden input.
 */
/** @var array<string,mixed> $account */
/** @var array<int, array<string,mixed>> $records */
/** @var bool $verified */
/** @var string $error */
/** @var string $baseUrl */
/** @var string $csrfField */

$account   ??= [];
$records   ??= [];
$verified  ??= false;
$error     ??= '';
$baseUrl   ??= '';
$csrfField ??= '';

use App\Shared\Support\StatusBadge;
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0"><i class="bi bi-folder2-open text-brand"></i> پرونده‌های من</h1>
            </div>
            <div class="card-body p-4">
                <?php if (!$verified): ?>
                    <p class="text-muted">
                        برای مشاهدهٔ پرونده‌های خود، شمارهٔ موبایل حساب کاربری خود را وارد کنید.
                    </p>
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>my-records/verify">
                        <?= $csrfField ?>
                        <div class="mb-3">
                            <label for="mobile" class="form-label">شماره موبایل</label>
                            <input type="tel" class="form-control" id="mobile" name="mobile"
                                   pattern="09\d{9}" maxlength="11" required
                                   value="<?= htmlspecialchars((string)($account['mobile'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                   dir="ltr" style="text-align: right;">
                            <div class="form-text">شمارهٔ موبایل باید با حساب کاربری شما مطابقت داشته باشد.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> نمایش پرونده‌ها
                        </button>
                    </form>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted">تعداد پرونده‌ها: <strong><?= count($records) ?></strong></span>
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>my-records"
                           class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-clockwise"></i> جستجوی دوباره
                        </a>
                    </div>
                    <?php if ($records === []): ?>
                        <div class="alert alert-info mb-0">هنوز پرونده‌ای ثبت نکرده‌اید.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>کد رهگیری</th>
                                        <th>نوع خدمت</th>
                                        <th>نام متقاضی</th>
                                        <th>وضعیت</th>
                                        <th>تاریخ ثبت</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($records as $r): ?>
                                        <tr>
                                            <td>
                                                <code class="user-select-all" dir="ltr">
                                                    <?= htmlspecialchars((string)($r['tracking_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                </code>
                                            </td>
                                            <td><?= htmlspecialchars((string)($r['service_type_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(
                                                trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?></td>
                                            <td>
                                                <span class="badge text-bg-<?= htmlspecialchars(StatusBadge::color((string)($r['current_status'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars((string)($r['current_status'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </td>
                                            <td class="text-muted small" dir="ltr">
                                                <?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td>
                                                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>tracking/status/<?= rawurlencode((string)($r['tracking_code'] ?? '')) ?>"
                                                   class="btn btn-outline-primary btn-sm">
                                                    <i class="bi bi-eye"></i> پیگیری
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
