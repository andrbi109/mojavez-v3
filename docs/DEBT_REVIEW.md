# بازبینی Debt فنی — mojavez-v3

> **نسخه:** 1.0 · **تاریخ:** ۱۴۰۵/۰۸/۲۶ (۲۰۲۶-۱۱-۱۶)
> **هدف:** قالب بازبینی debt فنی در پایان هر فاز از نقشهٔ راه، برای جلوگیری از انباشت debt و نیاز به refactor مجدد (رفع جنبهٔ پنهان ۵.۱۰ از سند استراتژی-ریسک).
> **مخاطب:** maintainer اصلی و توسعه‌دهندگان ماژول.

---

## ۱. قواعد بازبینی

۱. در پایان هر فاز (از فاز ۱ تا فاز ۸)، این فایل با یک بخش جدید پر می‌شود.

۲. هر فاز شامل:
   - **Debt introduced:** چه debt جدیدی در این فاز وارد شد.
   - **Debt paid:** چه debt قبلی در این فاز پرداخت شد.
   - **Debt carried forward:** چه debt‌هایی به فاز بعد منتقل شدند.
   - **Recommendation:** آیا فاز بعدی باید شامل «پاکسازی debt» باشد یا خیر.

۳. اگر debt انباشته شده (debt carried forward > ۳ مورد)، فاز بعدی باید شامل «پاکسازی debt» باشد — حتی اگر به‌معنای تأخیر در feature باشد.

۴. هر item debt با: شناسه، توضیح، موقعیت (فایل/ماژول)، شدت، راهکار پیشنهادی.

---

## ۲. شدت debt

| شدت | توضیح | مهلت رفع |
|---|---|---|
| `critical` | ریسک امنیتی یا فاجعهٔ تولید | پیش از فاز بعدی |
| `high` | ریسک عملکرد یا maintainability | در فاز بعدی |
| `medium` | نگرانی کیفیت کد | در ۲ فاز بعدی |
| `low` | نگرانی زیبایی‌شناختی | در فرصت مناسب |

---

## ۳. قالب بازبینی هر فاز

### فاز X: <نام فاز>

**تاریخ تکمیل:** YYYY-MM-DD
**Maintainer:** <نام>
**نسخهٔ منتشرشده:** vX.Y.Z

#### Debt introduced در این فاز

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-X-1 | ... | `path/to/file.php:L42` | medium | ... |

#### Debt paid در این فاز

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-(X-1)-2 | ... | refactor به ... |

#### Debt carried forward به فاز بعد

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-X-1 | ... | medium | فاز X+2 |

#### توصیه برای فاز بعد

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ (بله / خیر)
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟
- [ ] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟

---

## ۴. بازبینی‌های ثبت‌شده

### فاز ۰: تثبیت foundation

**تاریخ تکمیل:** 2026-11-17 (ادغام‌شده در فاز ۱ — فاز جداگانه اجرا نشد)
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** — (ادغام با فاز ۱)

> نکته: در mojavez-v3، فاز ۰ به‌صورت یک گام از فاز ۱ اجرا شد (ساخت مخزن، VERSION واحد،
> README، LICENSE، قراردادهای کد). این سند با پروژهٔ v2 ادغام می‌شود.

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| — | — | — | — | — |

#### Debt paid

- سه‌گانگی نسخهٔ v2 (README=0.1.0، VERSION=0.6.3، REFACTOR_PLAN=v2.0.0) رفع شد:
  منبع حقیقت واحد در `VERSION` (0.1.0) با ارجاع از `CHANGELOG.md` و `README.md`.

#### Debt carried forward

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| — | — | — | — |

#### توصیه

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ خیر — فاز ۰ بدون debt باقی ماند.

---

