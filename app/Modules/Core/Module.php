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
        // Phase 1: no service bindings yet — the Container's boot() already
        // registers all shared services. Phase 2 will register Core-specific
        // services here (LicenseService, AutoCancelService, ...).
    }

    public function boot(ModuleLoader $loader): void
    {
        // Phase 1: no cross-module wiring yet.
    }

    public function routes(): array
    {
        // Phase 1 meta routes. The front controller in public/index.php
        // dispatches these directly (without instantiating heavy controllers)
        // because /health must respond even when the DB is down.
        return [
            [
                'method'  => 'GET',
                'path'    => '/health',
                'handler' => \App\Modules\Core\Controllers\HealthController::class,
                'action'  => 'health',
            ],
            [
                'method'  => 'GET',
                'path'    => '/check-cancel',
                'handler' => \App\Modules\Core\Controllers\CronController::class,
                'action'  => 'cancel',
            ],
            [
                'method'  => 'GET',
                'path'    => '/check-sync',
                'handler' => \App\Modules\Core\Controllers\CronController::class,
                'action'  => 'sync',
            ],
            [
                'method'  => 'GET',
                'path'    => '/api/v1/stream',
                'handler' => \App\Modules\Core\Controllers\SseController::class,
                'action'  => 'stream',
            ],
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
