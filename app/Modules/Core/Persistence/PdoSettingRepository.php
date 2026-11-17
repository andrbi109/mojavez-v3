<?php
declare(strict_types=1);

namespace App\Modules\Core\Persistence;

use App\Shared\Contracts\SettingRepositoryInterface;

/**
 * Settings repository — key/value with in-request memoization.
 *
 * Inherited from v2's Setting model. The `all()` query is run at most once
 * per request; subsequent `get()` calls hit the cache.
 */
final class PdoSettingRepository extends PdoRepository implements SettingRepositoryInterface
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows = $this->all('SELECT setting_key, setting_value FROM settings');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['setting_key']] = (string)$r['setting_value'];
        }
        $this->cache = $out;
        return $out;
    }

    public function get(string $key, string $default = ''): string
    {
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        // INSERT ... ON DUPLICATE KEY UPDATE — the `settings` table uses
        // setting_key as the PK so this upsert works cleanly.
        $this->execute(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()',
            [$key, $value]
        );
        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }

    public function setMany(array $pairs): void
    {
        $this->transactional(function () use ($pairs): void {
            foreach ($pairs as $k => $v) {
                $this->set((string)$k, (string)$v);
            }
        });
    }

    public function editableKeys(): array
    {
        return [
            'site_name', 'site_description', 'about_text', 'guide_text',
            'owner_name', 'owner_contact', 'social_links',
            'fee_tariff_active', 'fee_tariff_valid_until',
            'fee_default_amount', 'fee_rural_auto_eligible',
            'fee_modal_enabled', 'fee_modal_dismissable',
            'fee_fallback_group_code', 'fee_min_amount_percent',
            'fee_max_discount_without_reason', 'fee_max_discount_with_reason',
            // NEW in v3: golden-link rollout toggle (per docs/00-MASTER.md §29.3).
            'sync_two_way_enabled',
        ];
    }
}