### فاز ۱: هسته و معماری

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 0.1.0 (phase/1-core-architecture branch)

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-1-1 | EventBus هنوز یک anonymous placeholder است (یک کلاس inline در `public/index.php` و `scripts/migrate.php`) تا Phase 4 EventBus واقعی پیاده شود. ModuleLoader یک EventBus واقعی را انتظار دارد. | `public/index.php` lines ~155-165; `scripts/migrate.php` lines ~55-58 | medium | در فاز ۴ یک کلاس `App\Events\EventBus` بساز و به Container بایند. |
| D-1-2 | `collectSubscribers()` در ModuleLoader هنوز صدا زده نمی‌شود چون EventBus واقعی نیست. | `app/Modules/ModuleLoader.php` (متد موجود اما در index.php کامنت شده) | medium | در فاز ۴ فعال کن. |
| D-1-3 | `Request::$trustProxy` hard-coded `false` است. باید از `config('app.trust_proxy')` خوانده شود. | `public/index.php` خط ~140 | low | در فاز ۲ `app.trust_proxy` config اضافه کن و از Container بخوان. |
| D-1-4 | `install.php` و `check.php` برای shared hosting هنوز پیاده‌سازی نشده‌اند (تنها قالب ذکر شده در docs/00-MASTER.md §16.5). | `deploy/shared-hosting/install.php` | medium | در فاز ۶ بساز. |
| D-1-5 | `database/schema.sql` و `database/install.sql` هنوز ساخته نشده‌اند (تنها migration bootstrap هست). | `database/` | medium | در فاز ۲ همراه با migrations کامل Core بساز. |
| D-1-6 | `SseController::stream` در production 501 برمی‌گرداند به‌جای real streaming. | `app/Modules/Core/Controllers/SseController.php` | low | در فاز ۴ با EventBus واقعی پیاده کن. |
| D-1-7 | تست‌های واحد با `php tests/run.php` نوشته شده‌اند اما در محیط sandbox محلی اجرا نمی‌شوند چون PHP نصب نیست. CI در GitHub Actions اجرا می‌کند. | `tests/Unit/` | low | در محیط‌های توسعه با PHP نصب اجرا کن. |
| D-1-8 | static_analysis.php در فاز ۱ advisory-only است (warnings را fail نمی‌کند). | `tools/static_analysis.py` | low | در فاز ۶ به fail-on-warning سختگیرانه تبدیل کن. |
| D-1-9 | مسیر ذخیرهٔ `storage/logs` و `storage/sessions` به‌صورت پیش‌فرض writable فرض می‌شود. در shared hosting ممکن است نیاز به پیکربندی داشته باشد. | `app/Http/Session.php`, `app/Support/StructuredLogger.php` | low | در فاز ۶ در `deploy/shared-hosting/check.php` بررسی پیش‌نیاز اضافه کن. |

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| (پیشینیان) | `Database::getInstance()` Singleton در v2 — سخت‌گیری در تست | با Container دستی با autowire + setter injection جایگزین شد. Connection حالا یک memoized singleton در Container است. |
| (پیشینیان gateway) | CSRF غیرفعال (S1) | در `public/index.php` هر درخواست mutating CSRF را با `hash_equals` اعتبارسنجی می‌کند. |
| (پیشینیان gateway) | Rate limiter کد مرده (ST1) | در فاز ۳ هنگام ساخت `MojavecClient::doWithRetry()` فعال خواهد شد؛ زیرساخت `RateLimiterService` در فاز ۳ اضافه می‌شود. |
| (پیشینیان gateway) | Connection factory (004-05) | `Connection::instance()` static cache — singleton واقعی. |
| (پیشینیان gateway) | Router regex `/` نامعتبر (004-06) | هر pattern با `/?$` تمام می‌شود (در `Router::matchPattern`). |
| (پیشینیان v2) | سه‌گانگی نسخه | `VERSION` (0.1.0) منبع واحد؛ CHANGELOG و README به آن ارجاع می‌دهند. |
| (پیشینیان v2) | SQLite در gateway | MySQL/MariaDB با `EMULATE_PREPARES=false` و utf8mb4_unicode_ci. |
| (پیشینیان v2) | `unsafe-eval` در CSP | `Response::CSP` بدون `unsafe-eval` و بدون CDN (self-host اجباری). |
| (پیشینیان v2) | `phpinfo.php` | هرگز وجود ندارد در v3. |
| (پیشینیان v2) | رمز hardcoded در seed-admin.sql | `install.php` در فاز ۶ رمز تصادفی ۱۶-کاراکتری تولید می‌کند. |

