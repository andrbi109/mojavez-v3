<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for SettingsController::validateCustomKey (Phase 14 / D-7-4).
 *
 * Verifies the per-key validation rules for custom settings:
 *   - Allowed chars: [a-zA-Z0-9_.-]+ (no spaces, no Persian).
 *   - Max length: 64 chars.
 *   - Reject reserved prefixes `_` and `.`.
 *   - Reject empty.
 *   - Accept valid snake_case + dot + dash + numeric keys.
 *
 * This is a pure unit test (no DB) — validateCustomKey is a public
 * static method, so we can call it directly without booting the
 * Container or constructing the controller (which requires a Session,
 * Csrf, etc.).
 */
final class SettingsControllerValidationTest extends TestCase
{
    public function name(): string
    {
        return 'SettingsControllerValidationTest';
    }

    public function run(): void
    {
        $cls = \App\Modules\Core\Controllers\SettingsController::class;

        // 1. Valid snake_case keys → null (valid).
        $this->assertNull(
            $cls::validateCustomKey('my_setting'),
            'valid snake_case key passes'
        );
        $this->assertNull(
            $cls::validateCustomKey('feature_x_enabled'),
            'valid snake_case with longer name passes'
        );

        // 2. Valid keys with dots / dashes → null (valid).
        $this->assertNull(
            $cls::validateCustomKey('feature.flag'),
            'valid key with dot passes'
        );
        $this->assertNull(
            $cls::validateCustomKey('feature-flag'),
            'valid key with dash passes'
        );

        // 3. Valid key with numbers → null.
        $this->assertNull(
            $cls::validateCustomKey('cron_interval_30s'),
            'valid key with numbers passes'
        );

        // 4. Valid key at the max length boundary (64 chars) → null.
        $maxKey = str_repeat('a', 64);
        $this->assertSame(64, strlen($maxKey), 'precondition: max-key is 64 chars');
        $this->assertNull(
            $cls::validateCustomKey($maxKey),
            'key at exactly 64 chars passes'
        );

        // 5. Key longer than 64 chars → rejected.
        $longKey = str_repeat('a', 65);
        $errLong = $cls::validateCustomKey($longKey);
        $this->assertNotNull($errLong, 'key > 64 chars rejected');
        $this->assertStringContainsString('۶۴', (string)$errLong, 'error mentions 64');

        // 6. Empty key → rejected.
        $errEmpty = $cls::validateCustomKey('');
        $this->assertNotNull($errEmpty, 'empty key rejected');
        $this->assertStringContainsString('خالی', (string)$errEmpty, 'empty-key error mentions "خالی"');

        // 7. Reserved prefix `_` → rejected.
        $errUnderscore = $cls::validateCustomKey('_internal_key');
        $this->assertNotNull($errUnderscore, 'reserved `_` prefix rejected');
        $this->assertStringContainsString('رزرو', (string)$errUnderscore, 'reserved-prefix error mentions "رزرو"');

        // 8. Reserved prefix `.` → rejected.
        $errDot = $cls::validateCustomKey('.next_id');
        $this->assertNotNull($errDot, 'reserved `.` prefix rejected');
        $this->assertStringContainsString('رزرو', (string)$errDot, 'reserved-prefix error mentions "رزرو"');

        // 9. Key with spaces → rejected.
        $errSpace = $cls::validateCustomKey('my key');
        $this->assertNotNull($errSpace, 'key with space rejected');

        // 10. Key with Persian chars → rejected.
        $errFa = $cls::validateCustomKey('تنظیمات');
        $this->assertNotNull($errFa, 'Persian key rejected (only ASCII allowed)');

        // 11. Key with Persian + ASCII mixed → rejected.
        $errMixed = $cls::validateCustomKey('تنظیم_1');
        $this->assertNotNull($errMixed, 'mixed Persian + ASCII key rejected');

        // 12. Key with special chars (@, #, $) → rejected.
        $errAt = $cls::validateCustomKey('key@host');
        $this->assertNotNull($errAt, 'key with @ rejected');
        $errHash = $cls::validateCustomKey('key#1');
        $this->assertNotNull($errHash, 'key with # rejected');

        // 13. Single-character valid key → null.
        $this->assertNull(
            $cls::validateCustomKey('a'),
            'single ASCII letter passes'
        );

        // 14. Numeric-only key → null (valid).
        $this->assertNull(
            $cls::validateCustomKey('123'),
            'numeric-only key passes'
        );
    }
}

return new SettingsControllerValidationTest();
