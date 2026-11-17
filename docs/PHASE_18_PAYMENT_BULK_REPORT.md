# Phase 18 — Payment + Bulk import + Reports (v1.9.0)

> **تاریخ:** 2026-11-17
> **شاخه:** `phase/18-payment-bulk-report` (merge به `main` با `--no-ff`)
> **هدف:** پورت ۳ کنترلر بزرگ باقی‌مانده از v2 — PaymentController،
> BulkImportController، ReportController — با ۹ view جدید. این فاز
> بخش مالی و گزارش‌گیری سامانه را به v3 می‌آورد و فاز ۱۹-۲۰ برای
> License/Document/Revision/Tracking باقی می‌ماند.

## ۱) زمینه

پس از فاز ۱۷ (v1.8.0) که پنل ادمین کامل را با ۳ کنترلر جدید
(ServiceType، WizardStep، Sms) آورد، ۳ کنترلر بزرگ گمشده باقی
مانده بود:

- **PaymentController** — مدیریت پرداخت‌ها + کانال‌های پرداخت
- **BulkImportController** — ایمپورت دسته‌جمعی متقاضیان از CSV
- **ReportController** — گزارش‌های آماری + خروجی CSV/PDF

این فاز سه کنترلر را با ۲۴ مسیر جدید + ۹ view جدید پورت می‌کند.
تطبیق به معماری ماژولار v3 (DI Container، Connection prepared
statements، CsvReader، FileStorage، Hash) — نه کپی verbatim از v2
(procedural + Database::getInstance).

## ۲) PaymentController (۵ مسیر)

| متد | مسیر | شرح |
|---|---|---|
| `index` | `GET /admin/payments` | لیست پرداخت‌ها JOIN با applicants (صفحه‌بندی ۲۰/صفحه) + آمار مالی + لیست payment_channels + فرم افزودن/ویرایش کانال |
| `review` | `POST /admin/payments/review` | تأیید/رد رسید (decision=approved/rejected + note). آپدیت payments.status + INSERT در payment_audit_log (تراکنش) |
| `csv` | `GET /admin/payments/csv` | خروجی CSV همهٔ پرداخت‌ها با فیلترهای فعلی (UTF-8 BOM + محافظت formula-injection) |
| `saveChannel` | `POST /admin/payment-channels/save` | INSERT/UPDATE در payment_channels (id, title, channel_type, is_active, sort_order) |
| `deleteChannel` | `POST /admin/payment-channels/delete` | DELETE از payment_channels (یا غیرفعال‌سازی در صورت وجود تراکنش) |

### تفاوت با v2

- **بدون `PaymentChannel::TYPES`** — v3 schema فقط `channel_type` را به‌عنوان varchar(50) نگه می‌دارد، بدون enum.
- **بدون `account_info`** — ستون `account_info` در v3 schema نیست (v2 داشت).
- **CSV در Response object** — v2 از `CsvExporter::export` با `header()/echo` استفاده می‌کرد؛ v3 از `Response` value object با `Content-Type` + `Content-Disposition` مناسب استفاده می‌کند.
- **payment_audit_log در تراکنش** — آپدیت `payments.status` و INSERT در `payment_audit_log` در یک DB تراکنش (`Connection::transactional`) انجام می‌شود تا اتمیک باشد.

## ۳) BulkImportController (۸ مسیر)

| متد | مسیر | شرح |
|---|---|---|
| `index` | `GET /admin/bulk-import` | فرم آپلود CSV |
| `upload` | `POST /admin/bulk-import/upload` | پارس CSV با `CsvReader`، اعتبارسنجی هدر، ذخیره در session (`bulk_import.rows` + `bulk_import.filename`)، ریدایرکت به preview |
| `preview` | `GET /admin/bulk-import/preview` | نمایش ۱۰۰ ردیف اول + badge معتبر/نامعتبر + دکمهٔ execute |
| `execute` | `POST /admin/bulk-import/execute` | INSERT هر ردیف معتبر به‌عنوان applicants با status='تأیید اولیه' و tracking_code. ذخیرهٔ summary در session، ریدایرکت به result |
| `result` | `GET /admin/bulk-import/result` | نمایش summary (inserted/failed/skipped) + جدول per-row |
| `missingDocs` | `GET /admin/bulk-import/missing-docs` | لیست applicants بدون documents (LIMIT 500) |
| `uploadDocs` | `GET /admin/bulk-import/documents/{code}` | فرم آپلود مدرک برای یک applicant (با tracking_code) |
| `saveDocs` | `POST /admin/bulk-import/documents/{code}/save` | ذخیرهٔ فایل با FileStorage + INSERT در documents |