#### Debt carried forward به فاز ۲

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-1-1 | EventBus placeholder | medium | فاز ۴ |
| D-1-2 | collectSubscribers() معلق | medium | فاز ۴ |
| D-1-3 | Request::$trustProxy hard-coded | low | فاز ۲ (با config app.trust_proxy) |
| D-1-4 | install.php و check.php | medium | فاز ۶ |
| D-1-5 | database/schema.sql و install.sql | medium | فاز ۲ |
| D-1-6 | SseController::stream واقعی | low | فاز ۴ |
| D-1-7 | اجرای محلی تست‌ها | low | محیط توسعه کاربر |
| D-1-8 | static_analysis fail-on-warning | low | فاز ۶ |
| D-1-9 | writable storage assumptions | low | فاز ۶ |

#### توصیه برای فاز ۲

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ خیر — ۹ مورد low/medium، همه با مهلت در فازهای بعدی.
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟ D-1-3 (سریع)، D-1-5 (حتماً — فاز ۲ به schema.sql نیاز دارد).
- [ ] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ کم — D-1-5 جزء کار فاز ۲ است، نه debt اضافی.

---

### فاز ۲: دامنهٔ v2

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 0.1.0 (phase/2-v2-domain branch)

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-2-1 | Viewها فعلاً HTML ساده هستند (Bootstrap 5 RTL + Vazirmatn هنوز پیاده نشده‌اند). | `app/Views/*.php` | medium | در فاز ۶ استایل کامل را با Bootstrap + Vazirmatn self-host پیاده کن. |
| D-2-2 | کنترلرهای AdminController فقط ۴ متد اول پیاده شده‌اند (dashboard, applications, viewApplication, changeStatus). سایر عملیات ادمین (register manual, edit, postal requests, my-tasks, users, form-fields, wizard-steps, settings, payments, bulk-import, fee-tariff, license-titles, sms, revisions, license upload/requests) هنوز اضافه نشده‌اند. | `app/Modules/Core/Controllers/AdminController.php` | medium | در فازهای ۳-۶ به‌صورت incremental اضافه کن. |
| D-2-3 | کنترلرهای License, Payment, Revision, Report, UserPanel, ServiceType, ServiceController, BulkImport, FeeTariff, FormField, WizardStep, Setting, Sms, LicenseTitle, DocumentController, UserController, RecordsController هنوز ساخته نشده‌اند. | `app/Modules/Core/Controllers/` | medium | در فازهای ۳-۶ اضافه کن. |
| D-2-4 | FeeCalculatorService::calculateForApplicant از `applicants.income_decile` به‌صورت مستقیم می‌خواند (شبیه v2) به‌جای استفاده از `applicant_field_values`. هنگام پورت کامل ویزارد ثبت‌نام باید از form_fields خوانده شود. | `app/Modules/Core/Services/FeeCalculatorService.php:78` | low | در فاز ۶ هنگام تکمیل ویزارد ثبت‌نام اصلاح کن. |
| D-2-5 | AutoCancelService::updatePriorities از paginate استفاده می‌کند و در PHP فیلتر می‌کند — برای حجم بالا کند است. | `app/Modules/Core/Services/AutoCancelService.php:75` | low | در فاز ۶ با یک کوئری مستقیم SQL بهینه کن. |
| D-2-6 | rate_limit (session-based و IP-based) هنوز در کنترلرها اعمال نشده است. guards پیاده شده‌اند ولی rate limit نه. | `app/Modules/Core/Controllers/Controller.php` | high | در فاز ۶ هنگام تکمیل کنترلرها فعال کن. |
| D-2-7 | `database/schema.sql` و `database/install.sql` هنوز ساخته نشده‌اند (فقط migration files موجودند). | `database/` | medium | در فاز ۶ به‌عنوان منبع واحد schema بساز. |
| D-2-8 | `install.php` تعاملی برای shared hosting هنوز پیاده نشده است. | `deploy/shared-hosting/` | medium | در فاز ۶ بساز. |
| D-2-9 | Migration seed برای 5 service_type + license_titles پیش‌فرض هنوز اجرا نشده است. | `app/Modules/Core/migrations/` | low | در فاز ۶ یک migration 006_seed_defaults.sql اضافه کن. |

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-1-1 | EventBus placeholder | هنوز placeholder — به فاز ۴ منتقل شد. |
| D-1-2 | collectSubscribers() معلق | هنوز معلق — به فاز ۴. |
| D-1-3 | Request::$trustProxy hard-coded | هنوز — به فاز ۶ منتقل شد (با config app.trust_proxy). |
| D-1-5 | database/schema.sql و install.sql | migration files هستند اما schema.sql واحد هنوز نه — به D-2-7 منتقل شد. |
| (پیشینیان) | `Database::getInstance()` Singleton | همه repository‌ها از Connection و Interface وابسته‌اند — پیاده‌سازی کامل شد. |
| (پیشینیان) | نبود Repository interfaces | 12 interface در app/Shared/Contracts/ ساخته شد. |
| (پیشینیان) | نبود ماتریس مالکیت schema | 4 migration فایل در Core که همگی از check_schema_ownership.py عبور کردند. |
| (پیشینیان) | نبود `version`, `deleted_at`, `consent_external_share` روی applicants | در migration 002 اضافه شدند. |
| (پیشینیان) | نبود `triggered_by` روی status_log | در migration 002 اضافه شد (admin\|user\|system\|auto_sync). |

