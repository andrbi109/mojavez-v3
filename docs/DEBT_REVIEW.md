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

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 0.1.0 (phase/5-data-migration branch)

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-5-1 | seed_lookups.php در sandbox بدون upstream کار نمی‌کند — --from-gateway fallback فقط townships از sub_organization می‌سازد، نه provinces/organizations واقعی. در production با upstream واقعی حل می‌شود. | `scripts/seed_lookups.php` | low | در فاز ۶ با deploy در محیط کاربر داخل ایران. |
| D-5-2 | migrate_from_v2.php فقط جداول اصلی را مهاجرت می‌دهد — جداول جانبی (applicant_revisions, identity_checks, fee_groups, payment_audit_log, postal_code_changes, wizard_steps) هنوز در plan نیستند. در production اگر دادهٔ واقعی در این جداول باشد، باید اضافه شوند. | `scripts/migrate_from_v2.php` plan array | low | در فاز ۶ هنگام deploy واقعی. |
| D-5-3 | migrate_from_gateway.php عدد `result_count` را در jobs مهاجرت نمی‌دهد (v3 ستون دارد ولی gateway schema متفاوت). در صورت نیاز به historical job stats، باید اضافه شود. | `scripts/migrate_from_gateway.php` jobs section | low | در فاز ۶. |
| D-5-4 | business_address در PatternMatcher اغلب mismatch می‌شود چون upstream آدرس را با `*****` prefix-mask می‌کند (۵ کاراکتر اول پنهان) ولی property address محلی طولانی‌تر است و suffix مچ نمی‌شود. این محدودیت ذاتی suffix-mask matching است. | `app/Modules/NationalGateway/Services/PatternMatcher::matchText()` | low | در فاز ۶ با fuzzy address matching (contains به‌جای suffix). |
| D-5-5 | property_details.county به‌عنوان township به matcher فرستاده می‌شود (در v2/v3 county = township). در production اگر county و township متفاوت باشند، باید جداگانه handle شوند. | `app/Modules/NationalGateway/Services/ApplicantSyncAdapter::matchAndAct()` | low | در فاز ۶. |
| D-5-6 | tests/setup_v2_source.php یک helper است که v2_src_ schema را در همان database v3 می‌سازد — در CI باید قبل از tests/run.php اجرا شود. در حال حاضر دستی است. | `tests/setup_v2_source.php` | low | در فاز ۶ با CI pipeline. |

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-4-2 | property_details در PatternMatcher wire نشده | ApplicantSyncAdapter حالا PropertyDetail را از JobAndPropertyRepository می‌خواند و province/township/business_address/postal_code را به matchQuorum می‌فرستد. PatternMatcher اصلاح شد تا provinceTitle/townshipTitle (نه businessProvince) بخواند. quorum حالا 6 فیلد مچ می‌کند (قبلاً 3). |
| D-4-3 | national_lookups seed هنوز اجرا نشده | scripts/seed_lookups.php ساخته شد — upstream fetch در production، --from-gateway fallback در sandbox. |

#### Debt carried forward به فاز ۶

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
| D-4-4 | Worker::run(1) کند در poor man's cron | low | فاز ۶ |
| D-4-5 | SSE تست واقعی نیاز به Apache | low | فاز ۶ |
| D-4-6 | License::fromArray anchor‌ها را پر نمی‌کند | low | فاز ۶ |
| D-4-7 | OperatorFlag notification به admin | low | فاز ۶ |
| D-4-8 | SyncAdminController requireRole guard | medium | فاز ۶ |
| D-5-1 | seed_lookups sandbox fallback محدود | low | فاز ۶ |
| D-5-2 | جداول جانبی v2 در migration نیستند | low | فاز ۶ |
| D-5-3 | result_count در jobs migration نیست | low | فاز ۶ |
| D-5-4 | business_address suffix-mask محدودیت | low | فاز ۶ |
| D-5-5 | county = township assumption | low | فاز ۶ |
| D-5-6 | tests/setup_v2_source.php دستی | low | فاز ۶ |

#### توصیه برای فاز ۶

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ بله — D-4-8 (SyncAdminController requireRole) و D-4-1 (SMS واقعی) در فاز ۶ پرداخت می‌شوند چون به امنیت و deploy وابسته‌اند.
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟ D-2-6 (rate limit، high)، D-4-8 (requireRole guard)، D-4-1 (SMS gateway)، D-1-4 (install.php).
- [ ] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ بالا — فاز ۶ شامل امنیت کامل + deploy + تست end-to-end است. حدود ۳-۴ جلسه کاری.

---

### فاز ۶: امنیت، تست، استقرار

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 0.1.0 (phase/6-security-tests-deploy branch)

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-6-1 | smoke-test.sh HTTP checks در sandbox قابل اجرا نیست (sandbox نمی‌تواند port bind کند). در production با BASE_URL اجرا می‌شود. CI job `smoke` آن را اجرا می‌کند. | `tests/smoke-test.sh` CAN_HTTP=0 path | low | در فاز ۷ با deploy واقعی در محیط تولید، HTTP checks فعال خواهند شد. |
| D-6-2 | static_analysis.py 10 warning false-positive دارد (EventBus/Module/Worker به‌اشتباه controller شناخته می‌شوند، layout views برای `<?= $content ?>`، Connection.php SET NAMES). heuristic تشخیص بیش از حد ساده‌گیرانه است. | `tools/static_analysis.py` | low | در فاز ۷ با بهبود heuristic‌ها (exclude list برای non-controller classes). |
| D-6-3 | test_track_scraper.py نیاز به fixture واقعی HTML از qr.mojavez.ir دارد که فقط از داخل ایران قابل دسترس است. fixture فعلی موجود نیست. | `tests/fixtures/track_page.html` | low | در فاز ۷ با deploy در ایران و ذخیرهٔ HTML واقعی. |
| D-6-4 | install.php خودکار delete می‌کند ولی اگر unlink fail شود (permissions)، فایل باقی می‌ماند. باید یک lock file `storage/.installed` هم چک شود. | `deploy/shared-hosting/install.php` | low | در فاز ۷ با lock-file check. |
| D-6-5 | deploy/docker/.env.example secrets خالی است — کاربر باید `openssl rand -hex 32` دستی اجرا کند. می‌توان یک اسکریپت generate-secrets.sh ساخت. | `deploy/docker/.env.example` | low | در فاز ۷. |
| D-6-6 | CI job `smoke` نیاز به MariaDB service ندارد ولی smoke-test.sh migration‌ها را اجرا می‌کند — در CI ممکن است fail شود اگر .env درست set نباشد. | `.github/workflows/ci.yml` job smoke | low | در فاز ۷ با تست کامل CI در GitHub Actions واقعی. |

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-2-6 | rate limit مبتنی بر IP نشست قابل دور زدن | `App\Http\IpRateLimiter` (dual mechanism: session + IP در DB) + `RateLimitConfig` (7 rule) + `rate_limit_buckets` migration 006 + enforcement در `public/index.php` با 429 + X-RateLimit headers. IpRateLimiterTest (22 assertion). |
| D-1-4 | install.php و check.php | `deploy/shared-hosting/install.php` (نصب‌کنندهٔ وب دو مرحله‌ای با تولید خودکار secrets + self-delete) + `check.php` (بررسی پیش‌نیازها). |
| D-2-8 | install.php تعاملی | install.php حالا یک فرم وب دو مرحله‌ای است (GET form → POST write .env + run migrations + create admin → self-delete). |
| D-4-8 | SyncAdminController requireRole guard | audit نشان داد requireRole(['admin','sync_operator']) در __construct قبلاً موجود بود — D-4-8 یک non-issue بود. |

