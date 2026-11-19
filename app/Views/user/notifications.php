<?php
/**
 * user/notifications.php — اعلان‌های کاربر (Phase 9).
 */
/** @var array $notifications */
/** @var int $unreadCount */
/** @var string $baseUrl */
/** @var string $csrfField */
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-bell"></i> اعلان‌ها</span>
        <?php if ($unreadCount > 0): ?>
            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>panel/notifications/read" class="m-0">
                <?= $csrfField ?>
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-check2-all"></i> خواندن همه (<?= (int)$unreadCount ?>)
                </button>
            </form>
        <?php endif; ?>
    </div>
    <div class="list-group list-group-flush">
        <?php if (empty($notifications)): ?>
            <div class="list-group-item text-center text-muted py-5">
                <i class="bi bi-bell-slash display-4"></i>
                <p class="mt-2 mb-0">اعلانی وجود ندارد.</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
                <div class="list-group-item <?= empty($n['is_read']) ? 'border-end border-primary border-3' : '' ?>">
                    <div class="d-flex w-100 justify-content-between">
                        <span class="fw-<?= empty($n['is_read']) ? 'bold' : 'normal' ?>">
                            <?= htmlspecialchars((string)($n['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <small class="text-muted"><?= htmlspecialchars((string)($n['created_at'] ?? ''), ENT_QUOTES) ?></small>
                    </div>
                    <?php if (!empty($n['body'])): ?>
                        <div class="small text-muted mt-1"><?= htmlspecialchars((string)$n['body'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