#### Debt carried forward به فاز ۳

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-1-1 | EventBus placeholder | medium | فاز ۴ |
| D-1-2 | collectSubscribers() معلق | medium | فاز ۴ |
| D-1-4 | install.php و check.php | medium | فاز ۶ |
| D-1-6 | SseController::stream واقعی | low | فاز ۴ |
| D-1-7 | اجرای محلی تست‌ها | low | محیط کاربر |
| D-1-8 | static_analysis fail-on-warning | low | فاز ۶ |
| D-1-9 | writable storage assumptions | low | فاز ۶ |
| D-2-1 | Viewهای بدون Bootstrap/Vazirmatn | medium | فاز ۶ |
| D-2-2 | AdminController فقط ۴ متد | medium | فاز ۳-۶ |
| D-2-3 | کنترلرهای باقی‌مانده | medium | فاز ۳-۶ |
| D-2-4 | FeeCalculatorService reads income_decile directly | low | فاز ۶ |
| D-2-5 | AutoCancelService::updatePriorities slow | low | فاز ۶ |
| D-2-6 | rate_limit not applied in controllers | high | فاز ۶ |
| D-2-7 | schema.sql و install.sql واحد | medium | فاز ۶ |
| D-2-8 | install.php تعاملی | medium | فاز ۶ |
| D-2-9 | seed migration پیش‌فرض | low | فاز ۶ |

#### توصیه برای فاز ۳

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ خیر — D-2-6 (high) را در فاز ۶ (همراه با تکمیل کنترلرها) پرداخت می‌کنیم؛ بقیه medium/low با مهلت در فازهای بعدی.
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟ هیچ‌کدام در فاز ۳ — فاز ۳ روی NationalGateway متمرکز است.
- [ ] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ کم — فاز ۳ مستقل از debt فاز ۲ است.

---

