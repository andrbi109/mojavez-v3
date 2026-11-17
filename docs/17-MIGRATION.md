# مهاجرت داده در mojavez-v3

> این سند سه اسکریپت مهاجرت داده را بر اساس کد واقعی `scripts/` تشریح می‌کند. مرجع راهبردی `docs/00-MASTER.md §17` است.

---

## ۱. migrate_from_v2.php (۱۷ جدول، INSERT IGNORE، source-prefix)

فایل `scripts/migrate_from_v2.php` دادهٔ کسب‌وکار را از یک دیتابیس MySQL موjavez-v2 به v3 کپی می‌کند. دو schema تقریباً یکسان‌اند (v3 superset است)، بنابراین مهاجرت row-by-row copy با چند default برای ستون‌های جدید است. ۱۷ جدول به ترتیب وابستگی:

۱. `service_types`, ۲. `license_titles`, ۳. `persons`, ۴. `applicants` (قلب سامانه — ستون‌های golden-link در v3 NULL باقی می‌مانند)، ۵. `job_details` (۱:۱ با applicants)، ۶. `property_details` (۱:۱ با applicants — این است که PatternMatcher می‌خواند)، ۷. `documents`, ۸. `status_log` (با default `triggered_by='admin'` چون v2 این ستون را ندارد)، ۹. `payments`, ۱۰. `license_files`, ۱۱. `user_accounts`, ۱۲. `sms_templates`, ۱۳. `user_sms_entries`, ۱۴. `user_notifications`, ۱۵. `form_fields`, ۱۶. `applicant_field_values` (منبع father_name/birth_date)، ۱۷. `settings`.

هر جدول با `INSERT IGNORE` نوشته می‌شود تا re-run امن باشد:

```php
// scripts/migrate_from_v2.php
$insertSql = "INSERT IGNORE INTO `$table` ($colList) VALUES ($placeholders)";
$insertStmt = $destPdo->prepare($insertSql);
foreach ($rows as $row) {
    $insertStmt->execute($params);
    if ($insertStmt->rowCount() > 0) { $inserted++; } else { $skipped++; }
}
```

آپشن `--source-prefix=PFX` برای زمانی است که schema منبع در همان دیتابیس v3 بارگذاری شده (مثلاً `v2_src_applicants`) برای host‌هایی که نمی‌توانند `CREATE DATABASE` بزنند. regex callback در FROM clause پیشوند را تزریق می‌کند. آپشن‌های دیگر: `--dry-run`, `--table=NAME`, `--limit=N`. در پایان یک migration marker در `sync_audit_log` ثبت می‌شود:

```php
$destConn->execute(
    'INSERT INTO sync_audit_log (applicant_id, national_request_number, action, outcome, confidence, context) '
    . 'VALUES (0, "", "migrated", "v2_import", 0, ?)',
    [json_encode(['source' => 'v2', 'rows_migrated' => $totalRows, 'migrated_at' => date('Y-m-d H:i:s')])]
);
```

---

## ۲. migrate_from_gateway.php (۸ جدول، raw_json parsing)

فایل `scripts/migrate_from_gateway.php` دادهٔ cache‌شدهٔ درگاه ملی را از یک SQLite `gateway/storage/app.db` به v3 MySQL کپی می‌کند. ۸ جدول به ترتیب وابستگی:

۱. `lookups` → `national_lookups` (kind mapping: `province` ثابت، `main_org` → `main_organization`, `sub_org` → `sub_organization`؛ gateway `township` ندارد).
۲. `licenses` → `national_licenses` — مهم‌ترین جدول. gateway در ستون `raw_json` پاسخ کامل upstream را ذخیره کرده بود؛ این اسکریپت آن را parse می‌کند تا فیلدهای anchor golden-link را که gateway در ستون‌های جداگانه denormalize نکرده بود استخراج کند:

```php
$raw = json_decode((string)($row['raw_json'] ?? '{}'), true) ?: [];
$stmt->execute([
    $row['request_number'],
    $row['applicant_name'],
    // ...
    $raw['national_id']         ?? $raw['masked_national_code'] ?? null,
    $raw['father_name']         ?? null,
    $raw['business_address']    ?? null,
    $raw['postal_code']         ?? $raw['postal_code_masked'] ?? null,
    // ...
]);
```

