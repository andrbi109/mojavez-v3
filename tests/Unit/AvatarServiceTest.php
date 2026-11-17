<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Unit test for App\Modules\Core\Services\AvatarService (Phase 12).
 *
 * Verifies the validation paths of `upload()` WITHOUT actually writing
 * files to disk — the test constructs `$_FILES['avatar']`-style arrays
 * with various error codes / MIME types / sizes and asserts that
 * `InvalidArgumentException` is thrown with a Persian message.
 *
 * The "happy path" (a real image upload + GD resize + DB insert) is
 * intentionally NOT tested here — it would require a real image file +
 * writable storage + a real DB row in `applicants`. That integration test
 * is left for v1.4 (manual testing covered the happy path during
 * development).
 *
 * Test environment:
 *   - DB availability is required (we use a real Connection for the
 *     `getPath()` test). If DB isn't reachable, the test is skipped.
 *   - GD availability is NOT required for the validation-path tests
 *     (the GD check is placed AFTER validation in AvatarService::upload(),
 *     so validation throws before GD is needed).
 */
final class AvatarServiceTest extends TestCase
{
    private \App\Shared\Persistence\Connection $conn;
    private \App\Modules\Core\Services\AvatarService $service;
    private \App\Shared\Support\StructuredLogger $logger;
    private int $fakeAccountId = 999999;

    public function name(): string
    {
        return 'AvatarServiceTest';
    }

    public function setUp(): void
    {
        // Build a real Container — same pattern as SmsServiceTest.
        $this->logger = new \App\Shared\Support\StructuredLogger(
            sys_get_temp_dir() . '/avatar-test.log',
            'debug'
        );
        try {
            $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
            $container = new \App\Container($env, $this->logger);
            $container->boot(dirname(__DIR__, 2));
            $this->conn    = $container->make(\App\Shared\Persistence\Connection::class);
            $this->service = $container->make(\App\Modules\Core\Services\AvatarService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB or container unavailable: ' . $e->getMessage());
        }
    }

    public function run(): void
    {
        // 1. UPLOAD_ERR_INI_SIZE → Persian size message.
        $this->assertThrows(
            fn () => $this->service->upload($this->fakeAccountId, [
                'name'     => 'x.jpg',
                'tmp_name' => '/tmp/nonexistent',
                'size'     => 100,
                'error'    => UPLOAD_ERR_INI_SIZE,
                'type'     => 'image/jpeg',
            ]),
            'حجم فایل بیش از حد مجاز سرور',
            'UPLOAD_ERR_INI_SIZE throws Persian size-over message'
        );

        // 2. UPLOAD_ERR_NO_FILE → Persian "no file" message.
        $this->assertThrows(
            fn () => $this->service->upload($this->fakeAccountId, [
                'name'     => '',
                'tmp_name' => '',
                'size'     => 0,
                'error'    => UPLOAD_ERR_NO_FILE,
                'type'     => 'image/jpeg',
            ]),
            'هیچ فایلی بارگذاری',
            'UPLOAD_ERR_NO_FILE throws Persian "no file" message'
        );

        // 3. UPLOAD_ERR_PARTIAL → Persian "incomplete" message.
        $this->assertThrows(
            fn () => $this->service->upload($this->fakeAccountId, [
                'name'     => 'x.jpg',
                'tmp_name' => '/tmp/x',
                'size'     => 100,
                'error'    => UPLOAD_ERR_PARTIAL,
                'type'     => 'image/jpeg',
            ]),
            'ناقص',
            'UPLOAD_ERR_PARTIAL throws Persian incomplete message'
        );

        // 4. Invalid declared MIME type → Persian whitelist message.
        $this->assertThrows(
            fn () => $this->service->upload($this->fakeAccountId, [
                'name'     => 'x.gif',
                'tmp_name' => '/tmp/x',
                'size'     => 100,
                'error'    => UPLOAD_ERR_OK,
                'type'     => 'image/gif',
            ]),
            'JPEG، PNG و WEBP',
            'invalid MIME (image/gif) throws whitelist message'
        );

        // 5. PDF declared MIME (also not in whitelist) → same whitelist message.
        $this->assertThrows(
            fn () => $this->service->upload($this->fakeAccountId, [
                'name'     => 'x.pdf',
                'tmp_name' => '/tmp/x',
                'size'     => 100,
                'error'    => UPLOAD_ERR_OK,
                'type'     => 'application/pdf',
            ]),
            'JPEG، PNG و WEBP',
            'invalid MIME (application/pdf) throws whitelist message'
        );

        // 6. Size too large → Persian size cap message.
        $this->assertThrows(
            fn () => $this->service->upload($this->fakeAccountId, [
                'name'     => 'big.jpg',
                'tmp_name' => '/tmp/x',
                'size'     => 3 * 1024 * 1024,  // 3 MB > 2 MB cap
                'error'    => UPLOAD_ERR_OK,
                'type'     => 'image/jpeg',
            ]),
            'بیش از ۲ مگابایت',
            'oversized file throws Persian 2MB cap message'
        );

        // 7. Size = 0 (invalid) → Persian "invalid size" message.
        $this->assertThrows(
            fn () => $this->service->upload($this->fakeAccountId, [
                'name'     => 'zero.jpg',
                'tmp_name' => '/tmp/x',
                'size'     => 0,
                'error'    => UPLOAD_ERR_OK,
                'type'     => 'image/jpeg',
            ]),
            'حجم فایل نامعتبر',
            'zero-size file throws Persian invalid-size message'
        );

        // 8. Valid MIME declared but tmp_name doesn't exist → Persian
        //    "tmp file not found" message.
        $this->assertThrows(
            fn () => $this->service->upload($this->fakeAccountId, [
                'name'     => 'ok.jpg',
                'tmp_name' => '/tmp/this-does-not-exist-' . uniqid(),
                'size'     => 100,
                'error'    => UPLOAD_ERR_OK,
                'type'     => 'image/jpeg',
            ]),
            'فایل موقت',
            'missing tmp_name throws Persian temp-file message'
        );

        // 9. getPath(0) → null (invalid id).
        $this->assertNull($this->service->getPath(0), 'getPath(0) returns null');
        // 10. getPath(-1) → null (negative id).
        $this->assertNull($this->service->getPath(-1), 'getPath(-1) returns null');
        // 11. getPath(nonexistent) → null.
        $this->assertNull($this->service->getPath(999999), 'getPath(nonexistent) returns null');
    }

    /**
     * Helper: assert that the given callable throws InvalidArgumentException
     * with a message containing the Persian $needle.
     *
     * @param callable():void $fn
     */
    private function assertThrows(callable $fn, string $needle, string $message): void
    {
        try {
            $fn();
            $this->assertTrue(false, $message . ' — expected InvalidArgumentException, none thrown');
            return;
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage(), $message);
        } catch (\Throwable $e) {
            $this->assertTrue(false, $message . ' — expected InvalidArgumentException, got ' . get_class($e));
        }
    }
}

return new AvatarServiceTest();
