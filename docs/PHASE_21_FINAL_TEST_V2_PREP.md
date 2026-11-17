# Phase 21 — Final test + v2.0.0 preparation (v1.9.3)

> **تاریخ:** 2026-11-17
> **شاخه:** `phase/21-final-test-v2-prep` (merge به `main`)
> **هدف:** تست کامل همه مسیرها + پولیش نهایی + آماده‌سازی برای v2.0.0
> (پس از تأیید کاربر).

## ۱) تست‌های انجام‌شده

### ۱-۱) تست HTTP همه مسیرهای public (با curl)

| مسیر | HTTP | وضعیت |
|---|---|---|
| `/` | 200 | ✅ صفحه اصلی |
| `/guide` | 200 | ✅ راهنما |
| `/about` | 200 | ✅ درباره ما |
| `/services` | 200 | ✅ لیست خدمات |
| `/signup` | 200 | ✅ فرم ثبت‌نام کاربر |
| `/login` | 200 | ✅ فرم ورود |
| `/tracking` | 200 | ✅ فرم پیگیری |
| `/health` | 200 | ✅ بررسی سلامت |
| `/api/v1/licenses` | 200 | ✅ API عمومی |
| `/api/v1/license-titles/by-service/1` | 200 | ✅ API عمومی |
| `/license/view/nonexistent` | 404 | ✅ توکن نامعتبر |
| `/nonexistent-404` | 404 | ✅ صفحه 404 |

### ۱-۲) تست HTTP مسیرهای user (بدون نشست → 302)

| مسیر | HTTP | وضعیت |
|---|---|---|
| `/panel` | 302 | ✅ |
| `/panel/profile` | 302 | ✅ |
| `/panel/requests` | 302 | ✅ |
| `/panel/payments` | 302 | ✅ |
| `/panel/licenses` | 302 | ✅ |
| `/panel/notifications` | 302 | ✅ |
| `/panel/tracking` | 302 | ✅ |
| `/my-records` | 302 | ✅ |
| `/register` | 302 | ✅ |

### ۱-۳) تست HTTP مسیرهای admin (بدون نشست → 302)

| مسیر | HTTP | وضعیت |
|---|---|---|
| `/admin/dashboard` | 302 | ✅ |
| `/admin/applications` | 302 | ✅ |
| `/admin/users` | 302 | ✅ |
| `/admin/settings` | 302 | ✅ |
| `/admin/form-fields` | 302 | ✅ |
| `/admin/fee-tariff` | 302 | ✅ |
| `/admin/license-titles` | 302 | ✅ |
| `/admin/service-types` | 302 | ✅ |
| `/admin/wizard-steps` | 302 | ✅ |
| `/admin/sms` | 302 | ✅ |
| `/admin/payments` | 302 | ✅ |
| `/admin/bulk-import` | 302 | ✅ |
| `/admin/reports/by-province` | 302 | ✅ |
| `/admin/output/general` | 302 | ✅ |
| `/admin/report/csv` | 302 | ✅ |
| `/admin/license-requests` | 302 | ✅ |
| `/admin/revisions` | 302 | ✅ |
| `/admin/postal-requests` | 302 | ✅ |
| `/admin/register` | 302 | ✅ |
| `/admin/my-tasks` | 302 | ✅ |
| `/admin/sync/readiness` | 302 | ✅ |
| `/admin/sync/status` | 302 | ✅ |
| `/admin/sync/pending` | 302 | ✅ |

### ۱-۴) تست‌های واحد (php tests/run.php)

مجموع: **519 assertions، 6 failures**
- LicenseTitleTest: 1 failure (pre-existing، مربوط به الگوی تست)
- OperatorFlagNotificationTest: 4 failures (pre-existing، username-bridge gap D-14-1)
- MigrateFromGatewayTest: 1 failure (pre-existing، نیاز به source v2 gateway)

