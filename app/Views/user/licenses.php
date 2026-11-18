<?php
/**
 * user/licenses.php — مجوزهای صادرشده برای کاربر (Phase 9).
 *
 * هر درخواستی که current_status در ('صدور مجوز','تحویل مجوز') باشد را همراه
 * با فایل مجوز پیوست‌شده (اگر هست) نمایش می‌دهد.
 *
 * Phase 47 (v2.1.3): a "مشاهده مجوز" button was added next to each
 * issued license. The button POSTs to /tracking/license/first-access
 * (AJAX) and on auto_approved=true, opens /license/view/{token} in a
 * new tab. This complements the existing "دریافت مجوز" button (which
 * is a direct, authenticated document/inline download) by exposing the
 * 72-hour token-based access that the LicenseController::firstAccess
 * endpoint provides — the token flow is rate-limited + audited.
 */
/** @var array $applications */
/** @var array $serviceTypes */
/** @var string $baseUrl */

use App\Shared\Support\PersianDate;

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
                    <?php $tcode = (string)($a['tracking_code'] ?? ''); ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="card h-100 border-success border-opacity-50">
                            <div class="card-body">
                                <h5 class="card-title mb-1">
                                    <i class="bi bi-patch-check-fill text-success"></i>
                                    <?= htmlspecialchars($serviceTitle((int)($a['service_type_id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
                                </h5>
                                <div class="small text-muted mb-2">
                                    کد پیگیری: <code class="user-select-all"><?= htmlspecialchars($tcode, ENT_QUOTES) ?></code>
                                </div>
                                <?php if (!empty($a['license_valid_until'])): ?>
                                    <div class="small mb-2">
                                        اعتبار تا: <span class="fw-semibold"><?= htmlspecialchars(PersianDate::format((string)$a['license_valid_until']), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <?php if ($license && !empty($license['document_id'])): ?>
                                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>document/inline/<?= (int)$license['document_id'] ?>"
                                           class="btn btn-sm btn-success" target="_blank">
                                            <i class="bi bi-download"></i> دریافت مجوز
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark">فایل مجوز ثبت نشده</span>
                                    <?php endif; ?>
                                    <?php if ($tcode !== ''): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-success btn-view-license"
                                                data-tracking-code="<?= htmlspecialchars($tcode, ENT_QUOTES) ?>">
                                            <i class="bi bi-image"></i> مشاهدهٔ مجوز
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="small license-view-feedback d-none" role="status" aria-live="polite"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    'use strict';
    var buttons = document.querySelectorAll('.btn-view-license');
    if (buttons.length === 0) return;
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    function findFeedback(btn) {
        var card = btn.closest('.card');
        if (!card) return null;
        return card.querySelector('.license-view-feedback');
    }

    function setFeedback(fb, msg, cls) {
        if (!fb) return;
        fb.className = 'small license-view-feedback ' + (cls || 'text-muted');
        fb.textContent = msg;
        fb.classList.remove('d-none');
    }

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tcode = btn.getAttribute('data-tracking-code') || '';
            if (!tcode) return;
            var fb = findFeedback(btn);
            btn.disabled = true;
            var originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            setFeedback(fb, 'در حال ارسال درخواست…', 'text-muted');

            var formData = new FormData();
            formData.append('tracking_code', tcode);
            formData.append('_csrf', csrfToken);

            fetch('/tracking/license/first-access', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            }).then(function (res) {
                return res.json().then(function (j) {
                    return { status: res.status, json: j };
                });
            }).then(function (r) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                if (r.status === 200 && r.json && r.json.success) {
                    var d = r.json.data || {};
                    if (d.auto_approved && d.token) {
                        setFeedback(fb, 'در حال باز کردن تصویر مجوز…', 'text-success');
                        var linkUrl = '/license/view/' + encodeURIComponent(d.token);
                        window.open(linkUrl, '_blank', 'noopener');
                        var expires = d.expires_at ? ' (معتبر تا ' + d.expires_at + ')' : '';
                        setFeedback(fb, 'لینک دسترسی صادر شد' + expires + '.', 'text-success');
                    } else {
                        setFeedback(fb, 'درخواست ثبت شد و در انتظار بررسی مدیر است.', 'text-info');
                    }
                } else {
                    var msg = (r.json && r.json.error && r.json.error.message) ? r.json.error.message : 'خطا در ثبت درخواست.';
                    setFeedback(fb, msg, 'text-danger');
                }
            }).catch(function () {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                setFeedback(fb, 'خطای شبکه در ارتباط با سرور.', 'text-danger');
            });
        });
    });
})();
</script>
