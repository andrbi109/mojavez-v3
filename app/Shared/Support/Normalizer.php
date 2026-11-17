<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Input normalization utilities.
 *
 * Two responsibilities:
 *   1. Digit normalization — Persian/Arabic-Indic digits → Latin.
 *      Required before any numeric comparison, ID parsing, or DB lookup.
 *      (CODING_CONVENTIONS §2: "همیشه با App\Support\Normalizer::digits()".)
 *   2. National code / mobile normalization — strip non-digits, validate
 *      length, return null on malformed input.
 *
 * ZWNJ (\u200c) is preserved (it's significant in Persian text). Only
 * leading/trailing whitespace and Latin-space separators are stripped.
 */
final class Normalizer
{
    /** Persian digits ۰-۹ → 0-9. */
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /** Arabic-Indic digits ٠-٩ → 0-9. */
    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    /**
     * Normalize all Persian and Arabic-Indic digits in a string to Latin.
     */
    public static function digits(string $value): string
    {
        return str_replace(
            array_merge(self::PERSIAN_DIGITS, self::ARABIC_DIGITS),
            range('0', '9'),
            $value
        );
    }

    /**
     * Normalize a national code:
     *   - convert Persian/Arabic digits to Latin
     *   - strip spaces, dashes, dots
     *   - pad left with zeros to 10 digits if shorter
     *   - return null if not 10 digits after normalization
     *
     * Note: padding is intentional — Iranian national codes with leading
     * zeros are often written as "721593107" (9 chars) by users.
     */
    public static function nationalCode(string $raw): ?string
    {
        $digits = self::digits($raw);
        $digits = preg_replace('/[^0-9]/', '', $digits) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) > 10) {
            return null;
        }
        // Pad with leading zeros to 10 chars.
        return str_pad($digits, 10, '0', STR_PAD_LEFT);
    }

    /**
     * Normalize a mobile number to the canonical `09XXXXXXXXX` form
     * (11 digits, starts with 09). Returns null if invalid.
     */
    public static function mobile(string $raw): ?string
    {
        $digits = self::digits($raw);
        $digits = preg_replace('/[^0-9]/', '', $digits) ?? '';
        if ($digits === '') {
            return null;
        }
        // Strip leading Iran country code 98 or +98.
        if (str_starts_with($digits, '98')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 4);
        }
        // Mobile must start with 09 and be 11 digits.
        if (strlen($digits) !== 11) {
            return null;
        }
        if (!str_starts_with($digits, '09')) {
            return null;
        }
        return $digits;
    }

    /**
     * Normalize Persian text: trim, collapse runs of whitespace (preserving
     * ZWNJ), strip control characters. Useful for name / address input.
     */
    public static function persianText(string $raw): string
    {
        // Strip control chars except \t (kept for inline tab input).
        $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw) ?? $raw;
        // Collapse runs of whitespace (spaces, tabs, newlines) into one space,
        // but preserve ZWNJ (\u200c).
        $raw = preg_replace('/[ \t\r\n]+/u', ' ', $raw) ?? $raw;
        return trim($raw);
    }

    /**
     * Convert a national code's middle digits into a masked pattern for
     * golden-link matching (e.g. "0720593107" → "072****107").
     *
     * The first 3 and last 3 digits are anchors (visible in upstream's
     * masked output), the middle 4 are masked. See docs/00-MASTER.md §9.1.
     */
    public static function nationalCodeMask(string $fullCode): string
    {
        $code = self::nationalCode($fullCode);
        if ($code === null || strlen($code) !== 10) {
            return '';
        }
        return substr($code, 0, 3) . '****' . substr($code, -3);
    }
}