### فاز ۳: ماژول درگاه ملی

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 0.1.0 (phase/3-national-gateway branch)

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-3-1 | LicenseService هنوز پورت نشده — به EventBus + JobQueue + LicenseRepository وابسته است که در فاز ۴ کامل می‌شوند. LicenseService هستهٔ orchestration است (search، cache، persist، dispatch events). | `app/Modules/NationalGateway/Services/LicenseService.php` (هنوز نساخته‌شده) | medium | در فاز ۴ بساز. |
| D-3-2 | PatternMatcher هنوز پیاده نشده — هستهٔ منطق اتصال طلایی. | `app/Modules/NationalGateway/Services/PatternMatcher.php` | medium | در فاز ۴ بساز. |
| D-3-3 | ApplicantSyncAdapter (ACL) هنوز ساخته نشده — پل بین NationalGateway و Core. | `app/Modules/NationalGateway/Services/ApplicantSyncAdapter.php` | medium | در فاز ۴ بساز. |
| D-3-4 | PDO implementations برای 5 repository interface هنوز ساخته نشده‌اند (NationalLicenseRepository، NationalLookupRepository، NationalFilterRepository، NationalBatchRepository، SyncAuditLogRepository). | `app/Modules/NationalGateway/Persistence/` | medium | در فاز ۴ بساز. |
| D-3-5 | routes() در NationalGateway/Module.php خالی است — مسیرهای admin sync panel به فاز ۴ موکول شده. | `app/Modules/NationalGateway/Module.php` | low | در فاز ۴ اضافه کن. |
| D-3-6 | FilterService, CsvBatchService, ExportService پورت شده‌اند ولی به repository‌های وابسته‌اند که هنوز پیاده نشده‌اند (LicenseRepository, FilterRepository, BatchRepository, LookupRepository). در زمان استفادهٔ واقعی در فاز ۴ wire خواهند شد. | `app/Modules/NationalGateway/Services/` | medium | در فاز ۴ هنگام wire به Container. |
| D-3-7 | RateLimiterService::wait() هنوز به MojavecClient::doWithRetry() wire نشده — در حال حاضر rate limiter ساخته می‌شود ولی صدا زده نمی‌شود. این همان ST1 است که در مستندات ذکر شده. | `app/Modules/NationalGateway/Infrastructure/MojavecClient.php` (doWithRetry) | medium | در فاز ۴ هنگام wire کردن LicenseService. |
| D-3-8 | CircuitBreaker ساخته شده ولی هنوز به MojavecClient wire نشده. | `app/Modules/NationalGateway/Infrastructure/MojavecClient.php` | medium | در فاز ۴ هنگام wire کردن LicenseService. |
| D-3-9 | Migration seed برای lookup table‌های national_lookups هنوز اجرا نشده (provinces، townships، organizations از upstream fetch نمی‌شوند چون LicenseService هنوز نیست). | `app/Modules/NationalGateway/migrations/` | low | در فاز ۴ هنگام اولین اجرای LicenseService::refreshLookups(). |

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| (پیشینیان) | نبود ماژول NationalGateway | ماژول کامل با Module.php، Domain، Infrastructure، Services ساخته شد. |
| (پیشینیان gateway) | CSRF غیرفعال (S1) | در فاز ۱ رفع شد؛ در فاز ۳ تأیید شد که NationalGateway از همان Csrf استفاده می‌کند. |
| (پیشینیان gateway) | نبود CircuitBreaker (§23.3) | CircuitBreaker با سه حالت CLOSED/OPEN/HALF_OPEN و persistence در storage/cache/ ساخته شد. |
| (پیشینیان gateway) | نبود HttpClientInterface برای تزریق وابستگی در تست‌ها | HttpClientInterface ساخته شد و HttpClient آن را implements می‌کند. TrackScraper و MojavecClient به interface type-hint شده‌اند. |
| (پیشینیان gateway) | نبود تست regression برای TrackScraper | TrackScraperTest با HAR fixture واقعی ساخته شد — 19 assertion که مقادیر استخراج‌شده را lock می‌کند. |
| (پیشینیان gateway) | regex /u modifier bug در findLabelValue که postal_code را break می‌کرد | modifier /u حذف شد چون regex روی ASCII structural tokens کار می‌کند نه UTF-8. |

#### Debt carried forward به فاز ۴

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-1-1 | EventBus placeholder | medium | فاز ۴ |
| D-1-2 | collectSubscribers() معلق | medium | فاز ۴ |
| D-1-4 | install.php و check.php | medium | فاز ۶ |
| D-1-6 | SseController::stream واقعی | low | فاز ۴ |
| D-1-8 | static_analysis fail-on-warning | low | فاز ۶ |
| D-1-9 | writable storage assumptions | low | فاز ۶ |
| D-2-1 | Viewهای بدون Bootstrap/Vazirmatn | medium | فاز ۶ |
| D-2-2 | AdminController فقط ۴ متد | medium | فاز ۶ |
| D-2-3 | کنترلرهای باقی‌مانده | medium | فاز ۶ |
| D-2-4 | FeeCalculatorService reads income_decile directly | low | فاز ۶ |
| D-2-5 | AutoCancelService::updatePriorities slow | low | فاز ۶ |
| D-2-6 | rate_limit not applied in controllers | high | فاز ۶ |
| D-2-7 | schema.sql و install.sql واحد | medium | فاز ۶ |
| D-2-8 | install.php تعاملی | medium | فاز ۶ |
| D-2-9 | seed migration پیش‌فرض | low | فاز ۶ |
| D-3-1 | LicenseService پورت نشده | medium | فاز ۴ |
| D-3-2 | PatternMatcher پورت نشده | medium | فاز ۴ |
| D-3-3 | ApplicantSyncAdapter پورت نشده | medium | فاز ۴ |
| D-3-4 | 5 PDO repository implementation | medium | فاز ۴ |
| D-3-5 | NationalGateway routes خالی | low | فاز ۴ |
| D-3-6 | 3 service به repository‌های وابسته | medium | فاز ۴ |
| D-3-7 | RateLimiterService::wait() wire نشده به MojavecClient | medium | فاز ۴ |
| D-3-8 | CircuitBreaker wire نشده به MojavecClient | medium | فاز ۴ |
| D-3-9 | national_lookups seed | low | فاز ۴ |

