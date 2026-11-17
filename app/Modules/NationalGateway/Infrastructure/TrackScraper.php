<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Infrastructure;

use App\Shared\Support\StructuredLogger;

/**
 * Fetches the extended license detail shown on the upstream "track" page
 * (https://qr.mojavez.ir/track/{request_number}) and extracts the fields
 * that /api/v1/licenses/search does NOT return.
 *
 * === How the track page is built (verified from a real HAR capture) ===
 *
 * The site is a Next.js App Router app. The page is server-rendered and its
 * data is shipped inline as a React Server Components (RSC) "flight" payload:
 * a series of
 *     self.__next_f.push([1, "<json-encoded string>"])
 * calls inside <script> tags. There is NO <script id="__NEXT_DATA__"> blob
 * (that was the older Pages Router format). Each pushed string is itself a
 * JSON-encoded string; concatenated, they form a stream of frames like
 *     <frameId>:<json-value>
 * where the json-values are React element trees of the form
 *     ["$","div",null,{"className":"...","children":"LABEL"}]
 *
 * The license DATA sits as one plain JSON object nested somewhere in that
 * stream, with these EXACT keys (verified):
 *     license_title, organization_title, regulatory_organizations,
 *     isic_code, isic_title, issue_type, responded_at,
 *     old_license_responded_at, expires_at, status{status_id,status_title,status_slug}
 *
 * The applicant identity fields (father name, masked national id, masked
 * mobile) and the business location fields (province, township, postal code,
 * address) are NOT keys in that object — they are rendered as label→value
 * pairs in the RSC element tree:
 *     ...{"children":"کدملی"}...,{"children":"073****269"}...
 *     ...{"children":"نام پدر"}...,{"children":"عبدالكریم"}...
 *     ...{"children":"شماره همراه کسب و کاری"}...,{"children":""}...
 *     ...{"children":"استان"}...,{"children":"خراسان رضوی"}...
 *     ...{"children":"شهرستان"}...,{"children":"تربت جام"}...
 *     ...{"children":"کدپستی"}...,{"children":"9571968***"}...
 *     ...{"children":"نشانی کسب و کار"}...,{"children":"<full address>"}...
 * NOTE the EXACT labels (spelling as the site uses them): "کدملی" (no space),
 * "کدپستی" (no space). The mobile value is EMPTY on the upstream page — the
 * site does not expose it publicly.
 *
 * The business-history table renders as <tr> rows of <td> cells keyed:
 *     index (row number), license_operation, operation_reasons, created_at
 * e.g. {"index":1,"license_operation":"صدور مجوز","operation_reasons":"","created_at":"1405-08-17"}
 *
 * === What this scraper returns ===
 * A normalized array with the keys the license-detail page expects. Fields
 * that the upstream masks (کدملی, کدپستی) are returned as-is — masked — so the
 * UI shows exactly what the official portal shows. Fields the upstream omits
 * (mobile) come back as null and the UI shows "—".
 */
final class TrackScraper
{
    /** Label → value pairs we look for, in order. */
    private const APPLICANT_LABELS = [
        'father_name'  => 'نام پدر',
        'national_id'  => 'کدملی',
        'mobile'       => 'شماره همراه کسب و کاری',
    ];
    private const LOCATION_LABELS = [
        'business_province' => 'استان',
        'business_township' => 'شهرستان',
        'postal_code'        => 'کدپستی',   // no space — matches the live site exactly
        'business_address'   => 'نشانی کسب و کار',
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $upstreamBase,
        private readonly StructuredLogger $logger
    ) {}

