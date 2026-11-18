# پایگاه دادهٔ mojavez-v3

> این سند مشخصات پایگاه داده، ماتریس مالکیت schema و migrationها را بر اساس کد و `docs/SCHEMA_OWNERSHIP.md` تشریح می‌کند. مرجع راهبردی `docs/00-MASTER.md §7` و `§24` است.

---

## ۱. مشخصات کلی

- **DBMS:** MariaDB 10.11 (همین نسخه در CI service container، `.github/workflows/ci.yml`).
- **charset/collation:** `utf8mb4_unicode_ci` روی همهٔ جداول (`CODING_CONVENTIONS §11`).
- **نوع engine:** InnoDB (پشتیبانی FK و transaction).
- **موتور ذخیره‌سازی timestamp:** `DATETIME` نه `TIMESTAMP` (رفع مشکل ۲۰۳۸، `§5.2 CODING_CONVENTIONS`).
- **boolean:** `TINYINT(1)` با `0`/`1`.
- **status enum:** `VARCHAR(32)` با value از PHP enum (نه MySQL `ENUM` — جلوگیری از ALTER پرهزینه).
- **JSON:** ستون `JSON` برای `hits_json`, `context`, `payload_json`, `filter_data` (MariaDB 10.2+).

---

## ۲. ۴۱ جدول در ۵ ماژول

سامانه شامل ۳۵ جدول کسب‌وکار + ۶ جدول meta (مطابق `SCHEMA_OWNERSHIP.md`):

| ماژول مالک | تعداد جداول | نمونه جداول کلیدی |
|---|---|---|
| `Modules/Core` | 23 | `users`, `persons`, `applicants`, `service_types`, `job_details`, `property_details`, `documents`, `status_log`, `payments`, `license_files`, `fee_tariffs`, `sessions`, `settings`, `persian_date_cache`, `email_queue`, `rate_limit_buckets`, `applicant_revisions`, `user_notifications`, `user_sms_entries`, `postal_code_changes`, `applicant_field_values`, `license_access_requests`, `payment_audit_log`, `document_types` |
| `Modules/NationalGateway` | 5 | `national_licenses`, `national_lookups`, `sync_audit_log`, `national_filters`, `national_batches` |
| `Modules/Api` | 2 | `api_keys`, `api_access_log` |
| `Modules/Queue` | 4 (meta) | `jobs`, `batches`, `events`, `worker_state` |
| `Modules/Reporting` | 1 | `report_runs` |
| `Modules/Shared` | 2 | `integration_log`, `migrations` |

هر جدول دقیقاً یک مالک دارد. ماژول‌های دیگر برای خواندن از Interface، و برای نوشتن از ACL یا Interface استفاده می‌کنند. نقض این قاعده توسط `tools/check_schema_ownership.py` در CI شناسایی و fail می‌شود.

---

## ۳. Migrationها (۶ Core + ۳ NationalGateway + ۲ Queue)

Migrationها در `app/Modules/<Name>/migrations/*.sql` قرار دارند و در هر boot توسط `MigrationRunner` بررسی می‌شوند (`§24.5`).

### ۳.۱. Core (۶ migration)

در `app/Modules/Core/migrations/`:

```php
// app/Modules/Core/Module.php::migrations()
return [
    '20261117_001_bootstrap_marker.sql',
    '20261117_002_core_identity_and_applicant_tables.sql',  // users, persons, applicants
    '20261117_003_payments_and_license_access.sql',
    '20261117_004_wizard_form_fields_and_sms.sql',
    '20261117_005_user_accounts_fees_and_lookup_tables.sql',
    '20261117_006_rate_limit_buckets.sql',
];
```

### ۳.۲. NationalGateway (۳ migration)

```php
// app/Modules/NationalGateway/Module.php::migrations()
return [
    '20261117_001_national_gateway_tables.sql',           // 5 tables
    '20261117_002_golden_link_columns.sql',                // applicants nwa columns
    '20261117_003_sync_audit_log_nullable_applicant.sql', // D-4-3 fix
];
```