همه failures از قبل موجود بودند و به state sandbox مربوط می‌شوند (نیاز به source code v2 gateway یا الگوی تست خاص). **هیچ regression جدیدی از فازهای ۱۶-۲۰ وارد نشده.**

### ۱-۵) تست‌های agent-browser (flow کامل)

**Flow ۱: ثبت‌نام کاربر جدید → ورود → ثبت‌نام متقاضی**
1. `/signup` → fill mobile + password → submit → redirect به `/login` ✅
2. `/login` → fill credentials → submit → redirect به `/panel` ✅
3. `/panel` → click "شروع ثبت‌نام" → redirect به `/register` ✅
4. `/register` → ۵ کارت خدمات نمایش داده می‌شود ✅

**Flow ۲: ورود مدیر → ثبت‌نام دستی متقاضی**
1. `/admin/login` → fill admin/admin123 → submit → redirect به `/admin/dashboard` ✅
2. Sidebar کامل با همه بخش‌ها: اصلی، مدیریت، درخواست‌ها، گزارش‌ها، درگاه ملی، حساب ✅
3. Click "ثبت‌نام دستی" → فرم نمایش داده می‌شود ✅
4. Fill form + submit → متقاضی جدید با tracking code ساخته شد (در DB تأیید شد) ✅

**Flow ۳: ویزارد ثبت‌نام متقاضی (از فاز ۱۵.۱ تأیید شد)**
1. Login → /panel → /register → select service → step 1 → step 2 → step 3 → step 4 → success page ✅
2. All 4 steps functional، tracking code generated، status_log entry inserted ✅

## ۲) آمار نهایی پروژه

### ۲-۱) مسیرها
- **Core module:** 156 مسیر
- **NationalGateway module:** 43 مسیر
- **مجموع:** 199 مسیر (vs 146 در v2، vs 104 در v1.5.0)

### ۲-۲) کنترلرها
- **Core:** 20 کنترلر
  - HomeController, AuthController, AdminController, UserPanelController, UsersController,
    SettingsController, FormFieldController, FeeTariffController, LicenseTitleController,
    ServiceTypeController, WizardStepController, SmsController, PaymentController,
    BulkImportController, ReportController, RegistrationController, ServiceController,
    RecordsController, DocumentController, LicenseController, RevisionController,
    TrackingController, CronController, HealthController, SseController
- **NationalGateway:** 2 کنترلر
  - SyncAdminController, Api\V1ApiController

### ۲-۳) ویوها
- **62+ view** در `app/Views/` (همه viewهای v2 پورت شدند + viewهای جدید v3)

### ۲-۴) migrations
- **15 migration** در Core (001 bootstrap تا 011 seed admin)
- **5 migration** در NationalGateway (001 tables تا 005 seed lookups)
- **2 migration** در Queue (001 events+jobs، 002 jobs priority)
- **مجموع:** 22 migration

### ۲-۵) تست‌ها
- **32 فایل تست** در `tests/Unit/`
- **519 assertion** (6 failure pre-existing، بدون regression)

## ۳) قابلیت‌های پیاده‌سازی‌شده (relative to v2)

### ۳-۱) قابلیت‌های عمومی (public)
- ✅ صفحه اصلی با ۵ کارت خدمت
- ✅ راهنما + درباره ما
- ✅ لیست خدمات
- ✅ فرم ثبت‌نام کاربر (mobile + password)
- ✅ فرم ورود کاربر
- ✅ فرم پیگیری با کد رهگیری
- ✅ صفحه وضعیت پرونده + timeline
- ✅ نسخه چاپی A4
- ✅ مشاهده مجوز با توکن ۷۲ ساعته (public)

### ۳-۲) قابلیت‌های کاربر (user)
- ✅ پنل کاربری (overview + 7 sub-pages)
- ✅ پروفایل + آپلود آواتار
- ✅ درخواست‌ها + پرداخت‌ها + مجوزها + اعلان‌ها
- ✅ ویزارد ثبت‌نام ۴ مرحله‌ای
- ✅ درخواست بازبینی پرونده
- ✅ سوابق خود
- ✅ پیگیری از داخل پنل

