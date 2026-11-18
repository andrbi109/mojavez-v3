<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Test for App\Http\IpRateLimiter + RateLimitConfig.
 *
 * Verifies:
 *   - RateLimitConfig::match() finds the right rule for a method+path.
 *   - IpRateLimiter::hit() returns true until the bucket is full, then false.
 *   - Session-based and IP-based buckets work independently.
 *   - Sliding window: old hits expire.
 *   - remaining() counts correctly.
 */
final class IpRateLimiterTest extends TestCase
{
    private \App\Shared\Persistence\Connection $conn;
    private \App\Http\IpRateLimiter $limiter;
    private \App\Http\Session $session;
    /** @var list<string> */
    private array $createdBuckets = [];

    public function name(): string
    {
        return 'IpRateLimiterTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/rl.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $container = new \App\Container($env, $logger);
        $container->boot(dirname(__DIR__, 2));
        $this->conn = $container->make(\App\Shared\Persistence\Connection::class);
        $this->session = $container->make(\App\Http\Session::class);
        $this->limiter = $container->make(\App\Http\IpRateLimiter::class);

        // Clean test buckets.
        $this->conn->execute("DELETE FROM rate_limit_buckets WHERE bucket_key LIKE 'test_%'");
        // Reset session rate-limit state.
        $_SESSION = [];
        $this->session->start();
    }

    public function tearDown(): void
    {
        $this->conn->execute("DELETE FROM rate_limit_buckets WHERE bucket_key LIKE 'test_%'");
        $_SESSION = [];
    }

    public function run(): void
    {
        // 1. RateLimitConfig::match — finds the right rule.
        $rule = \App\Http\RateLimitConfig::match('POST', '/login');
        $this->assertNotNull($rule, 'POST /login matched a rule');
        $this->assertSame('user_login', $rule['key'], 'matched rule key');
        $this->assertSame('ip', $rule['type'], 'user_login is ip-based');

        $rule = \App\Http\RateLimitConfig::match('POST', '/admin/login');
        $this->assertSame('admin_login', $rule['key'], 'admin_login rule');
        $this->assertSame('session', $rule['type'], 'admin_login is session-based');

        $rule = \App\Http\RateLimitConfig::match('GET', '/tracking/status/HG-12345678');
        $this->assertSame('tracking_status', $rule['key'], 'tracking_status rule');

        $rule = \App\Http\RateLimitConfig::match('GET', '/health');
        $this->assertNull($rule, 'no rate limit on /health');

        // 2. IP-based bucket — allows up to max, then blocks.
        $key = 'test_ip_' . random_int(1000, 9999);
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->limiter->hit($key, 3, 60, 'ip'), "hit $i allowed");
        }
        $this->assertFalse($this->limiter->hit($key, 3, 60, 'ip'), '4th hit blocked');
        $this->assertSame(0, $this->limiter->remaining($key, 3, 60, 'ip'), 'remaining = 0');

        // 3. Session-based bucket — independent of IP bucket.
        $sessKey = 'test_session_' . random_int(1000, 9999);
        $this->assertTrue($this->limiter->hit($sessKey, 2, 60, 'session'), 'session hit 1');
        $this->assertTrue($this->limiter->hit($sessKey, 2, 60, 'session'), 'session hit 2');
        $this->assertFalse($this->limiter->hit($sessKey, 2, 60, 'session'), 'session hit 3 blocked');

        // 4. Different keys have independent buckets.
        $key2 = 'test_ip_other_' . random_int(1000, 9999);
        $this->assertTrue($this->limiter->hit($key2, 3, 60, 'ip'), 'different key starts fresh');

        // 5. remaining() decrements as we hit.
        $key3 = 'test_rem_' . random_int(1000, 9999);
        $this->assertSame(3, $this->limiter->remaining($key3, 3, 60, 'ip'), 'remaining=3 before any hit');
        $this->limiter->hit($key3, 3, 60, 'ip');
        $this->assertSame(2, $this->limiter->remaining($key3, 3, 60, 'ip'), 'remaining=2 after 1 hit');

        // 6. Sliding window — old hits expire (simulate with window=1s).
        $key4 = 'test_slide_' . random_int(1000, 9999);
        $this->limiter->hit($key4, 1, 1, 'ip');
        $this->assertFalse($this->limiter->hit($key4, 1, 1, 'ip'), 'blocked within window');
        usleep(1100000); // 1.1s
        $this->assertTrue($this->limiter->hit($key4, 1, 1, 'ip'), 'allowed after window expired');

        // 7. Buckets persist in DB (IP-based survives session reset).
        $row = $this->conn->one(
            'SELECT * FROM rate_limit_buckets WHERE bucket_key = ?',
            [$key]
        );
        $this->assertNotNull($row, 'ip bucket persisted in DB');
        $hits = json_decode((string)$row['hits_json'], true);
        $this->assertCount(3, $hits, '3 hits stored in hits_json');
    }
}

return new IpRateLimiterTest();
