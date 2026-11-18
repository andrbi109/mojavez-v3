<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;

/**
 * SettingsController — admin system settings (key/value store).
 *
 * Per docs/00-MASTER.md §16.5 + §29 — manages the `settings` table.
 * Critical for Rollout Stage 1: the `sync_direction` setting controls
 * whether the national-gateway sync is inbound-only (Stage 1) or two-way
 * (Stage 2, future). Changing it is a runtime no-deploy switch.
 *
 * Operations:
 *   - index():  GET  /admin/settings             list + edit all settings
 *   - update(): POST /admin/settings             bulk-update submitted settings
 *   - delete(): POST /admin/settings/delete      delete a custom (non-known) setting
 *
 * Access: requireRole(['admin']) — only superadmin can change settings.
 *
 * Settings schema: (setting_key, setting_value, updated_at).
 * Pre-defined keys (see seed + Rollout stage 1):
 *   - sync_direction          = 'inbound' (Stage 1) | 'two_way' (Stage 2)
 *   - sync_two_way_enabled    = '0' | '1'  (runtime flag for Stage 2)
 *   - site_name               = display name (footer + header)
 *   - site_description        = tagline
 *   - default_service_type_id = 1 (handicraft) | 2 (household business)
 *
 * Phase 10 fix: same constructor + render() bugs as UsersController — the
 * parent::__construct() call was missing Hash + StructuredLogger (4 args vs
 * 6 required → fatal on every /admin/settings GET/POST), and render() was
 * being called with a Persian title string as the third argument (the
 * layout name slot), so the page was rendered without admin_layout. Both
 * fixed: the title is now passed via the $data array ('title' => ...) and
 * the render() call relies on the auto-detect of admin/* views.
 *
 * Phase 43 (v2.0.9) — KNOWN_SETTINGS extended from 5 → 20 keys to cover
 * ALL v2 settings used by the public-facing views: about_title, about_content
 * (textarea), owner_name, owner_username, owner_image (image type),
 * social_eitaa/rubika/bale, success_message_template (textarea), and the
 * fee-* keys (registration_fee_amount, license_reissue_fee_amount,
 * fee_tariff_active, fee_default_amount, fee_rural_auto_eligible,
 * fee_modal_enabled). The update() loop now respects the `textarea` type
 * (no 500-char truncation) and the `image` type (treats the value as a
 * filesystem path; the actual upload is handled by uploadImage()).
 *
 * Phase 49 (v2.1.5) — delete() method added: a POST /admin/settings/delete
 * route lets the admin permanently delete a CUSTOM (non-known) setting row
 * from the `settings` table. Known settings (in KNOWN_SETTINGS) are refused
 * (they have first-class UI controls and deleting one would silently
 * reset it to the default — confusing). The settings view now shows a
 * delete button next to each custom setting.
 */