### تفاوت با v2

- **هدر ثابت، نه auto-mapping** — v2 از تطبیق فازی هدر فارسی (نام کامل → first_name/last_name) استفاده می‌کرد؛ v3 فقط هدر انگلیسی `national_code, first_name, last_name, mobile, service_type_id, postal_code` را می‌پذیرد (ساده‌تر + امن‌تر).
- **بدون split_full_name / full_name** — چون هدر ثابت است، نیازی به شکستن نام کامل نیست.
- **بدون merge scenario** — v2 ردیف‌های تکراری (همان کد ملی) را با person قبلی ادغام می‌کرد (mobile_2/mobile_3)؛ v3 ساده‌سازی کرده: ردیف با کد ملی موجود در DB → skip.
- **بدون login + account creation** — v2 برای هر ردیف، user_account با رمز پیش‌فرض می‌ساخت؛ v3 فقط applicants ایجاد می‌کند (حساب کاربری در فاز ۱۹ اضافه می‌شود).
- **بدون batch commits** — v2 با batch commits کار می‌کرد؛ v3 هر ردیف را به‌صورت مجزا INSERT می‌کند (ساده‌تر، با set_time_limit(0) برای CSV بزرگ).

## ۴) ReportController (۱۱ مسیر)

| متد | مسیر | شرح |
|---|---|---|
| `byProvince` | `GET /admin/reports/by-province` | آمار بر اساس property_details.province |
| `byCity` | `GET /admin/reports/by-city` | بر اساس city |
| `byVillage` | `GET /admin/reports/by-village` | بر اساس village (فقط ردیف‌های non-empty) |
| `byLicense` | `GET /admin/reports/by-license` | بر اساس job_details.license_title |
| `byDate` | `GET /admin/reports/by-date` | بر اساس DATE(applicants.created_at) |
| `generalReport` | `GET /admin/output/general` | داشبورد آمار کلی (total + by-status + by-service-type + by-license-state + روند ماهانه ۱۲ ماه) |
| `singleApplicantReport` | `GET /admin/output/single/{trackingCode}` | پروندهٔ چاپی یک متقاضی (با `print.php` و layout='none') |
| `groupedReportForm` | `GET /admin/output/grouped` | فرم انتخاب group_by |
| `groupedReportResult` | `POST /admin/output/grouped` | پردازش فرم + نمایش گزارش گروه‌بندی (همان view با نتایج) |
| `csvReport` | `GET /admin/report/csv` | خروجی CSV همهٔ متقاضیان با داده‌های مرتبط (UTF-8 BOM + محافظت formula-injection) |
| `pdfReport` | `GET /admin/report/pdf` | خروجی قابل چاپ/PDF (HTML صفحه‌بندی A4 landscape — کاربر با Save as PDF مرورگر ذخیره می‌کند) |

### الگوی view مشترک برای ۵ کنترلر `by*`

همهٔ ۵ متد `by*` از یک view واحد `admin/report_stats.php` استفاده می‌کنند که یک آرایهٔ `$report` با ساختار زیر می‌گیرد:

```php
$report = [
    'title'       => 'گزارش بر اساس استان',
    'group_label' => 'استان',
    'total'       => 42,  // مجموع
    'rows'        => [
        ['label' => 'فارس',       'count' => 18, 'percentage' => 42.9],
        ['label' => 'تهران',      'count' => 12, 'percentage' => 28.6],
        // ...
    ],
];
```

### تفاوت با v2

- **CSV در Response object** — به‌جای `CsvExporter::export` با `header()/echo` مستقیم.
- **محافظت formula-injection** — هر سلول که با `=`, `+`, `-`, یا `@` شروع شود با `'` پیشوند می‌شود (محافظت در برابر حملهٔ CSV injection در Excel/LibreOffice).
- **groupedReportForm + groupedReportResult** در یک view واحد (`output_grouped_form.php`) — v2 فرم را در همان view نگه می‌داشت و نتیجه را در `report_stats.php` نمایش می‌داد؛ v3 هر دو را در همان view می‌گیرد (ساده‌تر برای کاربر).
- **whitelist group_by** — v3 از یک آرایهٔ whitelist برای نگاشت `group_by` → SQL استفاده می‌کند (prevents SQL injection از طریق نام ستون).

