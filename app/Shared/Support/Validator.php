<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Input validation utilities — whitelist-first, no regex-only for
 * security-critical checks.
 *
 * Inherited from gateway's Validator class, extended for v3 with:
 *  - nationalCode() — Iranian national code check (10 digits, parity check).
 *  - trackingCode() — HG-XXXXXXXX format.
 *  - phoneNumber() — Iranian mobile format 09XXXXXXXXX.
 *
 * All methods return nullable typed results (null = invalid). This pattern
 * keeps the caller code readable: `if (($id = Validator::nationalCode($raw)) === null) {…}`.
 */
final class Validator
{
    /** @var array<int,string> */
    private static array $persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /**
     * Validate a non-empty trimmed string with reasonable length.
     */
    public static function nonEmptyString(mixed $value, int $max = 255): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            return null;
        }
        return $value;
    }

    public static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int)$value;
        }
        return null;
    }

    public static function positiveIntOrNull(mixed $value): ?int
    {
        $i = self::intOrNull($value);
        return ($i !== null && $i > 0) ? $i : null;
    }

    /**
     * Validate Jalali date string: YYYY/MM/DD with 4-digit year,
     * 1-12 month, 1-31 day. Also accepts Persian digits.
     */
    public static function jalaliDateOrNull(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        // Normalize Persian digits.
        $value = str_replace(self::$persianDigits, range('0', '9'), $value);
        if (!preg_match('#^\d{4}/(0?[1-9]|1[0-2])/(0?[1-9]|[12]\d|3[01])$#', $value)) {
            return null;
        }
        return $value;
    }

    /**
     * @param array<int,string> $allowed
     */
    public static function inEnum(mixed $value, array $allowed): ?string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            return null;
        }
        return $value;
    }

    /**
     * Sanitize a string for safe display — strip control chars,
     * normalize whitespace, truncate to max length.
     */
    public static function sanitizeText(mixed $value, int $max = 1000): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        if (mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }
        return $value;
    }

    /**
     * Validate an Iranian national code (کد ملی) — 10 digits + parity check.
     * Returns the canonical 10-digit form (left-padded with zeros), or null.
     */
    public static function nationalCode(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $normalized = Normalizer::nationalCode($value);
        if ($normalized === null || strlen($normalized) !== 10) {
            return null;
        }
        // Parity check: sum of first 9 digits × weight mod 11.
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$normalized[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        $parity = (int)$normalized[9];
        $valid = ($remainder < 2)
            ? ($parity === $remainder)
            : ($parity === (11 - $remainder));
        return $valid ? $normalized : null;
    }

    /**
     * Validate a tracking code in the format HG-XXXXXXXX where X is [A-Z0-9].
     */
    public static function trackingCode(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^HG-[A-Z0-9]{8}$/', $value)) {
            return null;
        }
        return $value;
    }

    /**
     * Validate an Iranian mobile phone number (09XXXXXXXXX).
     */
    public static function mobile(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return Normalizer::mobile($value);
    }

    /**
     * Validate that a value is a positive integer in a given inclusive range.
     */
    public static function intInRange(mixed $value, int $min, int $max): ?int
    {
        $i = self::intOrNull($value);
        if ($i === null || $i < $min || $i > $max) {
            return null;
        }
        return $i;
    }

    /**
     * Validate a request number from the national gateway
     * (e.g. "I17981871" or "Q-12345678"). Allows alphanumeric, dash, underscore.
     */
    public static function nationalRequestNumber(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $value)) {
            return null;
        }
        return $value;
    }
}