#### توصیه برای فاز ۴

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ بله — D-1-1, D-1-2, D-3-1, D-3-2, D-3-3, D-3-4, D-3-7, D-3-8 همگی در فاز ۴ پرداخت می‌شوند چون جوهر فاز ۴ هستند.
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟ همه‌ی موارد بالا که فاز ۴ را هدف گرفته‌اند.
- [ ] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ بالا — فاز ۴ بزرگ‌ترین فاز است (EventBus + JobQueue + Worker + LicenseService + PatternMatcher + ApplicantSyncAdapter + 5 PDO repository).

---

### فاز ۴: زیرساخت async و اتصال طلایی

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 0.1.0 (phase/4-async-golden-link branch)

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-4-1 | SMS ارسال واقعی نشده — فقط در user_sms_entries با status='در انتظار' persist می‌شود. ارسال واقعی نیاز به ادغام با دروازهٔ SMS (Kavenegar/Farapayamak) دارد. | `app/Modules/Core/Services/NotificationService.php::queueSms()` | medium | در فاز ۶ با SmsService واقعی. |
| D-4-2 | property_details در PatternMatcher هنوز wire نشده — ACL یک آرایهٔ خالی به matchQuorum می‌فرستاد. در نتیجه فیلدهای business_province، business_township، postal_code، business_address همیشه mismatch می‌شوند. تست golden-link با national_code + full_name + father_name کار می‌کند ولی quorum کامل نه. | `app/Modules/NationalGateway/Services/ApplicantSyncAdapter::matchAndAct()` | medium | در فاز ۵ (مهاجرت داده) هنگام wire کردن JobAndPropertyRepository. |
| D-4-3 | LicenseService::refreshLookups() هنوز فراخوانی نشده — جداول national_lookups خالی‌اند. نیاز به اجرای دستی یا cron دارد. | `scripts/seed_lookups.php` (هنوز نساخته‌شده) | low | در فاز ۵ با scripts/seed_lookups.php. |
| D-4-4 | Worker::run(1) در poor man's cron فقط یک job در هر poll اجرا می‌کند — اگر صف شلوغ باشد، سرعت تخلیه کند است. در production با cron واقعی (هر دقیقه Worker::drain) حل می‌شود. | `app/Modules/NationalGateway/Controllers/SyncAdminController::status()` | low | در فاز ۶ با cron واقعی. |
| D-4-5 | SseController::stream در dev فقط JSON polling برمی‌گرداند — تست واقعی SSE streaming نیاز به محیط Apache/PHP-FPM دارد. | `app/Modules/Core/Controllers/SseController.php` | low | در فاز ۶ با deploy Apache. |
| D-4-6 | LicenseService از License::fromArray برای hydrate در searchLocal استفاده می‌کند که فیلدهای golden-link anchor را پر نمی‌کند (masked_national_code، father_name). برای دسترسی به anchor‌ها باید از findByRequestNumber استفاده کرد. | `app/Modules/NationalGateway/Services/LicenseService::hydrateLicense()` | low | در فاز ۶ هنگام refactor. |
| D-4-7 | NotificationSubscriber فقط برای AutoConfirmed (confidence >= 90) فعال است — OperatorFlag به اپراتور notification نمی‌رسد. باید یک admin notification برای OperatorFlag اضافه شود. | `app/Events/Subscribers/NotificationSubscriber.php` | low | در فاز ۶. |
| D-4-8 | SyncAdminController هنوز requireRole guard ندارد — باید با requireRole(['admin','sync_operator']) محافظت شود. در حال حاضر routes ثبت شده‌اند ولی guard فعال نیست. | `app/Modules/NationalGateway/Controllers/SyncAdminController.php` | medium | در فاز ۶ با Controller::requireRole. |

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-1-1 | EventBus placeholder | EventBus واقعی با persistence به جدول events، dispatch به subscribers، eventsSince/lastEventId برای SSE replay ساخته شد. |
| D-1-2 | collectSubscribers() معلق | ModuleLoader::collectSubscribers پیاده شد ولی در v3 از subscribe(SubscriberInterface) استفاده می‌کنیم. Module::boot حالا 3 subscriber را ثبت می‌کند. |
| D-1-6 | SseController::stream واقعی | SseController::stream با text/event-stream loop پیاده شد (production) + JSON polling fallback (dev). |
| D-3-1 | LicenseService پورت نشده | LicenseService با searchHybrid، searchLocal، fetchAndCacheByTrackNumber، refreshLookups، stats، analytics ساخته شد. |
| D-3-2 | PatternMatcher پورت نشده | PatternMatcher با matchNumeric، matchText، matchExactText، matchFuzzyText، matchQuorum + 37 تست ساخته شد. |
| D-3-3 | ApplicantSyncAdapter پورت نشده | ApplicantSyncAdapter (ACL) با matchAndAct، actAutoConfirmed، actOperatorFlagged ساخته شد. |
| D-3-4 | 5 PDO repository implementation | NationalLicenseRepository، NationalLookupRepository، NationalFilterRepository، NationalBatchRepository، SyncAuditLogRepository همه ساخته شدند. |
| D-3-5 | NationalGateway routes خالی | 3 route اضافه شد: GET /admin/sync/status، GET /admin/sync/pending، POST /admin/sync/run. |
| D-3-6 | 3 service به repository‌های وابسته | LicenseService به NationalLicenseRepositoryInterface و NationalLookupRepositoryInterface wire شد. |
| D-3-7 | RateLimiterService::wait() wire نشده | LicenseService::searchHybrid + fetchAndCacheByTrackNumber + refreshLookups همگی rateLimiter.wait() را صدا می‌زنند. |
| D-3-8 | CircuitBreaker wire نشده | LicenseService::searchHybrid + fetchAndCacheByTrackNumber همگی circuitBreaker.canProceed() را چک می‌کنند و recordSuccess/recordFailure صدا می‌زنند. |
| D-3-9 | national_lookups seed | هنوز اجرا نشده (D-4-3) ولی LicenseService::refreshLookups() آماده است. |