## ۵) Viewهای جدید (۹)

| فایل | شرح |
|---|---|
| `admin/payments.php` | جدول پرداخت‌ها + ۴ کارت آماری + سایدبار درآمد بر اساس کانال + CRUD کانال‌ها |
| `admin/bulk_import.php` | فرم آپلود CSV با نمونهٔ فرمت |
| `admin/bulk_preview.php` | ۱۰۰ ردیف اول با badge معتبر/نامعتبر + دکمهٔ execute |
| `admin/bulk_result.php` | ۴ کارت شمارشی (inserted/failed/skipped/total) + جدول per-row |
| `admin/bulk_missing_docs.php` | لیست applicants با doc_count=0 + لینک upload |
| `admin/bulk_upload_docs.php` | اطلاعات متقاضی + مدارک موجود (گروه‌بندی بر اساس doc_type) + فرم آپلود |
| `admin/report_stats.php` | جدول آماری عمومی (label + count + percentage با progress bar) |
| `admin/output_general.php` | داشبورد آمار کلی + دکمه‌های CSV/PDF |
| `admin/output_grouped_form.php` | فرم group_by (۷ گزینه) + نتایج inline |

## ۶) سایدبار ادمین

`admin_layout.php` با ۹ لینک جدید گسترش یافت:

### جدید: بخش "گزارش‌ها"
- `/admin/reports/by-province` (گزارش استان, bi-geo-alt)
- `/admin/reports/by-license` (گزارش عنوان مجوز, bi-award)
- `/admin/reports/by-date` (گزارش روزانه, bi-calendar)
- `/admin/output/general` (خروجی عمومی, bi-file-bar-graph)
- `/admin/output/grouped` (خروجی گروه‌بندی, bi-table)
- `/admin/report/csv` (خروجی CSV, bi-filetype-csv)
- `/admin/report/pdf` (خروجی PDF, bi-filetype-pdf)

### بخش "مدیریت" (افزوده)
- `/admin/payments` (پرداخت‌ها, bi-credit-card)
- `/admin/bulk-import` (ایمپورت دسته‌جمعی, bi-cloud-upload)

## ۷) Container

سه کنترلر جدید با autowire توسط Container ساخته می‌شوند (بدون binding
صریح در Module.php — مانند `UsersController`، `ServiceTypeController`).
تمام وابستگی‌های constructor در Container موجودند:

- `Connection` (factory)
- `ApplicantRepositoryInterface` → `PdoApplicantRepository` (bound در Module.php)
- `DocumentRepositoryInterface` → `PdoDocumentRepository`
- `ServiceTypeRepositoryInterface` → `PdoServiceTypeRepository`
- `StatusLogRepositoryInterface` → `PdoStatusLogRepository`
- `FileStorage` (bound در Module.php)
- `Hash` (singleton در Container constructor)
- `Container`, `Session`, `Csrf`, `Environment`, `StructuredLogger` (همه bound)

## ۸) امنیت

### محافظت formula-injection در CSV

هر دو کنترلر (PaymentController::csv و ReportController::csvReport)
از یک متد خصوصی `csvLine()` استفاده می‌کنند که سلول‌های آغازشده با
`=`, `+`, `-`, `@` را با `'` پیشوند می‌کند. این محافظت در برابر
حملهٔ CSV injection است (که در آن یک سلول مخرب `=CMD(...)` در
Excel/LibreOffice اجرا می‌شود).

### prepared statements

همهٔ کوئری‌های SQL از prepared statements استفاده می‌کنند (`?` placeholder).
متد `Connection::escapeLike()` برای escape کردن کاراکترهای `%`, `_`, `\\`
در LIKE pattern ها استفاده می‌شود. هیچ string-concat با $variable در SQL
وجود ندارد.

### whitelist filters

PaymentController فیلترهای `status` و `purpose` را با whitelist بررسی
می‌کند (هر مقدار خارج از لیست مجاز به `''` تبدیل می‌شود).
ReportController::groupedReportResult از یک آرایهٔ whitelist برای
نگاشت `group_by` → SQL استفاده می‌کند (prevents SQL injection از طریق
نام ستون).

### payment_audit_log در تراکنش