final class SettingsController extends Controller
{
    /** @var array<string,array<string,mixed>> Settings known to the UI (key → Persian label + hint + type). */
    private const KNOWN_SETTINGS = [
        'sync_direction' => [
            'label' => 'جهت همگام‌سازی درگاه ملی',
            'hint'  => 'inbound = یک‌طرفه (مرحلهٔ ۱)، two_way = دوطرفه (مرحلهٔ ۲، آینده)',
            'type'  => 'select',
            'options' => ['inbound' => 'یک‌طرفه ورودی (مرحلهٔ ۱)', 'two_way' => 'دوطرفه (مرحلهٔ ۲)'],
        ],
        'sync_two_way_enabled' => [
            'label' => 'فعال‌سازی همگام‌سازی دوطرفه',
            'hint'  => 'فقط زمانی فعال کنید که مرحلهٔ ۱ حداقل ۳ ماه بدون فاجعه اجرا شده باشد.',
            'type'  => 'select',
            'options' => ['0' => 'غیرفعال (مرحلهٔ ۱)', '1' => 'فعال (مرحلهٔ ۲)'],
        ],
        'site_name' => [
            'label' => 'نام سامانه',
            'hint'  => 'در سرصفحه و پاصفحه نمایش داده می‌شود.',
            'type'  => 'text',
        ],
        'site_description' => [
            'label' => 'توضیح کوتاه',
            'hint'  => 'توضیحی تک‌خطی برای پاصفحه.',
            'type'  => 'text',
        ],
        'default_service_type_id' => [
            'label' => 'نوع خدمت پیش‌فرض',
            'hint'  => '۱ = صنایع دستی، ۲ = مشاغل خانگی.',
            'type'  => 'select',
            'options' => ['1' => 'صنایع دستی', '2' => 'مشاغل خانگی'],
        ],
        // ===== Phase 43 (v2.0.9) — v2 settings missing from v3 =====
        'about_title' => [
            'label' => 'عنوان صفحهٔ درباره',
            'hint'  => 'عنوانی که در بالای صفحهٔ /about نمایش داده می‌شود.',
            'type'  => 'text',
        ],
        'about_content' => [
            'label' => 'محتوای صفحهٔ درباره',
            'hint'  => 'متن کامل صفحهٔ دربارهٔ ما. می‌تواند چندخطی باشد. در صورت خالی بودن، محتوای پیش‌فرض نمایش داده می‌شود.',
            'type'  => 'textarea',
        ],
        'owner_name' => [
            'label' => 'نام مدیر سامانه',
            'hint'  => 'نام کامل مدیر سامانه که در پاصفحه و کارت مدیر صفحهٔ درباره نمایش داده می‌شود.',
            'type'  => 'text',
        ],
        'owner_username' => [
            'label' => 'نام کاربری شبکه‌های اجتماعی',
            'hint'  => 'آیدی پیش‌فرض برای شبکه‌های اجتماعی اگر آیدی هر شبکه به‌تنظیم وارد نشده باشد.',
            'type'  => 'text',
        ],
        'owner_image' => [
            'label' => 'تصویر مدیر',
            'hint'  => 'مسیر تصویر مدیر سامانه. از فرم آپلود بالا با کلید owner_image آپلود کنید یا مسیر مستقیم وارد کنید.',
            'type'  => 'image',
        ],
        'social_eitaa' => [
            'label' => 'آیدی ایتا',
            'hint'  => 'آیدی پیام‌رسان ایتا (بدون @). در صورت خالی بودن از «نام کاربری شبکه‌های اجتماعی» استفاده می‌شود.',
            'type'  => 'text',
        ],
        'social_rubika' => [
            'label' => 'آیدی روبیکا',
            'hint'  => 'آیدی پیام‌رسان روبیکا (بدون @). در صورت خالی بودن از «نام کاربری شبکه‌های اجتماعی» استفاده می‌شود.',
            'type'  => 'text',
        ],
        'social_bale' => [
            'label' => 'آیدی بله',
            'hint'  => 'آیدی پیام‌رسان بله (بدون @). در صورت خالی بودن از «نام کاربری شبکه‌های اجتماعی» استفاده می‌شود.',
            'type'  => 'text',
        ],
        'success_message_template' => [
            'label' => 'قالب پیام موفقیت',
            'hint'  => 'قالب پیام موفقیت پس از ثبت‌نام. از {name} برای نام متقاضی و {code} برای کد رهگیری استفاده کنید.',
            'type'  => 'textarea',
        ],
        'registration_fee_amount' => [
            'label' => 'مبلغ ثبت‌نام (تومان)',
            'hint'  => 'مبلغ ثابت هزینهٔ ثبت‌نام اولیه به تومان. در صورت خالی بودن، از نرخ‌نامه استفاده می‌شود.',
            'type'  => 'text',
        ],
        'license_reissue_fee_amount' => [
            'label' => 'مبلغ صدور مجدد مجوز (تومان)',
            'hint'  => 'مبلغ هزینهٔ صدور مجدد مجوز به تومان.',
            'type'  => 'text',
        ],
        'fee_tariff_active' => [
            'label' => 'نرخ‌نامه فعال',
            'hint'  => 'اگر فعال باشد، مبلغ هزینه از نرخ‌نامه خوانده می‌شود؛ در غیر این صورت از مبلغ ثابت بالا استفاده می‌گردد.',
            'type'  => 'select',
            'options' => ['0' => 'غیرفعال', '1' => 'فعال'],
        ],
        'fee_default_amount' => [
            'label' => 'مبلغ پیش‌فرض هزینه (تومان)',
            'hint'  => 'مبلغ هزینه برای مواردی که نرخ‌نامه فعال نیست یا مورد در نرخ‌نامه یافت نشد.',
            'type'  => 'text',
        ],
        'fee_rural_auto_eligible' => [
            'label' => 'معافیت روستایی خودکار',
            'hint'  => 'اگر فعال باشد، متقاضیان دارای نشانی روستایی به‌طور خودکار از هزینه معاف می‌شوند.',
            'type'  => 'select',
            'options' => ['0' => 'غیرفعال', '1' => 'فعال'],
        ],
        'fee_modal_enabled' => [
            'label' => 'نمایش مودال هزینه',
            'hint'  => 'نمایش مودال اطلاع‌رسانی هزینه پیش از تکمیل ثبت‌نام.',
            'type'  => 'select',
            'options' => ['0' => 'غیرفعال', '1' => 'فعال'],
        ],
    ];

    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly Connection $conn,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $settings = $this->loadSettings();
        return $this->render('admin/settings/index', [
            'title'    => 'تنظیمات سامانه',
            'settings' => $settings,
            'known'    => self::KNOWN_SETTINGS,
            'saved'    => false,
            'errors'   => [],
            'message'  => $this->readFlash('settings_message'),
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();

        // Validate each known setting.
        foreach (self::KNOWN_SETTINGS as $key => $meta) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $value = trim((string)$body[$key]);

            // Type-specific validation.
            if ($meta['type'] === 'select') {
                if (!array_key_exists($value, $meta['options'])) {
                    continue;  // skip invalid option
                }
            } elseif ($meta['type'] === 'textarea') {
                // Multi-line settings (about_content, success_message_template)
                // can be long — cap at 16 KB to keep the column reasonable
                // but never silently truncate the user's content to 500 chars.
                if (mb_strlen($value) > 16384) {
                    $value = mb_substr($value, 0, 16384);
                }
            } elseif ($meta['type'] === 'image') {
                // Image-typed settings store a filesystem path. Strip any
                // surrounding whitespace and keep the existing 500-char cap
                // (paths are short — 500 is plenty).
                $value = trim($value);
                if (mb_strlen($value) > 500) {
                    $value = mb_substr($value, 0, 500);
                }
            } elseif ($meta['type'] === 'text') {
                if (mb_strlen($value) > 500) {
                    $value = mb_substr($value, 0, 500);
                }
            }

            $this->upsertSetting($key, $value);
        }

        // Also persist any custom (unknown) settings submitted.
        // Phase 14 (v1.5.0) — pays D-7-4: per-key validation for custom
        // settings. Previously invalid custom keys were silently
        // dropped (no banner shown, admin had no feedback). Now invalid
        // keys are collected into $errors and shown as a warning banner
        // on the settings page.
        $customKeys = $body['custom_keys'] ?? [];
        $customValues = $body['custom_values'] ?? [];
        $errors = [];
        if (is_array($customKeys) && is_array($customValues)) {
            for ($i = 0, $n = count($customKeys); $i < $n; $i++) {
                $k = trim((string)($customKeys[$i] ?? ''));
                $v = trim((string)($customValues[$i] ?? ''));
                if ($k === '') {
                    // Empty key — silently drop (allow rows for "add a new
                    // custom setting" — the empty row at the bottom of the
                    // form has empty key + maybe-empty value).
                    continue;
                }
                if (array_key_exists($k, self::KNOWN_SETTINGS)) {
                    // Custom form shouldn't redefine a known key — silently
                    // drop (the known-setting section above handles it).
                    continue;
                }
                // Per-key validation (D-7-4):
                $error = self::validateCustomKey($k);
                if ($error !== null) {
                    $errors[$k] = $error;
                    continue;
                }
                $this->upsertSetting($k, mb_substr($v, 0, 500));
            }
        }

        $settings = $this->loadSettings();
        return $this->render('admin/settings/index', [
            'title'    => 'تنظیمات سامانه',
            'settings' => $settings,
            'known'    => self::KNOWN_SETTINGS,
            'saved'    => true,
            'errors'   => $errors,
            'message'  => $this->readFlash('settings_message'),
        ]);
    }

