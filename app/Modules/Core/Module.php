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
            \App\Shared\Contracts\UserNotificationRepositoryInterface::class,
            fn () => new \App\Modules\Core\Persistence\PdoUserNotificationRepository(
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

        // NotificationService — SMS + in-app notification delivery.
        // Called by NotificationSubscriber on ApplicantMatched events.
        $container->bind(
            \App\Modules\Core\Services\NotificationService::class,
            fn () => new \App\Modules\Core\Services\NotificationService(
                $container->make(\App\Shared\Contracts\ApplicantRepositoryInterface::class),
                $container->make(\App\Shared\Contracts\PersonRepositoryInterface::class),
                $container->make(\App\Shared\Persistence\Connection::class),
                $container->logger
            )
        );

        // SmsService — real SMS gateway (Kavenegar-compatible). In production
        // + with SMS_API_KEY set, sends via HTTP. Otherwise log-only (dev mode).
        // Drain invoked by scripts/run_worker.php on each cron tick.
        $container->bind(
            \App\Modules\Core\Services\SmsService::class,
            fn () => new \App\Modules\Core\Services\SmsService(
                $container->make(\App\Shared\Persistence\Connection::class),
                $container->make(\App\Shared\Support\Environment::class),
                $container->logger
            )
        );

        // AvatarService — user-profile image upload + storage.
        // Phase 12 (v1.3.0): resolves debt D-9-1 (the last medium-priority
        // debt). Called by UserPanelController::uploadAvatar() for the
        // multipart POST to /panel/avatar. Resizes via GD to 256x256,
        // stores at storage/uploads/avatars/account-{id}.jpg, inserts a
        // documents row, sets user_accounts.avatar_document_id.
        $container->bind(
            \App\Modules\Core\Services\AvatarService::class,
            fn () => new \App\Modules\Core\Services\AvatarService(
                $container->make(\App\Shared\Persistence\Connection::class),
                $container->logger
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

            // ----- Phase 9 user panel routes (/panel/*) -----
            // Closes the v2→v3 gap reported by the user (404 after login).
            // AuthController::login() redirects to /panel — these routes serve it.
            ['method' => 'GET',  'path' => '/panel',                     'handler' => \App\Modules\Core\Controllers\UserPanelController::class, 'action' => 'index'],
            ['method' => 'GET',  'path' => '/panel/profile',             'handler' => \App\Modules\Core\Controllers\UserPanelController::class, 'action' => 'profile'],
            ['method' => 'POST', 'path' => '/panel/profile',             'handler' => \App\Modules\Core\Controllers\UserPanelController::class, 'action' => 'updateProfile'],
            ['method' => 'POST', 'path' => '/panel/avatar',               'handler' => \App\Modules\Core\Controllers\UserPanelController::class, 'action' => 'uploadAvatar'],
            ['method' => 'GET',  'path' => '/panel/requests',             'handler' => \App\Modules\Core\Controllers\UserPanelController::class, 'action' => 'requests'],
            ['method' => 'GET',  'path' => '/panel/payments',             'handler' => \App\Modules\Core\Controllers\UserPanelController::class, 'action' => 'payments'],
            ['method' => 'GET',  'path' => '/panel/licenses',             'handler' => \App\Modules\Core\Controllers\UserPanelController::class, 'action' => 'licenses'],
            ['method' => 'GET',  'path' => '/panel/notifications',        'handler' => \App\Modules\Core\Controllers\UserPanelController::class, 'action' => 'notifications'],
            ['method' => 'POST', 'path' => '/panel/notifications/read',   'handler' => \App\Modules\Core\Controllers\UserPanelController::class, 'action' => 'markAllRead'],
            ['method' => 'GET',  'path' => '/panel/tracking',             'handler' => \App\Modules\Core\Controllers\UserPanelController::class, 'action' => 'tracking'],

            // ----- Phase 2 admin auth routes -----
            ['method' => 'GET', 'path' => '/admin/login', 'handler' => \App\Modules\Core\Controllers\AuthController::class, 'action' => 'adminLoginForm'],
            ['method' => 'POST', 'path' => '/admin/login', 'handler' => \App\Modules\Core\Controllers\AuthController::class, 'action' => 'adminLogin'],
            ['method' => 'GET', 'path' => '/admin/logout', 'handler' => \App\Modules\Core\Controllers\AuthController::class, 'action' => 'adminLogout'],

            // ----- Phase 2 admin panel routes -----
            ['method' => 'GET', 'path' => '/admin/dashboard', 'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'dashboard'],
            ['method' => 'GET', 'path' => '/admin/applications', 'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'applications'],
            ['method' => 'GET', 'path' => '/admin/view/{id}', 'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'viewApplication'],
            ['method' => 'POST', 'path' => '/admin/change-status', 'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'changeStatus'],

            // ----- Phase 11 admin: form_fields CRUD (replaces Phase-10 stub) -----
            // Replaces the single GET /admin/form-fields stub with full CRUD
            // on the form_fields table. Pays D-7-1 + D-10-1 (DEBT_REVIEW).
            // Note: /move must be registered BEFORE /{id} so the POST
            // /admin/form-fields/{id} regex ([^/]+) doesn't catch "move".
            ['method' => 'GET',  'path' => '/admin/form-fields',              'handler' => \App\Modules\Core\Controllers\FormFieldController::class, 'action' => 'index'],
            ['method' => 'GET',  'path' => '/admin/form-fields/new',           'handler' => \App\Modules\Core\Controllers\FormFieldController::class, 'action' => 'create'],
            ['method' => 'POST', 'path' => '/admin/form-fields',               'handler' => \App\Modules\Core\Controllers\FormFieldController::class, 'action' => 'store'],
            ['method' => 'POST', 'path' => '/admin/form-fields/move',          'handler' => \App\Modules\Core\Controllers\FormFieldController::class, 'action' => 'move'],
            ['method' => 'GET',  'path' => '/admin/form-fields/{id}/edit',    'handler' => \App\Modules\Core\Controllers\FormFieldController::class, 'action' => 'edit'],
            ['method' => 'POST', 'path' => '/admin/form-fields/{id}',          'handler' => \App\Modules\Core\Controllers\FormFieldController::class, 'action' => 'update'],
            ['method' => 'POST', 'path' => '/admin/form-fields/{id}/delete',   'handler' => \App\Modules\Core\Controllers\FormFieldController::class, 'action' => 'destroy'],

            // ----- Phase 11 admin: fee_tariffs CRUD (replaces Phase-10 stub) -----
            ['method' => 'GET',  'path' => '/admin/fee-tariff',               'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'index'],
            ['method' => 'GET',  'path' => '/admin/fee-tariff/new',            'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'create'],
            ['method' => 'POST', 'path' => '/admin/fee-tariff',                'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'store'],
            ['method' => 'GET',  'path' => '/admin/fee-tariff/{id}/edit',     'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'edit'],
            ['method' => 'POST', 'path' => '/admin/fee-tariff/{id}',           'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'update'],
            ['method' => 'POST', 'path' => '/admin/fee-tariff/{id}/delete',  'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'destroy'],

            // ----- Phase 11 admin: license_titles CRUD (replaces Phase-10 stub) -----
            // /by-service/{id} must be registered before any catch-all GET
            // route on /admin/license-titles/* (none here, but explicit for
            // readability — both /by-service/{id} and /{id}/edit have 3 path
            // segments after /admin/license-titles and don't conflict).
            ['method' => 'GET',  'path' => '/admin/license-titles',                 'handler' => \App\Modules\Core\Controllers\LicenseTitleController::class, 'action' => 'index'],
            ['method' => 'GET',  'path' => '/admin/license-titles/new',              'handler' => \App\Modules\Core\Controllers\LicenseTitleController::class, 'action' => 'create'],
            ['method' => 'POST', 'path' => '/admin/license-titles',                  'handler' => \App\Modules\Core\Controllers\LicenseTitleController::class, 'action' => 'store'],
            ['method' => 'GET',  'path' => '/admin/license-titles/{id}/edit',       'handler' => \App\Modules\Core\Controllers\LicenseTitleController::class, 'action' => 'edit'],
            ['method' => 'POST', 'path' => '/admin/license-titles/{id}',            'handler' => \App\Modules\Core\Controllers\LicenseTitleController::class, 'action' => 'update'],
            ['method' => 'POST', 'path' => '/admin/license-titles/{id}/delete',     'handler' => \App\Modules\Core\Controllers\LicenseTitleController::class, 'action' => 'destroy'],
            ['method' => 'POST', 'path' => '/admin/license-titles/{id}/toggle',     'handler' => \App\Modules\Core\Controllers\LicenseTitleController::class, 'action' => 'toggle'],
            ['method' => 'GET',  'path' => '/admin/license-titles/by-service/{id}', 'handler' => \App\Modules\Core\Controllers\LicenseTitleController::class, 'action' => 'byService'],

            // ----- Phase 7 admin: user + settings management -----
            ['method' => 'GET',  'path' => '/admin/users',                'handler' => \App\Modules\Core\Controllers\UsersController::class, 'action' => 'index'],
            ['method' => 'GET',  'path' => '/admin/users/new',            'handler' => \App\Modules\Core\Controllers\UsersController::class, 'action' => 'create'],
            ['method' => 'POST', 'path' => '/admin/users',                'handler' => \App\Modules\Core\Controllers\UsersController::class, 'action' => 'store'],
            ['method' => 'GET',  'path' => '/admin/users/{id}/edit',      'handler' => \App\Modules\Core\Controllers\UsersController::class, 'action' => 'edit'],
            ['method' => 'POST', 'path' => '/admin/users/{id}',          'handler' => \App\Modules\Core\Controllers\UsersController::class, 'action' => 'update'],
            ['method' => 'POST', 'path' => '/admin/users/{id}/delete',    'handler' => \App\Modules\Core\Controllers\UsersController::class, 'action' => 'destroy'],

            ['method' => 'GET',  'path' => '/admin/settings',             'handler' => \App\Modules\Core\Controllers\SettingsController::class, 'action' => 'index'],
            ['method' => 'POST', 'path' => '/admin/settings',             'handler' => \App\Modules\Core\Controllers\SettingsController::class, 'action' => 'update'],

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
            '20261117_006_rate_limit_buckets.sql',
            '20261117_007_seed_avatar_document_type.sql',
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
