<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Infrastructure\Parser;

/**
 * JSON parser for qr.mojavez.ir responses.
 *
 * The site wraps results in {"data": { ... }} envelope:
 *   - Search:                {"data":{"filterLicenses":{"license":[...]}}}
 *   - Count:                 {"data":{"countFilteredLicenses":{"total":N}}}
 *   - Provinces:             {"data":{"provinceTownship":{"provinces":[...]}}}
 *   - Townships (per prov):  {"data":{"provinceTownship":{"townshipsOfProvince":[...]}}}
 *   - Main organizations:    {"data":{"organization":{"main":[...]}}}
 *   - Sub-organizations:     {"data":{"organization":{"sub":[...]}}}
 */
final class JsonSearchParser implements ResponseParserInterface
{
    public function parseSearch(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['licenses' => [], 'total' => null];
        }
        $filterLicenses = $decoded['data']['filterLicenses'] ?? [];
        if (!is_array($filterLicenses)) {
            $filterLicenses = [];
        }
        $licenses = $filterLicenses['license'] ?? [];
        if (!is_array($licenses)) {
            $licenses = [];
        }
        return ['licenses' => array_values($licenses), 'total' => null];
    }

    public function parseCount(string $body): ?int
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }
        $total = $decoded['data']['countFilteredLicenses']['total'] ?? null;
        return is_numeric($total) ? (int)$total : null;
    }

    public function parseProvinces(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }
        $provinces = $decoded['data']['provinceTownship']['provinces'] ?? [];
        if (!is_array($provinces)) return [];

        $out = [];
        foreach ($provinces as $p) {
            if (isset($p['id'], $p['title'])) {
                $out[] = ['id' => (string)$p['id'], 'title' => (string)$p['title']];
            }
        }
        return $out;
    }

    /**
     * Parse townships for a province (from /lookups/provinces/{id}/townships).
     * Structure: {"data":{"provinceTownship":{"townshipsOfProvince":[...]}}}
     *
     * @return list<array{id:string,title:string}>
     */
    public function parseTownships(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }
        $townships = $decoded['data']['provinceTownship']['townshipsOfProvince'] ?? [];
        if (!is_array($townships)) return [];

        $out = [];
        foreach ($townships as $t) {
            if (isset($t['id'], $t['title'])) {
                $out[] = ['id' => (string)$t['id'], 'title' => (string)$t['title']];
            }
        }
        return $out;
    }

    public function parseOrganizations(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['main' => [], 'sub' => []];
        }
        $org = $decoded['data']['organization'] ?? [];
        $main = $org['main'] ?? [];
        $sub = $org['sub'] ?? [];

        $toOut = static function (array $list): array {
            $out = [];
            foreach ($list as $o) {
                if (isset($o['id'], $o['title'])) {
                    $out[] = ['id' => (string)$o['id'], 'title' => (string)$o['title']];
                }
            }
            return $out;
        };

        return [
            'main' => $toOut(is_array($main) ? $main : []),
            'sub' => $toOut(is_array($sub) ? $sub : []),
        ];
    }

    /**
     * Parse sub-organizations for a main org (from /lookups/organizations/{id}/subs).
     * Structure: {"data":{"organization":{"sub":[...]}}}
     *
     * @return list<array{id:string,title:string}>
     */
    public function parseSubOrganizations(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }
        $sub = $decoded['data']['organization']['sub'] ?? [];
        if (!is_array($sub)) return [];

        $out = [];
        foreach ($sub as $o) {
            if (isset($o['id'], $o['title'])) {
                $out[] = ['id' => (string)$o['id'], 'title' => (string)$o['title']];
            }
        }
        return $out;
    }
}
