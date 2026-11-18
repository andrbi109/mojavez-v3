<?php
declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Shared\Contracts\UserAccountRepositoryInterface;
use App\Shared\Support\Normalizer;

/**
 * ProfileUpdateValidator — validates the POST body of `/panel/profile`.
 *
 * Static class, same pattern as `App\Shared\Support\Validator` — methods
 * are static so callers don't have to wire the validator through the
 * Container (the dependencies it needs are passed as method args).
 *
 * Resolves debt D-9-1 (the last medium-priority debt after Phase 11).
 *
 * Validates four optional fields (any may be omitted — the validator
 * only checks fields that are present in the body):
 *
 *   - `username`     3-50 chars, alphanumeric + `_` + `.`, unique
 *                    across other accounts. Normalised to lower-case.
 *   - `mobile`       Iranian mobile `09\d{9}`, unique across other
 *                    accounts. Returned as-is (already in canonical form).
 *   - `email`        `filter_var(FILTER_VALIDATE_EMAIL)`, max 254 chars.
 *   - `display_name` 1-100 chars (Persian allowed).
 *
 * Returns an array with three keys:
 *
 *   - `valid`  bool
 *   - `errors` array<int,string>   Persian user-facing messages
 *   - `clean`  array<string,mixed> The validated + normalised values
 *                                  (only fields that were provided
 *                                  AND passed validation are present).
 *
 * On any failure, `valid=false`, `errors` is a non-empty Persian list,
 * and `clean` contains only the safe-to-return fields (those that did
 * pass).
 *
 * Schema note: as of v1.3.0, the `user_accounts` table only has
 * `username` and `mobile` columns — `email` and `display_name` are
 * validated (for forward compatibility) but are NOT persisted by
 * the panel's update action because the columns don't exist. This
 * is tracked as low-priority debt D-12-1 (see `docs/DEBT_REVIEW.md`
 * Phase 12 section).
 */
final class ProfileUpdateValidator
{
    /**
     * Validate the POST body of `/panel/profile`.
     *
     * @param array<string,mixed>            $body
     * @param UserAccountRepositoryInterface $repo
     * @param int                            $accountId  The current user's id
     *                                                  (excluded from
     *                                                  uniqueness checks).
     *
     * @return array{valid:bool, errors:array<int,string>, clean:array<string,mixed>}
     */
    public static function validate(array $body, UserAccountRepositoryInterface $repo, int $accountId): array
    {
        $errors = [];
        $clean  = [];

        // ----- username -----
        if (array_key_exists('username', $body)) {
            $raw = self::toStringOrNull($body['username']);
            if ($raw === null || trim($raw) === '') {
                // Empty/missing username → user wants to CLEAR it.
                // The schema allows NULL on user_accounts.username, so
                // we accept and persist `null`.
                $clean['username'] = null;
            } else {
                $uname = strtolower(trim($raw));
                $len = strlen($uname);
                if ($len < 3 || $len > 50) {
                    $errors[] = 'نام کاربری باید ۳ تا ۵۰ نویسه باشد.';
                } elseif (!preg_match('/^[a-z0-9_.]+$/', $uname)) {
                    $errors[] = 'نام کاربری تنها می‌تواند شامل حروف کوچک انگلیسی، عدد، زیرخط و نقطه باشد.';
                } elseif (self::usernameTaken($uname, $repo, $accountId)) {
                    $errors[] = 'این نام کاربری قبلاً ثبت شده است.';
                } else {
                    $clean['username'] = $uname;
                }
            }
        }

        // ----- mobile -----
        if (array_key_exists('mobile', $body)) {
            $raw = self::toStringOrNull($body['mobile']);
            if ($raw === null || trim($raw) === '') {
                $errors[] = 'شماره موبایل نمی‌تواند خالی باشد.';
            } else {
                $mobile = Normalizer::mobile($raw);
                if ($mobile === null) {
                    $errors[] = 'شماره موبایل نامعتبر است (مثال: 09123456789).';
                } elseif (self::mobileTaken($mobile, $repo, $accountId)) {
                    $errors[] = 'این شماره موبایل قبلاً ثبت شده است.';
                } else {
                    $clean['mobile'] = $mobile;
                }
            }
        }

        // ----- email -----
        if (array_key_exists('email', $body)) {
            $raw = self::toStringOrNull($body['email']);
            if ($raw === null || trim($raw) === '') {
                // Empty email = user wants to clear it.
                $clean['email'] = null;
            } else {
                $email = trim($raw);
                if (strlen($email) > 254) {
                    $errors[] = 'ایمیل نباید بیش از ۲۵۴ نویسه باشد.';
                } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = 'ایمیل نامعتبر است.';
                } else {
                    $clean['email'] = $email;
                }
            }
        }

        // ----- display_name -----
        if (array_key_exists('display_name', $body)) {
            $raw = self::toStringOrNull($body['display_name']);
            if ($raw === null || trim($raw) === '') {
                $clean['display_name'] = null;
            } else {
                $name = trim($raw);
                $len = mb_strlen($name);
                if ($len < 1 || $len > 100) {
                    $errors[] = 'نام نمایشی باید ۱ تا ۱۰۰ نویسه باشد.';
                } else {
                    $clean['display_name'] = $name;
                }
            }
        }

        return [
            'valid'  => $errors === [],
            'errors' => $errors,
            'clean'  => $clean,
        ];
    }

    // ---------------- private helpers ----------------

    /**
     * Coerce a value to a string or null. Reject non-scalar (arrays,
     * objects) outright — they shouldn't be in the POST body.
     */
    private static function toStringOrNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        // Arrays, objects, bools — not valid for these fields.
        return null;
    }

    /**
     * Has another account already taken this username?
     */
    private static function usernameTaken(string $username, UserAccountRepositoryInterface $repo, int $accountId): bool
    {
        $other = $repo->findByUsername($username);
        return $other !== null && (int)$other['id'] !== $accountId;
    }

    /**
     * Has another account already taken this mobile?
     */
    private static function mobileTaken(string $mobile, UserAccountRepositoryInterface $repo, int $accountId): bool
    {
        $other = $repo->findByMobile($mobile);
        return $other !== null && (int)$other['id'] !== $accountId;
    }
}
