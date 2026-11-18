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

            // ----- Phase 13 public API: license-titles by service (pays D-11-1) -----
            // Used by the registration wizard's job-detail step to dynamically
            // fetch the license-title dropdown options for a chosen
            // service_type. NO auth required (license titles are public
            // catalog data). The payload surface is restricted to
            // {id, title, category, is_active} — no internal fields like
            // sort_order. The admin variant (byService) stays admin-only
            // and returns the full set.
            ['method' => 'GET', 'path' => '/api/v1/license-titles/by-service/{id}', 'handler' => \App\Modules\Core\Controllers\LicenseTitleController::class, 'action' => 'byServicePublic'],

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

            // ----- Phase 17 admin: AdminController extensions (12 routes) -----
            // Order: specific routes (/register, /edit/{id}, /postal-requests,
            // /my-tasks, /add-document, /delete-document, /request-payment,
            // /license-state, /postal-requests/approve, /postal-requests/reject,
            // /update) are registered BEFORE the catch-all /admin/view/{id}
            // (first-match router — the {id} placeholder matches [^/]+, so
            // a generic /admin/{id} would catch /admin/register etc.).
            ['method' => 'GET',  'path' => '/admin/register',                  'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'registerForm'],
            ['method' => 'POST', 'path' => '/admin/register',                  'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'registerProcess'],
            ['method' => 'GET',  'path' => '/admin/edit/{id}',                 'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'editApplication'],
            ['method' => 'POST', 'path' => '/admin/update',                    'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'updateApplication'],
            ['method' => 'POST', 'path' => '/admin/add-document',              'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'addDocument'],
            ['method' => 'POST', 'path' => '/admin/delete-document',          'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'deleteDocument'],
            ['method' => 'POST', 'path' => '/admin/request-payment',          'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'requestPayment'],
            ['method' => 'POST', 'path' => '/admin/license-state',            'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'updateLicenseState'],
            ['method' => 'GET',  'path' => '/admin/postal-requests',          'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'postalRequests'],
            ['method' => 'POST', 'path' => '/admin/postal-requests/approve',  'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'approvePostalRequest'],
            ['method' => 'POST', 'path' => '/admin/postal-requests/reject',   'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'rejectPostalRequest'],
            ['method' => 'GET',  'path' => '/admin/my-tasks',                  'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'myTasks'],

            // ----- Phase 17 admin: ServiceType CRUD (3 routes) -----
            ['method' => 'GET',  'path' => '/admin/service-types',         'handler' => \App\Modules\Core\Controllers\ServiceTypeController::class, 'action' => 'index'],
            ['method' => 'POST', 'path' => '/admin/service-types/save',    'handler' => \App\Modules\Core\Controllers\ServiceTypeController::class, 'action' => 'save'],
            ['method' => 'POST', 'path' => '/admin/service-types/delete', 'handler' => \App\Modules\Core\Controllers\ServiceTypeController::class, 'action' => 'delete'],

            // ----- Phase 17 admin: WizardStep CRUD (5 routes) -----
            // toggle/{id} uses a path param; save/delete/move use body fields.
            // /move must be registered before /save (no conflict — different
            // paths) and before any catch-all on /admin/wizard-steps.
            ['method' => 'GET',  'path' => '/admin/wizard-steps',            'handler' => \App\Modules\Core\Controllers\WizardStepController::class, 'action' => 'index'],
            ['method' => 'POST', 'path' => '/admin/wizard-steps/save',       'handler' => \App\Modules\Core\Controllers\WizardStepController::class, 'action' => 'save'],
            ['method' => 'POST', 'path' => '/admin/wizard-steps/toggle/{id}', 'handler' => \App\Modules\Core\Controllers\WizardStepController::class, 'action' => 'toggle'],
            ['method' => 'POST', 'path' => '/admin/wizard-steps/delete',     'handler' => \App\Modules\Core\Controllers\WizardStepController::class, 'action' => 'delete'],
            ['method' => 'POST', 'path' => '/admin/wizard-steps/move',       'handler' => \App\Modules\Core\Controllers\WizardStepController::class, 'action' => 'move'],

            // ----- Phase 17 admin: Sms CRUD + entry review (4 routes) -----
            ['method' => 'GET',  'path' => '/admin/sms',                     'handler' => \App\Modules\Core\Controllers\SmsController::class, 'action' => 'index'],
            ['method' => 'POST', 'path' => '/admin/sms/save-template',       'handler' => \App\Modules\Core\Controllers\SmsController::class, 'action' => 'saveTemplate'],
            ['method' => 'POST', 'path' => '/admin/sms/delete-template',     'handler' => \App\Modules\Core\Controllers\SmsController::class, 'action' => 'deleteTemplate'],
            ['method' => 'POST', 'path' => '/admin/sms/review-entry',       'handler' => \App\Modules\Core\Controllers\SmsController::class, 'action' => 'reviewEntry'],

            // ----- Phase 22 (v1.9.4): 18-status workflow diagram -----
            // Documentation-only view (requireRole(['admin'])) showing all
            // 18 statuses (14 shared + 4 household-only) as cards with
            // order, color, manual/auto flag, pre_issue flag.
            ['method' => 'GET',  'path' => '/admin/status-cycle',            'handler' => \App\Modules\Core\Controllers\AdminController::class, 'action' => 'statusCycle'],

            // Catch-all: view a single applicant (must come AFTER the new
            // /admin/{action} routes so they don't get swallowed by {id}).
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
            // Note: the more specific /admin/fee-tariff/settings route
            // (Phase 20) MUST be registered BEFORE the /admin/fee-tariff/{id}
            // catch-all — otherwise `settings` would be captured by the {id}
            // regex ([^/]+) and dispatched to `update()` instead of
            // `saveSettings()`. The other 4 Phase-20 routes have a different
            // path structure (more than 3 segments or different prefix) and
            // don't conflict with the {id} catch-all, but they're grouped
            // here for readability.
            ['method' => 'GET',  'path' => '/admin/fee-tariff',               'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'index'],
            ['method' => 'GET',  'path' => '/admin/fee-tariff/new',            'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'create'],
            ['method' => 'POST', 'path' => '/admin/fee-tariff',                'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'store'],
            ['method' => 'GET',  'path' => '/admin/fee-tariff/{id}/edit',     'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'edit'],
            ['method' => 'POST', 'path' => '/admin/fee-tariff/{id}/delete',  'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'destroy'],
            // Phase 20 (v1.9.2) — FeeTariff extensions (5 routes) ported from v2.
            // /settings is registered BEFORE the {id} catch-all so the
            // `update()` action doesn't capture it.
            ['method' => 'POST', 'path' => '/admin/fee-tariff/settings',          'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'saveSettings'],
            ['method' => 'POST', 'path' => '/admin/fee-tariff/group/save',        'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'groupSave'],
            ['method' => 'POST', 'path' => '/admin/fee-tariff/group/{id}/delete', 'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'groupDelete'],
            ['method' => 'POST', 'path' => '/admin/fee-tariff/items/save',        'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'itemsSave'],
            // The {id} catch-all MUST come after the /settings, /group/save,
            // /items/save, /group/{id}/delete routes above (otherwise
            // `update()` would steal /settings). The {id} segment matches
            // [^/]+ so a 4-segment path like /group/save never collides
            // with the 3-segment {id} pattern — but a 3-segment path like
            // /settings DOES collide, hence the ordering above.
            ['method' => 'POST', 'path' => '/admin/fee-tariff/{id}',           'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'update'],
            ['method' => 'POST', 'path' => '/admin/payment/tick',                  'handler' => \App\Modules\Core\Controllers\FeeTariffController::class, 'action' => 'toggleTick'],

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

            // ----- Phase 42 (v2.0.8) admin: applicant account management -----
            // These are the `user_accounts` rows (the accounts applicants use
            // to log in to /panel/*), NOT the admin `users` table managed by
            // UsersController above. The 4 routes give an admin:
            //   - list with search (mobile / username LIKE)
            //   - detail view (account fields + linked applicants)
            //   - toggle is_active (disable / re-enable login)
            //   - reset password (generate a new random password, hash + show
            //     the raw value once via session flash so the admin can pass it
            //     to the applicant out-of-band).
            // All routes are admin-only (requireRole(['admin'])).
            ['method' => 'GET',  'path' => '/admin/applicant-accounts',                        'handler' => \App\Modules\Core\Controllers\ApplicantAccountController::class, 'action' => 'index'],
            ['method' => 'GET',  'path' => '/admin/applicant-accounts/{id}',                   'handler' => \App\Modules\Core\Controllers\ApplicantAccountController::class, 'action' => 'view'],
            ['method' => 'POST', 'path' => '/admin/applicant-accounts/{id}/toggle',             'handler' => \App\Modules\Core\Controllers\ApplicantAccountController::class, 'action' => 'toggle'],
            ['method' => 'POST', 'path' => '/admin/applicant-accounts/{id}/reset-password',     'handler' => \App\Modules\Core\Controllers\ApplicantAccountController::class, 'action' => 'resetPassword'],

            ['method' => 'GET',  'path' => '/admin/settings',             'handler' => \App\Modules\Core\Controllers\SettingsController::class, 'action' => 'index'],
            ['method' => 'POST', 'path' => '/admin/settings',             'handler' => \App\Modules\Core\Controllers\SettingsController::class, 'action' => 'update'],
            // Phase 20 (v1.9.2): image upload for site_logo + header banners.
            ['method' => 'POST', 'path' => '/admin/settings/upload-image', 'handler' => \App\Modules\Core\Controllers\SettingsController::class, 'action' => 'uploadImage'],
            // Phase 49 (v2.1.5): delete a custom (non-known) setting.
            ['method' => 'POST', 'path' => '/admin/settings/delete',       'handler' => \App\Modules\Core\Controllers\SettingsController::class, 'action' => 'delete'],

            // ----- Phase 2 public tracking routes -----
            ['method' => 'GET', 'path' => '/tracking', 'handler' => \App\Modules\Core\Controllers\TrackingController::class, 'action' => 'search'],
            ['method' => 'POST', 'path' => '/tracking/search', 'handler' => \App\Modules\Core\Controllers\TrackingController::class, 'action' => 'find'],
            ['method' => 'GET', 'path' => '/tracking/status/{trackingCode}', 'handler' => \App\Modules\Core\Controllers\TrackingController::class, 'action' => 'status'],
            ['method' => 'POST', 'path' => '/tracking/change-postal-code', 'handler' => \App\Modules\Core\Controllers\TrackingController::class, 'action' => 'changePostalCode'],
            ['method' => 'GET', 'path' => '/tracking/print/{trackingCode}', 'handler' => \App\Modules\Core\Controllers\TrackingController::class, 'action' => 'printView'],

            // ----- Phase 15 — Tracking: payment + SMS submit (user-facing) -----
            ['method' => 'POST', 'path' => '/tracking/payments/submit', 'handler' => \App\Modules\Core\Controllers\TrackingController::class, 'action' => 'submitPayment'],
            ['method' => 'POST', 'path' => '/tracking/sms/submit', 'handler' => \App\Modules\Core\Controllers\TrackingController::class, 'action' => 'submitSms'],

            // ----- Phase 15 — Registration wizard -----
            // The 4-step applicant registration wizard. Auth: requireUserLogin()
            // at the top of every action (the session-keyed wizard.applicant_id
            // is tied to user_account_id).
            //
            // Route order: specific routes (/service/{id}, /step/{n},
            // /success/{trackingCode}) are registered before any catch-all.
            ['method' => 'GET',  'path' => '/register',                       'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'index'],
            ['method' => 'GET',  'path' => '/register/service/{id}',          'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'selectService'],
            ['method' => 'GET',  'path' => '/register/step/{n}',              'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'stepByPosition'],
            ['method' => 'POST', 'path' => '/register/step/{n}',              'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'processByPosition'],
            ['method' => 'GET',  'path' => '/register/step1',                  'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'step1'],
            ['method' => 'POST', 'path' => '/register/step1',                  'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'processStep1'],
            ['method' => 'GET',  'path' => '/register/step2',                  'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'step2'],
            ['method' => 'POST', 'path' => '/register/step2',                  'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'processStep2'],
            ['method' => 'GET',  'path' => '/register/step3',                  'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'step3'],
            ['method' => 'POST', 'path' => '/register/step3',                  'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'processStep3'],
            ['method' => 'GET',  'path' => '/register/step4',                  'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'step4'],
            ['method' => 'POST', 'path' => '/register/step4',                  'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'processStep4'],
            ['method' => 'GET',  'path' => '/register/success/{trackingCode}', 'handler' => \App\Modules\Core\Controllers\RegistrationController::class, 'action' => 'success'],

            // ----- Phase 15 — Services (public list + purchase → wizard) -----
            ['method' => 'GET',  'path' => '/services',              'handler' => \App\Modules\Core\Controllers\ServiceController::class, 'action' => 'index'],
            ['method' => 'POST', 'path' => '/services/{id}/purchase', 'handler' => \App\Modules\Core\Controllers\ServiceController::class, 'action' => 'purchase'],

            // ----- Phase 15 — Records (user's own applications) -----
            ['method' => 'GET',  'path' => '/my-records',         'handler' => \App\Modules\Core\Controllers\RecordsController::class, 'action' => 'form'],
            ['method' => 'POST', 'path' => '/my-records/verify',  'handler' => \App\Modules\Core\Controllers\RecordsController::class, 'action' => 'verify'],

            // ----- Phase 15 — Document serving -----
            // /view is POST (AJAX modal). /avatar is GET (file stream).
            // /thumb/{id}, /inline/{id}, /raw/{id} are GET (file streams).
            // /pdf-viewer is GET (HTML wrapper, layout='none').
            ['method' => 'POST', 'path' => '/document/view',       'handler' => \App\Modules\Core\Controllers\DocumentController::class, 'action' => 'view'],
            ['method' => 'GET',  'path' => '/document/avatar',     'handler' => \App\Modules\Core\Controllers\DocumentController::class, 'action' => 'avatar'],
            ['method' => 'GET',  'path' => '/document/thumb/{id}', 'handler' => \App\Modules\Core\Controllers\DocumentController::class, 'action' => 'thumb'],
            ['method' => 'GET',  'path' => '/document/inline/{id}','handler' => \App\Modules\Core\Controllers\DocumentController::class, 'action' => 'inline'],
            ['method' => 'GET',  'path' => '/document/pdf-viewer', 'handler' => \App\Modules\Core\Controllers\DocumentController::class, 'action' => 'pdfViewer'],
            ['method' => 'GET',  'path' => '/document/raw/{id}',   'handler' => \App\Modules\Core\Controllers\DocumentController::class, 'action' => 'raw'],

            // ----- Phase 15 — Revision requests (user form + admin review) -----
            ['method' => 'GET',  'path' => '/tracking/revision/{trackingCode}', 'handler' => \App\Modules\Core\Controllers\RevisionController::class, 'action' => 'form'],
            ['method' => 'POST', 'path' => '/tracking/revision/submit',          'handler' => \App\Modules\Core\Controllers\RevisionController::class, 'action' => 'submit'],
            ['method' => 'GET',  'path' => '/admin/revisions',                    'handler' => \App\Modules\Core\Controllers\RevisionController::class, 'action' => 'index'],
            ['method' => 'GET',  'path' => '/admin/revisions/view/{id}',         'handler' => \App\Modules\Core\Controllers\RevisionController::class, 'action' => 'detail'],
            ['method' => 'POST', 'path' => '/admin/revisions/approve/{id}',     'handler' => \App\Modules\Core\Controllers\RevisionController::class, 'action' => 'approve'],
            ['method' => 'POST', 'path' => '/admin/revisions/reject/{id}',      'handler' => \App\Modules\Core\Controllers\RevisionController::class, 'action' => 'reject'],

            // ----- Phase 15 — License image access + admin license mgmt -----
            ['method' => 'GET',  'path' => '/license/view/{token}',              'handler' => \App\Modules\Core\Controllers\LicenseController::class, 'action' => 'viewByToken'],
            ['method' => 'POST', 'path' => '/tracking/license/first-access',     'handler' => \App\Modules\Core\Controllers\LicenseController::class, 'action' => 'firstAccess'],
            ['method' => 'POST', 'path' => '/tracking/license/request-access',   'handler' => \App\Modules\Core\Controllers\LicenseController::class, 'action' => 'requestAccess'],
            ['method' => 'POST', 'path' => '/tracking/license/pay',              'handler' => \App\Modules\Core\Controllers\LicenseController::class, 'action' => 'submitReissuePayment'],
            ['method' => 'POST', 'path' => '/admin/license/upload',              'handler' => \App\Modules\Core\Controllers\LicenseController::class, 'action' => 'upload'],
            ['method' => 'GET',  'path' => '/admin/license-requests',             'handler' => \App\Modules\Core\Controllers\LicenseController::class, 'action' => 'requests'],
            ['method' => 'POST', 'path' => '/admin/license-requests/review',     'handler' => \App\Modules\Core\Controllers\LicenseController::class, 'action' => 'reviewRequest'],

            // ----- Phase 18 — Payment management (5 routes) -----
            // Auth: requireRole(['admin']) on every action. The catch-all
            // /admin/view/{id} above doesn't conflict (different path prefix).
            ['method' => 'GET',  'path' => '/admin/payments',                  'handler' => \App\Modules\Core\Controllers\PaymentController::class, 'action' => 'index'],
            ['method' => 'POST', 'path' => '/admin/payments/review',           'handler' => \App\Modules\Core\Controllers\PaymentController::class, 'action' => 'review'],
            ['method' => 'GET',  'path' => '/admin/payments/csv',               'handler' => \App\Modules\Core\Controllers\PaymentController::class, 'action' => 'csv'],
            ['method' => 'POST', 'path' => '/admin/payment-channels/save',     'handler' => \App\Modules\Core\Controllers\PaymentController::class, 'action' => 'saveChannel'],
            ['method' => 'POST', 'path' => '/admin/payment-channels/delete',   'handler' => \App\Modules\Core\Controllers\PaymentController::class, 'action' => 'deleteChannel'],

            // ----- Phase 18 — Bulk import (8 routes) + Phase 37 template (1) -----
            // Specific routes (upload, preview, execute, result, missing-docs,
            // documents/{code}, template) come in path-disjoint prefixes — no
            // first-match ordering issue with any catch-all.
            ['method' => 'GET',  'path' => '/admin/bulk-import',                          'handler' => \App\Modules\Core\Controllers\BulkImportController::class, 'action' => 'index'],
            ['method' => 'GET',  'path' => '/admin/bulk-import/template',                  'handler' => \App\Modules\Core\Controllers\BulkImportController::class, 'action' => 'template'],
            ['method' => 'POST', 'path' => '/admin/bulk-import/upload',                   'handler' => \App\Modules\Core\Controllers\BulkImportController::class, 'action' => 'upload'],
            ['method' => 'GET',  'path' => '/admin/bulk-import/preview',                   'handler' => \App\Modules\Core\Controllers\BulkImportController::class, 'action' => 'preview'],
            ['method' => 'POST', 'path' => '/admin/bulk-import/execute',                  'handler' => \App\Modules\Core\Controllers\BulkImportController::class, 'action' => 'execute'],
            ['method' => 'GET',  'path' => '/admin/bulk-import/result',                   'handler' => \App\Modules\Core\Controllers\BulkImportController::class, 'action' => 'result'],
            ['method' => 'GET',  'path' => '/admin/bulk-import/missing-docs',             'handler' => \App\Modules\Core\Controllers\BulkImportController::class, 'action' => 'missingDocs'],
            ['method' => 'GET',  'path' => '/admin/bulk-import/documents/{code}',         'handler' => \App\Modules\Core\Controllers\BulkImportController::class, 'action' => 'uploadDocs'],
            ['method' => 'POST', 'path' => '/admin/bulk-import/documents/{code}/save',    'handler' => \App\Modules\Core\Controllers\BulkImportController::class, 'action' => 'saveDocs'],

            // ----- Phase 18 — Reports + exports (11 routes) -----
            // All admin-only. csv/pdf are GET (download). grouped is GET (form)
            // + POST (result). single/{trackingCode} uses a path param.
            ['method' => 'GET',  'path' => '/admin/reports/by-province',              'handler' => \App\Modules\Core\Controllers\ReportController::class, 'action' => 'byProvince'],
            ['method' => 'GET',  'path' => '/admin/reports/by-city',                  'handler' => \App\Modules\Core\Controllers\ReportController::class, 'action' => 'byCity'],
            ['method' => 'GET',  'path' => '/admin/reports/by-village',               'handler' => \App\Modules\Core\Controllers\ReportController::class, 'action' => 'byVillage'],
            ['method' => 'GET',  'path' => '/admin/reports/by-license',               'handler' => \App\Modules\Core\Controllers\ReportController::class, 'action' => 'byLicense'],
            ['method' => 'GET',  'path' => '/admin/reports/by-date',                   'handler' => \App\Modules\Core\Controllers\ReportController::class, 'action' => 'byDate'],
            ['method' => 'GET',  'path' => '/admin/output/general',                   'handler' => \App\Modules\Core\Controllers\ReportController::class, 'action' => 'generalReport'],
            ['method' => 'GET',  'path' => '/admin/output/single/{trackingCode}',     'handler' => \App\Modules\Core\Controllers\ReportController::class, 'action' => 'singleApplicantReport'],
            ['method' => 'GET',  'path' => '/admin/output/grouped',                   'handler' => \App\Modules\Core\Controllers\ReportController::class, 'action' => 'groupedReportForm'],
            ['method' => 'POST', 'path' => '/admin/output/grouped',                   'handler' => \App\Modules\Core\Controllers\ReportController::class, 'action' => 'groupedReportResult'],
            ['method' => 'GET',  'path' => '/admin/report/csv',                        'handler' => \App\Modules\Core\Controllers\ReportController::class, 'action' => 'csvReport'],
            ['method' => 'GET',  'path' => '/admin/report/pdf',                       'handler' => \App\Modules\Core\Controllers\ReportController::class, 'action' => 'pdfReport'],
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
            '20261117_008_user_accounts_email_display_name.sql',
            // Phase 14 (v1.5.0) — pays D-2-9. Seeds the 5 default
            // service_types + 8 license_titles so a fresh install has a
            // non-empty wizard dropdown out of the box. Idempotent
            // (INSERT ... ON DUPLICATE KEY UPDATE on the UNIQUE
            // constraints).
            '20261117_009_seed_default_service_types.sql',
            // Phase 16 (v1.7.0) — pays part of D-7-3. Seeds the 6 default
            // document_types (national_id, personal_photo, ownership_deed,
            // skill_cert, business_license, avatar) so the wizard's step 4
            // has real document-type definitions to render against, and
            // the controller can require national_id + personal_photo
            // dynamically. Idempotent (INSERT ... ON DUPLICATE KEY UPDATE
            // on UNIQUE doc_type_key).
            '20261117_010_seed_document_types_default.sql',
            // Phase 16 (v1.7.0) — seeds the default admin user
            // (username=admin, password=admin123 — change on first
            // login!) so a fresh install is immediately usable.
            // Idempotent (INSERT ... WHERE NOT EXISTS — skips if any
            // admin row exists, so password rotation is preserved).
            '20261117_011_seed_admin_default.sql',
            // Phase 22 (v1.9.4) — pays D-16-1. Adds a real `is_required`
            // TINYINT(1) column to the document_types lookup table so
            // RegistrationController::requiredDocs() can read
            // requiredness from the row instead of computing it from
            // the doc_type_key string. Marks national_id + personal_photo
            // as required (idempotent UPDATE). Idempotent ALTER
            // (information_schema guard) so re-running is safe.
            '20261117_012_document_types_is_required_column.sql',
            // Phase 24 (v1.9.6) — pays D-5-5. Adds a real `township`
            // VARCHAR(50) NULL column to property_details so the
            // registration wizard can store the resolved township
            // title separately from `county`. The matcher
            // (ApplicantSyncAdapter) prefers `township` if set and
            // falls back to `county` for backward compat with rows
            // written by older code. Idempotent ALTER
            // (information_schema guard) so re-running is safe.
            '20261117_013_property_details_township_column.sql',
            // Phase 27 (v1.9.9) — pays D-14-2 (the structural follow-up
            // to D-14-1 from Phase 22). Makes
            // `user_notifications.user_account_id` nullable so the
            // NotificationService::notifyAdmins() can insert a
            // notification row even when an admin has no matching
            // user_account (the bridge by username came up empty).
            // Idempotent ALTER (information_schema guard on
            // IS_NULLABLE) so re-running is safe. The existing FK to
            // user_accounts(id) ON DELETE CASCADE is preserved (FKs
            // allow NULL — InnoDB skips the FK check when the column
            // is NULL).
            '20261117_014_user_notifications_nullable_user_account.sql',
            // Phase 32 (v1.9.12) — comprehensive seed data from andrbi108/mojavez-v3.
            // Seeds: settings (15), service_types (5 full), wizard_steps (8),
            // form_fields (~28 system fields), license_titles (~190 across 4
            // service types), sms_templates (6), fee_groups (4), payment_channels (3).
            // Idempotent (INSERT ... ON DUPLICATE KEY UPDATE).
            '20261117_015_comprehensive_seed_data.sql',
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