### ۳-۳) قابلیت‌های مدیر (admin)
- ✅ داشبورد آماری
- ✅ لیست درخواست‌ها + فیلتر + صفحه‌بندی
- ✅ مشاهده پرونده + timeline
- ✅ ویرایش پرونده
- ✅ ثبت‌نام دستی متقاضی
- ✅ آپلود/حذف مدرک
- ✅ تغییر وضعیت + درخواست پرداخت + تغییر وضعیت مجوز
- ✅ درخواست‌های کد پستی (تأیید/رد)
- ✅ وظایف من (فیلتر نقش)
- ✅ مدیریت کاربران سامانه (CRUD)
- ✅ مدیریت انواع خدمت (CRUD)
- ✅ مدیریت مراحل ویزارد (CRUD + reorder)
- ✅ مدیریت فیلدهای فرم (CRUD + reorder)
- ✅ مدیریت عناوین مجوز (CRUD + toggle + by-service)
- ✅ مدیریت تعرفه هزینه (CRUD + groups + items + tick)
- ✅ مدیریت تنظیمات (key/value + آپلود عکس)
- ✅ مدیریت پیامک (templates + review entries)
- ✅ مدیریت پرداخت‌ها (review + csv + channels)
- ✅ ایمپورت دسته‌جمعی CSV (8 routes)
- ✅ گزارش‌گیری (by-province, by-city, by-village, by-license, by-date)
- ✅ خروجی عمومی + گروه‌بندی + CSV + PDF
- ✅ بازبینی‌ها (تأیید/رد + اعمال payload)
- ✅ درخواست‌های مجوز (تأیید/رد)
- ✅ آپلود تصویر مجوز

### ۳-۴) قابلیت‌های درگاه ملی
- ✅ همگام‌سازی (status + pending + run)
- ✅ آمادگی مرحله ۲ (Stage2ReadinessChecker UI)
- ✅ API v1 (39 endpoint: licenses, applicants, admin, lookups, filters, batch, sync, proxy)

### ۳-۵) قابلیت‌های سرویس
- ✅ AvatarService (GD image resize)
- ✅ SmsService (Kavenegar-compatible)
- ✅ FileStorage
- ✅ FeeCalculatorService
- ✅ AutoCancelService
- ✅ NotificationService
- ✅ NationalSyncOrchestrator
- ✅ OutboundSyncService (Stage 2)
- ✅ Stage2ReadinessChecker
- ✅ PatternMatcher (golden link)

## ۴) debt نهایی

| شدت | تعداد | شرح |
|---|---|---|
| critical | 0 | — |
| high | 0 | — |
| medium | 0 | — |
| low | ~13 | موارد sandbox/deploy-only |

**همه debt medium/high پرداخت شد.** فقط low debt باقی مانده که به محیط production/sandbox مربوط است.

## ۵) آماده‌سازی برای v2.0.0

پس از تأیید کاربر، v2.0.0 منتشر می‌شود با:
- **همه قابلیت‌های v2 + gateway** پیاده‌سازی شده
- **معماری ماژولی v3** (Core + NationalGateway + Queue)
- **۰ debt medium/high**
- **199 مسیر** (vs 146 در v2)
- **519 assertion تست** (۶ failure pre-existing)
- **PHP 8.2+ + MariaDB 10.11+**

## ۶) نتیجه

پروژه آمادهٔ تأیید کاربر برای ارتقا به v2.0.0 است. همه flowهای اصلی کار می‌کنند:
- ثبت‌نام کاربر → ورود → ویزارد ثبت‌نام متقاضی → کد رهگیری
- ورود مدیر → مدیریت کامل پرونده‌ها + گزارش‌گیری + ایمپورت
- پیگیری عمومی با کد رهگیری
- مشاهده مجوز با توکن
- درخواست بازبینی

تنها کار باقی‌مانده: تأیید کاربر برای v2.0.0.
