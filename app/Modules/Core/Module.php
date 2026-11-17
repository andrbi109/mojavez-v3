<?php
declare(strict_types=1);

namespace App\Modules\Core;

use App\Modules\ModuleInterface;
use App\Modules\ModuleLoader;

/**
 * Core module — owner of the v2-inherited schema (applicants, persons,
 * payments, documents, license_files, settings, ...).
 *
 * Phase 1 ships only the meta routes (/health, /check-cancel, /check-sync,
 * /api/v1/stream) wired through this module. The full Core routes
 * (registration wizard, tracking, admin panel, user panel, reports) are
 * added in Phase 2 once the Core Domain layer is in place.
 *
 * Per docs/SCHEMA_OWNERSHIP.md, Core owns 23 tables. The list returned by
 * ownedTables() must stay in sync with the actual migrations in
 * app/Modules/Core/migrations/.
 */
final class Module implements ModuleInterface
{
    public function name(): string
    {
        return 'core';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleLoader $loader): void
    {
        $container = $loader->container();

        // Bind repository interfaces to their PDO implementations.
        // All are memoized singletons (the Container caches after first make()).
        $container->bind(
            \App\Shared\Contracts\ApplicantRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoApplicantRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\PersonRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoPersonRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\StatusLogRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoStatusLogRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\DocumentRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoDocumentRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\SettingRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoSettingRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\ServiceTypeRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoServiceTypeRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\LicenseTitleRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoLicenseTitleRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\JobAndPropertyRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoJobAndPropertyRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\UserRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoUserRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\UserAccountRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoUserAccountRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\PaymentRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoPaymentRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );
        $container->bind(
            \App\Shared\Contracts\LicenseFileRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoLicenseFileRepository(
                $container->make(\App\Shared\Persistence\Connection::class)
            )
        );

        // Bind Core services.
        $container->bind(
            \App\Modules\Core\Services\AutoCancelService::class,
            fn () => new \App\Modules\Core\Services\AutoCancelService(
                $container->make(\App\Shared\Contracts\ApplicantRepositoryInterface::class),
                $container->make(\App\Shared\Contracts\StatusLogRepositoryInterface::class),
                $container->logger
            )
        );
        $container->bind(
            \App\Modules\Core\Services\FileStorage::class,
            fn () => new \App\Modules\Core\Services\FileStorage(
                $container->logger
            )
        );
        $container->bind(
            \App\Modules\Core\Services\FeeCalculatorService::class,
            fn () => new \App\Modules\Core\Services\FeeCalculatorService(
                $container->make(\App\Shared\Persistence\Connection::class),
                $container->make(\App\Shared\Contracts\ApplicantRepositoryInterface::class),
                $container->make(\App\Shared\Contracts\JobAndPropertyRepositoryInterface::class),
                $container->make(\App\Shared\Contracts\PaymentRepositoryInterface::class),
                $container->make(\App\Shared\Contracts\SettingRepositoryInterface::class)
            )
        );
    }

    public function boot(ModuleLoader $loader): void
    {
        // Phase 1: no cross-module wiring yet.
    }

