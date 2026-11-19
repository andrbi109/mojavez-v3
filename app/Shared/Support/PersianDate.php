<?php
declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Jalali (Persian Solar) date utilities.
 *
 * Uses the Borkowski algorithm (exact within Jalaali year range -61 … 3177),
 * ported from the canonical jalaali-js package (jalaali/jalaali-js on GitHub)
 * which is the de-facto reference for Jalali ↔ Gregorian conversion.
 *
 * @see https://github.com/jalaali/jalaali-js
 * @see https://www.astro.uni.torun.pl/~kb/Papers/EMP/PersianC-EMP.htm
 *
 * All public methods accept/return arrays or strings in YYYY/MM/DD form.
 */
final class PersianDate
{
    /**
     * Jalaali years that begin a new 33-year leap cycle (Borkowski breaks).
     */
    private const BREAKS = [
        -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181,
        1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178,
    ];

    private const MIN_JY = -61;
    private const MAX_JY = 3177;

    private static function div(int $a, int $b): int
    {
        // Truncated division (toward zero), matching jalaali-js's `~~(a/b)`.
        $q = intdiv($a, $b);
        // intdiv already truncates toward zero in PHP 7+; nothing more to do.
        return $q;
    }

    private static function mod(int $a, int $b): int
    {
        return $a - self::div($a, $b) * $b;
    }

    /**
     * Core Borkowski calculation for a Jalaali year: returns the Gregorian
     * year in which Farvardin 1 falls, and the March day (1-31 Gregorian)
     * of Farvardin 1. Same as jalaali-js's `jalCalCore`.
     *
     * @return array{gy:int, march:int, jump:int, n:int}
     */
    private static function jalCalCore(int $jy): array
    {
        if ($jy < self::MIN_JY || $jy > self::MAX_JY) {
            throw new \InvalidArgumentException(
                "Invalid Jalaali year {$jy}: must be between " . self::MIN_JY . " and " . self::MAX_JY
            );
        }
        $gy = $jy + 621;
        $leapJ = -14;
        $jp = self::BREAKS[0];
        $jm = 0;
        $jump = 0;
        $breaksCount = count(self::BREAKS);
        for ($i = 1; $i < $breaksCount; $i++) {
            $jm = self::BREAKS[$i];
            $jump = $jm - $jp;
            if ($jy < $jm) break;
            $leapJ = $leapJ + self::div($jump, 33) * 8 + self::div(self::mod($jump, 33), 4);
            $jp = $jm;
        }
        $n = $jy - $jp;
        $leapJ = $leapJ + self::div($n, 33) * 8 + self::div(self::mod($n, 33) + 3, 4);
        if (self::mod($jump, 33) === 4 && $jump - $n === 4) $leapJ += 1;
        $leapG = self::div($gy, 4) - self::div((self::div($gy, 100) + 1) * 3, 4) - 150;
        $march = 20 + $leapJ - $leapG;
        return ['gy' => $gy, 'march' => $march, 'jump' => $jump, 'n' => $n];
    }

    /**
     * Number of years since the last leap year (0 if `jy` IS a leap year).
     * Same as jalaali-js's `leapFromCycle`.
     */
    private static function leapFromCycle(int $jump, int $n): int
    {
        $adjusted = $n;
        if ($jump - $n < 6) {
            $adjusted = $n - $jump + self::div($jump + 4, 33) * 33;
        }
        $leap = self::mod(self::mod($adjusted + 1, 33) - 1, 4);
        if ($leap === -1) $leap = 4;
        return $leap;
    }

    /**
     * jalCal = jalCalCore + leap computation. Returns {gy, march, leap}.
     *
     * @return array{gy:int, march:int, leap:int}
     */
    private static function jalCal(int $jy): array
    {
        $core = self::jalCalCore($jy);
        return [
            'gy' => $core['gy'],
            'march' => $core['march'],
            'leap' => self::leapFromCycle($core['jump'], $core['n']),
        ];
    }

    /**
     * Convert Gregorian (Y, M, D) to a Julian Day Number (proleptic Gregorian).
     * Same as jalaali-js's `g2d`.
     */
    private static function g2d(int $gy, int $gm, int $gd): int
    {
        $d = self::div(($gy + self::div($gm - 8, 6) + 100100) * 1461, 4)
            + self::div(153 * self::mod($gm + 9, 12) + 2, 5)
            + $gd - 34840408;
        $d = $d - self::div(self::div($gy + 100100 + self::div($gm - 8, 6), 100) * 3, 4) + 752;
        return $d;
    }

