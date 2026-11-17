<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Shared\Persistence\Connection;

/**
 * SettingsController — admin system settings (key/value store).
 *
 * Per docs/00-MASTER.md §16.5 + §29 — manages the `settings` table.
 * Critical for Rollout Stage 1: the `sync_direction` setting controls
 * whether the national-gateway sync is inbound-only (Stage 1) or two-way
 * (Stage 2, future). Changing it is a runtime no-deploy switch.
 *
 * Operations:
 *   - index():  GET  /admin/settings           list + edit all settings
 *   - update(): POST /admin/settings             bulk-update submitted settings
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
 */
final class SettingsController extends Controller
{
    /** @var array<string,string> Settings known to the UI (key → Persian label + hint). */
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
    ];

    public function __construct(
        \App\Container $container,
        \App\Http\Session $session,
        \App\Http\Csrf $csrf,
        \App\Shared\Support\Environment $environment,
        private readonly Connection $conn,
    ) {
        parent::__construct($container, $session, $csrf, $environment);
    }

    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $settings = $this->loadSettings();
        return $this->render('admin/settings/index', [
            'settings' => $settings,
            'known' => self::KNOWN_SETTINGS,
            'saved' => false,
        ], 'تنظیمات سامانه');
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
            } elseif ($meta['type'] === 'text') {
                if (mb_strlen($value) > 500) {
                    $value = mb_substr($value, 0, 500);
                }
            }

            $this->upsertSetting($key, $value);
        }

        // Also persist any custom (unknown) settings submitted.
        $customKeys = $body['custom_keys'] ?? [];
        $customValues = $body['custom_values'] ?? [];
        if (is_array($customKeys) && is_array($customValues)) {
            for ($i = 0, $n = count($customKeys); $i < $n; $i++) {
                $k = trim((string)($customKeys[$i] ?? ''));
                $v = trim((string)($customValues[$i] ?? ''));
                if ($k !== '' && !array_key_exists($k, self::KNOWN_SETTINGS)) {
                    $this->upsertSetting($k, mb_substr($v, 0, 500));
                }
            }
        }

        $settings = $this->loadSettings();
        return $this->render('admin/settings/index', [
            'settings' => $settings,
            'known' => self::KNOWN_SETTINGS,
            'saved' => true,
        ], 'تنظیمات سامانه');
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
}
