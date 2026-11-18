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
        // Phase 24 (v1.9.6 / D-5-4): inject the per-field match modes
        // from config/pattern_matcher.php so the fuzzy `contains`
        // matching for business_address is tunable without code change.
        $container->bind(
            \App\Modules\NationalGateway\Services\PatternMatcher::class,
            fn () => new \App\Modules\NationalGateway\Services\PatternMatcher(
                \App\Shared\Support\Config::get('pattern_matcher.modes', []),
                (float)\App\Shared\Support\Config::get('pattern_matcher.contains_confidence', 0.7)
            )
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

        // Stage2ReadinessChecker — evaluates the 4 §29.2 conditions before
        // enabling Stage 2. Never auto-enables — only reports readiness.
        $container->bind(
            \App\Modules\NationalGateway\Services\Stage2ReadinessChecker::class,
            fn () => new \App\Modules\NationalGateway\Services\Stage2ReadinessChecker(
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
                $container->make(\App\Shared\Persistence\Connection::class),
                $container->make(\App\Modules\NationalGateway\Services\OutboundSyncService::class)
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
            // ----- Phase 39 — dedicated gateway admin pages (HTML, not API) -----
            // These are the 5 admin pages that replaced the 4 raw API JSON
            // links in the admin sidebar (per the user's request: "no API
            // links visible to the user"). The /api/v1/* endpoints below
            // remain registered and accessible programmatically — they're
            // just no longer linked from admin_layout.php's sidebar.
            //
            // GatewayAdminController renders the same Bootstrap 5 RTL +
            // Vazirmatn admin_layout that the rest of the admin area uses.
            // Mutations (filter CRUD, batch upload, save/unsave, run-crawl)
            // go through the existing /api/v1/* JSON endpoints via fetch()
            // in the views' <script> blocks.
            ['method' => 'GET', 'path' => '/admin/gateway',          'handler' => \App\Modules\NationalGateway\Controllers\GatewayAdminController::class, 'action' => 'panel'],
            ['method' => 'GET', 'path' => '/admin/gateway/search',    'handler' => \App\Modules\NationalGateway\Controllers\GatewayAdminController::class, 'action' => 'search'],
            ['method' => 'GET', 'path' => '/admin/gateway/saved',     'handler' => \App\Modules\NationalGateway\Controllers\GatewayAdminController::class, 'action' => 'saved'],
            ['method' => 'GET', 'path' => '/admin/gateway/filters',   'handler' => \App\Modules\NationalGateway\Controllers\GatewayAdminController::class, 'action' => 'filters'],
            ['method' => 'GET', 'path' => '/admin/gateway/batch',     'handler' => \App\Modules\NationalGateway\Controllers\GatewayAdminController::class, 'action' => 'batch'],

            // Golden-link sync admin panel — poor man's cron pattern.
            // Each /admin/sync/status poll drains one queued job.
            ['method' => 'GET',  'path' => '/admin/sync/status',     'handler' => \App\Modules\NationalGateway\Controllers\SyncAdminController::class, 'action' => 'status'],
            ['method' => 'GET',  'path' => '/admin/sync/pending',    'handler' => \App\Modules\NationalGateway\Controllers\SyncAdminController::class, 'action' => 'pending'],
            ['method' => 'POST', 'path' => '/admin/sync/run',        'handler' => \App\Modules\NationalGateway\Controllers\SyncAdminController::class, 'action' => 'run'],
            // Phase 11: admin UI for the Stage2ReadinessChecker report (D-8-4).
            ['method' => 'GET',  'path' => '/admin/sync/readiness',  'handler' => \App\Modules\NationalGateway\Controllers\SyncAdminController::class, 'action' => 'readiness'],

            // ----- Phase 15 — REST API v1 surface (V1ApiController) -----
            // Auth: GET /api/v1/licenses (list/show/track) is public;
            // admin actions use requireRole(['admin']); /api/v1/applicants/*
            // uses requireApiKey (X-API-Key header); /api/v1/admin/* uses
            // requireRole(['admin']).
            //
            // Route order matters: specific routes (/saved, /export,
            // /search, /save-all, /{id}/save, /{id}/track) are registered
            // BEFORE the generic /{id} catch-all.
            //
            // Licenses API.
            ['method' => 'GET',    'path' => '/api/v1/licenses',                'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'licenseIndex'],
            ['method' => 'GET',    'path' => '/api/v1/licenses/saved',          'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'licenseSaved'],
            ['method' => 'GET',    'path' => '/api/v1/licenses/export',         'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'licenseExport'],
            ['method' => 'POST',   'path' => '/api/v1/licenses/search',        'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'licenseSearch'],
            ['method' => 'POST',   'path' => '/api/v1/licenses/save-all',      'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'licenseSaveAll'],
            ['method' => 'POST',   'path' => '/api/v1/licenses/{id}/save',     'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'licenseSave'],
            ['method' => 'DELETE', 'path' => '/api/v1/licenses/{id}/save',     'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'licenseUnsave'],
            ['method' => 'GET',    'path' => '/api/v1/licenses/{id}/track',     'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'licenseTrack'],
            ['method' => 'GET',    'path' => '/api/v1/licenses/{id}',           'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'licenseShow'],

            // Applicants API (X-API-Key auth).
            ['method' => 'GET', 'path' => '/api/v1/applicants/{trackingCode}',           'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'applicantShow'],
            ['method' => 'GET', 'path' => '/api/v1/applicants/{trackingCode}/status',     'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'applicantStatus'],
            ['method' => 'GET', 'path' => '/api/v1/applicants/by-national/{nationalCode}', 'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'applicantByNational'],

            // Admin API.
            ['method' => 'GET',  'path' => '/api/v1/admin/stats',         'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'adminStats'],
            ['method' => 'GET',  'path' => '/api/v1/admin/analytics',     'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'adminAnalytics'],
            ['method' => 'GET',  'path' => '/api/v1/admin/jobs',           'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'adminJobs'],
            ['method' => 'POST', 'path' => '/api/v1/admin/jobs/{id}/retry','handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'adminRetryJob'],
            ['method' => 'GET',  'path' => '/api/v1/admin/logs',           'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'adminLogs'],
            ['method' => 'POST', 'path' => '/api/v1/admin/crawl',         'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'adminCrawl'],
            ['method' => 'POST', 'path' => '/api/v1/admin/worker/start',   'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'adminWorkerStart'],
            ['method' => 'POST', 'path' => '/api/v1/admin/worker/stop',    'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'adminWorkerStop'],
            ['method' => 'GET',  'path' => '/api/v1/admin/worker/status',  'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'adminWorkerStatus'],

            // Lookups + filters API.
            ['method' => 'GET',  'path' => '/api/v1/lookups',                       'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'lookupIndex'],
            ['method' => 'GET',  'path' => '/api/v1/lookups/townships/{provinceId}',  'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'lookupTownships'],
            ['method' => 'GET',  'path' => '/api/v1/lookups/sub-organizations/{orgCode}', 'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'lookupSubOrgs'],
            ['method' => 'POST', 'path' => '/api/v1/lookups/refresh',                 'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'lookupRefresh'],
            ['method' => 'GET',  'path' => '/api/v1/filters',                         'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'filterIndex'],
            ['method' => 'POST', 'path' => '/api/v1/filters',                         'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'filterStore'],
            ['method' => 'GET',  'path' => '/api/v1/filters/{id}',                     'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'filterShow'],
            ['method' => 'PUT',  'path' => '/api/v1/filters/{id}',                     'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'filterUpdate'],
            ['method' => 'DELETE', 'path' => '/api/v1/filters/{id}',                  'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'filterDestroy'],

            // Batch API.
            ['method' => 'POST', 'path' => '/api/v1/batch/upload',          'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'batchUpload'],
            ['method' => 'GET',  'path' => '/api/v1/batch',                  'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'batchIndex'],
            ['method' => 'GET',  'path' => '/api/v1/batch/{id}/status',     'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'batchStatus'],

            // Sync API (admin or secret).
            ['method' => 'GET',  'path' => '/api/v1/sync/status',              'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'syncStatus'],
            ['method' => 'GET',  'path' => '/api/v1/sync/pending',             'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'syncPending'],
            ['method' => 'POST', 'path' => '/api/v1/sync/run',                 'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'syncRun'],
            ['method' => 'POST', 'path' => '/api/v1/sync/{licenseId}/confirm', 'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'syncConfirm'],
            ['method' => 'POST', 'path' => '/api/v1/sync/{licenseId}/reject',  'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'syncReject'],

            // Proxy-image API (admin).
            ['method' => 'GET',  'path' => '/api/v1/proxy-image',             'handler' => \App\Modules\NationalGateway\Controllers\Api\V1ApiController::class, 'action' => 'proxyImage'],
        ];
    }

    public function migrations(): array
    {
        return [
            '20261117_001_national_gateway_tables.sql',
            '20261117_002_golden_link_columns.sql',
            '20261117_003_sync_audit_log_nullable_applicant.sql',
            '20261117_004_integration_log_table.sql',
            '20261117_005_seed_lookups_for_dev.sql',
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
