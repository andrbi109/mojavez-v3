# Phase 11 — admin CRUD + Stage2ReadinessChecker UI

> **تاریخ:** 2026-11-17
> **شاخه:** `phase/11-admin-crud-v1.2.0` (merge به `main` با `--no-ff`)
> **نسخهٔ منتشرشده:** v1.2.0
> **هدف:** تعویض سه stub admin فاز ۱۰ (form-fields/fee-tariff/license-titles)
> با کنترلرهای CRUD کامل + افزودن admin UI برای `Stage2ReadinessChecker`.

## ۱) زمینه

پس از فاز ۱۰ که سه مسیر سایدبار ادمین را به‌صورت stub «coming soon» متصل
کرد (برای برطرف‌سازی ۴۰۴ سایدبار)، فاز ۱۱ تمرکز روی **تعویض این stub با
کنترللرهای CRUD کامل** بود. به‌علاوه، debt item D-8-4 (admin UI برای
`Stage2ReadinessChecker` که از فاز ۸ موکول شده بود) پرداخت شد.

این فاز ۴ debt item medium را کاملاً پرداخت می‌کند (D-7-1, D-10-1,
D-2-2, D-8-4) و یک debt item medium را partial می‌کند (D-2-3: ۳ از ~۱۵
کنترلر ادمین موکول‌شده ساخته شد).

## ۲) چه ساخته شد

### ۲-۱) `FormFieldController` — `/admin/form-fields/*` (۷ route)

| Method | Path | Action | هدف |
|---|---|---|---|
| GET  | `/admin/form-fields` | `index` | لیست همهٔ فیلدها با JOIN به service_types + wizard_steps |
| GET  | `/admin/form-fields/new` | `create` | فرم ایجاد (همان view edit) |
| POST | `/admin/form-fields` | `store` | اعتبارسنجی + درج |
| GET  | `/admin/form-fields/{id}/edit` | `edit` | فرم ویرایش |
| POST | `/admin/form-fields/{id}` | `update` | اعتبارسنجی + به‌روزرسانی |
| POST | `/admin/form-fields/{id}/delete` | `destroy` | حذف |
| POST | `/admin/form-fields/move` | `move` | جابجایی up/down با swap sort_order |

اعتبارسنجی: `field_key` یکتا + pattern `[a-zA-Z][a-zA-Z0-9_]{0,63}`،
`field_label` الزامی + ≤۱۵۰ نویسه، `field_type` در whitelist ۹تایی،
`field_options` و `validation_rules` در صورت non-empty باید JSON معتبر باشند.

### ۲-۲) `FeeTariffController` — `/admin/fee-tariff/*` (۶ route)

| Method | Path | Action | هدف |
|---|---|---|---|
| GET  | `/admin/fee-tariff` | `index` | لیست با `number_format()` + " تومان" |
| GET  | `/admin/fee-tariff/new` | `create` | فرم ایجاد |
| POST | `/admin/fee-tariff` | `store` | اعتبارسنجی + درج |
| GET  | `/admin/fee-tariff/{id}/edit` | `edit` | فرم ویرایش |
| POST | `/admin/fee-tariff/{id}` | `update` | اعتبارسنجی + به‌روزرسانی |
| POST | `/admin/fee-tariff/{id}/delete` | `destroy` | حذف |

اعتبارسنجی: `decile_min`/`decile_max` در [۱,۱۰]، `min ≤ max`،
`base_amount ≥ 0`، `valid_from ≤ valid_until`، `notes` ≤ ۵۰۰ نویسه.

### ۲-۳) `LicenseTitleController` — `/admin/license-titles/*` (۸ route)

| Method | Path | Action | هدف |
|---|---|---|---|
| GET  | `/admin/license-titles` | `index` | لیست با JOIN به service_types |
| GET  | `/admin/license-titles/new` | `create` | فرم ایجاد |
| POST | `/admin/license-titles` | `store` | اعتبارسنجی + درج |
| GET  | `/admin/license-titles/{id}/edit` | `edit` | فرم ویرایش |
| POST | `/admin/license-titles/{id}` | `update` | اعتبارسنجی + به‌روزرسانی |
| POST | `/admin/license-titles/{id}/delete` | `destroy` | حذف |
| POST | `/admin/license-titles/{id}/toggle` | `toggle` | flip `is_active` |
| GET  | `/admin/license-titles/by-service/{id}` | `byService` | JSON لیست بر اساس service_type_id |

اعتبارسنجی: `title` الزامی + ≤۱۵۰ نویسه، `category` ≤۱۰۰ نویسه، UNIQUE
`(service_type_id, title)` با پیام فارسی (به‌جای SQLSTATE 23000 خام).
`byService` تنها `is_active = 1` برمی‌گرداند، برای استفاده در dropdown ویزارد.