    /**
     * Fetch and parse the track page for a license.
     *
     * @return array<string,mixed>
     */
    public function fetch(string $requestNumber, bool $includeAdvancedData = true): array
    {
        $empty = $this->emptyResult($requestNumber);
        if ($requestNumber === '') return $empty;

        $url = $this->upstreamBase . '/track/' . rawurlencode($requestNumber);
        try {
            $response = $this->http->request('GET', $url);
        } catch (\Throwable $e) {
            $this->logger->warning('TrackScraper request failed', [
                'rn' => $requestNumber, 'error' => $e->getMessage(),
            ]);
            $empty['found'] = false;
            $empty['error'] = $e->getMessage();
            return $empty;
        }

        if (!$response->isSuccess()) {
            $this->logger->warning('TrackScraper non-2xx', [
                'rn' => $requestNumber, 'status' => $response->status,
            ]);
            $empty['found'] = false;
            $empty['error'] = 'HTTP ' . $response->status;
            return $empty;
        }

        $html = $response->body;
        $rsc = $this->extractRscPayload($html);
        if ($rsc === '') {
            $this->logger->info('TrackScraper: no RSC payload found', ['rn' => $requestNumber]);
            $empty['found'] = false;
            $empty['error'] = 'no RSC payload in track page';
            return $empty;
        }

        $empty['found'] = true;

        // 1) license data object (isic_code key is its signature)
        $licenseData = $this->findLicenseDataObject($rsc);
        if ($licenseData !== null) {
            $empty['license_title']            = $this->str($licenseData['license_title'] ?? null);
            $empty['issuing_authority']        = $this->str($licenseData['organization_title'] ?? null);
            $empty['supervisory_authorities']  = $this->str($licenseData['regulatory_organizations'] ?? null);
            $empty['isic_code']                = $this->str($licenseData['isic_code'] ?? null);
            $empty['isic_title']               = $this->str($licenseData['isic_title'] ?? null);
            $empty['issuance_type']            = $this->str($licenseData['issue_type'] ?? null);
            // Issue / renewal date: responded_at (Gregorian) — UI converts to Jalali.
            $empty['issue_date']    = $this->str($licenseData['responded_at'] ?? null);
            // Expiry: upstream uses "0000-00-00 00:00:00" to mean "دائمی" (permanent).
            $expiryRaw = $this->str($licenseData['expires_at'] ?? null);
            $empty['expiry_date']   = ($expiryRaw !== '' && strpos($expiryRaw, '0000-00-00') === false)
                ? $expiryRaw : 'دائمی';
            // Status object {status_id, status_title, status_slug} — used by
            // License::fromTrackPage when the batch looks a license up by number.
            $empty['status'] = $licenseData['status'] ?? null;
        }

        // 2) applicant identity (label → next-children value)
        foreach (self::APPLICANT_LABELS as $fieldKey => $label) {
            $val = $this->findLabelValue($rsc, $label);
            // Mobile is publicly empty on the upstream page — keep null so UI shows "—".
            $empty[$fieldKey] = ($val === '' && $fieldKey === 'mobile') ? null : $val;
        }

        // 3) business location (label → next-children value)
        foreach (self::LOCATION_LABELS as $fieldKey => $label) {
            $empty[$fieldKey] = $this->findLabelValue($rsc, $label);
        }

        // 4) business history table rows
        $empty['business_history'] = $this->parseHistoryRows($rsc);

        // 5) tracking code = the request number itself (the /track/{rn} URL)
        $empty['tracking_code'] = $requestNumber;

        // 6) specialized items (اقلام تخصصی) — fetched from the upstream
        // advanced-data API (NOT in the track-page RSC; the Next.js page loads
        // it client-side). Empty list when the license has none. Skipped for
        // the batch path ($includeAdvancedData=false) to avoid the extra call.
        $empty['specialized_items'] = $includeAdvancedData
            ? $this->fetchSpecializedItems($requestNumber)
            : [];

        return $empty;
    }