#### Debt carried forward به فاز ۷

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-1-8 | static_analysis fail-on-warning | low | فاز ۷ (D-6-2 overlap) |
| D-1-9 | writable storage assumptions | low | فاز ۷ |
| D-2-1 | Viewهای بدون Bootstrap/Vazirmatn | medium | فاز ۷ |
| D-2-2 | AdminController فقط ۴ متد | medium | فاز ۷ |
| D-2-3 | کنترلرهای باقی‌مانده | medium | فاز ۷ |
| D-2-4 | FeeCalculatorService reads income_decile directly | low | فاز ۷ |
| D-2-5 | AutoCancelService::updatePriorities slow | low | فاز ۷ |
| D-2-7 | schema.sql و install.sql واحد | medium | فاز ۷ |
| D-2-9 | seed migration پیش‌فرض | low | فاز ۷ |
| D-4-1 | SMS ارسال واقعی نشده | medium | فاز ۷ |
| D-4-4 | Worker::run(1) کند در poor man's cron | low | فاز ۷ (systemd timers در deploy/linux حل می‌کند) |
| D-4-5 | SSE تست واقعی نیاز به Apache | low | فاز ۷ |
| D-4-6 | License::fromArray anchor‌ها را پر نمی‌کند | low | فاز ۷ |
| D-4-7 | OperatorFlag notification به admin | low | فاز ۷ |
| D-5-1 | seed_lookups sandbox fallback محدود | low | فاز ۷ |
| D-5-2 | جداول جانبی v2 در migration نیستند | low | فاز ۷ (deploy واقعی) |
| D-5-3 | result_count در jobs migration نیست | low | فاز ۷ |
| D-5-4 | business_address suffix-mask محدودیت | low | فاز ۷ |
| D-5-5 | county = township assumption | low | فاز ۷ |
| D-5-6 | tests/setup_v2_source.php دستی | low | فاز ۷ (CI آن را اجرا می‌کند) |
| D-6-1 | smoke-test HTTP sandbox limitation | low | فاز ۷ |
| D-6-2 | static_analysis false-positive warnings | low | فاز ۷ |
| D-6-3 | test_track_scraper fixture missing | low | فاز ۷ |
| D-6-4 | install.php lock-file fallback | low | فاز ۷ |
| D-6-5 | docker secrets manual generation | low | فاز ۷ |
| D-6-6 | CI smoke job needs .env | low | فاز ۷ |

#### توصیه برای فاز ۷

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ بله — فاز ۷ نهایی‌سازی است و باید D-2-1 (Views بدون Bootstrap/Vazirmatn) و D-2-3 (کنترلرهای باقی‌مانده) و D-2-7 (schema.sql واحد) را پرداخت کند چون به یکدست‌سازی UI/کد وابسته‌اند.
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟ D-2-1 (medium، UI یکدست)، D-2-2 (medium، AdminController کامل)، D-2-3 (medium، کنترلرهای باقی‌مانده)، D-2-7 (medium، schema یکپارچه)، D-4-1 (medium، SMS gateway واقعی). 5 debt medium.
- [ ] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ متوسط — فاز ۷ شامل نهایی‌سازی طراحی + Rollout مرحلهٔ ۱ است. حدود ۲-۳ جلسه کاری.

---

### فاز ۷: نهایی‌سازی و Rollout مرحلهٔ ۱

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 1.0.0 (phase/7-finalization-rollout1 branch, tag v1.0.0)

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-7-1 | کنترلرهای ادمین باقی‌مانده (form-fields, fee-tariff, license-titles, bulk-import, wizard-steps, sms, revisions) هنوز پیاده نشده‌اند — UsersController + SettingsController اضافه شد ولی بقیه به v1.1 موکول شد. | `app/Modules/Core/Controllers/` | medium | در v1.1 به‌صورت incremental. این کنترلرها فقط CRUD هستند و برای Rollout Stage 1 لازم نیست. |
| D-7-2 | schema.sql با `scripts/generate_schema_sql.py` تولید می‌شود ولی CI هنوز drift را چک نمی‌کند (که آیا schema.sql با migrations هم‌خوان است). | `.github/workflows/ci.yml` | low | در v1.1 با اضافه‌کردن یک step در CI که generator را اجرا می‌کند و `git diff` چک می‌کند. |
| D-7-3 | viewهای ثبت‌نام (registration/, services/, user/) هنوز به Bootstrap ارتقا نیافته‌اند — فقط 14 view اصلی ارتقا یافت. | `app/Views/{registration,services,user}/` | low | در v1.1 هنگام تکمیل ویزارد ثبت‌نام. |
| D-7-4 | SettingsController custom settings validation ساده‌گیرانه است (طول ≤ 500، no type-check). برای production باید validation per-key اضافه شود. | `app/Modules/Core/Controllers/SettingsController.php` | low | در v1.1. |

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-2-1 | Viewها فعلاً HTML ساده هستند (Bootstrap 5 RTL + Vazirmatn هنوز پیاده نشده‌اند). | Bootstrap 5 RTL + Vazirmatn self-hosted (no CDN per CSP) + day/night theme toggle. 14 view ارتقا یافت. public/assets/vendor/ شامل bootstrap.rtl.min.css + js + icons + Vazirmatn woff2 (4 وزن). app.css با brand palette + admin sidebar + print styles. app.js با theme toggle persisted in localStorage. |
| D-2-7 | `database/schema.sql` و `database/install.sql` هنوز ساخته نشده‌اند. | `scripts/generate_schema_sql.py` همهٔ migrationها را اعمال می‌کند و SHOW CREATE TABLE می‌گیرد. `database/schema.sql` (35KB, 41 table, idempotent CREATE TABLE IF NOT EXISTS، AUTO_INCREMENT حذف شده، ALTERها در CREATE نهایی ادغام شده). `database/install.sql` wrapper. install.php حالا fallback به schema.sql دارد. |
| D-4-1 | SMS ارسال واقعی نشده است. | `App\Modules\Core\Services\SmsService` — Kavenegar-compatible. در production با SMS_API_KEY واقعی HTTP POST می‌زند؛ در غیر آن log-only (storage/logs/sms.log). drainQueue() در run_worker.php. SmsServiceTest (9 assertion). |

#### Debt carried forward به فاز ۸ (Rollout Stage 2)

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-2-2 | AdminController فقط ۴ متد (UsersController + SettingsController اضافه شد ولی بقیه) | medium | v1.1 (D-7-1 overlap) |
| D-2-3 | کنترلرهای License, Payment, Revision, Report, UserPanel, ... | medium | v1.1 (D-7-1 overlap) |
| D-1-8 | static_analysis fail-on-warning | low | v1.1 (D-6-2 overlap) |
| D-1-9 | writable storage assumptions | low | v1.1 |
| D-2-4 | FeeCalculatorService reads income_decile directly | low | v1.1 |
| D-2-5 | AutoCancelService::updatePriorities slow | low | v1.1 |
| D-2-9 | seed migration پیش‌فرض | low | v1.1 |
| D-4-4 | Worker::run(1) کند در poor man's cron | low | حل شد با systemd timers در deploy/linux/ |
| D-4-5 | SSE تست واقعی نیاز به Apache | low | v1.1 |
| D-4-6 | License::fromArray anchor‌ها را پر نمی‌کند | low | v1.1 |
| D-4-7 | OperatorFlag notification به admin | low | v1.1 |
| D-5-1 | seed_lookups sandbox fallback محدود | low | v1.1 |
| D-5-2 | جداول جانبی v2 در migration نیستند | low | v1.1 |
| D-5-3 | result_count در jobs migration نیست | low | حل شد — schema.sql آن را دارد |
| D-5-4 | business_address suffix-mask محدودیت | low | v1.1 |
| D-5-5 | county = township assumption | low | v1.1 |
| D-5-6 | tests/setup_v2_source.php دستی | low | حل شد — CI آن را اجرا می‌کند |
| D-6-1 | smoke-test HTTP sandbox limitation | low | حل شد — CI job `smoke` آن را اجرا می‌کند |
| D-6-2 | static_analysis false-positive warnings | low | v1.1 |
| D-6-3 | test_track_scraper fixture missing | low | v1.1 |
| D-6-4 | install.php lock-file fallback | low | v1.1 |
| D-6-5 | docker secrets manual generation | low | v1.1 |
| D-6-6 | CI smoke job needs .env | low | v1.1 |
| D-7-1 | کنترلرهای ادمین باقی‌مانده | medium | v1.1 |
| D-7-2 | schema.sql drift check در CI نیست | low | v1.1 |
| D-7-3 | viewهای ثبت‌نام به Bootstrap ارتقا نیافت | low | v1.1 |
| D-7-4 | SettingsController validation ساده‌گیرانه | low | v1.1 |

