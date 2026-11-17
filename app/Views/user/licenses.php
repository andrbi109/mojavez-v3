<?php
/**
 * user/licenses.php — مجوزهای صادرشده برای کاربر (Phase 9).
 *
 * هر درخواستی که current_status در ('صدور مجوز','تحویل مجوز') باشد را همراه
 * با فایل مجوز پیوست‌شده (اگر هست) نمایش می‌دهد.
 */
/** @var array $applications */
/** @var array $serviceTypes */
/** @var string $baseUrl */

$serviceTitle = function (int $id) use ($serviceTypes): string {
    return $serviceTypes[$id] ?? '—';
};
$issued = array_filter($applications, fn($a) => in_array((string)($a['current_status'] ?? ''), ['صدور مجوز', 'تحویل مجوز'], true));
?>
<div class="card">
    <div class="card-header"><i class="bi bi-patch-check"></i> مجوزهای صادرشده</div>
    <div class="card-body">
        <?php if (empty($issued)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-patch display-4"></i>
                <p class="mt-2 mb-0">هنوز مجوزی صادر نشده است.</p>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($issued as $a): ?>
                    <?php $license = $a['license'] ?? null; ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="card h-100 border-success border-opacity-50">
                            <div class="card-body">
                                <h5 class="card-title mb-1">
                                    <i class="bi bi-patch-check-fill text-success"></i>
                                    <?= htmlspecialchars($serviceTitle((int)($a['service_type_id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
                                </h5>
                                <div class="small text-muted mb-2">
                                    کد پیگیری: <code class="user-select-all"><?= htmlspecialchars((string)($a['tracking_code'] ?? '—'), ENT_QUOTES) ?></code>
                                </div>
                                <?php if (!empty($a['license_valid_until'])): ?>
                                    <div class="small mb-2">
                                        اعتبار تا: <span class="fw-semibold"><?= htmlspecialchars((string)$a['license_valid_until'], ENT_QUOTES) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($license && !empty($license['document_id'])): ?>
                                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>document/inline/<?= (int)$license['document_id'] ?>"
                                       class="btn btn-sm btn-success" target="_blank">
                                        <i class="bi bi-download"></i> دریافت مجوز
                                    </a>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark">فایل مجوز ثبت نشده</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
