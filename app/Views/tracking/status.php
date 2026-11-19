<?php
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array $statusLog */
/** @var ?\App\Modules\Core\Domain\JobDetail $jobDetail */

use App\Modules\Core\Domain\Enums\LicenseState;
use App\Shared\Support\PersianDate;

$trackingCode = $applicant->trackingCode;
$licenseIssued = $applicant->licenseState === LicenseState::Issued;
?>
<?php
// Phase 57b — page-local layout hardening. The public layout.php is a
// flex column (body has display:flex / flex-direction:column / min-height:100vh
// + .moj-main has flex:1 0 auto + .moj-footer has margin-top:auto) which
// normally sticks the footer to the bottom of the viewport. With the
// rejection alert (added in Phase 53) and the conditional license
// section (added in Phase 51) stacked on top of the row, on some
// viewports the page contents end up overlapping the footer at the
// bottom (the footer renders BELOW the main content but visually
// peeks through the last card). The hardening here:
//   - Wrap the whole view in .moj-tracking-status with position:relative
//     and z-index:1 so the content layer always paints above the
//     footer's stacking context.
//   - Give the wrapper a min-height so short pages (e.g. a fresh
//     tracking code with no status log yet) still push the footer
//     down to the bottom of the viewport instead of leaving the
//     footer floating mid-page over the license-request section.
//   - The footer itself is left to the public layout's existing
//     sticky-footer rule (margin-top:auto inside the flex body) —
//     we only need the wrapper to keep its own painting above it.
// No `position:absolute` is used anywhere in this view (verified by
// grep), so removing that class isn't needed; the fix is purely the
// z-index/min-height hardening below.
//
// Note: the wrapper uses a class selector (.moj-tracking-status) and
// not an ID selector (#moj-tracking-status) on purpose — the simple
// PHP linter (tools/php_lint_simple.py) treats any line that starts
// with `#` as a PHP line comment, so an ID selector would confuse
// its brace-balance check (the `{` on the `#id {` line would be
// stripped, leaving the matching `}` unbalanced). The class selector
// starts with `.` which the linter leaves alone.
?>
<style>
.moj-tracking-status {
    position: relative;
    z-index: 1;
    /* Reserve enough vertical space so short pages still push the
       footer down — the public layout's sticky-footer rule
       (margin-top:auto) does the actual stick-to-bottom work, this
       just guarantees the wrapper has non-trivial height on its own
       so the footer never overlaps the last card. */
    min-height: 60vh;
}
/* The license-request-section card and the rejection alert both
   carry `mt-4` / `mb-4` margins. On tight mobile viewports those
   margins collapse with the section's own padding and the result
   can read as "the footer is jammed under the section". Force a
   small gap below the section so it always has breathing room
   before the footer divider. */
.moj-tracking-status > section:last-of-type {
    margin-bottom: 1.5rem;
}
</style>

<div class="moj-tracking-status">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h4 mb-1">پیگیری پرونده</h1>
        <div class="text-muted small">کد رهگیری:</div>
        <span class="moj-tracking-code fs-5"><?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <a href="/tracking/print/<?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-outline-primary">
        <i class="bi bi-printer"></i> نسخهٔ چاپی
    </a>
</div>