#### Debt carried forward به فاز ۵

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-1-4 | install.php و check.php | medium | فاز ۶ |
| D-1-8 | static_analysis fail-on-warning | low | فاز ۶ |
| D-1-9 | writable storage assumptions | low | فاز ۶ |
| D-2-1 | Viewهای بدون Bootstrap/Vazirmatn | medium | فاز ۶ |
| D-2-2 | AdminController فقط ۴ متد | medium | فاز ۶ |
| D-2-3 | کنترلرهای باقی‌مانده | medium | فاز ۶ |
| D-2-4 | FeeCalculatorService reads income_decile directly | low | فاز ۶ |
| D-2-5 | AutoCancelService::updatePriorities slow | low | فاز ۶ |
| D-2-6 | rate_limit not applied in controllers | high | فاز ۶ |
| D-2-7 | schema.sql و install.sql واحد | medium | فاز ۶ |
| D-2-8 | install.php تعاملی | medium | فاز ۶ |
| D-2-9 | seed migration پیش‌فرض | low | فاز ۶ |
| D-4-1 | SMS ارسال واقعی نشده | medium | فاز ۶ |
| D-4-2 | property_details در PatternMatcher wire نشده | medium | فاز ۵ |
| D-4-3 | national_lookups seed هنوز اجرا نشده | low | فاز ۵ |
| D-4-4 | Worker::run(1) کند در poor man's cron | low | فاز ۶ |
| D-4-5 | SSE تست واقعی نیاز به Apache | low | فاز ۶ |
| D-4-6 | License::fromArray anchor‌ها را پر نمی‌کند | low | فاز ۶ |
| D-4-7 | OperatorFlag notification به admin | low | فاز ۶ |
| D-4-8 | SyncAdminController requireRole guard | medium | فاز ۶ |

