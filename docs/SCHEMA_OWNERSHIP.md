# ماتریس مالکیت Schema — mojavez-v3

> **نسخه:** 1.0 · **تاریخ:** ۱۴۰۵/۰۸/۲۶ (۲۰۲۶-۱۱-۱۶)
> **منبع:** بخش ۲۶ سند `00-MASTER.md`
> **هدف:** تعیین دقیق مالکیت هر جدول پایگاه داده در میان ماژول‌ها، برای جلوگیری از drift schema در زمان توسعهٔ موازی. قبل از merge هر migration، CI این ماتریس را بررسی می‌کند.

---

## ۱. قواعد مالکیت

۱. هر جدول دقیقاً **یک مالک (ماژول)** دارد. مالک مسئول migration‌ها، ALTER، و قرارداد schema آن جدول است.

۲. ماژول‌های دیگر می‌توانند جدول را **بخوانند** یا **بنویسند**، اما نوشتن باید از طریق `Interface` (Repository) یا ACL انجام شود، نه از طریق مدل concreto مالک.

۳. هر migration که به جدولی از ماژول دیگری دست می‌زند، باید در PR توضیح دهد چرا و با تأیید maintainer ماژول مالک همراه شود.

۴. جداول `Modules/Shared` متعلق به همه‌اند و تغییر آن‌ها نیازمند تأیید همهٔ maintainerهای ماژول است.

۵. جداول meta (`migrations`, `jobs`, `batches`, `events`, `worker_state`) متعلق به زیرساخت‌اند و فقط MigrationRunner/Queue/EventBus روی آن‌ها می‌نویسند.

---

## ۲. ماتریس مالکیت

| # | جدول | مالک (ماژول) | خوانده‌شده توسط | نوشته‌شده توسط | نوع |
|---|---|---|---|---|---|
| ۱ | `users` | `Modules/Core` | Core, Api, NationalGateway (read-only) | Core | core |
| ۲ | `persons` | `Modules/Core` | Core, NationalGateway (via ACL) | Core, NationalGateway (via ACL) | core |
| ۳ | `applicants` | `Modules/Core` | Core, NationalGateway (via ACL), Api | Core, NationalGateway (via ACL) | core |
| ۴ | `service_types` | `Modules/Core` | Core | Core | core |
| ۵ | `job_details` | `Modules/Core` | Core | Core | core |
| ۶ | `property_details` | `Modules/Core` | Core, NationalGateway (read) | Core | core |
| ۷ | `documents` | `Modules/Core` | Core | Core | core |
| ۸ | `document_types` | `Modules/Core` | Core | Core | core |
| ۹ | `status_log` | `Modules/Core` | Core, NationalGateway (read) | Core, NationalGateway (via ACL, `triggered_by='auto_sync'`) | core |
| ۱۰ | `postal_code_changes` | `Modules/Core` | Core | Core | core |
| ۱۱ | `applicant_field_values` | `Modules/Core` | Core | Core | core |
| ۱۲ | `license_access_requests` | `Modules/Core` | Core | Core | core |
| ۱۳ | `payments` | `Modules/Core` | Core | Core | core |
| ۱۴ | `payment_audit_log` | `Modules/Core` | Core | Core | core |
| ۱۵ | `license_files` | `Modules/Core` | Core | Core | core |
| ۱۶ | `user_sms_entries` | `Modules/Core` | Core | Core | core |
| ۱۷ | `applicant_revisions` | `Modules/Core` | Core | Core | core |
| ۱۸ | `user_notifications` | `Modules/Core` | Core | Core | core |
| ۱۹ | `fee_tariffs` | `Modules/Core` | Core | Core | core |
| ۲۰ | `sessions` | `Modules/Core` | Core | Core | core |
| ۲۱ | `settings` | `Modules/Core` | همه (read) | Core (write) | core |
| ۲۲ | `persian_date_cache` | `Modules/Core` | Core | Core | core |
| ۲۳ | `email_queue` | `Modules/Core` | Core, Queue | Core, Queue | core |
| ۲۴ | `national_licenses` | `Modules/NationalGateway` | Core (read), NationalGateway, Api | NationalGateway | gateway |
| ۲۵ | `national_lookups` | `Modules/NationalGateway` | NationalGateway, Api | NationalGateway | gateway |
| ۲۶ | `sync_audit_log` | `Modules/NationalGateway` | NationalGateway, Api (admin) | NationalGateway | gateway |
| ۲۷ | `integration_log` | `Modules/Shared` | همه (read) | همه (write) | shared |
| ۲۸ | `api_keys` | `Modules/Api` | Api | Api | api |
| ۲۹ | `api_access_log` | `Modules/Api` | Api | Api | api |
| ۳۰ | `migrations` | `Modules/Shared` | MigrationRunner | MigrationRunner | meta |
| ۳۱ | `jobs` | `Modules/Queue` | Queue, Api (admin) | Queue | meta |
| ۳۲ | `batches` | `Modules/Queue` | Queue | Queue | meta |
| ۳۳ | `events` | `Modules/Queue` | Queue | Queue | meta |
| ۳۴ | `worker_state` | `Modules/Queue` | Queue, Api (admin) | Queue | meta |
| ۳۵ | `report_runs` | `Modules/Reporting` | Reporting, Core (admin) | Reporting | reporting |