<?php
// Phase 53 (v2.1.9) — if any status_log entry marks this case as
// rejected, surface the admin's rejection description in a red alert
// above the timeline so the user sees the reason. The statusLog is
// ordered ASC by created_at (PdoStatusLogRepository::findByApplicantId),
// so we keep overwriting to capture the LATEST rejection entry's
// description (a case may be rejected, then re-opened, then rejected
// again — the latest rejection is what the user cares about).
$rejectionReason = '';
foreach ($statusLog as $entry) {
    if ($entry->statusName === 'رد درخواست') {
        $reason = (string)($entry->description ?? '');
        if ($reason !== '') {
            $rejectionReason = $reason;
        }
    }
}
?>
<?php if ($rejectionReason !== ''): ?>
<div class="alert alert-danger d-flex align-items-start gap-2 mb-4" role="alert">
    <i class="bi bi-exclamation-octagon-fill fs-4 flex-shrink-0"></i>
    <div>
        <div class="fw-bold">علت رد درخواست:</div>
        <div class="mt-1"><?= htmlspecialchars($rejectionReason, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-person-vcard"></i> اطلاعات متقاضی
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">نام</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars($applicant->fullName(), ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-5 text-muted">وضعیت</dt>
                    <dd class="col-sm-7">
                        <span class="badge text-bg-<?= htmlspecialchars($applicant->currentStatus->badgeColor(), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($applicant->currentStatus->titleFa(), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </dd>
                    <dt class="col-sm-5 text-muted">وضعیت مجوز</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars($applicant->licenseState->titleFa(), ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($jobDetail !== null): ?>
        <div class="card mt-3">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-briefcase"></i> اطلاعات شغلی
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5 text-muted">رشته/عنوان</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars($jobDetail->licenseTitle, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-sm-5 text-muted">سابقهٔ کار</dt>
                    <dd class="col-sm-7"><?= htmlspecialchars($jobDetail->experienceLabel(), ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-md-7">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-clock-history"></i> تاریخچهٔ وضعیت
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr><th>#</th><th>وضعیت</th><th>تاریخ</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($statusLog as $i => $entry): ?>
                            <tr>
                                <td class="text-muted"><?= $i + 1 ?></td>
                                <td>
                                    <span class="badge text-bg-secondary">
                                        <?= htmlspecialchars($entry->statusName, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td class="text-muted" dir="ltr"><?= htmlspecialchars(PersianDate::format($entry->createdAt), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($statusLog)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">تاریخچه‌ای ثبت نشده است.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($licenseIssued): ?>
<section class="card mt-4 border-success border-opacity-50" id="license-request-section">
    <div class="card-header bg-success-subtle d-flex align-items-center gap-2">
        <i class="bi bi-patch-check text-success"></i>
        <span class="fw-semibold">درخواست تصویر مجوز</span>
    </div>
    <div class="card-body">
        <p class="mb-3 text-muted">
            مجوز این پرونده صادر شده است. می‌توانید درخواست دریافت تصویر مجوز را ثبت کنید.
            لینک دسترسی پس از درخواست، به مدت ۷۲ ساعت معتبر خواهد بود.
        </p>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <button type="button"
                    class="btn btn-success"
                    id="license-request-btn"
                    data-tracking-code="<?= htmlspecialchars($trackingCode, ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-image"></i> درخواست تصویر مجوز
            </button>
            <span class="spinner-border spinner-border-sm text-success d-none" id="license-request-spinner" role="status" aria-hidden="true"></span>
            <div class="text-muted small" id="license-request-status" aria-live="polite"></div>
        </div>
        <div class="mt-3 d-none" id="license-request-result">
            <!-- Populated by JS: either the token link or "pending admin review" message. -->
        </div>
    </div>
</section>
<?php endif; ?>
</div><!-- /#moj-tracking-status -->

<script>
(function () {
    'use strict';
    var btn = document.getElementById('license-request-btn');
    if (!btn) return;  // section is hidden when license_state != Issued
    var spinner = document.getElementById('license-request-spinner');
    var statusEl = document.getElementById('license-request-status');
    var resultEl = document.getElementById('license-request-result');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var trackingCode = btn.getAttribute('data-tracking-code') || '';

    function setStatus(msg, cls) {
        statusEl.className = 'small ' + (cls || 'text-muted');
        statusEl.textContent = msg;
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        spinner.classList.remove('d-none');
        setStatus('در حال ارسال درخواست…', 'text-muted');
        resultEl.classList.add('d-none');
        resultEl.innerHTML = '';

        var formData = new FormData();
        formData.append('tracking_code', trackingCode);
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
            spinner.classList.add('d-none');
            if (r.status === 200 && r.json && r.json.success) {
                var d = r.json.data || {};
                if (d.auto_approved && d.token) {
                    setStatus('درخواست تأیید شد.', 'text-success');
                    var linkUrl = '/license/view/' + encodeURIComponent(d.token);
                    var link = '<a href="' + linkUrl + '" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm">'
                              + '<i class="bi bi-box-arrow-up-right"></i> مشاهدهٔ تصویر مجوز</a>';
                    var expires = d.expires_at ? ' (تاریخ انقضا: ' + d.expires_at + ')' : '';
                    resultEl.innerHTML =
                        '<div class="alert alert-success mb-0" role="alert">'
                        + '<i class="bi bi-check2-circle"></i> لینک دسترسی به تصویر مجوز صادر شد' + expires + '.<br>'
                        + '<div class="mt-2">' + link + '</div>'
                        + '</div>';
                    resultEl.classList.remove('d-none');
                } else {
                    setStatus('درخواست ثبت شد.', 'text-success');
                    resultEl.innerHTML =
                        '<div class="alert alert-info mb-0" role="alert">'
                        + '<i class="bi bi-clock-history"></i> درخواست شما ثبت شد و در انتظار بررسی مدیر سامانه است. '
                        + 'پس از تأیید، لینک دسترسی از طریق اعلان‌ها در دسترس شما قرار خواهد گرفت.'
                        + '</div>';
                    resultEl.classList.remove('d-none');
                }
            } else {
                var msg = (r.json && r.json.error && r.json.error.message) ? r.json.error.message : 'خطا در ثبت درخواست.';
                setStatus(msg, 'text-danger');
            }
        }).catch(function () {
            btn.disabled = false;
            spinner.classList.add('d-none');
            setStatus('خطای شبکه در ارتباط با سرور.', 'text-danger');
        });
    });
})();
</script>