    /**
     * Convert a Julian Day Number to Gregorian (Y, M, D).
     * Same as jalaali-js's `d2g`.
     *
     * @return array{0:int,1:int,2:int} [gy, gm, gd]
     */
    private static function d2g(int $jdn): array
    {
        $j = 4 * $jdn + 139361631;
        $j = $j + self::div(self::div(4 * $jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
        $i = self::div(self::mod($j, 1461), 4) * 5 + 308;
        $gd = self::div(self::mod($i, 153), 5) + 1;
        $gm = self::mod(self::div($i, 153), 12) + 1;
        $gy = self::div($j, 1461) - 100100 + self::div(8 - $gm, 6);
        return [$gy, $gm, $gd];
    }

    /**
     * Convert a Jalaali date to its Julian Day Number.
     * Same as jalaali-js's `j2d`.
     */
    private static function j2d(int $jy, int $jm, int $jd): int
    {
        $r = self::jalCalCore($jy);
        return self::g2d($r['gy'], 3, $r['march'])
            + ($jm - 1) * 31
            - self::div($jm, 7) * ($jm - 7)
            + $jd - 1;
    }

    /**
     * Convert Jalaali (Y, M, D) to Gregorian (Y, M, D).
     *
     * @return array{0:int,1:int,2:int} [gregorianYear, gregorianMonth, gregorianDay]
     */
    public function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jdn = self::j2d($jy, $jm, $jd);
        return self::d2g($jdn);
    }

    /**
     * Convert Gregorian (Y, M, D) to Jalali (Y, M, D).
     *
     * @return array{0:int,1:int,2:int} [jalaliYear, jalaliMonth, jalaliDay]
     */
    public function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $jdn = self::g2d($gy, $gm, $gd);
        return $this->d2j($jdn);
    }

    /**
     * Convert a Julian Day Number to Jalaali (Y, M, D).
     * Same as jalaali-js's `d2j`.
     *
     * @return array{0:int,1:int,2:int} [jy, jm, jd]
     */
    private function d2j(int $jdn): array
    {
        $gy = self::d2g($jdn)[0];
        // In the last supported year the Gregorian year runs one ahead of the
        // Jalaali year, so gy - 621 can land on MAX + 1; clamp before jalCal.
        $jy = min($gy - 621, self::MAX_JY);
        $r = self::jalCal($jy);
        $jdn1f = self::g2d($r['gy'], 3, $r['march']);

        // Days since Farvardin 1.
        $k = $jdn - $jdn1f;
        if ($k >= 0) {
            if ($k <= 185) {
                // First six months are 31 days each.
                return [$jy, 1 + self::div($k, 31), self::mod($k, 31) + 1];
            }
            // Remaining months are 30 days each.
            $k -= 186;
        } else {
            // The JDN falls in the *previous* Jalaali year.
            $jy -= 1;
            $k += 179;
            if ($r['leap'] === 1) $k += 1;
        }
        return [$jy, 7 + self::div($k, 30), self::mod($k, 30) + 1];
    }

    /**
     * Format a Gregorian datetime string (e.g. "2026-11-11 22:32:22")
     * as a Jalali date string "1405/08/21".
     */
    public function formatGregorianDateTime(string $gregorianDateTime): string
    {
        $ts = strtotime($gregorianDateTime);
        if ($ts === false) return '';
        [$jy, $jm, $jd] = $this->gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    /**
     * Format a Gregorian datetime as Jalali with time "1405/08/21 22:32".
     */
    public function formatGregorianDateTimeFull(string $gregorianDateTime): string
    {
        $ts = strtotime($gregorianDateTime);
        if ($ts === false) return '';
        [$jy, $jm, $jd] = $this->gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, date('H:i', $ts));
    }

    /**
     * Static convenience: format a Gregorian datetime (with optional
     * time component) as a Jalali string "1405/08/26 18:01".
     *
     * Phase 45 (v2.1.1) — added so views can format dates without an
     * injected $persianDate instance (just call the static method
     * directly with the FQCN). Returns '—' for empty/zero dates so
     * views don't have to special-case them.
     *
     * If the input has no time component (just "2026-11-17"), the
     * output is "1405/08/26" (no time). If it has a time component
     * ("2026-11-17 18:01:49"), the output is "1405/08/26 18:01".
     */
    public static function format(string $gregorianDateTime): string
    {
        if ($gregorianDateTime === '' || $gregorianDateTime === '0000-00-00' || $gregorianDateTime === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($gregorianDateTime);
        if ($ts === false) {
            return $gregorianDateTime;
        }
        $date = getdate($ts);
        $instance = new self();
        [$jy, $jm, $jd] = $instance->gregorianToJalali($date['year'], $date['mon'], $date['mday']);
        // Detect whether the input has a time component (any non-zero
        // hour/minute/second). If so, append "HH:MM" to the Jalali date.
        $hasTime = (int)date('H', $ts) !== 0
            || (int)date('i', $ts) !== 0
            || (int)date('s', $ts) !== 0
            || (bool)preg_match('/\d{1,2}:\d{2}(:\d{2})?\s*$/', $gregorianDateTime);
        if ($hasTime) {
            return sprintf('%04d/%02d/%02d %02d:%02d', $jy, $jm, $jd, $date['hours'], $date['minutes']);
        }
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    /**
     * Convert a Jalali date string "1405/08/21" to Gregorian "2026-11-12".
     * Returns null if the input is malformed or out of the supported range.
     */
    public function jalaliStringToGregorian(string $jalali): ?string
    {
        if (!preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $jalali, $m)) {
            return null;
        }
        try {
            [$gy, $gm, $gd] = $this->jalaliToGregorian((int)$m[1], (int)$m[2], (int)$m[3]);
        } catch (\Throwable $e) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /**
     * Today's Jalali date as "YYYY/MM/DD".
     */
    public function todayJalali(): string
    {
        [$jy, $jm, $jd] = $this->gregorianToJalali((int)date('Y'), (int)date('n'), (int)date('j'));
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    /**
     * Persian month names.
     */
    public function monthName(int $month): string
    {
        $names = [
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
            5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
            9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
        ];
        return $names[$month] ?? '';
    }
}
