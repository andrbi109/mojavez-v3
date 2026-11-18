# Phase 14 — seed defaults + low-debt cleanup (v1.5.0)

> **تاریخ:** 2026-11-17
> **شاخه:** `phase/14-low-debt-cleanup-v1.5.0` (merge به `main` با `--no-ff`)
> **نسخهٔ منتشرشده:** v1.5.0
> **هدف:** پرداخت D-2-9 (seed migration) + ۶ debt low دیگر از فازهای
> قبلی (D-1-9، D-4-6، D-4-7، D-5-2، D-7-4، D-7-3).

## ۱) زمینه

فاز ۱۳ (`user_accounts.email/display_name` + ۷ low-debt items) پروژه
را به ۰ critical، ۰ high، ۰ medium، ۲۱ low رساند. فاز ۱۴ به‌صورت
targeted-cleanup روی ۷ مورد از آن‌ها تمرکز کرد که همگی cheap-to-fix
بودند و در همان release پرداخت شدند.

## ۲) چه ساخته شد

### ۲-۱) Seed defaults migration (D-2-9)

| File | Change |
|---|---|
| `app/Modules/Core/migrations/20261117_009_seed_default_service_types.sql` | new — 5 `service_types` + 8 `license_titles` با `INSERT ... ON DUPLICATE KEY UPDATE` |
| `app/Modules/Core/Module.php` | `migrations()` array +1 entry (009) |
| `database/install.sql` | +2 hand-added INSERTs (service_types + license_titles) — generator فقط DDL می‌نویسد |

Migration 009 در `app/Modules/Core/migrations/` قرار دارد چون هر دو
جدول (`service_types` و `license_titles`) متعلق به Core هستند
(per `docs/SCHEMA_OWNERSHIP.md` §2). `check_schema_ownership.py` فقط
CREATE/ALTER/DROP را بررسی می‌کند، نه INSERT را — پس INSERT-only
migration هیچ ownership violation ایجاد نمی‌کند.

### ۲-۲) Writable storage check (D-1-9)

| File | Change |
|---|---|
| `deploy/shared-hosting/check.php` | writable-storage check بازنویسی شد: probe-write در ۵ زیرشاختهٔ `storage/` + legacy `uploads/` با PASS/FAIL + پیام خطای PHP |