### ۲-۴) `/admin/sync/readiness` — `Stage2ReadinessChecker` UI

`SyncAdminController::readiness()` با همان الگوی constructor فاز ۱۰ (extends
`Controller` + `requireRole(['admin', 'sync_operator'])`). `checker` از
Container با `$this->container->make(Stage2ReadinessChecker::class)` گرفته
می‌شود.

View `admin/sync_readiness.php` هر یک از ۴ شرط §29.2 را به‌صورت یک Bootstrap
card با badge pass (سبز) / fail (قرمز) نشان می‌دهد. در بالای صفحه flag کلی
`ready` به‌صورت badge بزرگ نمایش داده می‌شود. در انتها یک alert info
توضیح می‌دهد که فعال‌سازی Stage 2 از صفحهٔ Settings (با تنظیم
`sync_direction='two_way'` + `sync_two_way_enabled='1'`) انجام می‌شود —
runtime switch، بدون نیاز به deploy.

سایدبار `admin_layout.php` یک لینک جدید باアイکن `bi-clipboard2-check` تحت
بخش «درگاه ملی» (که قبلاً admin/sync_operator-gated است) اضافه شد.

## ۳) فایل‌های اضافه/تغییر یافته

### فایل‌های جدید

| فایل | شرح |
|---|---|
| `app/Modules/Core/Controllers/FormFieldController.php` | CRUD + move (۷ action) |
| `app/Modules/Core/Controllers/FeeTariffController.php` | CRUD (۶ action) |
| `app/Modules/Core/Controllers/LicenseTitleController.php` | CRUD + toggle + byService (۸ action) |
| `app/Views/admin/form_fields/index.php` | لیست با reorder up/down |
| `app/Views/admin/form_fields/edit.php` | فرم create+edit مشترک |
| `app/Views/admin/fee_tariff/index.php` | لیست با مبلغ فارسی |
| `app/Views/admin/fee_tariff/edit.php` | فرم create+edit مشترک |
| `app/Views/admin/license_titles/index.php` | لیست با toggle button |
| `app/Views/admin/license_titles/edit.php` | فرم create+edit مشترک |
| `app/Views/admin/sync_readiness.php` | گزارش Stage2ReadinessChecker |
| `tests/Unit/FormFieldTest.php` | CRUD happy-path |
| `tests/Unit/FeeTariffTest.php` | CRUD happy-path |
| `tests/Unit/LicenseTitleTest.php` | CRUD happy-path |
| `docs/PHASE_11_ADMIN_CRUD.md` | این فایل |

### فایل‌های تغییر یافته

| فایل | تغییر |
|---|---|
| `app/Modules/Core/Controllers/AdminController.php` | −۳ stub action (formFields/feeTariff/licenseTitles) + docblock |
| `app/Modules/Core/Module.php` | −۳ stub route، +۷ form_fields، +۶ fee_tariff، +۸ license_titles |
| `app/Modules/NationalGateway/Controllers/SyncAdminController.php` | +۱ action: `readiness()` |
| `app/Modules/NationalGateway/Module.php` | +۱ route: `GET /admin/sync/readiness` |
| `app/Views/admin_layout.php` | +۱ لینک سایدبار: آمادگی مرحلهٔ ۲ |
| `VERSION` | `1.1.0` → `1.2.0` |
| `README.md` | ارجاع نسخه در سرصفحه: `0.1.0` → `1.2.0`، فاز ۱ → فاز ۱۱ |
| `CHANGELOG.md` | بخش `## [1.2.0]` جدید + forward-reference از `## [Unreleased]` (فاز ۱۰) |
| `docs/DEBT_REVIEW.md` | بخش فاز ۱۱ + به‌روزرسانی خلاصهٔ live debt (medium ۶→۲، low ۲۶→۲۸) |

## ۴) Debt

### پرداخت‌شده (۴ medium)

| ID | شرح |
|---|---|
| D-7-1 | ۳ کنترلر ادمین باقی‌مانده از فاز ۷ ساخته شد |
| D-10-1 | ۳ stub اکشن فاز ۱۰ با CRUD کامل تعویض شد |
| D-2-2 | surface ادمین بین کنترلرهای اختصاصی پخش شد (الگوی Phase-7) |
| D-8-4 | admin UI برای `Stage2ReadinessChecker::check()` ساخته شد |

### Partial-paid (۱ medium)

| ID | شرح |
|---|---|
| D-2-3 (partial) | ۳ از ~۱۵ کنترلر موکول‌شده ساخته شد؛ ۱۲ تای باقی‌مانده به v1.4+ موکول شد (به low کاهش شد) |

