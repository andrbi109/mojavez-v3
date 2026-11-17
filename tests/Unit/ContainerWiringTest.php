<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Integration test: verify the full Container wiring for Phase 4.
 *
 * Builds the Container (which boots all modules, registers subscribers,
 * binds LicenseService + PatternMatcher + repositories + EventBus + JobQueue)
 * and verifies each key service can be resolved without errors.
 *
 * This catches DI wiring bugs that unit tests on individual classes miss
 * (wrong constructor order, missing bindings, circular deps).
 */
final class ContainerWiringTest extends TestCase
{
    private \App\Container $container;

    public function name(): string
    {
        return 'ContainerWiringTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/wiring-test.log', 'debug');
        $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
        $this->container = new \App\Container($env, $logger);
        $this->container->boot(dirname(__DIR__, 2));
    }

    public function run(): void
    {
        // 1. Core infrastructure resolvable.
        $this->assertInstanceOf(
            \App\Shared\Persistence\Connection::class,
            $this->container->make(\App\Shared\Persistence\Connection::class),
            'Connection resolvable'
        );

        // 2. EventBus resolvable + has Connection wired.
        $bus = $this->container->make(\App\Events\EventBus::class);
        $this->assertInstanceOf(\App\Events\EventBus::class, $bus, 'EventBus resolvable');

        // 3. JobQueue + Worker resolvable.
        $this->assertInstanceOf(
            \App\Queue\JobQueue::class,
            $this->container->make(\App\Queue\JobQueue::class),
            'JobQueue resolvable'
        );
        $this->assertInstanceOf(
            \App\Queue\Worker::class,
            $this->container->make(\App\Queue\Worker::class),
            'Worker resolvable'
        );

        // 4. NationalGateway infrastructure resolvable.
        $this->assertInstanceOf(
            \App\Modules\NationalGateway\Infrastructure\HttpClientInterface::class,
            $this->container->make(\App\Modules\NationalGateway\Infrastructure\HttpClient::class),
            'HttpClient resolvable (implements HttpClientInterface)'
        );
        $this->assertInstanceOf(
            \App\Modules\NationalGateway\Infrastructure\MojavecClient::class,
            $this->container->make(\App\Modules\NationalGateway\Infrastructure\MojavecClient::class),
            'MojavecClient resolvable'
        );
        $this->assertInstanceOf(
            \App\Modules\NationalGateway\Infrastructure\TrackScraper::class,
            $this->container->make(\App\Modules\NationalGateway\Infrastructure\TrackScraper::class),
            'TrackScraper resolvable'
        );
        $this->assertInstanceOf(
            \App\Modules\NationalGateway\Infrastructure\CircuitBreaker::class,
            $this->container->make(\App\Modules\NationalGateway\Infrastructure\CircuitBreaker::class),
            'CircuitBreaker resolvable'
        );

        // 5. NationalGateway services resolvable.
        $this->assertInstanceOf(
            \App\Modules\NationalGateway\Services\RateLimiterService::class,
            $this->container->make(\App\Modules\NationalGateway\Services\RateLimiterService::class),
            'RateLimiterService resolvable'
        );
        $this->assertInstanceOf(
            \App\Modules\NationalGateway\Services\CacheService::class,
            $this->container->make(\App\Modules\NationalGateway\Services\CacheService::class),
            'CacheService resolvable'
        );
        $this->assertInstanceOf(
            \App\Modules\NationalGateway\Services\PatternMatcher::class,
            $this->container->make(\App\Modules\NationalGateway\Services\PatternMatcher::class),
            'PatternMatcher resolvable'
        );
        $this->assertInstanceOf(
            \App\Modules\NationalGateway\Services\LicenseService::class,
            $this->container->make(\App\Modules\NationalGateway\Services\LicenseService::class),
            'LicenseService resolvable'
        );

        // 6. Repositories resolvable (via interface, get the PDO impl).
        $this->assertInstanceOf(
            \App\Shared\Contracts\NationalLicenseRepositoryInterface::class,
            $this->container->make(\App\Shared\Contracts\NationalLicenseRepositoryInterface::class),
            'NationalLicenseRepository resolvable via interface'
        );
        $this->assertInstanceOf(
            \App\Shared\Contracts\NationalLookupRepositoryInterface::class,
            $this->container->make(\App\Shared\Contracts\NationalLookupRepositoryInterface::class),
            'NationalLookupRepository resolvable via interface'
        );
        $this->assertInstanceOf(
            \App\Shared\Contracts\NationalFilterRepositoryInterface::class,
            $this->container->make(\App\Shared\Contracts\NationalFilterRepositoryInterface::class),
            'NationalFilterRepository resolvable via interface'
        );
        $this->assertInstanceOf(
            \App\Shared\Contracts\NationalBatchRepositoryInterface::class,
            $this->container->make(\App\Shared\Contracts\NationalBatchRepositoryInterface::class),
            'NationalBatchRepository resolvable via interface'
        );
        $this->assertInstanceOf(
            \App\Shared\Contracts\SyncAuditLogRepositoryInterface::class,
            $this->container->make(\App\Shared\Contracts\SyncAuditLogRepositoryInterface::class),
            'SyncAuditLogRepository resolvable via interface'
        );

        // 7. Singleton memoization — same instance returned twice.
        $bus2 = $this->container->make(\App\Events\EventBus::class);
        $this->assertSame($bus, $bus2, 'EventBus is memoized singleton');

        // 8. LicenseService.stats() works end-to-end (hits the DB via repo).
        $license = $this->container->make(\App\Modules\NationalGateway\Services\LicenseService::class);
        $stats = $license->stats();
        $this->assertArrayHasKey('total', $stats, 'stats has total key');
        $this->assertArrayHasKey('by_status', $stats, 'stats has by_status key');
        $this->assertGreaterThanOrEqual(0, $stats['total'], 'stats total is non-negative int');
    }
}

return new ContainerWiringTest();
