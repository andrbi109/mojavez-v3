<?php
/**
 * admin/status_cycle.php — placeholder for the moved 18-status cycle.
 *
 * Phase 22 (v1.9.4): originally rendered the full 18-status workflow
 * diagram as Bootstrap cards (shared 14 + household-only 4).
 *
 * Phase 41 (v2.0.7): the cycle diagram was MOVED to the public help
 * page (/guide#status-cycle) so applicants and admins see the same
 * authoritative reference. Phase 51 (v2.1.7) removed the last admin
 * UI links pointing here (the "مشاهدهٔ چرخهٔ کامل" link in
 * view_application.php and the "چرخهٔ ۱۸ وضعیت" button on the admin
 * dashboard). The 18-status cycle now ONLY appears in guide.php.
 *
 * The route + view are kept so any old bookmarks or external links
 * still resolve (no 404), but the diagram is no longer duplicated in
 * an admin view — visitors are redirected to the public guide page.
 *
 * @var string $csrfField
 * @var string $baseUrl
 */
/** @var string $csrfField */
/** @var string $baseUrl */

$csrfField ??= '';
$baseUrl   ??= '/';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-diagram-3"></i> چرخهٔ وضعیت‌ها</h2>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/dashboard"
       class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-return-right"></i> بازگشت به داشبورد
    </a>
</div>

<div class="alert alert-info d-flex align-items-center gap-2">
    <i class="bi bi-info-circle fs-4"></i>
    <div class="small">
        چرخهٔ ۱۸ وضعیت گردش‌کار به صفحهٔ راهنمای عمومی منتقل شده است.
        برای مشاهدهٔ کامل به
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>guide#status-cycle" class="alert-link">
            صفحهٔ راهنما <i class="bi bi-box-arrow-up-right"></i>
        </a>
        مراجعه کنید.
    </div>
</div>
<?php
// Inline redirect: send browsers to the public guide page. Kept short
// so the admin still sees the alert above if JS is disabled.
?>
<script>window.location.replace('<?= htmlspecialchars(rtrim($baseUrl, '/') . '/guide#status-cycle', ENT_QUOTES) ?>');</script>