PaymentController::review آپدیت `payments.status` و INSERT در
`payment_audit_log` را در یک تراکنش انجام می‌دهد (`Connection::transactional`)
تا در صورت شکست INSERT، آپدیت status هم rollback شود.

### requireRole(['admin'])

هر ۲۴ مسیر جدید با `requireRole(['admin'])` شروع می‌شوند — فقط super-admin.
Operatorها 403 می‌گیرند.

## ۹) آمار مسیرها

| ماژول | قبل | بعد | Δ |
|---|---|---|---|
| Core | 126 | 150 | +24 |
| NationalGateway | 43 | 43 | 0 |
| **مجموع** | **169** | **193** | **+24** |

## ۱۰) Debt

### پرداخت‌شده

- **D-2-3 (partial)** — ۳ کنترلر جدید: PaymentController، BulkImportController، ReportController.

### وارد‌شده

هیچ.

### به فاز ۱۹ منتقل

- **D-2-3 (partial)** — ~۶ کنترلر باقی‌مانده: License، Document، Revision، Tracking + تکمیلی ServiceType/FeeTariff.

## ۱۱) تست

- `python3 tools/php_lint_simple.py` — ۷ failure pre-existing (همه در فایل‌های بدون تغییر). ۰ failure جدید.
- `python3 tools/static_analysis.py --strict` — ۰ surviving warning (بعد از افزودن ۳ false-positive جدید).
- `python3 tools/check_schema_ownership.py` — ۱ violation pre-existing (integration_log، NationalGateway migration). ۰ violation جدید.

## ۱۲) فایل‌های تغییرشده

### جدید (۱۲)

- `app/Modules/Core/Controllers/PaymentController.php`
- `app/Modules/Core/Controllers/BulkImportController.php`
- `app/Modules/Core/Controllers/ReportController.php`
- `app/Views/admin/payments.php`
- `app/Views/admin/bulk_import.php`
- `app/Views/admin/bulk_preview.php`
- `app/Views/admin/bulk_result.php`
- `app/Views/admin/bulk_missing_docs.php`
- `app/Views/admin/bulk_upload_docs.php`
- `app/Views/admin/report_stats.php`
- `app/Views/admin/output_general.php`
- `app/Views/admin/output_grouped_form.php`

### تغییرشده (۷)

- `app/Modules/Core/Module.php` (+۲۴ routes)
- `app/Views/admin_layout.php` (+۹ sidebar links)
- `app/Shared/Support/CsvReader.php` (PHP 8.4 fix: explicit setCsvControl + handle fgetcsv() returning false)
- `tools/static_analysis_ignore.txt` (+۳ false-positive patterns)
- `VERSION` (1.8.0 → 1.9.0)
- `README.md` (header version)
- `CHANGELOG.md` (این بخش)
- `docs/DEBT_REVIEW.md` (Phase 18 section + live-debt summary)
- `docs/PHASE_18_PAYMENT_BULK_REPORT.md` (این سند)

## ۱۳) Bug fix مرتبط: CsvReader + PHP 8.4

Phase 18 نخستین فازی است که از `App\Shared\Support\CsvReader` در مسیر
runtime استفاده می‌کند (BulkImportController::upload). این فایل از
فاز ۱ موجود بود اما هیچ کنترلری آن را صدا نمی‌زد.

روی PHP 8.4.23 (نسخهٔ سرور توسعه)، `SplFileObject::fgetcsv()` یک
E_DEPRECATED منتشر می‌کند وقتی پارامتر `$escape` به‌طور صریح تنظیم
نشده (در PHP 9 مقدار پیش‌فرض از `"\\"` به `""` تغییر می‌کند).
`set_error_handler` در front controller همهٔ E_DEPRECATED‌ها را به
`ErrorException` تبدیل می‌کند، پس `CsvReader::read()` روی اولین ردیف
استثنا پرتاب می‌کرد و BulkImportController آن را به‌عنوان خطای hard
می‌گرفت و ریدایرکت می‌کرد.

Fix: `CsvReader::read()` حالا بعد از `setFlags()` یک `setCsvControl(',',
'"', '\\')` صدا می‌زند تا مقدار escape پیش‌فرض legacy به‌صورت صریح
تنظیم شود. همچنین `fgetcsv()` که در PHP 8.4 می‌تواند `false` (نه
فقط `null`) برگرداند را هم به‌درستی handle می‌کند.

این bug از فاز ۱ latent بود اما فقط حالا surfaced شد چون Phase 18
اولین caller است.