    /**
     * POST /admin/settings/upload-image — image upload for site_logo,
     * header banner, and similar small brand assets.
     *
     * Phase 20 (v1.9.2) — ports v2's `/admin/settings/upload-image` to v3.
     * The admin can upload a JPEG/PNG/WEBP image (≤ 2 MB) tied to a
     * `setting_key` (defaults to `site_logo`). The file is saved under
     * `storage/uploads/settings/{key}-{Ymd-His}.{ext}` and the relative
     * path is stored in the `settings` table under that key.
     *
     * Defense:
     *   - Only image/jpeg, image/png, image/webp MIME types are accepted
     *     (verified via finfo, not the browser-supplied type).
     *   - File size capped at 2 MB (defense + UX; the PHP upload_max_filesize
     *     is typically higher but we don't want 50 MB photos in the header).
     *   - The `setting_key` from the user is sanitized to `[a-zA-Z0-9_.-]`
     *     (the same charset enforced by `validateCustomKey()`) — this is
     *     used as the on-disk filename prefix, so path-traversal is
     *     impossible.
     *   - The destination directory is created with 0755 if missing.
     *   - `move_uploaded_file()` is used (not `copy`/`rename`) so the
     *     SAPI-provided tmp_name check is enforced.
     *
     * Returns JSON: `{'success': true, 'data': {'path': 'storage/...'}}`
     * on success, or `{'success': false, 'error': '...'}` with HTTP 422
     * (or 500 on `move_failed`).
     */
    public function uploadImage(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $file = $_FILES['image'] ?? null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return Response::json(['success' => false, 'error' => 'no_file'], 422);
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return Response::json(['success' => false, 'error' => 'upload_error'], 422);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!array_key_exists($mime, $allowed)) {
            return Response::json(['success' => false, 'error' => 'invalid_mime'], 422);
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            return Response::json(['success' => false, 'error' => 'too_large'], 422);
        }
        $settingKey = (string)$request->input('setting_key', 'site_logo');
        $safeKey = preg_replace('/[^a-zA-Z0-9_.-]/', '', $settingKey);
        if ($safeKey === '' || $safeKey === '.' || $safeKey === '..') {
            $safeKey = 'site_logo';
        }
        $filename = $safeKey . '-' . date('Ymd-His') . '.' . $allowed[$mime];
        $dir = ROOT_PATH . '/storage/uploads/settings';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return Response::json(['success' => false, 'error' => 'move_failed'], 500);
        }
        $relativePath = 'storage/uploads/settings/' . $filename;
        $this->upsertSetting($safeKey, $relativePath);
        return Response::json(['success' => true, 'data' => ['path' => $relativePath]]);
    }

    /**
     * POST /admin/settings/delete — permanently delete a CUSTOM (non-known)
     * setting from the `settings` table.
     *
     * Phase 49 (v2.1.5) — full admin control over all system settings.
     * Before this phase, the admin could add or edit any custom key via the
     * "تنظیمات سفارشی" card, but there was no way to remove an obsolete
     * custom key entirely — once added, it stayed in the table forever
     * (the update() loop only ever upserts; it never deletes).
     *
     * Defense:
     *   - Body param `setting_key` is required (non-empty after trim).
     *   - Rejects any key in KNOWN_SETTINGS — known settings have first-
     *     class UI controls and the seeded defaults; deleting one would
     *     silently reset it to the default on the next index() load,
     *     which is confusing. The admin should use the edit form instead.
     *   - The key is run through `validateCustomKey()` so a malicious
     *     input like `sync_direction OR 1=1` is rejected before it ever
     *     reaches SQL (defense in depth — the actual DELETE uses a
     *     bound placeholder, so SQL injection is already impossible,
     *     but this also rejects reserved `_`/`.` prefixes).
     *   - The DELETE is a parameterized prepared statement (no string
     *     concatenation).
     *
     * Outcome: redirect back to /admin/settings with a flash message
     * (`settings_message`) shown at the top of the page — success,
     * "not found" (no row matched the key), or "refused" (known key /
     * invalid key).
     */
    public function delete(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $key = trim((string)($body['setting_key'] ?? ''));

        if ($key === '') {
            $this->session->set('settings_message', 'کلید تنظیم برای حذف وارد نشده است.');
            return $this->redirect('/admin/settings');
        }

        // Refuse known settings — they have first-class UI controls.
        if (array_key_exists($key, self::KNOWN_SETTINGS)) {
            $this->session->set(
                'settings_message',
                'کلید «' . $key . '» یک تنظیم سیستمی است و از این مسیر قابل حذف نیست؛ از فرم ویرایش بالا مقداردهی کنید.'
            );
            return $this->redirect('/admin/settings');
        }

        // Defense in depth — same per-key rules as the custom-add form.
        $error = self::validateCustomKey($key);
        if ($error !== null) {
            $this->session->set('settings_message', 'کلید نامعتبر: ' . $error);
            return $this->redirect('/admin/settings');
        }

        $existing = $this->conn->one('SELECT setting_key FROM settings WHERE setting_key = ?', [$key]);
        if ($existing === null) {
            $this->session->set('settings_message', 'کلید «' . $key . '» در پایگاه داده یافت نشد.');
            return $this->redirect('/admin/settings');
        }

        $this->conn->execute('DELETE FROM settings WHERE setting_key = ?', [$key]);
        $this->logger->info('Custom setting deleted', [
            '_module' => 'core',
            'key'     => $key,
        ]);
        $this->session->set('settings_message', 'تنظیم سفارشی «' . $key . '» حذف شد.');
        return $this->redirect('/admin/settings');
    }

    /**
     * Validate a custom settings key against the per-key rules.
     *
     * Phase 14 (v1.5.0) — pays D-7-4. Rules (also exercised by
     * tests/Unit/SettingsControllerValidationTest.php):
     *   - Allowed chars: [a-zA-Z0-9_.-]+ (no spaces, no Persian).
     *   - Max length: 64 chars (matches the settings.setting_key
     *     VARCHAR(64) column).
     *   - Reserved prefixes: keys starting with `_` or `.` are rejected
     *     (reserved for internal/system keys like `_migration_version`
     *     or `.next_id`).
     *
     * @return string|null  null if valid; a Persian error message otherwise.
     */
    public static function validateCustomKey(string $key): ?string
    {
        if ($key === '') {
            return 'کلید خالی مجاز نیست.';
        }
        if (strlen($key) > 64) {
            return 'کلید بیش از ۶۴ نویسه است.';
        }
        // Reject reserved prefixes (internal / system keys).
        if ($key[0] === '_' || $key[0] === '.') {
            return 'کلیدهای پیشوند «_» یا «.» رزرو شده‌اند.';
        }
        // Allowed chars: a-z A-Z 0-9 _ . -  (no spaces, no Persian).
        // The pattern is anchored (full-match). The `u` flag is not
        // needed because the charset is ASCII-only.
        if (preg_match('/^[a-zA-Z0-9_.\-]+$/', $key) !== 1) {
            return 'کلید فقط می‌تواند شامل حروف انگلیسی، اعداد، زیرخط، نقطه و خط تیره باشد.';
        }
        return null;
    }

    /**
     * Load all settings (known + custom) as key → value map.
     */
    private function loadSettings(): array
    {
        $rows = $this->conn->all('SELECT setting_key, setting_value FROM settings');
        $map = [];
        foreach ($rows as $r) {
            $map[$r['setting_key']] = (string)$r['setting_value'];
        }
        // Ensure all known settings exist (default to '' if missing).
        foreach (array_keys(self::KNOWN_SETTINGS) as $k) {
            if (!isset($map[$k])) {
                $map[$k] = self::defaultValue($k);
            }
        }
        return $map;
    }

    private static function defaultValue(string $key): string
    {
        return match ($key) {
            'sync_direction' => 'inbound',
            'sync_two_way_enabled' => '0',
            'site_name' => 'سامانهٔ مجوز',
            'site_description' => 'صدور مجوز کسب‌وکار خانگی',
            'default_service_type_id' => '1',
            // Phase 43 (v2.0.9) — sensible defaults for the new v2 keys.
            'about_title' => 'دربارهٔ ما',
            'about_content' => '',
            'owner_name' => '',
            'owner_username' => '',
            'owner_image' => '',
            'social_eitaa' => '',
            'social_rubika' => '',
            'social_bale' => '',
            'success_message_template' => "باسلام\n{name} هستم .\nدر سامانه مدارک رو بارگذاری کردم و کد رهگیری {code} را اخذ نمودم .\nبا تشکر {name}",
            'registration_fee_amount' => '',
            'license_reissue_fee_amount' => '',
            'fee_tariff_active' => '0',
            'fee_default_amount' => '',
            'fee_rural_auto_eligible' => '0',
            'fee_modal_enabled' => '0',
            default => '',
        };
    }

    private function upsertSetting(string $key, string $value): void
    {
        $existing = $this->conn->one('SELECT setting_key FROM settings WHERE setting_key = ?', [$key]);
        if ($existing !== null) {
            $this->conn->execute(
                'UPDATE settings SET setting_value = ? WHERE setting_key = ?',
                [$value, $key]
            );
        } else {
            $this->conn->execute(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)',
                [$key, $value]
            );
        }
    }

    /**
     * Read + clear a one-shot flash message from the session.
     *
     * Phase 49 (v2.1.5) — used by index()/update() to surface the
     * `settings_message` set by delete(). The message is removed after
     * being read so a refresh of the settings page does not re-show it.
     */
    private function readFlash(string $key): string
    {
        $value = (string)$this->session->get($key, '');
        if ($value !== '') {
            $this->session->remove($key);
        }
        return $value;
    }
}