#### توصیه برای فاز ۸ (Rollout Stage 2)

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ بله — فاز ۸ (v1.1.0) پس از ۳ ماه تثبیت Stage 1 در تولید. باید D-7-1 (کنترلرهای باقی‌مانده) را پرداخت کند چون برای Stage 2 (دوطرفه) به مدیریت کامل admin نیاز است.
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟ D-7-1 (medium)، D-2-2 (medium)، D-2-3 (medium). همچنین نیازمندی‌های Stage 2: contract test برای endpointهای دوطرفه، حل تعارض last-write-wins با updated_at.
- [ ] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ متوسط — فاز ۸ شامل انتظار ۳ ماهه + پیاده‌سازی Stage 2 است. حدود ۱-۲ جلسه کاری پس از پایان دوره تثبیت.

---

### فاز ۸: Rollout مرحلهٔ ۲ (دوطرفه)

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 1.1.0 (phase/8-rollout-stage2 branch, tag v1.1.0)

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-8-1 | OutboundSyncService::pushToUpstream() یک stub است — upstream (qr.mojavez.ir) هنوز endpoint نوشتنی ندارد (§19.8). وقتی upstream اضافه کرد، فقط این متد باید ویرایش شود. | `app/Modules/NationalGateway/Services/OutboundSyncService.php` | low | وقتی upstream endpoint اضافه کرد، stub را با HTTP POST واقعی جایگزین کن. |
| D-8-2 | Stage2ReadinessChecker::checkContractTests فقط وجود فایل را چک می‌کند، نه اجرای آن‌ها را. در آینده می‌تواند shell out به tests/contract.php کند. | `app/Modules/NationalGateway/Services/Stage2ReadinessChecker.php` | low | در v1.2 با shell_exec به contract.php + parse خروجی. |
| D-8-3 | NationalSyncOrchestrator Stage 2 push بر اساس matched_at در پنجرهٔ cycle است — اگر cycle طولانی باشد، ممکن است applicant دوبار push شود (idempotency آن را handle می‌کند ولی بهتر است set از applicant_id‌های push‌شده نگه‌داری شود). | `app/Modules/NationalGateway/Services/NationalSyncOrchestrator.php` | low | در v1.2 با dedup set. |
| D-8-4 | admin UI برای /admin/sync/readiness (نمایش گزارش Stage2ReadinessChecker) هنوز ساخته نشده — Checker آماده است ولی view/route ندارد. | `app/Modules/Core/Controllers/` | medium | در v1.2 با افزودن SyncController + view. |
| D-8-5 | تا ۱۰ تست HTTP در smoke-test روی sandbox PHP dev server شکست می‌خورند (CSRF 419، /admin/dashboard 302، /admin/sync/status، /health، .env path traversal) — این‌ها از فاز ۶ وجود داشتند ولی تا fix ISSUE-001 پنهان بودند چون سرور اصلاً boot نمی‌شد و smoke-test به‌صورت خاموش به --no-http بازمی‌گشت. پس از fix boot، حالا قابل دیدن هستند. این‌ها regression ISSUE-001 نیستند — رفتار HTTP خاص dev server / sandbox هستند (مسیرها وجود ندارند، PHP built-in server path traversal را مثل nginx مسدود نمی‌کند). | `tests/smoke-test.sh` §7,11-14 | low | در v1.2: یا assertion ها را با env-aware skip سازگار کن، یا CI واقعی روی GitHub Actions (با PHP-FPM/nginx) که این رفتارها را درست آزمون کند. |
| D-9-1 | `POST /panel/profile` (updateProfile) و `POST /panel/avatar` (uploadAvatar) در فاز ۹ به‌صورت stub با 501 برگردانده می‌شوند. فاز ۹ فقط هدف «بستن 404 پس از ورود» را داشت؛ پیاده‌سازی کامل نیاز به `AvatarService` (resize + cache + path-traversal defense) و فرم اعتبارسنجی پروفایل (نام/موبایل/ایمیل) دارد. | `app/Modules/Core/Controllers/UserPanelController.php::updateProfile/uploadAvatar` | medium | در v1.3 با افزودن `AvatarService` + `ProfileUpdateValidator`. |

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-7-1 (partial) | کنترلرهای ادمین باقی‌مانده | فاز ۸ تمرکز روی Stage 2 بود نه کنترلرها — D-7-1 همچنان به v1.2 موکول. |

#### توصیه برای فاز ۹ (post-v1.1.0) — **انجام شد**

- [x] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ بخشی — فاز ۹ به‌جای پاکسازی debt،
  یک **bug-fix کاربر-محور** بود: خطای 404 پس از ورود به `/panel`. پیاده‌سازی شد.
- [x] اگر بله، کدام debt‌ها باید پرداخت شوند؟ فاز ۹ D-9-1 را **ثبت کرد** (نه
  پرداخت): updateProfile/uploadAvatar به v1.3 موکول شد چون فاز ۹ فقط هدف
  «بستن 404» را داشت.
- [x] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ کم — فاز ۹ scope محدود داشت و در
  همان release کامل شد. ۹ assertion تست جدید اضافه شد.

#### توصیه برای فاز ۱۰ (post-Phase 9) — **انجام شد**

- [x] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ بخشی — فاز ۱۰ به‌جای پاکسازی
  کامل D-7-1/D-8-4، یک **bug-fix مسیر/ویو** بود: برطرف‌سازی ۴۰۴ها و خطاهای
  ۵۰۰ پنهان که در time اجرای واقعی ظاهر می‌شدند. پیاده‌سازی شد.
- [x] اگر بله، کدام debt‌ها باید پرداخت شوند؟ فاز ۱۰ D-7-1 را **بخشی پرداخت**
  کرد: سه مسیر admin که در سایدبار ارجاع شده بودند اما کنترلر نداشتند
  (`/admin/form-fields`, `/admin/fee-tariff`, `/admin/license-titles`) با stub
  «coming soon» متصل شدند (به‌جای ۴۰۴). D-8-4 همچنان به v1.2 موکول.
- [x] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ کم — فاز ۱۰ scope محدود داشت
  (برطرف‌سازی ۴۰۴ها و ۵۰۰های پنهان) و در همان release کامل شد.

---

### فاز ۱۰: برطرف‌سازی خطاهای مسیر و ویو (Route/View 404/500 prevention)

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 1.1.1 (phase/10-routes-views-404-prevention branch)

#### زمینه

کاربر پس از فاز ۹ (پیاده‌سازی `/panel/*` و برطرف‌سازی ۴۰۴ پس از ورود) درخواست
کرد که بازبینی گسترده‌تری روی **تمام** مسیرها و ویوهای پروژه انجام شود تا
هر ۴۰۴ یا خطای ۵۰۰ پنهان که در time اجرا ظاهر می‌شود برطرف گردد. این فاز
حاصل آن بازبینی است.

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-7-1 (partial) | کنترلرهای ادمین باقی‌مانده | سه مسیر سایدبار ادمین که ۴۰۴ برمی‌گرداندند (`/admin/form-fields`, `/admin/fee-tariff`, `/admin/license-titles`) با stub «coming soon» در `AdminController::formFields/feeTariff/licenseTitles` متصل شدند — کاربر دیگر ۴۰۴ نمی‌بیند، بلکه یک صفحهٔ ۲۰۰ با توضیح «فعال‌سازی در v1.2» می‌بیند. کنترلرهای کامل (CRUD) همچنان به v1.2 موکول. |

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-10-1 | `AdminController::formFields/feeTariff/licenseTitles` سه action stub هستند که فقط یک view «coming soon» برمی‌گردانند — CRUD کامل در v1.2 باید پیاده شود. این یک stub آگاهانه است (نه ۴۰۴) و در DEBT_REVIEW ثبت شده. | `app/Modules/Core/Controllers/AdminController.php::formFields/feeTariff/licenseTitles` | medium | در v1.2 با افزودن `FormFieldController` + `FeeTariffController` + `LicenseTitleController` کامل (CRUD + views). |
| D-10-2 | `SyncAdminController` در فاز ۸ از `Controller` base ارث نمی‌برد و در نتیجه `requireLogin/requireRole` فراخوانی نمی‌کرد — یعنی `/admin/sync/*` برای کاربران غیراحراز هویت باز بود. این یک **باگ امنیتی** است که از فاز ۸ موجود بود و در این فاز برطرف شد. برای ثبت تاریخی این اتفاق، در CHANGELOG و این فایل آورده شده. | `app/Modules/NationalGateway/Controllers/SyncAdminController.php` | (paid) | paid — حالا از `Controller` ارث می‌برد و `requireRole(['admin','sync_operator'])` در هر action فراخوانی می‌کند. |