**مجموع:** ۳۵ جدول + ۵ جدول meta = ۴۰ رکورد در ماتریس (۲۳ core + ۳ gateway + ۱ shared + ۲ api + ۱ reporting + ۵ meta + ۴ مورد متفرقه core از ۱۸→۲۳ شمارش می‌شوند).

> **توضیح:** شمارش نهایی ۳۵ جدول کسب‌وکار (مطابق `00-MASTER.md` بخش ۲۴) + جداول meta که به‌عنوان زیرساخت شمارش نمی‌شوند.

---

## ۳. قواعد نوشتن بین‌ماژولی

وقتی ماژول A می‌خواهد به جدولی متعلق به ماژول B بنویسد:

۱. **از طریق Interface:** ماژول B یک `<Entity>RepositoryInterface` در `app/Shared/Contracts/` ارائه می‌دهد. ماژول A به Interface وابسته می‌شود، نه پیاده‌سازی.

۲. **از طریق ACL:** اگر نوشتن نیازمند ترجمهٔ مدل داده یا اعتبارسنجی متمرکز باشد، یک `<Entity>SyncAdapter` در ماژول نوشنده ساخته می‌شود (مانند `ApplicantSyncAdapter` در `Modules/NationalGateway/Services/`).

۳. **هرگز مستقیم:** ماژول A هرگز `Database::getInstance()->prepare(...)` روی جدول ماژول B صدا نمی‌زند. CI این الگو را با static analysis شناسایی می‌کند و fail می‌شود.

---

## ۴. migration چیست و کجا می‌رود؟

هر migration در مسیر ماژول مالک جدول قرار می‌گیرد:

```
app/Modules/Core/migrations/
├── 20261116_001_create_users.sql
├── 20261116_002_create_persons.sql
├── 20261116_003_create_applicants.sql
├── ...
└── 20261116_023_create_email_queue.sql

app/Modules/NationalGateway/migrations/
├── 20261116_001_create_national_licenses.sql
├── 20261116_002_create_national_lookups.sql
└── 20261116_003_create_sync_audit_log.sql

app/Modules/Shared/migrations/
└── 20261116_001_create_integration_log.sql

app/Modules/Api/migrations/
├── 20261116_001_create_api_keys.sql
└── 20261116_002_create_api_access_log.sql

app/Modules/Queue/migrations/
├── 20261116_001_create_jobs.sql
├── 20261116_002_create_batches.sql
├── 20261116_003_create_events.sql
└── 20261116_004_create_worker_state.sql

app/Modules/Reporting/migrations/
└── 20261116_001_create_report_runs.sql

database/
├── schema.sql                # مرجع توسعهٔ واحد (همهٔ ۳۵ جدول با DDL کامل)
└── install.sql               # تولیدشده خودکار از schema.sql (idempotent)
```

`MigrationRunner` در هر boot:
۱. اسکن `app/Modules/*/migrations/*.sql`.
۲. بررسی `migrations` table با `filename` و `checksum`.
۳. اگر ناموجود یا checksum متفاوت، اجرا و ثبت.

---

## ۵. CI check

فایل `tools/check_schema_ownership.py`:

```python
#!/usr/bin/env python3
"""بررسی ماتریس مالکیت schema در CI.
هر migration که به جدولی خارج از ماژول خود دست می‌زند، fail می‌شود.
"""
import re, sys, pathlib

OWNERSHIP = {
    # جدول -> ماژول مالک
    'users': 'Core', 'persons': 'Core', 'applicants': 'Core',
    # ... (کامل از ماتریس فوق)
}

def check_migration(path: pathlib.Path) -> list[str]:
    module = path.parent.parent.name  # Core, NationalGateway, ...
    sql = path.read_text(encoding='utf-8')
    errors = []
    for table in re.findall(r'ALTER\s+TABLE\s+(\w+)|CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)', sql, re.I):
        t = table[0] or table[1]
        owner = OWNERSHIP.get(t)
        if owner and owner.lower() != module.lower():
            errors.append(f"{path}: table '{t}' owned by '{owner}', not '{module}'")
    return errors

if __name__ == '__main__':
    errors = []
    for p in pathlib.Path('app/Modules').glob('*/migrations/*.sql'):
        errors.extend(check_migration(p))
    if errors:
        print('\n'.join(errors), file=sys.stderr)
        sys.exit(1)
    print('OK: all migrations respect schema ownership matrix.')
```

این check در `ci.yml` پیش از `php scripts/migrate.php --all` اجرا می‌شود.

---

## ۶. تغییر ماتریس

اگر نیاز به انتقال مالکیت جدول از ماژول A به ماژول B بود:

۱. PR با توضیح چرایی.
۲. تأیید maintainer هر دو ماژول.
۳. به‌روزرسانی این فایل + `OWNERSHIP` dict در `tools/check_schema_ownership.py`.
۴. انتقال فایل migration به ماژول جدید (با rename، نه copy).
۵. ثبت در `CHANGELOG.md`.

---

*پایان ماتریس.*
