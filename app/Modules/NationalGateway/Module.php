<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway;

use App\Modules\ModuleInterface;
use App\Modules\ModuleLoader;

/**
 * NationalGateway module — owner of the upstream national-gateway scraper
 * and the golden-link audit trail.
 *
 * Ports the entire gateway project's Infrastructure + Domain + lightweight
 * Services. The LicenseService (which depends on EventBus + JobQueue) is
 * deferred to Phase 4.
 *
 * Per docs/SCHEMA_OWNERSHIP.md §2 — NationalGateway owns 5 tables:
 *   national_licenses, national_lookups, sync_audit_log, national_filters,
 *   national_batches.
 */
final class Module implements ModuleInterface
{
    public function name(): string
    {
        return 'national_gateway';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleLoader $loader): void
    {
        $container = $loader->container();

        // Infrastructure — singletons via factory closures.
        $container->bind(
            \App\Modules\NationalGateway\Infrastructure\AntiDetectionMiddleware::class,
            fn () => new \App\Modules\NationalGateway\Infrastructure\AntiDetectionMiddleware(
                \App\Shared\Support\Config::get('anti_detection', [])
            )
        );

        $container->bind(
            \App\Modules\NationalGateway\Infrastructure\HttpClient::class,
            fn () => new \App\Modules\NationalGateway\Infrastructure\HttpClient(
                $container->make(\App\Modules\NationalGateway\Infrastructure\AntiDetectionMiddleware::class),
                $container->logger
            )
        );

        $container->bind(
            \App\Modules\NationalGateway\Infrastructure\Parser\ResponseParserInterface::class,
            fn () => new \App\Modules\NationalGateway\Infrastructure\Parser\JsonSearchParser()
        );

        $container->bind(
            \App\Modules\NationalGateway\Infrastructure\MojavecClient::class,
            fn () => new \App\Modules\NationalGateway\Infrastructure\MojavecClient(
                $container->make(\App\Modules\NationalGateway\Infrastructure\HttpClient::class),
                $container->make(\App\Modules\NationalGateway\Infrastructure\Parser\ResponseParserInterface::class),
                $container->environment->upstreamBase,
                $container->logger
            )
        );

        $container->bind(
            \App\Modules\NationalGateway\Infrastructure\TrackScraper::class,
            fn () => new \App\Modules\NationalGateway\Infrastructure\TrackScraper(
                $container->make(\App\Modules\NationalGateway\Infrastructure\HttpClient::class),
                $container->environment->upstreamBase,
                $container->logger
            )
        );

        $container->bind(
            \App\Modules\NationalGateway\Infrastructure\CircuitBreaker::class,
            fn () => new \App\Modules\NationalGateway\Infrastructure\CircuitBreaker(
                failureThreshold: (int)\App\Shared\Support\Config::get('anti_detection.circuit_breaker.failure_threshold', 3),
                failureWindowS: (int)\App\Shared\Support\Config::get('anti_detection.circuit_breaker.failure_window_s', 60),
                cooldownS: (int)\App\Shared\Support\Config::get('anti_detection.circuit_breaker.cooldown_s', 300),
                stateFile: dirname(__DIR__, 3) . '/storage/cache/circuit_breaker.json'
            )
        );

        // Services — singletons.
        $container->bind(
            \App\Modules\NationalGateway\Services\RateLimiterService::class,
            fn () => new \App\Modules\NationalGateway\Services\RateLimiterService(
                \App\Shared\Support\Config::get('anti_detection.rate_limit', []),
                dirname(__DIR__, 3) . '/storage/cache/rate_limiter.json'
            )
        );

        $container->bind(
            \App\Modules\NationalGateway\Services\CacheService::class,
            fn () => new \App\Modules\NationalGateway\Services\CacheService(
                dirname(__DIR__, 3) . '/storage/cache/national_gateway',
                $container->logger
            )
        );

        // PDO repositories — implement the Shared\Contracts interfaces.
        $container->bind(
            \App\Shared\Contracts\NationalLicenseRepositoryInterface::class,
            fn () => new \App\Modules\NationalGateway\Persistence\NationalLicenseRepository(
                $container->make(\App\Shared\Persistence\Connection::class),
                $container->logger
            )
        );
        $container->bind(
            \App\Shared\Contracts\NationalLookupRepositoryInterface::class,
            fn () => new \App\Modules\NationalGateway\Persistence\NationalLookupRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\NationalFilterRepositoryInterface::class,
            fn () => new \App\Modules\NationalGateway\Persistence\NationalFilterRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\NationalBatchRepositoryInterface::class,
            fn () => new \App\Modules\NationalGateway\Persistence\NationalBatchRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\SyncAuditLogRepositoryInterface::class,
            fn () => new \App\Modules\NationalGateway\Persistence\SyncAuditLogRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );

        // PatternMatcher — stateless, cheap to construct.
        $container->bind(
            \App\Modules\NationalGateway\Services\PatternMatcher::class,
            fn () => new \App\Modules\NationalGateway\Services\PatternMatcher()
        );

        // LicenseService — the orchestrator. Wires all of the above.
        $container->bind(
            \App\Modules\NationalGateway\Services\LicenseService::class,
            fn () => new \App\Modules\NationalGateway\Services\LicenseService(
                $container->make(\App\Modules\NationalGateway\Infrastructure\MojavecClient::class),
                $container->make(\App\Modules\NationalGateway\Infrastructure\TrackScraper::class),
                $container->make(\App\Modules\NationalGateway\Services\CacheService::class),
                $container->make(\App\Shared\Contracts\NationalLicenseRepositoryInterface::class),
                $container->make(\App\Shared\Contracts\NationalLookupRepositoryInterface::class),
                $container->make(\App\Events\EventBus::class),
                $container->logger,
                $container->make(\App\Modules\NationalGateway\Infrastructure\CircuitBreaker::class),
                $container->make(\App\Modules\NationalGateway\Services\RateLimiterService::class)
            )
        );

        // ApplicantSyncAdapter (ACL) — the golden-link bridge to Core.
        $container->bind(
            \App\Modules\NationalGateway\Services\ApplicantSyncAdapter::class,
            fn () => new \App\Modules\NationalGateway\Services\ApplicantSyncAdapter(
                $container->make(\App\Modules\NationalGateway\Services\PatternMatcher::class),
                $container->make(\App\Shared\Contracts\ApplicantRepositoryInterface::class),
                $container->make(\App\Shared\Contracts\PersonRepositoryInterface::class),
                $container->make(\App\Shared\Contracts\JobAndPropertyRepositoryInterface::class),
                $container->make(\App\Shared\Contracts\NationalLicenseRepositoryInterface::class),
                $container->make(\App\Shared\Contracts\SyncAuditLogRepositoryInterface::class),
                $container->make(\App\Events\EventBus::class),
                $container->logger
            )
        );

        // OutboundSyncService — pushes v3 state back to upstream (Stage 2 only).
        // Activated when settings.sync_two_way_enabled = '1' (checked at runtime
        // by OutboundSyncService::isEnabled()). Auto-disables on 3 consecutive
        // 5xx failures (§29.3 kill-switch).
        $container->bind(
            \App\Modules\NationalGateway\Services\OutboundSyncService::class,
            fn () => new \App\Modules\NationalGateway\Services\OutboundSyncService(
                $container->make(\App\Shared\Contracts\ApplicantRepositoryInterface::class),
                $container->make(\App\Shared\Persistence\Connection::class),
                $container->logger
            )
        );

        // NationalSyncOrchestrator — drives the full golden-link cycle.
        $container->bind(
            \App\Modules\NationalGateway\Services\NationalSyncOrchestrator::class,
            fn () => new \App\Modules\NationalGateway\Services\NationalSyncOrchestrator(
                $container->make(\App\Modules\NationalGateway\Services\LicenseService::class),
                $container->make(\App\Modules\NationalGateway\Services\ApplicantSyncAdapter::class),
                $container->make(\App\Shared\Contracts\NationalLicenseRepositoryInterface::class),
                $container->make(\App\Shared\Contracts\SyncAuditLogRepositoryInterface::class),
                $container->make(\App\Events\EventBus::class),
                $container->logger,
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
    }

    public function boot(ModuleLoader $loader): void
    {
        // Register the golden-link subscribers on the EventBus.
        // LogSubscriber (wildcard) + SyncSubscriber (LicenseIssuedOnNational)
        // + NotificationSubscriber (ApplicantMatched → SMS + in-app notification).
        $container = $loader->container();
        $eventBus = $container->make(\App\Events\EventBus::class);
        $logger = $container->logger;

        $eventBus->subscribe(new \App\Events\Subscribers\LogSubscriber($logger));
        $eventBus->subscribe(new \App\Events\Subscribers\SyncSubscriber(
            $container->make(\App\Queue\JobQueue::class),
            $logger
        ));
        $eventBus->subscribe(new \App\Events\Subscribers\NotificationSubscriber(
            $container->make(\App\Modules\Core\Services\NotificationService::class),
            $logger
        ));
    }

    public function routes(): array
    {
        return [
            // Golden-link sync admin panel — poor man's cron pattern.
            // Each /admin/sync/status poll drains one queued job.
            ['method' => 'GET',  'path' => '/admin/sync/status',  'handler' => \App\Modules\NationalGateway\Controllers\SyncAdminController::class, 'action' => 'status'],
            ['method' => 'GET',  'path' => '/admin/sync/pending',  'handler' => \App\Modules\NationalGateway\Controllers\SyncAdminController::class, 'action' => 'pending'],
            ['method' => 'POST', 'path' => '/admin/sync/run',      'handler' => \App\Modules\NationalGateway\Controllers\SyncAdminController::class, 'action' => 'run'],
        ];
    }

    public function migrations(): array
    {
        return [
            '20261117_001_national_gateway_tables.sql',
            '20261117_002_golden_link_columns.sql',
            '20261117_003_sync_audit_log_nullable_applicant.sql',
            '20261117_004_integration_log_table.sql',
        ];
    }

    public function subscribers(): array
    {
        // Phase 4 will wire SyncSubscriber + NotificationSubscriber.
        return [];
    }

    public function ownedTables(): array
    {
        // Per docs/SCHEMA_OWNERSHIP.md §2 — NationalGateway owns these tables.
        return [
            'national_licenses',
            'national_lookups',
            'sync_audit_log',
            'national_filters',
            'national_batches',
            'integration_log',  // Phase 8 — two-way sync audit trail (§29.1).
        ];
    }
}
