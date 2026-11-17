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
    }

    public function boot(ModuleLoader $loader): void
    {
        // No cross-module wiring in Phase 3 — Phase 4 wires the ACL
        // ApplicantSyncAdapter + the NationalSyncOrchestrator.
    }

    public function routes(): array
    {
        // Phase 3: routes are deferred — the admin sync panel will be added
        // when LicenseService (Phase 4) is in place. For now, only the
        // health endpoint circuit-breaker check is exposed via /health.
        return [];
    }

    public function migrations(): array
    {
        return [
            '20261117_001_national_gateway_tables.sql',
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
        ];
    }
}