قبلاً فقط `is_writable()` در ۴ دایرکتوری چک می‌شد و
`storage/sessions/` و `storage/exports/` پوشش داده نمی‌شد. حالا یک
فایل probe 1-byte در هر دایرکتوری نوشته می‌شود و در صورت شکست،
پیام خطای PHP (مثل "open_basedir restriction" یا "Disk quota
exceeded") در اختیار ادمین قرار می‌گیرد.

### ۲-۳) License::fromArray anchors (D-4-6)

| File | Change |
|---|---|
| `app/Modules/NationalGateway/Domain/License.php` | `fromArray` حالا `masked_national_code` + `father_name` را با default null-safe می‌خواند |
| `app/Modules/NationalGateway/Services/LicenseService.php` | `hydrateLicense` docblock به‌روز شد |

قبلاً `fromArray` فقط فیلدهای basic را پر می‌کرد و anchorهای
golden-link را drop می‌کرد. در نتیجه `searchLocal()` (که از
`hydrateLicense` استفاده می‌کند) همیشه Licenseهایی با anchorهای
`null` برمی‌گرداند، حتی اگر ردیف DB آنها را داشت. فقط
`findByRequestNumber` (که از `hydrate` خصوصی repository استفاده
می‌کند) anchorها را نگه می‌داشت.

با این fix، `fromArray` دو anchor را با هم‌خوانی null-safe
می‌خواند. cache-restoration path (که `toArray()` اول را صدا می‌زند؛
آن snapshot شامل anchorها نیست) همچنان `null` می‌گیرد — همان
رفتار قبلی. DB-row path (که `SELECT *` است) anchorها را به‌درستی
نگه می‌دارد.

### ۲-۴) OperatorFlag admin notification (D-4-7)

| File | Change |
|---|---|
| `app/Events/Events/OperatorFlag.php` | new — event class `OperatorFlag` (TYPE='operator_flag') |
| `app/Modules/NationalGateway/Services/ApplicantSyncAdapter.php` | `actOperatorFlagged` حالا `OperatorFlag` event را dispatch می‌کند |
| `app/Events/Subscribers/NotificationSubscriber.php` | به `ApplicantMatched` + `OperatorFlag` subscribe می‌کند؛ `handleOperatorFlag` جدید |
| `app/Modules/Core/Services/NotificationService.php` | +1 method: `notifyAdmins(string $title, string $body)` |

event flow دقیقاً متقارن با `ApplicantMatched` است: adapter →
subscriber → service. تفاوت: notification in-app-only است (بدون SMS،
بدون email) چون match هنوز تأیید نشده — ادمین‌ها از طریق پنل ادمین
بازبینی می‌کنند، نه از کانال‌های شخصی.

Schema bridge: `user_notifications.user_account_id` NOT NULL با FK
به `user_accounts`، ولی ادمین‌ها در جدول مجزای `users` هستند. bridge
با username-lookup انجام می‌شود — ادمین‌هایی که user_account هم
دارند notification را در پنل کاربری می‌بینند؛ ادمین‌های بدون
user_account skip می‌شوند (ردیف `sync_audit_log` همچنان برای پنل
ادمین موجود است). این gap به‌عنوان D-14-1 (low) ثبت شد.

### ۲-۵) migrate_from_v2.php side tables (D-5-2)

| File | Change |
|---|---|
| `scripts/migrate_from_v2.php` | +5 side table در migration plan |

5 side table اضافه شد:
- `applicant_revisions` (FK به applicants)
- `wizard_steps` (FK به service_types, nullable)
- `postal_code_changes` (FK به applicants)
- `fee_groups` (no FK)
- `fee_group_items` (FK به fee_groups)

هر کدام با `'columns' => '*'` و `INSERT IGNORE` (idempotent). v2 → v3
column mismatches به‌صورت row-by-row skip و log می‌شوند.

`identity_checks` و `payment_audit_log` عمداً migrate نمی‌شوند
(audit trails — schemaهای متفاوت، migrate کردن پرخطر است).

### ۲-۶) SettingsController per-key validation (D-7-4)

| File | Change |
|---|---|
| `app/Modules/Core/Controllers/SettingsController.php` | +1 static method `validateCustomKey`; `update()` errors را collect و به view پاس می‌دهد |
| `app/Views/admin/settings/index.php` | +warning banner (`alert-warning`) برای کلیدهای نامعتبر |

قوانین اعتبارسنجی:
- allowed chars: `[a-zA-Z0-9_.-]+` (بدون space، بدون فارسی)
- max length: 64 (هم‌خوان با `settings.setting_key VARCHAR(64)`)
- reserved prefixes: `_` یا `.` رد می‌شوند (رزرو برای کلیدهای
  internal/system مثل `_migration_version` یا `.next_id`)

قبلاً کلیدهای نامعتبر silently drop می‌شدند — ادمین هیچ بازخوردی
نداشت. حالا در یک warning banner در بالای صفحه نمایش داده می‌شوند.

### ۲-۷) Registration views to Bootstrap (D-7-3)

| File | Change |
|---|---|
| `app/Views/registration/_progress.php` | new — partial: 4-step progress indicator |
| `app/Views/registration/_dynamic_fields.php` | new — partial: dynamic form fields renderer |
| `app/Views/registration/select_service.php` | new — wizard step 0 (choose service_type) |
| `app/Views/registration/step1.php` | new — wizard step 1 (applicant identity) |
| `app/Views/registration/step2.php` | new — wizard step 2 (business address) |
| `app/Views/registration/step3.php` | new — wizard step 3 (job detail + dynamic fields) |
| `app/Views/registration/step4.php` | new — wizard step 4 (documents + review + submit) |
| `app/Views/registration/step_custom.php` | new — wizard custom step (renders dynamic fields) |
| `app/Views/registration/success.php` | new — wizard success page (tracking code) |

تمام ویوها از Bootstrap 5 RTL + Vazirmatn + Bootstrap Icons
(self-hosted تحت `public/assets/vendor/`) استفاده می‌کنند. Persian UI
در تمام ویوها. `RegistrationController` که این ویوها را به مسیرها
(`POST /register/*`) وصل می‌کند به فاز آینده موکول شده per
`docs/00-MASTER.md` TODO list — ویوها آماده هستند.

## ۳) Tests

| File | Change |
|---|---|
| `tests/Unit/LicenseAnchorsTest.php` | new — `fromArray` با anchors را assert می‌کند |
| `tests/Unit/OperatorFlagNotificationTest.php` | new — `notifyAdmins` یک ردیف `user_notifications` می‌سازد |
| `tests/Unit/SettingsControllerValidationTest.php` | new — قوانین per-key validation را assert می‌کند |
| `tests/Unit/SeedDefaultsTest.php` | new — migration 009 را در DB تست می‌کند و ردیف‌ها را assert می‌کند |

تمام testهای جدید DB-backed هستند (الگوی `SmsServiceTest` /
`NotificationServiceTest`) با `markTestSkipped` اگر DB/Container در
دسترس نباشد.

## ۴) Lint / static-analysis / schema-ownership results

- `tools/php_lint_simple.py`: PASS (هیچ issue جدیدی وارد نشد).
- `tools/static_analysis.py --strict`: PASS (38 warning پیش‌فرض، 38
  suppressed با ignore patterns، 0 surviving). ویوهای registration
  جدید review شدند و هیچ warning جدیدی اضافه نکردند.
- `tools/check_schema_ownership.py`: PASS — migration 009 INSERT-only
  است و ownership-check فقط CREATE/ALTER/DROP را بررسی می‌کند.

## ۵) Debt paid / introduced / carried forward

### Paid (7 items)

- D-2-9 — seed migration for 5 service_types + 8 license_titles.
- D-1-9 — writable storage check (probe-write in 5 storage subdirs).
- D-4-6 — `License::fromArray` populates golden-link anchors.
- D-4-7 — `OperatorFlag` event + admin in-app notification.
- D-5-2 — `migrate_from_v2.php` migrates 5 side tables.
- D-7-4 — `SettingsController` per-key custom-key validation.
- D-7-3 — registration views upgraded to Bootstrap 5 RTL (9 files).

### Introduced (1 item)

- D-14-1 — `NotificationService::notifyAdmins` bridges admins to
  `user_accounts` by username lookup. Admins without a user_account
  are skipped (no in-app notification — `sync_audit_log` is the only
  signal). Future: `admin_notifications` table or nullable
  `user_notifications.user_account_id` + `user_id` column. Severity:
  low.

### Carried forward

- D-2-3 (low, partial): ~12 deferred admin controllers. Incremental.
- D-8-5 (low): pre-existing smoke-test variance (8 known-failing HTTP
  smoke tests).
- D-14-1 (low, new): notifyAdmins username-bridge gap.
- 12 pre-existing low items from prior phases (D-4-5, D-5-1, D-5-4,
  D-5-5, D-6-2, D-6-3, D-6-4, D-6-5, D-6-6, D-8-1, D-8-2, D-8-3).

**After Phase 14:** 0 critical, 0 high, **0 medium**, ~13 low. The
project stays at the "no medium-or-higher debt" state.
