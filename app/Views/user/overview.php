<?php
/**
 * user/overview.php — پیشخوان پنل کاربری (Phase 9).
 *
 * الهام‌گرفته از v2 app/Views/user/overview.php (آمار + آخرین درخواست + timeline)
 * ولی با layout/components خود v3 بازنویسی شده.
 */
/** @var array $stats */
/** @var array $applications */
/** @var array $payments */
/** @var array $notifications */
/** @var array|null $latestApp */
/** @var array $timeline */
/** @var array $serviceTypes */
/** @var array $account */
/** @var int $unreadCount */
/** @var string $baseUrl */

$serviceTitle = function (int $id) use ($serviceTypes): string {
    return $serviceTypes[$id] ?? '—';
};
?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-4">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-folder2-open display-6 text-brand"></i>
                <div class="h2 mt-2 mb-0"><?= (int)($stats['total_apps'] ?? 0) ?></div>
                <div class="text-muted small">کل درخواست‌ها</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-hourglass-split display-6 text-warning"></i>
                <div class="h2 mt-2 mb-0"><?= (int)($stats['pending_apps'] ?? 0) ?></div>
                <div class="text-muted small">در حال بررسی</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="card text-center h-100">
            <div class="card-body">
                <i class="bi bi-patch-check display-6 text-success"></i>
                <div class="h2 mt-2 mb-0"><?= (int)($stats['issued_apps'] ?? 0) ?></div>
                <div class="text-muted small">صادرشده</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history"></i> آخرین درخواست</span>
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/requests" class="btn btn-sm btn-outline-primary">همه</a>
            </div>
            <div class="card-body">
                <?php if ($latestApp === null): ?>
                    <p class="text-muted mb-0">هنوز درخواستی ثبت نکرده‌اید.</p>
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register" class="btn btn-primary mt-3">
                        <i class="bi bi-plus-lg"></i> شروع ثبت‌نام
                    </a>
                <?php else: ?>
                    <h5 class="mb-1"><?= htmlspecialchars($serviceTitle((int)($latestApp['service_type_id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></h5>
                    <div class="text-muted small mb-3">
                        کد پیگیری:
                        <code class="user-select-all"><?= htmlspecialchars((string)($latestApp['tracking_code'] ?? '—'), ENT_QUOTES) ?></code>
                        <span class="mx-2">•</span>
                        وضعیت:
                        <span class="badge bg-info"><?= htmlspecialchars((string)($latestApp['current_status'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php if (!empty($timeline)): ?>
                        <h6 class="small text-uppercase text-muted">خط زمانی وضعیت</h6>
                        <ul class="list-unstyled ms-3 border-s-2 border-info border-opacity-50">
                            <?php foreach (array_slice($timeline, 0, 6) as $t): ?>
                                <?php
                                // Support both array (legacy) and StatusLogEntry (object) forms.
                                $tStatus = is_array($t) ? (string)($t['status'] ?? $t['to_status'] ?? '—') : (string)($t->statusName ?? '—');
                                $tCreated = is_array($t) ? (string)($t['created_at'] ?? '') : (string)($t->createdAt ?? '');
                                ?>
                                <li class="mb-2">
                                    <div class="fw-semibold"><?= htmlspecialchars($tStatus, ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($tCreated, ENT_QUOTES) ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-bell"></i> اعلان‌های اخیر</div>
            <div class="list-group list-group-flush">
                <?php if (empty($notifications)): ?>
                    <div class="list-group-item text-muted small">اعلانی نیست.</div>
                <?php else: ?>
                    <?php foreach ($notifications as $n): ?>
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/notifications"
                           class="list-group-item list-group-item-action <?= empty($n['is_read']) ? 'fw-semibold' : '' ?>">
                            <div class="d-flex w-100 justify-content-between">
                                <span class="small"><?= htmlspecialchars((string)($n['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                <small class="text-muted"><?= htmlspecialchars((string)($n['created_at'] ?? ''), ENT_QUOTES) ?></small>
                            </div>
                            <div class="small text-muted text-truncate"><?= htmlspecialchars((string)($n['body'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-credit-card-2-front"></i> پرداخت‌های اخیر</div>
            <div class="list-group list-group-flush">
                <?php if (empty($payments)): ?>
                    <div class="list-group-item text-muted small">پرداختی ثبت نشده.</div>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <div class="small fw-semibold"><?= htmlspecialchars((string)($p['purpose'] ?? ($p['description'] ?? 'پرداخت')), ENT_QUOTES, 'UTF-8') ?></div>
                                <small class="text-muted"><?= htmlspecialchars((string)($p['created_at'] ?? ''), ENT_QUOTES) ?></small>
                            </div>
                            <span class="badge bg-<?= ($p['status'] ?? '') === 'approved' ? 'success' : 'secondary' ?>">
                                <?= htmlspecialchars((string)($p['status'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