۳. `filters` → `national_filters` (`filter_json` TEXT → `filter_data` JSON).
۴. `batches` → `national_batches` (مستقیم).
۵. `jobs` → `jobs` (status mapping: `queued` → `pending`, `retrying` → `pending` re-queue).
۶. `events` → `events` (مستقیم).
۷. `settings` → `settings` (merge — فقط کلیدهایی که در v3 نیستند، `INSERT IGNORE`).
۸. `admin_users` → `users` (فقط اگر username تکرار نباشد، نقش `admin`).

migration marker با `outcome='gateway_import'` در `sync_audit_log` ثبت می‌شود. آپشن‌ها: `--source-sqlite`, `--dry-run`, `--table`, `--limit`.

---

## ۳. seed_lookups.php (upstream + --from-gateway fallback)

فایل `scripts/seed_lookups.php` جدول `national_lookups` را از منبع authorit پر می‌کند. دلیل: (الف) مهاجرت gateway فقط province + main_org + sub_org را دارد (township نه)، (ب) مهاجرت v2 هیچ national-gateway lookup ندارد، (پ) upstream منبع authorit است.

سه حالت خروج:

- `php scripts/seed_lookups.php` (حالت عادی) — فراخوانی `LicenseService::refreshLookups()` که از upstream fetch می‌کند. خروج ۰.
- `--dry-run` — فقط گزارش وضعیت cache فعلی.
- `--from-gateway` — وقتی sandbox خارج از geofence ایران است و upstream unreachable است، township را از `sub_organization` data می‌سازد (هر sub_org با `parent_code` main_org به‌عنوان township در نظر گرفته می‌شود). خروج ۲ (degraded mode).

```php
// scripts/seed_lookups.php --from-gateway
$subOrgs = $conn->all("SELECT code, title, parent_code FROM national_lookups WHERE kind='sub_organization'");
foreach ($subOrgs as $so) {
    $conn->execute(
        'INSERT IGNORE INTO national_lookups (kind, code, title, parent_code, is_active, refreshed_at) '
        . 'VALUES (?, ?, ?, ?, 1, NOW())',
        ['township', $so['code'], $so['title'], $so['parent_code']]
    );
}
exit(2); // degraded mode
```

---

## ۴. migration marker در sync_audit_log

هر سه اسکریپت در پایان یک رکورد در `sync_audit_log` ثبت می‌کنند تا دادهٔ pre-migration از post-migration متمایز شود:

| اسکریپت | action | outcome | context |
|---|---|---|---|
| `migrate_from_v2.php` | `migrated` | `v2_import` | source, source_dsn (mask), rows_migrated, migrated_at |
| `migrate_from_gateway.php` | `migrated` | `gateway_import` | source, source_sqlite, rows_migrated, migrated_at |
| `seed_lookups.php` | (به‌روزرسانی جدول lookup، marker مستقل نمی‌سازد) | — | — |

این رکوردها با `applicant_id=NULL, national_request_number=NULL` قابل تشخیص از تصمیم‌های golden-link واقعی هستند (که همیشه applicant_id دارند).

---

## ۵. استراتژی مهاجرت توصیه‌شده (§17.3)

استراتژی پنج‌مرحله‌ای برای اجرای production:

۱. **پشتیبان‌گیری کامل** (§17.4): `mysqldump` از هر دو دیتابیس منبع و dump فایل-به‌فایل از gateway SQLite. تست restore روی یک محیط staging.
۲. **اجرا روی staging**: اجرای `migrate_from_v2.php --dry-run` و `migrate_from_gateway.php --dry-run` برای شمارش رکوردها، سپس اجرای واقعی روی staging.
۳. **اعمال schema v3**: `php scripts/migrate.php --all` (MigrationRunner همهٔ migrationهای Core + NationalGateway + Queue را اعمال می‌کند).
۴. **اجرای مهاجرت واقعی**: اول `migrate_from_v2.php` (ساخت applicants و persons)، سپس `migrate_from_gateway.php` (ساخت national_licenses با anchor fields)، سپس `seed_lookups.php` (پر کردن lookups).
۵. **تأیید و فعال‌سازی**: بررسی migration marker در `sync_audit_log`، اجرای `tests/smoke-test.sh --no-http`، تست دستی پنل مدیریت، فعال‌سازی poor man's cron یا systemd timer.

پشتیبان‌گیری (`§17.4`): قبل از هر مهاجرت، `mysqldump --single-transaction --routines --triggers mojavez_v3 > backup_v3_$(date +%F).sql` و ذخیرهٔ offline. برای gateway SQLite، کپی فایل `app.db`. این به مدیر اجازه می‌دهد در صورت بروز مشکل به حالت قبل برگردد.