    /**
     * Extract the concatenated RSC flight payload from the page's
     * self.__next_f.push([1, "<json-string>"]) calls.
     */
    private function extractRscPayload(string $html): string
    {
        // Each push argument is a JSON-encoded string (or array). Pull them all
        // and concatenate. The RSC format is line-oriented frames "id:json".
        if (!preg_match_all('#self\.__next_f\.push\(\[\s*\d+\s*,\s*("(?:[^"\\\\]|\\\\.)*"|\[[^\]]*\])\s*\]\)#s', $html, $m)) {
            return '';
        }
        $blob = '';
        foreach ($m[1] as $encoded) {
            $decoded = json_decode($encoded, true);
            if (is_string($decoded)) {
                $blob .= $decoded;
            } elseif (is_array($decoded)) {
                $blob .= json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $blob;
    }

    /**
     * Find the license data object: scan all balanced JSON objects in the blob
     * and return the first that has the 'isic_code' key.
     *
     * @return array<string,mixed>|null
     */
    private function findLicenseDataObject(string $blob): ?array
    {
        $len = strlen($blob);
        $pos = 0;
        while (($start = strpos($blob, '{', $pos)) !== false) {
            $candidate = $this->scanBalancedObject($blob, $start, $len);
            if ($candidate !== null) {
                $decoded = json_decode($candidate, true);
                if (is_array($decoded) && isset($decoded['isic_code'])) {
                    return $decoded;
                }
            }
            $pos = $start + 1;
        }
        return null;
    }

    /**
     * Find the value rendered right after a label in the RSC tree.
     *
     * The upstream renders each field as two adjacent React elements:
     *     ...{"children":"LABEL"}...,{"children":"VALUE"}...
     * We locate the LABEL string inside a "children" value and capture the
     * NEXT "children" value that follows it. VALUE may be:
     *   - a plain quoted string     → "...","children":"VALUE"
     *   - a bare number             → ...,"children":1
     *   - a string array            → ...,"children":["073****269"," "]  (masked کدملی)
     *   - a React-element array     → ...,"children":["$","span",null,{...,"children":"STR"}]
     */
    private function findLabelValue(string $blob, string $label): string
    {
        // Match "children":"LABEL" (exact, whole string — no partial).
        $needle = '"children":"' . $label . '"';
        $pos = 0;
        while (($at = strpos($blob, $needle, $pos)) !== false) {
            $after = substr($blob, $at + strlen($needle), 600);
            // Look for the next "children":<value> within a 600-char window.
            // NOTE: preg_match() accepts at most 5 arguments (pattern, subject,
            // matches, flags, offset). A 6th "limit" argument throws a fatal
            // ArgumentCountError on PHP 8 (non-variadic built-in — see php.net
            // migration80.incompatible). Bound the window by truncating $after
            // to 600 chars instead of passing a non-existent 6th parameter.
            // (Regression: scripts/test_track_scraper.py mirrors this logic and
            //  verifies every field against the committed track-page HAR fixture.)
            //
            // IMPORTANT: do NOT use the /u (UTF-8) modifier here. The blob
            // contains mixed Persian + Latin + digits + symbols; under /u mode
            // PCRE rejects certain byte sequences as invalid UTF-8 and the
            // whole match returns false (no error, just empty result). Without
            // /u we treat the string as bytes, which is correct here because
            // the regex operates on ASCII structural tokens ("children":, ", [, ])
            // and doesn't care about the Persian label bytes (they're matched
            // by strpos above, not by this regex).
            if (preg_match('/"children":\s*("(?:[^"\\\\]|\\\\.)*"|\d+|\[(?:[^\]]*)\])/', $after, $mm)) {
                $raw = $mm[1];
                $val = $this->extractCellValue($raw);
                if ($val !== '') return $val;
            }
            $pos = $at + strlen($needle);
        }
        return '';
    }

    /**
     * Parse the business-history table rows from the RSC tree.
     *
     * Each row is a sequence of <td> cells keyed:
     *   index, license_operation, operation_reasons, created_at
     * We capture each cell's children value and group them into rows.
     *
     * @return list<array{index:int|string,title:string,operation:string,reasons:string,date:string}>
     */
    private function parseHistoryRows(string $blob): array
    {
        // Match each ["$","td","KEY",{... "children":VALUE}] cell.
        $pattern = '/' .
            '\["\$","td","(index|license_operation|operation_reasons|created_at)",' .
            '\{[^}]*?"children":(.*?)\}\]' .
            '/us';
        if (!preg_match_all($pattern, $blob, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $rows = [];
        $current = null;
        foreach ($matches as $m) {
            $key = $m[1];
            $raw = trim($m[2]);
            $val = $this->extractCellValue($raw);
            if ($current === null) {
                $current = ['index' => '', 'operation' => '', 'reasons' => '', 'date' => ''];
            }
            switch ($key) {
                case 'index':             $current['index'] = $val; break;
                case 'license_operation': $current['operation'] = $val; break;
                case 'operation_reasons': $current['reasons'] = $val; break;
                case 'created_at':
                    $current['date'] = $val;
                    $rows[] = [
                        'index'     => $current['index'],
                        'title'     => $current['operation'],   // main action title alias
                        'operation' => $current['operation'],
                        'reasons'   => $current['reasons'],
                        'date'      => $current['date'],
                    ];
                    $current = null;
                    break;
            }
        }
        return $rows;
    }

    /**
     * Fetch the "اقلام تخصصی برای این مجوز" (specialized items) from the
     * upstream advanced-data API. The track page loads these client-side via
     * GET /api/v1/licenses/{rn}/advanced-data, which returns:
     *   {"data":{"biServices":{"advanceData":{"Status":"OK","Result":[
     *     {"Value":"...","CellsInfoTitle":"...","Children":null}, ...]}}}}
     * Status="ERROR" or an empty Result means the license has no specialized
     * items (the track page then shows "داده ای یافت نشد").
     *
     * Returns a list of {label,value} pairs, or [] on any failure / empty.
     *
     * @return list<array{label:string,value:string}>
     */
    private function fetchSpecializedItems(string $requestNumber): array
    {
        if ($requestNumber === '') {
            return [];
        }
        $url = $this->upstreamBase . '/api/v1/licenses/' . rawurlencode($requestNumber) . '/advanced-data';
        try {
            $response = $this->http->request(
                'GET',
                $url,
                ['Referer' => $this->upstreamBase . '/track/' . rawurlencode($requestNumber)]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('TrackScraper advanced-data request failed', [
                'rn' => $requestNumber, 'error' => $e->getMessage(),
            ]);
            return [];
        }
        if (!$response->isSuccess()) {
            return [];
        }
        $json = json_decode($response->body, true);
        if (!is_array($json)) {
            return [];
        }
        $advance = $json['data']['biServices']['advanceData'] ?? null;
        if (!is_array($advance)) {
            return [];
        }
        if (($advance['Status'] ?? null) !== 'OK') {
            return [];
        }
        $result = $advance['Result'] ?? null;
        if (!is_array($result) || empty($result)) {
            return [];
        }
        $items = [];
        foreach ($result as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = $this->str($row['CellsInfoTitle'] ?? null);
            $value = $this->str($row['Value'] ?? null);
            if ($label === '' && $value === '') {
                continue;
            }
            $items[] = ['label' => $label, 'value' => $value];
        }
        return $items;
    }

    /**
     * Extract the cell value from the raw RSC children fragment.
     * Handles: bare number, quoted string, or ["$","span",null,{...,"children":"STR"}].
     */
    private function extractCellValue(string $raw): string
    {
        $raw = trim($raw);
        // strip a trailing comma + next-element continuation if regex captured too much
        if (preg_match('/^(.+?)\}\s*,\s*\["\$"/s', $raw, $mm)) {
            $raw = $mm[1];
        }
        // bare number
        if (preg_match('/^\d+$/s', $raw)) {
            return $raw;
        }
        // quoted string
        if ($raw !== '' && $raw[0] === '"') {
            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/s', $raw, $mm)) {
                $decoded = json_decode('"' . $mm[1] . '"', true);
                return is_string($decoded) ? trim($decoded) : '';
            }
        }
        // array — could be either:
        //   ["073****269"," "]               (raw string elements, e.g. masked کدملی)
        //   ["$","span",null,{...,"children":"STR"}]  (a React element subtree)
        // Try parsing the whole array; if it yields string elements, take the
        // first non-empty one. Otherwise fall back to searching for an inner
        // "children":"STR" (the React-element case).
        if ($raw !== '' && $raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $el) {
                    if (is_string($el) && trim($el) !== '') {
                        return trim($el);
                    }
                }
            }
            // React-element array — search for the inner children string.
            if (preg_match('/"children":"((?:[^"\\\\]|\\\\.)*)"/u', $raw, $mm)) {
                $decoded = json_decode('"' . $mm[1] . '"', true);
                return is_string($decoded) ? trim($decoded) : '';
            }
        }
        return '';
    }

    /**
     * Scan a balanced JSON object {...} starting at $start in $s.
     * Returns the substring (including braces) or null if unbalanced.
     */
    private function scanBalancedObject(string $s, int $start, int $len): ?string
    {
        $depth = 0;
        $inStr = false;
        $esc = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = $s[$i];
            if ($inStr) {
                if ($esc) { $esc = false; }
                elseif ($ch === '\\') { $esc = true; }
                elseif ($ch === '"') { $inStr = false; }
                continue;
            }
            if ($ch === '"') { $inStr = true; continue; }
            if ($ch === '{') { $depth++; }
            elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }

    /**
     * Coerce a value to a trimmed string; null/empty → ''.
     */
    private function str(mixed $v): string
    {
        if ($v === null) return '';
        if (is_string($v)) return trim($v);
        if (is_int($v) || is_float($v)) return (string)$v;
        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyResult(string $requestNumber): array
    {
        return [
            'found' => true,
            'error' => null,
            'request_number' => $requestNumber,
            'father_name' => null,
            'national_id' => null,
            'mobile' => null,
            'issuing_authority' => null,
            'supervisory_authorities' => null,
            'tracking_code' => $requestNumber,
            'isic_code' => null,
            'isic_title' => null,
            'issuance_type' => null,
            'issue_date' => null,
            'expiry_date' => null,
            'license_title' => null,
            'business_province' => null,
            'business_township' => null,
            'postal_code' => null,
            'business_address' => null,
            'business_history' => [],
            'status' => null,
            'specialized_items' => [],
        ];
    }
}