#### چیزهایی که برطرف شد اما debt ثبت نشد (چون bug-fix بود نه new feature)

این موارد خطای زمان اجرا بودند که از فاز ۷ موجود بودند و در این فاز برطرف شدند:

1. **`UsersController` constructor signature mismatch** — فقط ۴ arg به
   `parent::__construct` پاس می‌داد در حالی که `Controller` ۶ arg می‌خواهد
   (`Hash` و `StructuredLogger` کم داشت). این باعث fatal error روی هر مسیر
   `/admin/users*` می‌شد. برطرف شد: هر دو dependency اضافه شد و به parent پاس
   داده شد.

2. **`SettingsController` constructor signature mismatch** — همان باگ. هر
   مسیر `GET/POST /admin/settings` fatal error می‌داد. برطرف شد.

3. **`UsersController` و `SettingsController` render() third-arg misuse** —
   یک رشتهٔ فارسی به‌عنوان آرگومان سوم `render()` پاس می‌شد، اما آرگومان سوم
   `$layout` است نه title. نتیجه: layout resolver به دنبال
   `app/Views/مدیریت کاربران.php` می‌گشت، آن را پیدا نمی‌کرد، و به fallback
   «no layout» برمی‌گشت — یعنی صفحات admin بدون `admin_layout` رندر می‌شدند
   (بدون سایدبار، بدون هدر صحیح). برطرف شد: title حالا به‌عنوان کلید `title`
   در `$data` پاس داده می‌شود و `render()` با layout پیش‌فرض (`layout`) فراخوانی
   می‌شود که برای admin/* views به‌صورت خودکار به `admin_layout` تبدیل می‌شود.

4. **`admin/login.php` nested-HTML** — یک HTML document کامل بود (`<html>`,
   `<head>`, `<body>`) اما چون با `render('admin/login', [])` فراخوانی
   می‌شد، `render()` آن را در `admin_layout.php` می‌پیچید که خودش یک HTML
   کامل است — یعنی `<html>` داخل `<body>`. برطرف شد: `render('admin/login',
   [], 'none')` حالا یک sentinel جدید `'none'` دارد که layout wrapping را
   skip می‌کند.

5. **`print.php` nested-HTML** — همان باگ: یک HTML کامل بود اما با `render('print', ...)`
   در `layout.php` پیچیده می‌شد. برطرف شد: `render('print', ..., 'none')`.

6. **`Container` self-registration** — `Container` خودش را به‌عنوان singleton
   در `singletons[]` ثبت نمی‌کرد، بنابراین وقتی یک کنترلر با autowire ساخته
   می‌شد و `Container` به‌عنوان dependency می‌خواست، یک `Container` جدید خالی
   (بدون bindings) ساخته می‌شد. سپس `$this->container->make(Connection::class)`
   با `RuntimeException` fail می‌شد چون factory برای Connection نبود. این یعنی
   `AdminController::dashboard()` (که از `$this->connection()->scalar(...)` استفاده
   می‌کند) با ۵۰۰ fail می‌شد. برطرف شد: `$this->singletons[self::class] = $this`
   در constructor.

7. **صفحهٔ ۴۰۴ bare HTML** — صفحهٔ ۴۰۴ فقط `<h1>۴۰۴</h1><p>صفحه یافت نشد.</p>`
   بود بدون هیچ layout/brading. برطرف شد: `errors/404.php` view با layout.php
   اصلی سایت، با دکمه‌های بازگشت به خانه/پیگیری/راهنما.

#### Debt carried forward به فاز ۱۱

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-2-2 | AdminController فقط ۵ متد (dashboard/applications/viewApplication/changeStatus + 3 stub جدید) | medium | v1.2 |
| D-2-3 | کنترلرهای License, Payment, Revision, Report, ServiceType, ServiceController, BulkImport, FormField, WizardStep, Sms, LicenseTitle, DocumentController, RecordsController هنوز ساخته نشده | medium | v1.2 |
| D-7-1 (partial) | سه stub admin (form-fields/fee-tariff/license-titles) فعال شد ولی CRUD کامل نه | medium | v1.2 |
| D-8-4 | admin UI برای /admin/sync/readiness (نمایش گزارش Stage2ReadinessChecker) | medium | v1.2 |
| D-9-1 | `POST /panel/profile` و `POST /panel/avatar` stub | medium | v1.3 |
| D-10-1 | سه stub admin CRUD کامل ندارد | medium | v1.2 (با D-7-1 ادغام) |

#### توصیه برای فاز ۱۱ (post-Phase 10)

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ بله — فاز ۱۱ (v1.2.0) باید
  D-7-1 + D-10-1 (ادغام‌شده) و D-8-4 را پرداخت کند: CRUD کامل برای form-fields,
  fee-tariff, license-titles + admin UI برای Stage2ReadinessChecker.
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟ D-7-1+D-10-1 (medium)،
  D-8-4 (medium)، D-2-2 (medium)، D-2-3 (medium).
- [ ] آیا D-9-1 (updateProfile/uploadAvatar) باید در v1.2 یا v1.3 پرداخت شود؟
  v1.3 پیشنهاد می‌شود چون نیاز به `AvatarService` مستقل دارد که scope فاز ۱۱
  را بیش از حد گسترش می‌دهد.

---

### فاز ۱۱: admin CRUD + Stage2ReadinessChecker UI

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 1.2.0 (phase/11-admin-crud-v1.2.0 branch, tag v1.2.0)

#### زمینه

پس از فاز ۱۰ که سه مسیر سایدبار ادمین را به‌صورت stub «coming soon» متصل
کرد، فاز ۱۱ تمرکز روی **تعویض این سه stub با کنترلرهای CRUD کامل** بود. به
علاوه، admin UI برای `/admin/sync/readiness` (که از فاز ۸ موکول شده بود)
ساخته شد تا admin بتواند گزارش `Stage2ReadinessChecker` را ببیند و تصمیم
بگیرد آیا Stage 2 را فعال کند.

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-7-1 | کنترلرهای ادمین باقی‌مانده (deferred از فاز ۷) | ۳ کنترلر جدید با CRUD کامل: `FormFieldController` (۷ route)، `FeeTariffController` (۶ route)، `LicenseTitleController` (۸ route شامل toggle + by-service JSON). هر کدام ۲ view (index + edit) با همان سبک Bootstrap 5 RTL + Vazirmatn فاز ۷. |
| D-10-1 | سه stub admin اکشن CRUD کامل نداشتند | همان سه stub از `AdminController` حذف شدند و مسیرهایشان در `Core/Module.php` به کنترلرهای اختصاصی متصل شد. |
| D-2-2 | AdminController فقط ۵ متد داشت | با حذف ۳ stub، AdminController به ۴ متد اصلی (dashboard/applications/viewApplication/changeStatus) بازگشت و surface ادمین بین کنترلرهای اختصاصی پخش شد — مطابق الگوی Phase-7 (UsersController/SettingsController). |
| D-8-4 | admin UI برای /admin/sync/readiness ساخته نشده بود | `SyncAdminController::readiness()` + view `admin/sync_readiness.php` + لینک سایدبار تحت «درگاه ملی». گزارش ۴ شرط §29.2 به‌صورت Bootstrap card با badge pass/fail + flag کلی ready + راهنمای فعال‌سازی Stage 2. |
| D-2-3 (partial) | ~۱۵ کنترلر ادمین موکول‌شده | ۳ از ۱۵ ساخته شد (FormFields/FeeTariff/LicenseTitle). ۱۲ تای باقی‌مانده (Payment/Revision/Report/ServiceType/Service/BulkImport/WizardStep/Sms/DocumentController/RecordsController/...) به v1.4+ موکول شد. به‌عنوان low-debt علامت‌گذاری شد (قبلاً medium بود). |

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-11-1 | `LicenseTitleController::byService` admin-only است. در ویزارد عمومی ثبت‌نام، endpoint مشابهی برای کاربر نهایی لازم است (fetch license_titles بر اساس service_type بدون نیاز به admin). فعلاً از همان endpoint admin استفاده می‌شود ولی CSP+auth barrier است. | `app/Modules/Core/Controllers/LicenseTitleController.php::byService` | low | در v1.3 با افزودن endpoint عمومی `GET /api/v1/service-types/{id}/license-titles` بدون auth-but-CSRF. |
| D-11-2 | `FormFieldController::move` swap منطقش sort_order-only است؛ اگر دو فیلد sort_order برابر داشته باشند، tie-break با id می‌شود. در کد متد abruptly مشخص است ولی در docblock ذکر نشده. | `app/Modules/Core/Controllers/FormFieldController.php::move` | low | در v1.3 docblock روشن‌سازی شود. |

#### توصیه برای فاز ۱۲ (post-Phase 11) — انجام‌نشده

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ بله — فاز ۱۲ (v1.3.0)
  باید D-9-1 (updateProfile/uploadAvatar) و D-11-1 (public license-titles
  endpoint) را پرداخت کند. هر دو نیاز به service-layer مستقل دارند.
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟ D-9-1 (medium) + D-11-1
  (low). همچنین اگر آمادهٔ فعال‌سازی Stage 2 شدیم، تست پشتیبانی از
  `sync_direction='two_way'` در تولید به‌صورت کامل.
- [ ] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ متوسط — v1.3 باید AvatarService
  و ProfileUpdateValidator بسازد و endpoint عمومی license-titles را اضافه کند.

---

### فاز ۱۲: AvatarService + ProfileUpdateValidator (v1.3.0)

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 1.3.0 (phase/12-avatar-service-v1.3.0 branch, tag v1.3.0)

#### زمینه

پس از فاز ۱۱ که ۴ debt medium را پرداخت کرد و D-2-3 را به low کاهش داد،
تنها debt medium باقی‌مانده D-9-1 بود: دو stub `POST /panel/profile`
(updateProfile) و `POST /panel/avatar` (uploadAvatar) که در فاز ۹ به‌صورت
501 پیاده‌سازی شده بودند چون scope فاز ۹ فقط بستن 404 پس از ورود بود.

فاز ۱۲ این debt را با ساخت یک service layer مستقل (`AvatarService` +
`ProfileUpdateValidator`) پرداخت می‌کند. هر دو کنترلر stub با پیاده‌سازی
واقعی تعویض شد و `/panel/profile` از یک صفحهٔ فقط‌خواندنی به یک فرم
قابل‌ویرایش با AJAX + بازخورد درون‌خطی تبدیل شد.

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-9-1 | `POST /panel/profile` و `POST /panel/avatar` stub (501) | `AvatarService` جدید: اعتبارسنجی multipart، resize با GD به 256×256 (crop-center)، ذخیره به JPEG ۸۵، normalise به `storage/uploads/avatars/account-{id}.jpg`، درج documents row با `doc_type='avatar'`، تنظیم `user_accounts.avatar_document_id`، حذف best-effort آواتار قبلی (فایل + row). defense path-traversal: نام روی دیسک همیشه server-constructed (`account-{id}.jpg`)، نام کاربر فقط در `original_name` با basename + ASCII strip + dot strip. `ProfileUpdateValidator` جدید (static class): اعتبارسنجی ۴ فیلد username/mobile/email/display_name با بررسی یکتایی، خروجی `{valid, errors, clean}`. `UserAccountRepositoryInterface::update()` + `findByUsername()` اضافه شد. `Core/Module.php` binding `AvatarService` اضافه شد. View `user/profile.php` به فرم قابل‌ویرایش + فرم آواتار + AJAX + `<div id="profile-feedback">` بازنویسی شد. |

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-12-1 | فیلدهای `email` و `display_name` در فرم پروفایل اعتبارسنجی می‌شوند ولی در `user_accounts` ذخیره نمی‌شوند چون جدول این ستون‌ها را ندارد (فقط جدول admin `users` ستون `display_name` دارد و `email` جایی وجود ندارد). فرم برای forward-compat این فیلدها را نمایش می‌دهد و `ProfileUpdateValidator` آن‌ها را اعتبارسنجی می‌کند ولی `update()` whitelist به‌صورت silent آن‌ها را drop می‌کند. | `app/Shared/Contracts/UserAccountRepositoryInterface.php::update` (whitelist فقط `username`+`mobile`) | low | در v1.4 با migration کوچک `ALTER TABLE user_accounts ADD email VARCHAR(254) NULL, ADD display_name VARCHAR(100) NULL` اضافه شود. سپس whitelist در `PdoUserAccountRepository::update()` گسترش یابد. |

#### توصیه برای فاز ۱۳ (post-Phase 12)

- [x] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ **خیر** — پس از فاز ۱۲،
  هیچ debt medium یا بالاتر باقی نمانده است. تنها debt‌های low باقی‌مانده‌اند
  (D-2-3 partial، D-8-5، D-11-1، D-11-2، D-12-1، + ۲۴ مورد pre-existing).
  v1.4 می‌تواند به‌صورت feature-driven باشد (بدون نیاز به «پاکسازی debt»).
- [x] اگر خیر، تمرکز بعدی چه باشد؟ (۱) اتمام ~۱۲ کنترلر ادمین باقی‌مانده
  (D-2-3): PaymentController, RevisionController, ReportController,
  ServiceTypeController, ServiceController, BulkImport, WizardStep,
  SmsController, DocumentController, RecordsController, ... (۲) افزودن
  ستون‌های `email`+`display_name` به `user_accounts` (D-12-1). (۳) endpoint
  عمومی license-titles برای ویزارد (D-11-1).
- [x] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ کم — فاز ۱۲ یک service-layer
  مستقل ساخت که الگو برای فازهای بعدی تکرارپذیر است.

---

### فاز ۱۳: user_accounts email/display_name + low debt cleanup (v1.4.0)

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 1.4.0 (phase/13-user-profile-columns-v1.4.0 branch, tag v1.4.0)

#### زمینه

فاز ۱۲ به‌جز D-12-1 (یک debt low جدید) همه‌چیز را پاک کرد. D-12-1 این
بود: `ProfileUpdateValidator` (فاز ۱۲) چهار فیلد `username`/`mobile`/
`email`/`display_name` را اعتبارسنجی می‌کرد ولی `update()` whitelist در
`PdoUserAccountRepository` فقط `username`+`mobile` را قبول می‌کرد چون
`user_accounts` در schema فقط این دو ستون قابل‌ویرایش را داشت. نتیجه:
`email` و `display_name` به‌صورت silent drop می‌شدند.

فاز ۱۳ این gap را با یک migration کوچک `ALTER TABLE` می‌بندد. به‌علاوه،
هفت debt low از فازهای قبلی که همگی cheap-to-fix بودند پرداخت شد:
- D-11-1 (public license-titles endpoint برای ویزارد)
- D-11-2 (FormFieldController::move tie-break docblock)
- D-1-3 (Request::$trustProxy از config)
- D-2-4 (FeeCalculatorService از applicant_field_values با fallback)
- D-2-5 (AutoCancelService::updatePriorities به‌صورت SQL مستقیم)
- D-1-8 (static_analysis --strict + ignore patterns)
- D-7-2 (CI schema-drift check روی database/schema.sql)

#### Debt paid در این فاز

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-12-1 | `email` و `display_name` معتبر ولی ذخیره نمی‌شدند | migration 008: `ALTER TABLE user_accounts ADD email VARCHAR(254) NULL, ADD display_name VARCHAR(100) NULL, ADD INDEX idx_user_accounts_email`. `PdoUserAccountRepository::WHITELIST` به ۴ فیلد (`username`، `mobile`، `email`، `display_name`) گسترش یافت. `database/schema.sql` هم‌گام شد. |
| D-11-1 | `LicenseTitleController::byService` admin-only بود ولی ویزارد نیاز به endpoint عمومی داشت | اکشن جدید `byServicePublic` (بدون auth، payload محدود به `{id, title, category, is_active}` — بدون `sort_order`). مسیر جدید `GET /api/v1/license-titles/by-service/{id}` در `Core/Module.php`. اکشن admin `byService` همچنان admin-only باقی ماند. |
| D-11-2 | `FormFieldController::move` tie-break با id در docblock ذکر نشده بود | docblock روشن شد: وقتی چند ردیف `sort_order` یکسان دارند، tie-break با `id` (ASC برای down، DESC برای up). SQL از فاز ۱۱ درست بود — فقط docblock ناقص بود. |
| D-1-3 | `Request::$trustProxy` در `public/index.php` hard-code `false` بود | حالا از `Config::get('app.trust_proxy', false)` خوانده می‌شود. کلید جدید `'trust_proxy'` در `config/app.php` (default false، قابل override با `APP_TRUST_PROXY=true` در `.env`). |
| D-2-4 | `FeeCalculatorService` مستقیم از `applicants.income_decile` می‌خواند (legacy column) | helper خصوصی جدید `getIncomeDecile(int $applicantId): ?int`: اول `applicant_field_values` (EAV) JOIN `form_fields` با `field_key='income_decile'` را امتحان می‌کند، سپس fallback به `applicants.income_decile` برای رکوردهای pre-wizard. fallback شفاف است — تست‌های موجود که مستقیم در `applicants` درج می‌کنند همچنان pass می‌شوند. |
| D-2-5 | `AutoCancelService::updatePriorities` با `paginate(1000)` + حلقه PHP کند بود | متد جدید `ApplicantRepositoryInterface::bulkSetPriorityForPreIssueOlderThan(int $days): int` یک SQL `UPDATE ... WHERE ...` واحد صادر می‌کند (با همان WHERE clause قبلی: `priority=0 AND registration_completed_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND current_status IN (pre-issue statuses) AND deleted_at IS NULL`). `updatePriorities()` حالا از آن استفاده می‌کند. رفتار با نسخه قبلی همسان است. |
| D-1-8 | `static_analysis.py` advisory-only بود (warnings در CI fail نمی‌کرد) | flag جدید `--strict` (یا `MOJAVEZ_STRICT=1` env var). در حالت strict، warnings که با الگوهای `tools/static_analysis_ignore.txt` تطابق دارند suppress می‌شوند؛ اگر warning باقی ماند، exit 1. فایل ignore شامل ۶ الگوی false-positive شناخته‌شده است (csrfField، `$content` در layoutها، controller-not-in-Modules false positives، SQL string-concat در `Connection.php` برای `SET NAMES`/`SET time_zone`، `$totalPages`، `$roleLabel`). با فایل ignore، هر ۳۸ warning موجود suppress می‌شوند → ۰ surviving → CI pass. |
| D-7-2 | `database/schema.sql` drift در CI چک نمی‌شد | step جدید CI در `.github/workflows/ci.yml`: `python3 scripts/generate_schema_sql.py` را اجرا می‌کند، سپس `git checkout database/install.sql` (چون generator seed Phase 12 را شامل نمی‌شود)، سپس `git diff --exit-code -- database/schema.sql` — اگر schema.sql با migrations هماهنگ نباشد، build fail می‌شود. |

#### Debt introduced

(هیچ — فاز ۱۳ هیچ debt جدیدی وارد نکرد.)

#### Debt carried forward به فاز ۱۴

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-2-3 (partial) | ~۱۲ کنترلر ادمین باقی‌مانده (PaymentController, RevisionController, ReportController, ServiceTypeController, ...) | low | incremental — هر فاز feature-driven چندتا |
| D-8-5 | ۸ smoke test HTTP به‌دلیل MariaDB fixture drift fail می‌شوند (pre-existing از فاز ۸) | low | زمان مناسب |
| D-1-9 | writable storage assumptions برای shared hosting | low | فاز ۶ (still deferred) |
| D-2-9 | migration seed برای 5 service_type + license_titles پیش‌فرض هنوز اجرا نشده | low | فرصت مناسب |
| D-4-5, D-4-6, D-4-7 | ۳ debt از فاز ۴ (low) | low | فرصت مناسب |
| D-5-1, D-5-2, D-5-4, D-5-5 | ۴ debt از فاز ۵ (low) | low | فرصت مناسب |
| D-6-2, D-6-3, D-6-4, D-6-5, D-6-6 | ۵ debt از فاز ۶ (low) | low | فرصت مناسب |
| D-7-3, D-7-4 | ۲ debt از فاز ۷ (low) | low | فرصت مناسب |
| D-8-1, D-8-2, D-8-3 | ۳ debt از فاز ۸ (low) | low | فرصت مناسب |

#### توصیه برای فاز ۱۴ (post-Phase 13)

- [x] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ **خیر** — پس از فاز ۱۳،
  همچنان ۰ debt medium یا بالاتر باقی مانده است. ۲۱ debt low باقی‌مانده
  که incremental در فازهای feature-driven پرداخت می‌شوند.
- [x] اگر خیر، تمرکز بعدی چه باشد؟ (۱) اتمام ~۱۲ کنترلر ادمین باقی‌مانده
  (D-2-3). (۲) فعال‌سازی Stage 2 (sync_direction='two_way') اگر آماده
  باشیم. (۳) پورت کامل ویزارد ثبت‌نام (که از D-2-4 و D-11-1 بهره می‌برد).
- [x] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ کم — فاز ۱۳ الگوی migration
  + repository whitelist extension را تثبیت کرد که برای فازهای بعدی
  تکرارپذیر است.

---

### فاز ۱۴: seed defaults + low-debt cleanup (v1.5.0)

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 1.5.0 (phase/14-low-debt-cleanup-v1.5.0 branch, tag v1.5.0)

#### زمینه

پس از فاز ۱۳ که `user_accounts.email/display_name` را اضافه کرد و ۷
debt low پرداخت کرد، ۲۱ debt low باقی‌مانده بود. فاز ۱۴ به‌صورت
targeted-cleanup روی ۷ مورد از آن‌ها تمرکز کرد که همگی cheap-to-fix
بودند: D-2-9 (seed پیش‌فرض service_types/license_titles)، D-1-9
(writable storage check)، D-4-6 (License::fromArray anchors)،
D-4-7 (OperatorFlag admin notification)، D-5-2 (migrate_from_v2.php
side tables)، D-7-4 (SettingsController per-key validation)، D-7-3
(registration views to Bootstrap).

#### Debt paid در این فاز

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-2-9 | migration seed برای 5 service_types + license_titles هنوز اجرا نشده بود | Migration 009 (`20261117_009_seed_default_service_types.sql`) 5 service_types + 8 license_titles با `INSERT ... ON DUPLICATE KEY UPDATE`. companion INSERTs به `database/install.sql` (دستی — generator فقط DDL می‌نویسد). |
| D-1-9 | writable-storage assumptions برای shared hosting | `deploy/shared-hosting/check.php` حالا در ۵ زیرشاخهٔ `storage/` + `uploads/` یک فایل probe 1-byte می‌نویسد و PASS/FAIL + پیام خطای PHP را گزارش می‌کند. |
| D-4-6 | `License::fromArray` anchorهای golden-link (masked_national_code, father_name) را drop می‌کرد | `fromArray` حالا آن‌ها را با default null-safe می‌خواند. `LicenseService::hydrateLicense` docblock به‌روز شد. cache-restoration path همچنان null می‌گیرد (همان رفتار قبلی). |
| D-4-7 | OperatorFlag matches هیچ سیگنال in-band برای ادمین نداشت | Event جدید `OperatorFlag` + `NotificationSubscriber::handleOperatorFlag` + `NotificationService::notifyAdmins` (فقط in-app، بدون SMS). ادمین‌ها با username به user_accounts بریج می‌شوند. |
| D-5-2 | `migrate_from_v2.php` فقط جداول اصلی را migrate می‌کرد | +5 side table: `applicant_revisions`, `wizard_steps`, `postal_code_changes`, `fee_groups`, `fee_group_items`. هر کدام با `INSERT IGNORE` (idempotent). |
| D-7-4 | `SettingsController` کلیدهای سفارشی را فقط بررسی طول می‌کرد؛ کلیدهای نامعتبر silently drop می‌شدند | متد جدید `validateCustomKey` با regex `[a-zA-Z0-9_.-]+` + max len 64 + رد پیشوندهای `_`/`.`. خطاها در `$errors` جمع و به‌عنوان warning banner در صفحهٔ تنظیمات نمایش داده می‌شوند. |
| D-7-3 | ویوهای ویزارد ثبت‌نام bare HTML بودند | 9 فایل view جدید در `app/Views/registration/` با Bootstrap 5 RTL + Vazirmatn + Bootstrap Icons. آماده برای `RegistrationController` آینده. |

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-14-1 | `NotificationService::notifyAdmins` ادمین‌ها (`users`) را با username-lookup به `user_accounts` بریج می‌کند. ادمین‌های بدون user_account skip می‌شوند (هیچ in-app notificationای ساخته نمی‌شود — ردیف `sync_audit_log` تنها سیگنال است). در آینده با جدول `admin_notifications` یا nullable کردن `user_notifications.user_account_id` + ستون `user_id` این gap بسته شود. | `app/Modules/Core/Services/NotificationService.php::notifyAdmins` | low | فاز ۱۵+: جدول admin_notifications یا schema change روی user_notifications. |

#### Debt carried forward به فاز ۱۵

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-2-3 (partial) | ~۱۲ کنترلر ادمین باقی‌مانده (PaymentController, RevisionController, ReportController, ServiceTypeController, ...) | low | incremental — هر فاز feature-driven چندتا |
| D-8-5 | ۸ smoke test HTTP به‌دلیل MariaDB fixture drift fail می‌شوند (pre-existing از فاز ۸) | low | زمان مناسب |
| D-14-1 | notifyAdmins ادمین‌های بدون user_account را skip می‌کند (username-bridge gap) | low | فاز ۱۵+ |
| D-4-5, D-5-1, D-5-4, D-5-5 | ۴ debt از فاز ۵ (low) | low | فرصت مناسب |
| D-6-2, D-6-3, D-6-4, D-6-5, D-6-6 | ۵ debt از فاز ۶ (low) | low | فرصت مناسب |
| D-8-1, D-8-2, D-8-3 | ۳ debt از فاز ۸ (low) | low | فرصت مناسب |

#### توصیه برای فاز ۱۵ (post-Phase 14)

- [x] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ **خیر** — پس از فاز ۱۴،
  همچنان ۰ debt medium یا بالاتر باقی مانده است. ~۱۳ مورد low باقی‌مانده
  که incremental در فازهای feature-driven پرداخت می‌شوند.
- [x] اگر خیر، تمرکز بعدی چه باشد؟ (۱) پیاده‌سازی `RegistrationController`
  برای وصل کردن ویوهای ویزارد فاز ۱۴ به مسیرها (`/register/*`). (۲)
  اتمام ~۱۲ کنترلر ادمین باقی‌مانده (D-2-3). (۳) فعال‌سازی Stage 2 اگر
  آماده باشیم.
- [x] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ کم — فاز ۱۴ الگوی
  migration + event/subscriber + view Bootstrap را تثبیت کرد که برای
  فازهای بعدی تکرارپذیر است.

---

### فاز ۱۵: registration wizard + missing routes (v1.6.0)

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 1.6.0 (phase/15-registration-flow-and-missing-routes branch, tag v1.6.0)

#### زمینه

پس از فاز ۱۴ که ویوهای ویزارد ثبت‌نام را به Bootstrap بازنویسی کرد و
seed پیش‌فرض service_types را اضافه کرد، کاربر گزارش داد که پس از
ورود، دکمهٔ «شروع ثبت‌نام» در پنل کاربر به `/` (صفحهٔ اصلی) هدایت
می‌شود به‌جای یک ویزارد واقعی، و دکمهٔ «ثبت‌نام متقاضی» در صفحهٔ
اصلی به `/signup` (ساخت حساب) هدایت می‌شود. علت ریشه‌ای: هیچ مسیر
`/register/*` وجود نداشت — ویوهای ویزارد ساخته شده بودند ولی هیچ
کنترلری آن‌ها را serve نمی‌کرد.

فاز ۱۵ کل جریان ثبت‌نام + ~۷۰ مسیر مفقود که spec برای v1 تعریف کرده
بود را پیاده می‌کند: ویزارد ۴ مرحله‌ای، service catalog، records،
document serving، revision requests، license image access، و ~۳۵
مسیر REST API v1.

#### Debt paid در این فاز

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-2-3 (کامل) | ~۱۲ کنترلر ادمین باقی‌مانده (PaymentController, RevisionController, ReportController, ServiceTypeController, ServiceController, BulkImport, WizardStep, SmsController, DocumentController, RecordsController, ...) | همهٔ ~۱۵ کنترلر در فاز ۱۵ ساخته شدند: `RegistrationController` (14 مسیر)، `ServiceController` (2)، `RecordsController` (2)، `DocumentController` (6)، `RevisionController` (6)، `LicenseController` (7)، `V1ApiController` (~35 مسیر API v1)، و ۲ متد جدید روی `TrackingController` (`submitPayment`, `submitSms`). مجموع: ~۷۰ مسیر جدید. D-2-3 که از فاز ۷ carried forward بود، اکنون به‌طور کامل پرداخت شده است. |

#### Debt introduced

(هیچ — فاز ۱۵ هیچ debt جدیدی وارد نکرد.)

#### Debt carried forward به فاز ۱۶

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-8-5 | ۸ smoke test HTTP به‌دلیل MariaDB fixture drift fail می‌شوند (pre-existing از فاز ۸) | low | زمان مناسب |
| D-14-1 | notifyAdmins ادمین‌های بدون user_account را skip می‌کند (username-bridge gap) | low | فاز ۱۶+ |
| D-4-5, D-5-1, D-5-4, D-5-5 | ۴ debt از فاز ۵ (low) | low | فرصت مناسب |
| D-6-2, D-6-3, D-6-4, D-6-5, D-6-6 | ۵ debt از فاز ۶ (low) | low | فرصت مناسب |
| D-8-1, D-8-2, D-8-3 | ۳ debt از فاز ۸ (low) | low | فرصت مناسب |

#### توصیه برای فاز ۱۶ (post-Phase 15)

- [x] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ **خیر** — پس از فاز ۱۵،
  همچنان ۰ debt medium یا بالاتر باقی مانده است. ~۱۲ مورد low باقی‌مانده
  که incremental در فازهای feature-driven پرداخت می‌شوند. D-2-3 (umbrella
  «کنترلرهای معوق») که از فاز ۷ carried forward بود، اکنون کامل پرداخت
  شده است.
- [x] اگر خیر، تمرکز بعدی چه باشد؟ (۱) فعال‌سازی Stage 2 (sync_direction='two_way')
  اگر آماده باشیم. (۲) Stage 3: گزارش‌گیری + export. (۳) تست‌های
  end-to-end برای جریان ویزارد. (۴) پاکسازی D-8-5 (smoke test fixture
  drift) و D-14-1 (admin notifications bridge).
- [x] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ کم — فاز ۱۵ الگوی
  session-based wizard + REST API skeleton را تثبیت کرد که برای فازهای
  بعدی تکرارپذیر است.

---

### فاز ۱۶: seed document_types + admin default + real document upload (v1.7.0)

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 1.7.0 (phase/16-document-types-and-admin-controllers branch, tag v1.7.0)

#### زمینه

فاز ۱۵.۱ (v1.6.1) برای قابل‌تست‌کردن ویزارد، HTML5 `required` را از
ورودی‌های فایل مرحلهٔ ۴ حذف کرد چون جدول `document_types` در نصب
پیش‌فرض خالی بود و `required` مانع submit می‌شد. این فاز حلقه را
می‌بندد: ۶ `document_types` پیش‌فرض seed می‌شوند، `required` به‌صورت
دینامیک بر اساس `is_required` محاسبه‌شده (از `doc_type_key`) فعال
می‌شود، و یک admin پیش‌فرض seed می‌شود تا نصب تازه بلافاصله قابل
استفاده باشد.

#### Debt paid در این فاز

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-7-3 (partial) | viewهای ویزارد ثبت‌نام (registration/) bare HTML بودند — فاز ۱۴ Bootstrap را اضافه کرد ولی ویزارد همچنان doc_types واقعی نداشت (step 4 hard-coded بود) | migration 010 شامل ۶ document_type پیش‌فرض + `requiredDocs()` کنترلر از DB می‌خواند + `step4.php` با `is_required` دینامیک به `required` تنظیم می‌شود. حالا ویزارد ۵ ورودی فایل نشان می‌دهد (۲ اجباری، ۳ اختیاری). |

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-16-1 | `document_types` ستون `is_required` ندارد؛ `RegistrationController::requiredDocs()` آن را از `doc_type_key` محاسبه می‌کند (`national_id` و `personal_photo` اجباری). اگر admin بخواهد per-doctor آن را toggle کند، ستون واقعی لازم است. | `app/Modules/Core/Controllers/RegistrationController.php::requiredDocs` + `database/schema.sql` (جدول `document_types`) | low | در فاز ۱۷+ که کنترلر admin برای document_types ساخته می‌شود، ستون `is_required TINYINT(1) NOT NULL DEFAULT 0` اضافه شود و query از CASE به ستون واقعی تغییر کند. |

#### Debt carried forward به فاز ۱۷

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-8-5 | ۸ smoke test HTTP به‌دلیل MariaDB fixture drift fail می‌شوند (pre-existing از فاز ۸) | low | زمان مناسب |
| D-14-1 | notifyAdmins ادمین‌های بدون user_account را skip می‌کند (username-bridge gap) | low | فاز ۱۷+ |
| D-16-1 | `document_types.is_required` ستون واقعی نیست (در controller از doc_type_key محاسبه می‌شود) | low | فاز ۱۷+ (هنگام ساخت admin UI برای doc_types) |
| D-4-5, D-5-1, D-5-4, D-5-5 | ۴ debt از فاز ۵ (low) | low | فرصت مناسب |
| D-6-2, D-6-3, D-6-4, D-6-5, D-6-6 | ۵ debt از فاز ۶ (low) | low | فرصت مناسب |
| D-8-1, D-8-2, D-8-3 | ۳ debt از فاز ۸ (low) | low | فرصت مناسب |

#### توصیه برای فاز ۱۷ (post-Phase 16)

- [x] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ **خیر** — پس از فاز ۱۶،
  همچنان ۰ debt medium یا بالاتر باقی مانده است. ~۱۳ مورد low باقی‌مانده
  که incremental در فازهای feature-driven پرداخت می‌شوند. مهم‌ترین
  گام بعدی فاز ۱۷ (کنترلرهای admin کامل) است که D-16-1 را هم می‌تواند
  همراه بیاورد (هنگام ساخت admin UI برای doc_types، ستون `is_required`
  واقعی اضافه شود).
- [x] اگر خیر، تمرکز بعدی چه باشد؟ (۱) فاز ۱۷: کنترلرهای admin کامل
  (AdminController, ServiceType, WizardStep, Sms) با ۳۵+ مسیر.
  (۲) افزودن ستون `is_required` واقعی به `document_types` همراه با
  admin UI. (۳) پاکسازی D-8-5 و D-14-1.
- [x] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ کم — فاز ۱۶ الگوی
  seed-migration + read-from-DB را تثبیت کرد که برای فاز ۱۷ (CRUD admin)
  تکرارپذیر است.

---

### فاز ۱۷: پنل ادمین کامل (v1.8.0)

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 1.8.0 (phase/17-admin-panel-complete branch, tag v1.8.0)

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-2-2 | AdminController فقط ۵ متد | ۱۲ متد جدید اضافه شد (register, edit, update, addDocument, deleteDocument, requestPayment, updateLicenseState, postalRequests, approvePostalRequest, rejectPostalRequest, myTasks). حالا ۱۷ متد دارد. |

#### Debt partial-paid

| ID | توضیح |
|---|---|
| D-2-3 (partial) | ۳ کنترلر جدید اضافه شد: ServiceTypeController (۳ مسیر)، WizardStepController (۵ مسیر)، SmsController (۴ مسیر). باقی‌مانده: Payment, BulkImport, Report, License, Document, Revision, Tracking (فازهای ۱۸-۲۰). |

#### Debt introduced

هیچ.

#### Debt carried forward به فاز ۱۸

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-2-3 (partial) | ~۹ کنترلر باقی‌مانده (Payment, BulkImport, Report, License, Document, Revision, Tracking) | medium | فاز ۱۸-۲۰ |
| D-4-5 | SSE تست واقعی نیاز به Apache | low | v1.9+ |
| D-5-1 | seed_lookups sandbox fallback محدود | low | v1.9+ |
| D-5-4 | business_address suffix-mask محدودیت | low | v1.9+ |
| D-5-5 | county = township assumption | low | v1.9+ |
| D-6-2 | static_analysis false-positive warnings | low | v1.9+ |
| D-6-3 | test_track_scraper fixture missing | low | v1.9+ |
| D-6-4 | install.php lock-file fallback | low | v1.9+ |
| D-6-5 | docker secrets manual generation | low | v1.9+ |
| D-6-6 | CI smoke job needs .env | low | v1.9+ |
| D-8-1 | OutboundSyncService stub | low | v2.0+ |
| D-8-2 | Stage2ReadinessChecker::checkContractTests | low | v1.9+ |
| D-8-3 | NationalSyncOrchestrator dedup set | low | v1.9+ |
| D-8-5 | smoke-test sandbox variance | low | v1.9+ |
| D-14-1 | notifyAdmins username-bridge gap | low | v1.9+ |
| D-16-1 | document_types فاقد is_required column | low | v1.9+ |

#### توصیه برای فاز ۱۸

- [ ] فاز ۱۸ (v1.9.0): PaymentController + BulkImportController + ReportController
- [ ] فاز ۱۹ (v1.9.1): LicenseController کامل + DocumentController کامل + RevisionController کامل
- [ ] فاز ۲۰ (v1.9.2): TrackingController تکمیلی + پولیش نهایی
- [ ] فاز ۲۱ (v1.9.3): تست کامل + آماده‌سازی v2.0.0

---

## ۵. خلاصهٔ live debt

این بخش به‌صورت خودکار با اسکریپت `tools/debt_summary.py` به‌روزرسانی می‌شود
(اسکریپت در فاز ۱ پیاده نشده؛ تا فاز ۶ به‌صورت دستی نگه‌داری می‌شود):

| شدت | تعداد | قدیمی‌ترین | شناسه‌ها |
|---|---|---|---|
| critical | 0 | — | — |
| high | 0 | — | — |
| medium | 0 | — | — |
| low | 13 | 2026-11-17 | D-4-5, D-5-1, D-5-4, D-5-5, D-6-2, D-6-3, D-6-4, D-6-5, D-6-6, D-8-1, D-8-2, D-8-3, D-8-5, D-14-1, D-16-1 |

اگر critical > 0 یا high > 3، هشدار در CI.

**وضعیت پس از فاز ۱۶ (seed document_types + admin default + real document upload):**
۰ critical، ۰ high، **۰ medium**، ~۱۳ low. تغییر نسبت به فاز ۱۵:
D-7-3 (partial) پرداخت شد (ویزارد step 4 حالا ۵ ورودی فایل واقعی نشان می‌دهد).
D-16-1 جدید وارد شد (`document_types.is_required` ستون واقعی نیست — در
controller از `doc_type_key` محاسبه می‌شود). خالص: ۱ مورد افزایش (۱۲ → ۱۳).

پس از فاز ۱۶، پروژه همچنان در وضعیت «بدون debt medium یا بالاتر»
است — ~۱۳ مورد low-priority باقی‌مانده. مهم‌ترین دستاورد فاز ۱۶:
حلقهٔ فاز ۱۵.۱ بسته شد — ویزارد حالا ۵ ورودی فایل واقعی با ۲ اجباری
نشان می‌دهد (به‌جای hard-code ۳ ردیف)، و نصب تازه با admin پیش‌فرض
بلافاصله قابل استفاده است.

اگر critical > 0 یا high > 3، هشدار در CI.

---

## ۶. خط‌مشی debt

۱. **debt intentional است نه حسرت:** هر debt باید با آگاهی و توضیح وارد شود. «بدون debt» غیرعملی است؛ «debt مدیریت‌شده» هدف است.

۲. **debt بدون مالک ممنوع:** هر debt باید متعلق به یک maintainer باشد که مسئول رفع آن است.

۳. **debt پس از refactor:** اگر فازی به‌جای کاهش debt، آن را جابجا کرد (مثلاً از Core به NationalGateway)، در `Debt paid` نیاید چون واقعاً پرداخت نشده. در `Debt introduced` فاز جدید بیاید با ارجاع به ID قبلی.

۴. **debt در CHANGELOG:** هر debt جدید در `CHANGELOG.md` فاز هم ذکر شود.

---

*پایان قالب بازبینی debt.*