### جدید (۲ low)

| ID | شرح | مهلت |
|---|---|---|
| D-11-1 | `LicenseTitleController::byService` admin-only است؛ endpoint عمومی برای ویزارد لازم است | v1.3 |
| D-11-2 | `FormFieldController::move` tie-break با id در docblock ذکر نشده | v1.3 |

### Carried forward

- D-9-1 (medium, v1.3): `/panel/profile` + `/panel/avatar` stubs
- D-2-3 (low, v1.4, partial): ۱۲ کنترلر باقی‌مانده
- ۲ low جدید (D-11-1, D-11-2)

## ۵) تست

### ۵-۱) تست‌های unit جدید

سه فایل تست جدید تحت `tests/Unit/` اضافه شد، هر کدام با الگوی
`UserNotificationRepositoryTest`:

- `FormFieldTest` — create/edit/delete یک fixture در `form_fields` با
  `field_key='test_ff_*'` (پیشوند برای جلوگیری از تصادف با seed)،
  cleanup در tearDown.
- `FeeTariffTest` — create/edit/delete یک fixture در `fee_tariffs` با
  `notes='test_ft_*'`، cleanup در tearDown.
- `LicenseTitleTest` — create/edit/toggle/byService یک fixture در
  `license_titles` با `title='test_lt_*'`، cleanup در tearDown.

هر تست یک Container واقعی با MariaDB متصل می‌شود (الگوی
`ContainerWiringTest`) و CRUD happy-path را exercise می‌کند.

### ۵-۲) regression

- تمام unit tests (۳۹۱ assertion قبلی + assertions جدید): بدون regression.
- تمام contract tests (۲۱ assertion): بدون regression.
- HTTP smoke test (۶۶ pass / ۸ pre-existing D-8-5): بدون regression.
- `static_analysis.py` و `php_lint_simple.py`: همان warningهای pre-existing
  (false-positive‌ها)، بدون افزایش.
- `check_schema_ownership.py`: همچنان PASS — هیچ migration جدید اضافه نشد
  (۳ جدول از قبل در `database/schema.sql` موجود بودند).

## ۶) تصمیم‌های طراحی

### ۶-۱) چرا controller اختصاصی به‌جای گسترش AdminController؟

الگوی فاز ۷ (UsersController + SettingsController) کنترلرهای اختصاصی با
DI-constructor کامل را برای هر surface ادمین ترجیح می‌دهد. این نگهداری
را آسان‌تر می‌کند (هر کنترلر یک مسئولیت) و تست‌پذیری را بالا می‌برد
(مستقل از سایر surface‌ها).

### ۶-۲) چرا `move` route قبل از `{id}` ثبت شد؟

POST `/admin/form-fields/{id}` با regex `[^/]+` مسیر `/admin/form-fields/move`
را با `id="move"` match می‌کند. برای اطمینان از اینکه `move` به action
`move` برسد نه `update`، route `move` قبل از route `{id}` در
`Core/Module.php` ثبت شد. این deviate از ترتیب listed در task است (که
برای readability بود) ولی برای correctness لازم بود.

### ۶-۳) چرا `$title_` به‌جای `$title` در view `license_titles/edit.php`؟

layout `admin_layout.php` متغیر `$title` را به‌عنوان page-title در
`<h1>` و `<title>` استفاده می‌کند. اگر view هم `$title` را برای record
فرض می‌کرد، extract() در render() باعث overwrite می‌شد. بنامگذاری
`$title_` (با underscore) برای رکورد جلوی این collision را می‌گیرد.

### ۶-۴) چرا `byService` فقط `is_active = 1` برمی‌گرداند؟

ویزارد ثبت‌نام نباید عنوان‌های غیرفعال را به کاربر نشان دهد. admin می‌تواند
با `toggle` یک عنوان را غیرفعال کند تا از dropdown کاربر حذف شود ولی
داده در جدول باقی بماند (soft disable به‌جای hard delete).

## ۷) نتیجه

سه stub admin فاز ۱۰ با کنترلرهای CRUD کامل تعویض شد. admin UI برای
`Stage2ReadinessChecker` ساخته شد. ۴ debt item medium پرداخت شد و ۱ medium
به low کاهش یافت. ۳ تست unit جدید برای CRUD happy-path اضافه شد. هیچ
regression در تست‌های موجود رخ نداد.

کنترلرهای باقی‌مانده (Payment/Revision/Report/ServiceType/Service/
BulkImport/WizardStep/Sms/Document/Records) در v1.4+ ساخته خواهند شد.
