<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Persistence;

use App\Shared\Contracts\NationalLookupRepositoryInterface;
use App\Shared\Persistence\Connection;

/**
 * PDO implementation of NationalLookupRepositoryInterface.
 *
 * Caches upstream lookup data (provinces, townships, organizations) in the
 * `national_lookups` table. The MojavecClient fetches these once + this
 * repository persists them for 24h (CACHE_TTL_LOOKUPS).
 *
 * The `kind` column distinguishes the 4 lookup types; `code` is the upstream
 * identifier; `parent_code` links townships→provinces and sub_orgs→main_orgs.
 */
final class NationalLookupRepository implements NationalLookupRepositoryInterface
{
    public function __construct(private readonly Connection $conn) {}

    public function refreshProvinces(array $provinces): int
    {
        $count = 0;
        foreach ($provinces as $p) {
            $this->upsert('province', (string)$p['id'], (string)$p['title'], null);
            $count++;
        }
        return $count;
    }

    public function refreshTownships(int $provinceId, array $townships): int
    {
        $count = 0;
        foreach ($townships as $t) {
            $this->upsert('township', (string)$t['id'], (string)$t['title'], (string)$provinceId);
            $count++;
        }
        return $count;
    }

    public function townships(?string $provinceId = null): array
    {
        if ($provinceId !== null) {
            return $this->conn->all(
                "SELECT code AS id, title FROM national_lookups WHERE kind = 'township' AND parent_code = ? AND is_active = 1 ORDER BY title",
                [$provinceId]
            );
        }
        return $this->conn->all("SELECT code AS id, title FROM national_lookups WHERE kind = 'township' AND is_active = 1 ORDER BY title");
    }

    public function refreshOrganizations(array $main, array $sub = []): int
    {
        $count = 0;
        foreach ($main as $o) {
            $this->upsert('main_organization', (string)$o['id'], (string)$o['title'], null);
            $count++;
        }
        foreach ($sub as $o) {
            $parent = $o['parent_code'] ?? null;
            $this->upsert('sub_organization', (string)$o['id'], (string)$o['title'], $parent);
            $count++;
        }
        return $count;
    }

    public function provinces(): array
    {
        return $this->conn->all("SELECT code AS id, title FROM national_lookups WHERE kind = 'province' AND is_active = 1 ORDER BY title");
    }

    public function mainOrganizations(): array
    {
        return $this->conn->all("SELECT code AS id, title FROM national_lookups WHERE kind = 'main_organization' AND is_active = 1 ORDER BY title");
    }

    public function subOrganizations(?string $parentCode = null): array
    {
        if ($parentCode !== null) {
            return $this->conn->all(
                "SELECT code AS id, title FROM national_lookups WHERE kind = 'sub_organization' AND parent_code = ? AND is_active = 1 ORDER BY title",
                [$parentCode]
            );
        }
        return $this->conn->all("SELECT code AS id, title FROM national_lookups WHERE kind = 'sub_organization' AND is_active = 1 ORDER BY title");
    }

    public function refreshSubOrganizations(string $mainOrgCode, array $sub): int
    {
        $count = 0;
        foreach ($sub as $o) {
            $this->upsert('sub_organization', (string)$o['id'], (string)$o['title'], $mainOrgCode);
            $count++;
        }
        return $count;
    }

    public function lastRefreshedAt(string $kind): ?string
    {
        $v = $this->conn->scalar(
            "SELECT MAX(refreshed_at) FROM national_lookups WHERE kind = ?",
            [$kind]
        );
        return $v === null || $v === false ? null : (string)$v;
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        // Settings stored as a special lookup row with kind='setting'.
        $row = $this->conn->one(
            "SELECT title FROM national_lookups WHERE kind = 'setting' AND code = ?",
            [$key]
        );
        return $row === null ? $default : (string)$row['title'];
    }

    public function setSetting(string $key, string $value): void
    {
        $this->upsert('setting', $key, $value, null);
    }

    /**
     * Upsert a lookup row (INSERT ... ON DUPLICATE KEY UPDATE).
     */
    private function upsert(string $kind, string $code, string $title, ?string $parentCode): void
    {
        $this->conn->execute(
            'INSERT INTO national_lookups (kind, code, title, parent_code, is_active, refreshed_at) '
            . 'VALUES (?, ?, ?, ?, 1, NOW()) '
            . 'ON DUPLICATE KEY UPDATE title = VALUES(title), parent_code = VALUES(parent_code), is_active = 1, refreshed_at = NOW()',
            [$kind, $code, $title, $parentCode]
        );
    }
}
