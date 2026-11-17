# Core Module

> **Owner:** `app/Modules/Core/` · **Version:** 1.0.0

## مسئولیت

Core وارث Mojavez-v2 است و قلب سامانهٔ محلی mojavez-v3 را تشکیل می‌دهد: متقاضی، شخص،
پرداخت، مدارک، نرخ‌نامه، ویزارد ثبت‌نام، پنل مدیریت و پنل کاربر. در فاز ۱ فقط متادادهٔ
اولیه و مسیرهای فرا-سطح (`/health`, `/check-cancel`, `/check-sync`, `/api/v1/stream`)
را شامل می‌شود؛ کنترلرها، سرویس‌ها و دامنهٔ کامل در فاز ۲ اضافه می‌شوند.

## مالکیت جدول‌ها (per `docs/SCHEMA_OWNERSHIP.md`)

Core مالک 23 جدول است:

`users`, `persons`, `applicants`, `service_types`, `job_details`,
`property_details`, `documents`, `document_types`, `status_log`,
`postal_code_changes`, `applicant_field_values`, `license_access_requests`,
`payments`, `payment_audit_log`, `license_files`, `user_sms_entries`,
`applicant_revisions`, `user_notifications`, `fee_tariffs`, `sessions`,
`settings`, `persian_date_cache`, `email_queue`.

## ساختار

```
app/Modules/Core/
├── Controllers/             # Health, Cron, SSE (Phase 1) + full set (Phase 2)
├── Services/                # (Phase 2) License, AutoCancel, FeeCalculator, FileStorage, ...
├── Domain/                  # (Phase 2) Applicant, Person, License, Enums
├── Persistence/             # (Phase 2) Repository interfaces + PDO implementations
├── migrations/              # 20261117_001_bootstrap_marker.sql + Phase 2 migrations
└── Module.php               # implements ModuleInterface
```

## API عمومی (پارادوکس)

Core به‌صورت ضمنی از طریق `Container` و سرویس‌های به اشتراک‌گذاشته‌شده (Environment،
Config، StructuredLogger، Connection، MigrationRunner) با سایر ماژول‌ها ارتباط دارد.
سایر ماژول‌ها جداول Core را فقط از طریق `RepositoryInterface` در
`app/Shared/Contracts/` می‌خوانند (یا از طریق ACL برای نوشتن — فاز ۴).

## وابستگی‌ها

- `app/Shared/Support/*` برای تبدیل تاریخ، نرمال‌سازی، اعتبارسنجی.
- `app/Shared/Persistence/Connection` برای اتصال MySQL.
- بدون وابستگی به ماژول‌های دیگر (اولین ماژول در boot order).

## migration‌ها

- `20261117_001_bootstrap_marker.sql` — لاگ اولین راه‌اندازی Core در جدول `migrations`.

## تست

- فاز ۱: تست‌های واحد برای StructuredLogger، Container، Router، Normalizer در
  `tests/Unit/`.
- فاز ۲ به بعد: تست‌های کامل دامنه، گردش‌کار ۱۸ وضعیتی، امنیت.