    public function routes(): array
    {
        // Meta routes (Phase 1) + public/admin/user routes (Phase 2).
        // Order matters: specific routes before catch-all (none here yet).
        return [
            // ----- Phase 1 meta routes -----
            ['method' => 'GET', 'path' => '/health', 'handler' => \App\Modules\Core\Controllers\HealthController::class, 'action' => 'health'],
            ['method' => 'GET', 'path' => '/check-cancel', 'handler' => \App\Modules\Core\Controllers\CronController::class, 'action' => 'cancel'],
            ['method' => 'GET', 'path' => '/check-sync', 'handler' => \App\Modules\Core\Controllers\CronController::class, 'action' => 'sync'],
            ['method' => 'GET', 'path' => '/api/v1/stream', 'handler' => \App\Modules\Core\Controllers\SseController::class, 'action' => 'stream'],

            // ----- Phase 2 public routes -----
            ['method' => 'GET', 'path' => '/', 'handler' => \App\Modules\Core\Controllers\HomeController::class, 'action' => 'index'],
            ['method' => 'GET', 'path' => '/guide', 'handler' => \App\Modules\Core\Controllers\HomeController::class, 'action' => 'guide'],
            ['method' => 'GET', 'path' => '/about', 'handler' => \App\Modules\Core\Controllers\HomeController::class, 'action' => 'about'],

            // ----- Phase 2 user auth routes -----
            ['method' => 'GET', 'path' => '/signup', 'handler' => \App\Modules\Core\Controllers\AuthController::class, 'action' => 'signupForm'],
            ['method' => 'POST', 'path' => '/signup', 'handler' => \App\Modules\Core\Controllers\AuthController::class, 'action' => 'signup'],
            ['method' => 'GET', 'path' => '/login', 'handler' => \App\Modules\Core\Controllers\AuthController::class, 'action' => 'loginForm'],
            ['method' => 'POST', 'path' => '/login', 'handler' => \App\Modules\Core\Controllers\AuthController::class, 'action' => 'login'],
            ['method' => 'GET', 'path' => '/logout', 'handler' => \App\Modules\Core\Controllers\AuthController::class, 'action' => 'logout'],

            // ----- Phase 2 admin auth routes -----
            ['method' => 'GET', 'path' => '/admin/login', 'handler' => \App\Modules\Core\Controllers\AuthController::class, 'action' => 'adminLoginForm'],
            ['method' => 'POST', 'path' => '/admin/login', 'handler' => \App\Modules\Core\Controllers\AuthController::class, 'action' => 'adminLogin'],
            ['method' => 'GET', 'path' => '/admin/logout', 'handler' => \App\Modules\Core\Controllers\AuthController::class, 'action' => 'adminLogout'],

            // ----- Phase 2 admin panel routes -----
            ['method' => 'GET', 'path' => '/admin/dashboard', 'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'dashboard'],
            ['method' => 'GET', 'path' => '/admin/applications', 'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'applications'],
            ['method' => 'GET', 'path' => '/admin/view/{id}', 'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'viewApplication'],
            ['method' => 'POST', 'path' => '/admin/change-status', 'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'changeStatus'],

            // ----- Phase 2 public tracking routes -----
            ['method' => 'GET', 'path' => '/tracking', 'handler' => \App\Modules\Core\Controllers\TrackingController::class, 'action' => 'search'],
            ['method' => 'POST', 'path' => '/tracking/search', 'handler' => \App\Modules\Core\Controllers\TrackingController::class, 'action' => 'find'],
            ['method' => 'GET', 'path' => '/tracking/status/{trackingCode}', 'handler' => \App\Modules\Core\Controllers\TrackingController::class, 'action' => 'status'],
            ['method' => 'POST', 'path' => '/tracking/change-postal-code', 'handler' => \App\Modules\Core\Controllers\TrackingController::class, 'action' => 'changePostalCode'],
            ['method' => 'GET', 'path' => '/tracking/print/{trackingCode}', 'handler' => \App\Modules\Core\Controllers\TrackingController::class, 'action' => 'printView'],
        ];
    }

    public function migrations(): array
    {
        return [
            '20261117_001_bootstrap_marker.sql',
            '20261117_002_core_identity_and_applicant_tables.sql',
            '20261117_003_payments_and_license_access.sql',
            '20261117_004_wizard_form_fields_and_sms.sql',
            '20261117_005_user_accounts_fees_and_lookup_tables.sql',
        ];
    }

    public function subscribers(): array
    {
        // Phase 1: no event subscribers. Phase 4 will wire LogSubscriber.
        return [];
    }

    public function ownedTables(): array
    {
        // Per docs/SCHEMA_OWNERSHIP.md §2 — Core owns these tables.
        // Listed here so CI's check_schema_ownership.py can enforce the
        // matrix without reading the docs file.
        return [
            'users',
            'persons',
            'applicants',
            'service_types',
            'job_details',
            'property_details',
            'documents',
            'document_types',
            'status_log',
            'postal_code_changes',
            'applicant_field_values',
            'license_access_requests',
            'payments',
            'payment_audit_log',
            'license_files',
            'user_sms_entries',
            'applicant_revisions',
            'user_notifications',
            'fee_tariffs',
            'sessions',
            'settings',
            'persian_date_cache',
            'email_queue',
        ];
    }
}