### ۳.۳. Queue (۲ migration)

```php
// app/Modules/Queue/Module.php::migrations()
return [
    '20261117_001_events_and_jobs_tables.sql',
    '20261117_002_jobs_priority_and_result_count.sql',
];
```

MigrationRunner در هر boot: اسکن فایل‌ها، بررسی جدول `migrations` با `filename` و SHA-256 `checksum`، اجرای جدید یا تغییر یافته، ثبت. این مکانیزم drift schema را در زمان توسعهٔ موازی محو می‌کند.

---

## ۴. ماتریس مالکیت Schema

طبق `docs/SCHEMA_OWNERSHIP.md §2`، قواعد: (۱) هر جدول یک مالک، (۲) ماژول دیگر فقط از طریق Interface یا ACL می‌نویسد، (۳) هر migration که به جدول خارج از ماژول دست می‌زند در PR توضیح داده می‌شود، (۴) جداول `Modules/Shared` متعلق به همه‌اند، (۵) جداول meta فقط توسط MigrationRunner/Queue/EventBus نوشته می‌شوند. نمونه از `app/Modules/Core/Module.php`:

```php
public function ownedTables(): array
{
    return ['users', 'persons', 'applicants', 'service_types', 'job_details',
            'property_details', 'documents', 'document_types', 'status_log',
            'postal_code_changes', 'applicant_field_values', 'license_access_requests',
            'payments', 'payment_audit_log', 'license_files', 'user_sms_entries',
            'applicant_revisions', 'user_notifications', 'fee_tariffs', 'sessions',
            'settings', 'persian_date_cache', 'email_queue'];
}
```

نمونه ACL برای نوشتن بین‌ماژولی: `persons` متعلق به Core است، اما NationalGateway از طریق `PersonRepositoryInterface` (interface در `Shared/Contracts/`) آن را می‌خواند؛ نوشتن همیشه از طریق Core انجام می‌شود.

---

## ۵. CASCADE و sync_audit_log

طبق `§7.5` و `§24.7`، الگوی `ON DELETE`:

- `CASCADE` روی جداول فرعی وابسته به applicants: `job_details`, `property_details`, `documents`, `status_log`, `payments`, `license_files`, `applicant_field_values`. حذف یک applicant تمام زیرمجموعه‌ها را پاک می‌کند.
- `SET NULL` روی ارجاعات اختیاری.
- `RESTRICT` روی ارجاعات اجباری: `applicants.person_id` ← `persons(id)` با `RESTRICT` تا حذف شخص با پرونده‌های فعال ممکن نباشد.

`sync_audit_log` رکورد تصمیم‌گیری در اتصال طلایی است (`§24.1`، `§9.6`). ساختار کلیدی:

```sql
CREATE TABLE sync_audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT UNSIGNED NULL,               -- nullable (D-4-3)
    national_request_number VARCHAR(32) NULL,
    action VARCHAR(32) NOT NULL,                  -- match_attempted|auto_confirmed|operator_flagged|auto_rejected|migrated
    outcome VARCHAR(32) NOT NULL,                 -- auto_confirmed|operator_flag|auto_rejected|v2_import|gateway_import
    confidence INT DEFAULT 0,
    context JSON,                                 -- fields_matched, total_score, reason, expires_at
    idempotency_key VARCHAR(64) NULL,              -- §24.4
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_applicant (applicant_id),
    INDEX idx_audit_action_time (action, created_at),
    INDEX idx_audit_idempotency (idempotency_key)
);
```

هر تصمیم اتصال طلایی یک رکورد audit می‌سازد: `match_attempted` (همهٔ تلاش‌ها)، `auto_confirmed` (تأیید خودکار با score ≥۹۰)، `operator_flagged` (۵۰-۸۹ نیاز به مرور)، `auto_rejected` (anchor mismatch). Migration marker ها با `action='migrated'` و `outcome='v2_import'` یا `'gateway_import'` ثبت می‌شوند (مرجع `§17`).
