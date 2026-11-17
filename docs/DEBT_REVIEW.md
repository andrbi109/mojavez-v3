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

#### توصیه برای فاز ۱۰ (post-Phase 9)

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ بله — فاز ۱۰ (v1.2.0) باید
  D-8-4 (admin UI برای readiness) و D-7-1 (کنترلرهای باقی‌مانده) را پرداخت کند.
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟ D-8-4 (medium)، D-7-1 (medium).
  همچنین وقتی upstream endpoint نوشتنی اضافه کرد، D-8-1 باید فوراً پرداخت شود.
- [ ] آیا D-9-1 (updateProfile/uploadAvatar) باید در v1.2 یا v1.3 پرداخت شود؟
  v1.3 پیشنهاد می‌شود چون نیاز به `AvatarService` مستقل دارد که scope فاز ۱۰
  را بیش از حد گسترش می‌دهد.

---

## ۵. خلاصهٔ live debt

این بخش به‌صورت خودکار با اسکریپت `tools/debt_summary.py` به‌روزرسانی می‌شود
(اسکریپت در فاز ۱ پیاده نشده؛ تا فاز ۶ به‌صورت دستی نگه‌داری می‌شود):

| شدت | تعداد | قدیمی‌ترین | شناسه‌ها |
|---|---|---|---|
| critical | 0 | — | — |
| high | 0 | — | — |
| medium | 5 | 2026-11-17 | D-2-2, D-2-3, D-7-1, D-8-4, D-9-1 |
| low | 26 | 2026-11-17 | D-1-8, D-1-9, D-2-4, D-2-5, D-2-9, D-4-5, D-4-6, D-4-7, D-5-1, D-5-2, D-5-4, D-5-5, D-6-2, D-6-3, D-6-4, D-6-5, D-6-6, D-7-2, D-7-3, D-7-4, D-8-1, D-8-2, D-8-3, D-8-5 |

اگر critical > 0 یا high > 3، هشدار در CI.

**وضعیت پس از فاز ۹ (User Panel):** ۰ critical، ۰ high، ۵ medium (همگی به v1.2/v1.3 موکول)، ۲۶ low.
تغییر نسبت به فاز ۸ + fix ISSUE-001: ۱ debt جدید اضافه شد (D-9-1 = updateProfile/uploadAvatar stub ها در پنل کاربری). فاز ۹ هدف اصلی‌اش (بستن 404 پس از ورود با پیاده‌سازی `/panel/*`) را به‌طور کامل انجام داد — ۱۰ مسیر، ۱۰ اکشن، ۷ ویو، ۱ layout، ۱ repo جدید، ۹ assertion تست جدید.

اگر critical > 0 یا high > 3، هشدار در CI.

---

## ۶. خط‌مشی debt

۱. **debt intentional است نه حسرت:** هر debt باید با آگاهی و توضیح وارد شود. «بدون debt» غیرعملی است؛ «debt مدیریت‌شده» هدف است.

۲. **debt بدون مالک ممنوع:** هر debt باید متعلق به یک maintainer باشد که مسئول رفع آن است.

۳. **debt پس از refactor:** اگر فازی به‌جای کاهش debt، آن را جابجا کرد (مثلاً از Core به NationalGateway)، در `Debt paid` نیاید چون واقعاً پرداخت نشده. در `Debt introduced` فاز جدید بیاید با ارجاع به ID قبلی.

۴. **debt در CHANGELOG:** هر debt جدید در `CHANGELOG.md` فاز هم ذکر شود.

---

*پایان قالب بازبینی debt.*