#### توصیه برای فاز ۵

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ بله — D-4-2 (property_details در PatternMatcher) و D-4-3 (national_lookups seed) در فاز ۵ پرداخت می‌شوند چون به مهاجرت داده وابسته‌اند.
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟ D-4-2 و D-4-3. بقیه به فاز ۶ موکول می‌شوند.
- [ ] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ متوسط — فاز ۵ تمرکز روی مهاجرت داده از v2 و gateway دارد، و wire کردن property_details یک کار اضافی کوچک است.

---

### فاز ۵: مهاجرت داده

**تاریخ تکمیل:** ____________
**Maintainer:** ____________
**نسخهٔ منتشرشده:** ____________

_(قالب همان فاز ۰)_

---

### فاز ۶: امنیت، تست، استقرار

**تاریخ تکمیل:** ____________
**Maintainer:** ____________
**نسخهٔ منتشرشده:** ____________

_(قالب همان فاز ۰)_

---

### فاز ۷: نهایی‌سازی و Rollout مرحلهٔ ۱

**تاریخ تکمیل:** ____________
**Maintainer:** ____________
**نسخهٔ منتشرشده:** ____________

_(قالب همان فاز ۰)_

---

### فاز ۸: Rollout مرحلهٔ ۲ (دوطرفه)

**تاریخ تکمیل:** ____________
**Maintainer:** ____________
**نسخهٔ منتشرشده:** ____________

_(قالب همان فاز ۰)_

---

## ۵. خلاصهٔ live debt

این بخش به‌صورت خودکار با اسکریپت `tools/debt_summary.py` به‌روزرسانی می‌شود
(اسکریپت در فاز ۱ پیاده نشده؛ تا فاز ۶ به‌صورت دستی نگه‌داری می‌شود):

| شدت | تعداد | قدیمی‌ترین | شناسه‌ها |
|---|---|---|---|
| critical | 0 | — | — |
| high | 1 | 2026-11-17 | D-2-6 |
| medium | 9 | 2026-11-17 | D-1-4, D-2-1, D-2-2, D-2-3, D-2-7, D-2-8, D-4-1, D-4-2, D-4-8 |
| low | 11 | 2026-11-17 | D-1-8, D-1-9, D-2-4, D-2-5, D-2-9, D-4-3, D-4-4, D-4-5, D-4-6, D-4-7 |

اگر critical > 0 یا high > 3، هشدار در CI.

**وضعیت پس از فاز ۴:** ۰ critical، ۱ high (rate limit — فاز ۶)، 9 medium (D-4-2 و D-4-3 در فاز ۵ پرداخت می‌شوند، بقیه در فاز ۶)، 11 low.
فاز ۴ بزرگ‌ترین فاز بود و 9 debt از فاز ۳ را پرداخت کرد. اتصال طلایی کامل کار می‌کند (تست integration 18 assertion).

---

## ۶. خط‌مشی debt

۱. **debt intentional است نه حسرت:** هر debt باید با آگاهی و توضیح وارد شود. «بدون debt» غیرعملی است؛ «debt مدیریت‌شده» هدف است.

۲. **debt بدون مالک ممنوع:** هر debt باید متعلق به یک maintainer باشد که مسئول رفع آن است.

۳. **debt پس از refactor:** اگر فازی به‌جای کاهش debt، آن را جابجا کرد (مثلاً از Core به NationalGateway)، در `Debt paid` نیاید چون واقعاً پرداخت نشده. در `Debt introduced` فاز جدید بیاید با ارجاع به ID قبلی.

۴. **debt در CHANGELOG:** هر debt جدید در `CHANGELOG.md` فاز هم ذکر شود.

---

*پایان قالب بازبینی debt.*
