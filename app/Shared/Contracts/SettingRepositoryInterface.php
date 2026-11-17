<?php
declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Repository contract for the key/value settings table.
 *
 * Settings are read by all modules but written only by Core (admin panel).
 * The cache is in-memory per-request (memoized after first all()).
 */
interface SettingRepositoryInterface
{
    /**
     * @return array<string,string> all key/value pairs
     */
    public function all(): array;

    public function get(string $key, string $default = ''): string;

    public function set(string $key, string $value): void;

    /**
     * @param array<string,string> $pairs
     */
    public function setMany(array $pairs): void;

    /**
     * The whitelist of keys editable from the admin settings panel.
     *
     * @return array<int,string>
     */
    public function editableKeys(): array;
}
