<?php
declare(strict_types=1);

namespace App\Shared\Support;

/**
 * StatusBadge — single source of truth for the Bootstrap colour class
 * of an applicant status badge.
 *
 * The 18-status cycle is defined in `config/statuses.php` with a `color`
 * key on every status (secondary, info, primary, warning, success,
 * danger, dark). Before Phase 52 (v2.1.8) most views hard-coded the
 * badge colour (usually `text-bg-secondary`), so the colour was out of
 * sync with the config whenever a status was re-coloured.
 *
 * Usage in views:
 *
 *   use App\Shared\Support\StatusBadge;
 *   ...
 *   <span class="badge text-bg-<?= htmlspecialchars(StatusBadge::color($row['current_status']), ENT_QUOTES, 'UTF-8') ?>">
 *       <?= htmlspecialchars($row['current_status'], ENT_QUOTES, 'UTF-8') ?>
 *   </span>
 *
 * Unknown status strings (e.g. legacy v2 imports, custom log rows like
 * 'حذف مدرک') fall back to `secondary` so the badge still renders.
 */
final class StatusBadge
{
    /**
     * The Bootstrap 5 colour class for a given status name.
     *
     * Reads `config/statuses.php` → `statuses.{status}.color`. Returns
     * `'secondary'` if the status is not in the config (legacy / custom
     * log rows). The returned value is one of the Bootstrap 5 colour
     * keywords (secondary, info, primary, warning, success, danger,
     * dark, light) — pass it to `text-bg-<?= $color ?>` (Bootstrap 5.3
     * foreground+background combo) or `bg-<?= $color ?>` (Bootstrap 5.0
     * solid background only).
     */
    public static function color(string $status): string
    {
        $color = Config::get('statuses.statuses.' . $status . '.color');
        return is_string($color) && $color !== '' ? $color : 'secondary';
    }
}
