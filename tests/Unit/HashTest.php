<?php
declare(strict_types=1);

use App\Support\Hash;

require_once __DIR__ . '/../TestCase.php';

final class HashTest extends TestCase
{
    public function name(): string
    {
        return 'HashTest';
    }

    public function run(): void
    {
        $hash = new Hash();

        // sha256.
        $this->assertSame(
            '2ef7bde608ce5404e97d5f042f95f89b1c6697b5e3b6c2c7e3b6c2c7e3b6c2c7',
            $hash->sha256('test'),
            'sha256'
        );

        // short — first 12 hex chars of sha256.
        $this->assertSame(substr(hash('sha256', 'test'), 0, 12), $hash->short('test'), 'short hash');

        // randomToken — 64 hex chars (32 bytes).
        $tok1 = $hash->randomToken();
        $tok2 = $hash->randomToken();
        $this->assertSame(64, strlen($tok1), 'randomToken length 64');
        $this->assertSame(64, strlen($tok2), 'randomToken length 64');
        $this->assertFalse($tok1 === $tok2, 'randomToken unique per call');

        // apiKeyHash — sha256 of input.
        $this->assertSame(hash('sha256', 'mykey'), $hash->apiKeyHash('mykey'), 'apiKeyHash');

        // filterKey — stable, deterministic for same array.
        $key1 = $hash->filterKey(['a' => 1, 'b' => 2]);
        $key2 = $hash->filterKey(['b' => 2, 'a' => 1]);  // different order
        $this->assertSame($key1, $key2, 'filterKey is order-independent (ksort)');
        $this->assertSame(12, strlen($key1), 'filterKey length 12');

        // passwordHash / verifyPassword.
        $hashed = $hash->passwordHash('secret-pass-123');
        $this->assertTrue(str_starts_with($hashed, '$2y$'), 'passwordHash uses bcrypt');
        $this->assertTrue($hash->verifyPassword('secret-pass-123', $hashed), 'verifyPassword accepts correct');
        $this->assertFalse($hash->verifyPassword('wrong', $hashed), 'verifyPassword rejects wrong');

        // verifyPassword on null hash (anti-timing — runs dummy compare).
        $this->assertFalse($hash->verifyPassword('anything', null), 'verifyPassword null hash returns false');
        $this->assertFalse($hash->verifyPassword('anything', ''), 'verifyPassword empty hash returns false');

        // timingSafeCompare.
        $this->assertTrue($hash->timingSafeCompare('abc', 'abc'), 'equal strings match');
        $this->assertFalse($hash->timingSafeCompare('abc', 'abd'), 'different strings do not match');
        $this->assertFalse($hash->timingSafeCompare('abc', 'abcd'), 'different lengths do not match');

        // generateTrackingCode — HG-XXXXXXXX format.
        $code = $hash->generateTrackingCode();
        $this->assertTrue(preg_match('/^HG-[A-Z0-9]{8}$/', $code) === 1, 'tracking code format HG-XXXXXXXX');
        $code2 = $hash->generateTrackingCode();
        $this->assertFalse($code === $code2, 'tracking code unique per call');

        // generateIdempotencyKey — UUID v4 format.
        $key = $hash->generateIdempotencyKey();
        $this->assertTrue(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key) === 1, 'UUID v4 format');
    }
}

return new HashTest();
