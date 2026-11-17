# مقایسهٔ دو سند یکپارچه‌سازی + بهبود mojavez-v3 + راهنمای نهایی فایل‌ها

> **نسخهٔ سند:** 1.0
> **تاریخ تدوین:** ۱۴۰۵/۰۸/۲۶ (۲۰۲۶-۱۱-۱۶)
> **هدف:** تحلیل سند چهارم (`03-strategy-and-risk.md`)، مقایسهٔ آن با سند سوم (`00-MASTER.md`)، استخراج بهبودها، و تعیین اینکه در نسخهٔ نهایی کدام فایل‌ها در اختیار توسعه‌دهندگان و ایجنت‌های هوش مصنوعی قرار گیرد.

---

## فهرست

1. [تحلیل سند چهارم (سند توسعهٔ Mojavez-v2)](#1-تحلیل-سند-چهارم-سند-توسعهٔ-mojavez-v2)
2. [مقایسهٔ دو دیدگاه راهبردی](#2-مقایسهٔ-دو-دیدگاه-راهبردی)
3. [نقاط قوت سند چهارم که سند v3 فاقد آن بود](#3-نقاط-قوت-سند-چهارم-که-سند-v3-فاقد-آن-بود)
4. [نقاط قوت سند v3 که سند چهارم فاقد آن بود](#4-نقاط-قوت-سند-v3-که-سند-چهارم-فاقد-آن-بود)
5. [بهبودهای مستخرج برای v3](#5-بهبودهای-مستخرج-برای-v3)
6. [ضمیمهٔ فنی A: جوانب پنهان و تدابیر پیشگیرانه (۱۲+ مورد)](#ضمیمهٔ-فنی-a-جوانب-پنهان-و-تدابیر-پیشگیرانه)
7. [ضمیمهٔ فنی B: بهبود schema — idempotency، integration_log، soft-delete، optimistic concurrency](#ضمیمهٔ-فنی-b-بهبود-schema)
8. [ضمیمهٔ فنی C: ACL، Circuit Breaker و Degraded Mode](#ضمیمهٔ-فنی-c-acl-circuit-breaker-و-degraded-mode)
9. [ضمیمهٔ فنی D: ماتریس مالکیت Schema + ModuleInterface + Monorepo](#ضمیمهٔ-فنی-d-ماتریس-مالکیت-schema--moduleinterface--monorepo)
10. [ضمیمهٔ فنی E: Contract Test + CI/CD + Structured Logging](#ضمیمهٔ-فنی-e-contract-test--cicd--structured-logging)
11. [ضمیمهٔ فنی F: Rollout یک‌طرفه اول، دوطرفه بعد](#ضمیمهٔ-فنی-f-rollout-یک‌طرفه-اول-دوطرفه-بعد)
12. [راهنمای نهایی فایل‌ها: کدام، برای چه مخاطبی، در چه مرحله‌ای](#6-راهنمای-نهایی-فایل‌ها)
13. [توصیه‌های نهایی](#7-توصیه‌های-نهایی)

---

## 1. تحلیل سند چهارم (سند توسعهٔ Mojavez-v2)

### ۱.۱. هویت و زاویهٔ سند

سند چهارم با عنوان **«سند تحلیل توسعهٔ Mojavez-v2: کمبودها، نقص‌ها و جوانب پنهان یکپارچه‌سازی با mojavez-gateway»** یک سند تحلیلی-انتقادی است که با زاویهٔ کاملاً متفاوتی از سند v3 نوشته شده است:

- **سند v3 (سند سوم):** فرض می‌کند هر دو پروژه از صفر در مخزن جدید `mojavez-v3` بازنویسی می‌شوند. رویکرد «greenfield با تمام دانش دو پروژه».
- **سند چهارم:** فرض می‌کند v2 ابتدا باید با **۶ فاز زیرساختی** توسعه یابد، سپس با gateway ادغام شود. رویکرد «brownfield با توسعهٔ پیشینی». به‌صراحت می‌گوید: «اولاً v2 توسعهٔ کامل، ثانیاً یکپارچه‌سازی».

### ۱.۲. محدودیت‌های تحلیلی سند چهارم

سند چهارم در ابتدای خود یک **اظهار مهم** دارد:

> «مرجع یکپارچه‌سازی: `andrbi108/mojavez-gateway` — دسترسی با توکن ارائه‌شده توسط تیم توسعه. در زمان تدوین، تحلیل یکپارچه‌سازی بر پایهٔ الگوهای عمومیِ ادغام ماژول‌های gateway + قرینه‌سازی با ماژول Scraper مستندشده بود. اکنون با توکن دسترسی، پیاده‌سازی باید مستقیماً از روی کد واقعی gateway انجام شود.»

این یعنی نویسندهٔ سند چهارم **به محتوای فنی gateway دسترسی نداشته** و تحلیل یکپارچه‌سازی را بر اساس الگوهای عمومی انجام داده. در نتیجه:
- سند چهارم نمی‌داند که scraper چه می‌کند (`MojavecClient`, `TrackScraper`, RSC parser).
- سند چهارم نمی‌داند که الگوریتم تطبیق الگویی (ماسک به‌عنوان لنگر) چه ارزشی دارد.
- سند چهارم نمی‌داند که gateway چه مشکلات امنیتی شناخته‌شده‌ای دارد (S1-S5, ST1, I1).
- سند چهارم نمی‌تواند به‌صورت دقیق بگوید که «اتصال طلایی» چیست و چگونه کار می‌کند.

این محدودیت، تأثیر مهمی بر تحلیل دارد: سند چهارم به‌صورت «انتزاعی» یکپارچه‌سازی را فرض می‌کند، نه به‌صورت «بتنی» با دانش واقعی gateway.

### ۱.۳. ساختار سند چهارم

سند چهارم شامل ۱۱ بخش اصلی + ۲ پیوست است:

۱. **چکیدهٔ اجرایی و مرز اثر** — مرز اثر روشن: سند تحلیلی است، تغییری اعمال نمی‌کند.
۲. **زمینه: وضعیت فعلی Mojavez-v2** — هویت پروژه، تاریخچهٔ refactor، و **یافتهٔ نگران‌کنندهٔ سه‌گانگی نسخه** (README=0.1.0، VERSION=0.6.3، REFACTOR_PLAN=v2.0.0).
۳. **کمبودهای ساختاری (ده حوزه)** — تحلیل عمیق ۱۰ کمبود ساختاری v2.
۴. **نقص‌های داده‌ای و یکپارچگی** — ۴ نقص داده‌ای.
۵. **جوانب پنهان برای آینده (بخش مرکزی)** — ۱۲ جنبهٔ پنهان.
۶. **ماتریس ریسک** — ۱۲ ریسک با احتمال/شدت/زمان آشکار شدن/راهکار.
۷. **نقشهٔ راه توسعهٔ v2** — ۶ فاز (Foundation، ماژولار، API، رویداد/صف، repository، CI/CD).
۸. **استراتژی یکپارچه‌سازی با gateway** — ACL، قرارداد نسخه‌دار، یک‌طرفه اول، monorepo، migration مشترک، circuit breaker.
۹. **معماری هدف** — نمودار معماری (تصویری، در فایل آدرس شده ولی موجود نیست).
۱۰. **سنجه‌های موفقیت** — ۴ سنجه کلیدی.
۱۱. **جمع‌بندی و توصیه‌های نهایی** — ۳ توصیه.
- **پیوست الف:** چک‌لیست پیش از ادغام (۱۸ مورد).
- **پیوست ب:** قرارداد `ModuleInterface` پیشنهادی (PHP code).

### ۱.۴. ارزش اصلی سند چهارم

ارزش اصلی سند چهارم در سه بخش متمرکز است:

۱. **بخش ۳ (کمبودهای ساختاری):** تحلیل عمیق ۱۰ کمبود v2 که در غیاب آن‌ها یکپارچه‌سازی به debt فنی سنگین منجر می‌شود. این تحلیل دقیق‌تر از «کنار گذاشته می‌شود» در سند v3 است.

۲. **بخش ۵ (جوانب پنهان):** ۱۲ جنبه‌ای که در فاز طراحی پنهان می‌مانند اما در تولید پرهزینه می‌شوند — از drift همگام‌سازی دوطرفه و race condition تا مالکیت دادهٔ PII پس از قطع gateway. این بخش، **عمیق‌ترین بخش فکری** هر دو سند است.

۳. **بخش ۸ (استراتژی یکپارچه‌سازی):** پیشنهاد **Anti-Corruption Layer (ACL)** به‌عنوان لایهٔ adapter بین v2 و gateway، و **rollout یک‌طرفه اول، دوطرفه بعد** به‌عنوان استراتژی تقلیل ریسک. این پیشنهادها در سند v3 من به‌صورت ضمنی بودند ولی صریح نبودند.

---

## 2. مقایسهٔ دو دیدگاه راهبردی

### ۲.۱. جدول مقایسهٔ راهبردی

| شاخص | سند v3 (من) | سند چهارم (کاربر) |
|---|---|---|
| **فرض اصلی** | بازنویسی هر دو از صفر در مخزن جدید | توسعهٔ v2 اول، سپس ادغام با gateway |
| **نقطهٔ شروع** | `mojavez-v3` (مخزن جدید، کد صفر) | `Mojavez-v2` (مخزن موجود، توسعهٔ ۶ فازی) |
| **زاویهٔ دید** | gateway هم «دارای منبع» است (دو سند ورودی کامل) | فقط v2 را به‌عنوان منبع داریم، gateway خصوصی است |
| **پوشش کمبودها** | به‌صورت «رفع در فاز بازنویسی» | به‌صورت «ده حوزهٔ ساختاری» با عمق تحلیلی |
| **جوانب پنهان** | در بخش ریسک‌ها (۲۰ مورد، اما کم‌عمق) | در بخش مرکزی (۱۲ مورد، با نام «پنهان» و عمق تحلیلی) |
| **رویکرد یکپارچه‌سازی** | یکپارچه‌سازی مستقیم در همان پروژه (اتصال طلایی) | ACL (Anti-Corruption Layer) بین v2 و gateway |
| **مالکیت داده** | یکپارچه (یک schema، یک جدول `applicants`) | ماتریس مالکیت + shared kernel + `gateway_ref` nullable |
| **استراتژی sync** | اتصال طلایی (یک‌طرفه از ملی به محلی + اپراتور) | یک‌طرفه اول (v2 → gateway خواندنی)، دوطرفه بعد |
| **نشانه‌های ضعف v2** | «کنار گذاشته می‌شود» در بازنویسی | سه‌گانگی نسخه، drift schema، نبود modules/API/event/queue/repository/CI/CD |
| **رویکرد schema** | یکپارچه (۳۲ جدول جدید از صفر) | حفظ ۲۸ جدول v2 + افزودن integration_log + gateway_ref |
| **circuit breaker** | به‌صورت «fallback کش» ضمنی | صریح: open/half-open/closed + degraded mode |
| **idempotency** | به‌صورت کلی «اثر idempotent» | صریح: `idempotency_key` + `optimistic concurrency` |
| **contract test** | به‌صورت «تست واحد + smoke» | صریح: contract test با fixture‌های request/response |
| **CI/CD** | به‌صورت «اجرای دستی تست‌ها» | صریح: GitHub Actions + contract test در CI |
| **ساختار مخزن** | monorepo ضمنی (یک پروژه) | صریح: monorepo با `apps/mojavez/` + `apps/gateway/` + `packages/shared/` |
| **مهم‌ترین تحلیل غایب** | تحلیل سه‌گانگی نسخه، drift schema، SPOF singleton، debt پس از refactor | الگوریتم تطبیق الگویی، اتصال طلایی، RSC parser، مشکلات امنیتی gateway |

### ۲.۲. تحلیل بنیادی تفاوت

**سند v3** با دانش کامل دو پروژه نوشته شده — هم v2 و هم gateway را دیده است. به همین دلیل می‌تواند به‌صورت «بتنی» بگوید که scraper چه می‌کند، الگوریتم تطبیق الگویی چگونه کار می‌کند، و کدام مشکلات امنیتی gateway باید رفع شوند. اما به‌دلیل فرض «بازنویسی از صفر»، نکات **پیشگیری‌کنندهٔ پنهان** (که در تولید آشکار می‌شوند) را با عمق کافی پوشش نداده است.

**سند چهارم** بدون دسترسی به gateway نوشته شده — به همین دلیل تحلیل آن «انتزاعی» است و نمی‌تواند به‌صورت دقیق بگوید که یکپارچه‌سازی چه می‌کند. اما به‌دلیل فرض «توسعهٔ v2 اول» و تمرکز بر «جوانب پنهان»، نکات **پیشگیری‌کنندهٔ پنهان** را با عمق تحلیلی بالا پوشش داده — نکاتی که در هر یکپارچه‌سازی واقعی پیش می‌آیند (drift دوطرفه، race condition، lock-in داده، SPOF).

### ۲.۳. آیا دو دیدگاه قابل آشتی هستند؟

**بله، کاملاً.** دو دیدگاه دو سطح از یک مشکل را پوشش می‌دهند:

- **سند v3 (من):** سطح *طراحی و پیاده‌سازی* — چگونه mojavez-v3 را از صفر بسازیم.
- **سند چهارم:** سطح *استراتژی و ریسک* — چه فاجعه‌هایی در تولید ممکن است رخ دهد و چگونه از آن‌ها پیشگیری کنیم.

نسخهٔ 1.0 سند mojavez-v3 **هر دو دیدگاه را در خود دارد**: طراحی بتنی (از سند v3) + تدابیر پیشگیرانه (از سند چهارم). این سند مقایسه، هدفش استخراج و ادغام این دو است.

---

## 3. نقاط قوت سند چهارم که سند v3 فاقد آن بود

۱۵ مورد زیر از سند چهارم استخراج شده و در سند v3 (نسخهٔ 1.0) ادغام شده‌اند:

### ۳.۱. تحلیل سه‌گانگی نسخه (بخش ۲.۳ سند چهارم)

سند v3 من فرض کرد که `VERSION` یکپارچه است. اما سند چهارم نشان می‌دهد که v2 در واقع سه‌گانگی نسخه دارد:
- `README.md` خط ۵: «نسخهٔ ۰.۱.۰ — اولین نسخهٔ رسمی»
- فایل `VERSION`: `0.6.3`
- `REFACTOR_PLAN.md` خط ۱۲۱: «نسخهٔ نهایی: v2.0.0»

**درس برای v3:** باید یک منبع حقیقت واحد (`VERSION` = `1.0.0`) داشته باشد که `README`, `config/config.php` (`APP_VERSION`)، و `REFACTOR_PLAN` همگی به آن ارجاع دهند.

### ۳.۲. دو schema موازی و drift (بخش ۳.۷ سند چهارم)

سند v3 من فرض کرد که `schema.sql` منبع حقیقت است. اما سند چهارم نشان می‌دهد که v2 در واقع دو فایل schema موازی دارد:
- `database/schema.sql` (۱۱۷۹ خط)
- `database/install.sql` (۱۲۷۹ خط) — با ۱۰۰ خط اختلاف

**درس برای v3:** باید یک منبع حقیقت واحد برای schema داشته باشد. در v3: `database/schema.sql` به‌عنوان منبع حقیقت توسعه، و `database/install.sql` به‌عنوان alias یا نسخهٔ idempotent تولیدشده از همان منبع (نه دستی).

### ۳.۳. نبود runner مهاجرت و جدول migrations (بخش ۳.۷ سند چهارم)

سند v3 من فرض کرد که runner مهاجرت وجود دارد (با اشاره به `MigrationRunner`). اما سند چهارم نشان می‌دهد که v2 در واقع migrations دستی، idempotent دارد و جدول `migrations` وجود ندارد.

**درس برای v3:** باید جدول `migrations` (با `filename`, `applied_at`, `checksum`) و یک runner واقعی داشته باشد.

### ۳.۴. ACL (Anti-Corruption Layer) به‌عنوان لایهٔ adapter (بخش ۸.۱ سند چهارم)

سند v3 من اتصال طلایی را به‌صورت مستقیم پیاده‌سازی کرد (ماژول NationalGateway مستقیماً به `applicants` می‌نویسد). سند چهارم پیشنهاد می‌کند که gateway به‌طور مستقیم به مدل‌ها/schema v2 متصل نشود، بلکه از طریق ACL که ترجمهٔ مدل داده، اعتبارسنجی و circuit breaker را بر عهده دارد.

**درس برای v3:** باید یک لایهٔ ACL بین ماژول NationalGateway و دامنهٔ v2 قرار گیرد. این به‌معنای آن است که `NationalSyncOrchestrator` مستقیماً به `applicants` نمی‌نویسد، بلکه از طریق `ApplicantSyncAdapter` که قرارداد translation را پیاده‌سازی می‌کند، می‌نویسد.

### ۳.۵. Circuit Breaker و Degraded Mode (بخش ۸.۶ سند چهارم)

سند v3 من فقط «retry + fallback کش» را پوشش داد. سند چهارم صریحاً circuit breaker با سه حالت (open/half-open/closed) + degraded mode را پیشنهاد می‌کند:

- **closed:** همه چیز عادی، درخواست‌ها به upstream می‌روند.
- **open:** اگر ۳ خطای متوالی، همگام‌سازی متوقف، در صف می‌ماند، v3 در حالت degraded ادامه می‌دهد (بدون upstream).
- **half-open:** پس از cooldown، یک درخواست آزمایشی؛ اگر موفق، به closed برمی‌گردد.

**درس برای v3:** باید `CircuitBreaker` به‌عنوان کلاس جداگانه در `app/Modules/NationalGateway/Infrastructure/` پیاده‌سازی شود و `MojavecClient` از آن استفاده کند.

### ۳.۶. Idempotency Key و Optimistic Concurrency (بخش ۴.۳ و ۵.۲ سند چهارم)

سند v3 من به‌صورت کلی «اثر idempotent» را گفت (با `UNIQUE` constraint روی `national_license_request_number`). سند چهارم صریحاً پیشنهاد می‌کند:
- ستون `idempotency_key` روی جداول همگام‌شده.
- `optimistic concurrency` با ستون `version` برای race condition.
- جدول `integration_log` با `entity_type`, `entity_id`, `direction`, `status`, `gateway_ref`, `payload_hash`, `synced_at`.

**درس برای v3:** باید `idempotency_key` و `version` به `applicants` و `national_licenses` اضافه شود، و `integration_log` به‌عنوان جدول جداگانه (در کنار `sync_audit_log`) ساخته شود.

### ۳.۷. Soft-delete و دادهٔ زمان‌مند (بخش ۴.۴ سند چهارم)

سند v3 من CASCADE را به‌عنوان الگوی طراحی نگه داشت. سند چهارم هشدار می‌دهد که CASCADE دادهٔ تاریخی را نابود می‌کند و بدون بازگردانی است. پیشنهاد: ستون `deleted_at` برای soft-delete و `valid_from`/`valid_to` برای دادهٔ زمان‌مند.

**درس برای v3:** باید `deleted_at` به جداول کلیدی (`applicants`, `persons`, `national_licenses`, `users`) اضافه شود. این به‌ویژه برای «سیاست forget» gateway مهم است (بخش ۵.۴ سند چهارم).

### ۳.۸. ماتریس مالکیت Schema (بخش ۸.۵ سند چهارم)

سند v3 من فرض کرد که همهٔ ۳۲ جدول متعلق به یک پروژهٔ واحد هستند. سند چهارم پیشنهاد می‌کند که ماتریس مالکیت روشن باشد: کدام جدول متعلق به کدام ماژول است.

**درس برای v3:** حتی در یک پروژهٔ واحد، ماتریس مالکیت مهم است — جلوگیری از تغییر موازی schema در دو ماژول.

### ۳.۹. Contract Test با Fixture‌های request/response (بخش ۵.۸ و ۷.۶ سند چهارم)

سند v3 من به‌صورت کلی «تست واحد + smoke + رگرسیون scraper» را پوشش داد. سند چهارم صریحاً contract test را پیشنهاد می‌کند: یک مجموعه از fixture‌های request/response که قرارداد API را به‌صورت اجرایی ثبت می‌کنند. اگر v3 پاسخ `/api/v1/applicants/{id}` را تغییر دهد، contract test fail می‌شود.

**درس برای v3:** باید پوشهٔ `tests/contract/` با fixture‌های request/response برای همهٔ endpoint‌های `/api/v1/*` ساخته شود.

### ۳.۱۰. CI/CD با GitHub Actions (بخش ۳.۸ و ۷.۶ سند چهارم)

سند v3 من به‌صورت کلی «اجرای دستی تست‌ها» را پوشش داد. سند چهارم صریحاً CI/CD با GitHub Actions را پیشنهاد می‌کند: روی هر push و PR، اجرای `php tests/run.php`, lint, static analysis و contract test.

**درس برای v3:** باید `.github/workflows/ci.yml` ساخته شود.

### ۳.۱۱. Structured Logging و Metrics (بخش ۳.۱۰ سند چهارم)

سند v3 من به‌صورت کلی «Logger singleton» را پوشش داد. سند چهارم هشدار می‌دهد که `error_log` بومی PHP برای یکپارچه‌سازی کافی نیست — باید structured logging با زمینه و سطح + metrics داشته باشیم.

**درس برای v3:** باید `app/Support/StructuredLogger.php` با زمینه (context), سطح (level), و قابلیت emit metrics (count, latency) ساخته شود.

### ۳.۱۲. Scope-based Authorization برای API Key (بخش ۵.۵ سند چهارم)

سند v3 من یک API key سراسری (`GATEWAY_API_KEY`) برای همهٔ endpoint‌های `/api/v1/applicants/*` داشت. سند چهارم هشدار می‌دهد که یک API key نشت‌یافته به کل سیستم دسترسی می‌دهد. پیشنهاد: scope-based authorization (هر key فقط به منابع خاص).

**درس برای v3:** باید جدول `api_keys` با `key_hash`, `scopes` (JSON array), `rate_limit_per_min`, `expires_at`, `last_used_at`, `created_by` ساخته شود. هر key فقط به scope‌های خود دسترسی دارد.

### ۳.۱۳. Monorepo با packages/shared/ (بخش ۸.۴ سند چهارم)

سند v3 من monorepo ضمنی داشت (یک پروژهٔ واحد). سند چهارم صریحاً پیشنهاد می‌کند که ساختار monorepo با `apps/mojavez/` + `apps/gateway/` + `packages/shared/` باشد.

**درس برای v3:** حتی در یک پروژهٔ واحد، ساختار منطقی باید شبیه monorepo باشد: `app/Modules/Core/` (وارث v2) + `app/Modules/NationalGateway/` (وارث gateway) + `app/Shared/` (قرارداد مشترک).

### ۳.۱۴. Rollout یک‌طرفه اول، دوطرفه بعد (بخش ۸.۳ سند چهارم)

سند v3 من به‌صورت ضمنی فرض کرد که اتصال طلایی یک‌طرفه است (از درگاه ملی به محلی). سند چهارم صریحاً پیشنهاد می‌کند که rollout به دو مرحله تقسیم شود:
- مرحلهٔ ۱: همگام‌سازی یک‌طرفه خروجی (v3 → gateway فقط خواندنی).
- مرحلهٔ ۲: پس از تثبیت، همگام‌سازی دوطرفه با مکانیزم‌های حل تعارض.

**درس برای v3:** باید این به‌عنوان استراتژی rollout صریح در نقشهٔ راه ذکر شود. در واقع، اتصال طلایی خود یک حالت «یک‌طرفه ورودی» است (از ملی به محلی)، ولی ایدهٔ کلی «یک‌طرفه اول، دوطرفه بعد» باید در نقشهٔ راه ذکر شود.

### ۳.۱۵. SPOF Database Singleton و Pool اتصال (بخش ۵.۱۲ سند چهارم)

سند v3 من Singleton را به‌عنوان «محدود» نگه داشت ولی pool اتصال/read replica را پیشنهاد نداد. سند چهارم هشدار می‌دهد که `Database::getInstance()` یک نقطهٔ شکست است: رشد ترافیک gateway می‌تواند DB را برای کاربران کند کند.

**درس برای v3:** باید در «موارد باقی‌مانده پس از v1.0.0» صریحاً pool اتصال + read replica ذکر شود.

### ۳.۱۶. ModuleInterface و ModuleLoader (پیوست ب سند چهارم)

سند v3 من ماژول‌بندی را به‌صورت ساختاری پوشش داد (`app/Modules/`) ولی قرارداد صریح `ModuleInterface` را نداد. سند چهارم قرارداد کامل PHP را در پیوست ب ارائه می‌کند:

```php
interface ModuleInterface {
    public function name(): string;
    public function version(): string;
    public function register(ModuleLoader $loader): void;
    public function boot(ModuleLoader $loader): void;
    public function routes(): array;
    public function migrations(): array;
    public function subscribers(): array;
}
```

**درس برای v3:** باید `ModuleInterface` به‌عنوان قرارداد رسمی برای همهٔ ماژول‌ها در `app/Modules/` پذیرفته شود.

---

## 4. نقاط قوت سند v3 که سند چهارم فاقد آن بود

۱۰ مورد زیر در سند v3 موجود است که سند چهارم به‌دلیل عدم دسترسی به gateway فاقد آن است:

### ۴.۱. الگوریتم تطبیق الگویی (PatternMatcher)
سند چهارم نمی‌داند که scraper چه می‌کند. سند v3 الگوریتم کامل تطبیق الگویی (ماسک به‌عنوان لنگر + آرای چندفیلدی) را با پیاده‌سازی PHP و پورت پایتونی مستند کرده.

### ۴.۲. اتصال طلایی (Golden Connection)
سند چهارم به‌صورت کلی «gateway تغذیه می‌شود» می‌گوید. سند v3 چرخهٔ کامل scrape → match → act → notify را تعریف کرده.

### ۴.۳. معماری یکپارچهٔ v3 (۳۲ جدول از صفر)
سند چهارم ۲۸ جدول v2 + جدول‌های جدید integration را می‌گوید. سند v3 کل schema را با DDL طراحی کرده.

### ۴.۴. رفع مشکلات امنیتی gateway (S1-S5, ST1, I1)
سند چهارم نمی‌داند که gateway چه مشکلاتی دارد. سند v3 همه را فهرست و رفع کرده.

### ۴.۵. TrackScraper RSC parser
سند چهارم به scraper به‌صورت کلی اشاره می‌کند. سند v3 RSC parser را با ۱۵ assertion رگرسیون مستند کرده.

### ۴.۶. مهاجرت داده از هر دو پروژه
سند چهارم فرض می‌کند v2 موجود است و gateway جدید است. سند v3 مهاجرت از هر دو را پوشش داده.

### ۴.۷. مستندسازی endpointهای upstream
سند چهارم نمی‌داند که upstream چه endpointهایی دارد. سند v3 همه را از gateway فهرست کرده.

### ۴.۸. نقشهٔ راه ۷ فازی برای بازنویسی
سند چهارم ۶ فاز برای توسعهٔ v2 دارد. سند v3 ۷ فاز برای بازنویسی v3 از صفر دارد.

### ۴.۹. تست‌های واقعی (smoke, unit, رگرسیون scraper)
سند چهارم به‌صورت کلی «contract test» می‌گوید. سند v3 تست‌های واقعی را تعریف کرده.

### ۴.۱۰. فلسفهٔ ضد‌شناسایی احترام‌آمیز
سند چهارم به‌صورت کلی «crawl» می‌گوید. سند v3 هدرهای ثابت، jitter Gaussian، rate limit واقعی، و تفاوت با spoofing مخرب را مستند کرده.

---

## 5. بهبودهای مستخرج برای v3

بر اساس مقایسهٔ فوق، ۱۶ بهبود زیر باید به سند v3 اضافه شود. این بهبودها در ضمیمه‌های فنی A تا F با جزئیات پیاده‌سازی شده‌اند:

| # | بهبود | منبع (سند چهارم) | ضمیمه |
|---|---|---|---|
| ۱ | تثبیت منبع حقیقت واحد برای نسخه و schema | بخش ۲.۳ و ۳.۷ | A |
| ۲ | جدول `migrations` + runner واقعی | بخش ۳.۷ | A |
| ۳ | جدول `integration_log` با `entity_type`, `direction`, `status`, `gateway_ref`, `payload_hash` | بخش ۴.۳ | B |
| ۴ | ستون `idempotency_key` روی جداول همگام‌شده | بخش ۴.۳ و ۵.۲ | B |
| ۵ | ستون `version` برای optimistic concurrency | بخش ۵.۲ | B |
| ۶ | ستون `deleted_at` برای soft-delete روی جداول کلیدی | بخش ۴.۴ | B |
| ۷ | ACL (Anti-Corruption Layer) بین ماژول NationalGateway و دامنهٔ v2 | بخش ۸.۱ | C |
| ۸ | `CircuitBreaker` با سه حالت open/half-open/closed | بخش ۸.۶ | C |
| ۹ | Degraded Mode صریح (v3 بدون upstream) | بخش ۸.۶ | C |
| ۱۰ | ماتریس مالکیت schema (کدام جدول متعلق به کدام ماژول) | بخش ۸.۵ | D |
| ۱۱ | `ModuleInterface` و `ModuleLoader` به‌عنوان قرارداد رسمی | پیوست ب | D |
| ۱۲ | Contract Test با fixture‌های request/response | بخش ۵.۸ و ۷.۶ | E |
| ۱۳ | CI/CD با GitHub Actions | بخش ۳.۸ و ۷.۶ | E |
| ۱۴ | `StructuredLogger` با زمینه و سطح + metrics | بخش ۳.۱۰ | E |
| ۱۵ | Scope-based authorization برای API key (جدول `api_keys`) | بخش ۵.۵ | E |
| ۱۶ | Rollout یک‌طرفه اول، دوطرفه بعد | بخش ۸.۳ | F |

علاوه بر این، ۱۲ جنبهٔ پنهان از بخش ۵ سند چهارم باید به‌عنوان بخش جدید «جوانب پنهان و تدابیر پیشگیرانه» به سند v3 اضافه شود (ضمیمهٔ A).

---

## ضمیمهٔ فنی A: جوانب پنهان و تدابیر پیشگیرانه

این بخش باید به‌عنوان **بخش ۲۳ جدید** به سند v3 اضافه شود (پس از بخش ۲۲ «مرجع فایل‌های کلیدی»). این بخش از بخش ۵ سند چهارم استخراج و با تدابیر پیشگیرانهٔ v3 تطبیق داده شده است.

### A.۱. drift همگام‌سازی دوطرفه

**جنبهٔ پنهان:** وقتی v3 و upstream هر دو روی یک موجودیت (مثلاً وضعیت درخواست) نوشتن می‌کنند، drift اجتناب‌ناپذیر است. کاربر در v3 وضعیت را به «تأیید نهایی» تغییر می‌دهد، هم‌زمان scraper از درگاه ملی وضعیت «در حال بررسی» را می‌خواند و بازنویسی می‌کند. این drift در آزمون ظاهر نمی‌شود چون آزمون‌ها هم‌زمانی واقعی تولید نمی‌کنند؛ فقط در تولید با کاربر هم‌زمان آشکار می‌شود.

**تدبیر پیشگیرانه در v3:**
- **source-of-truth مشخص برای هر فیلد:** در `app/Modules/NationalGateway/OWNERSHIP_MATRIX.md`، هر فیلد مشخص می‌کند که مالک آن چه کسی است:
  - `applicants.current_status` → مالک: v3 (کاربر/ادمین). scraper فقط در حالت AutoConfirmed می‌نویسد، نه در حالت عادی.
  - `applicants.license_state` → مالک: scraper (AutoConfirmed) یا ادمین (دستی).
  - `applicants.license_valid_until` → مالک: scraper (از upstream).
  - `national_licenses.matched_applicant_id` → مالک: scraper یا اپراتور.
- **قاعدهٔ حل تعارض:** last-write-wins با `updated_at` timestamp. اگر scraper بخواهد `current_status` را تغییر دهد ولی `updated_at` متقاضی جدیدتر از `last_seen_at` مجوز ملی باشد، scraper تغییر نمی‌دهد و در `sync_audit_log` با `action='conflict_skipped'` لاگ می‌کند.
- **تست:** در `tests/ConflictResolutionTest.php` با سناریوهای هم‌زمانی مصنوعی.

### A.۲. race condition و retry تکراری

**جنبهٔ پنهان:** scraper timeout می‌شود، retry می‌کند، هم‌زمان v3 هم‌زمان یک به‌روزرسانی دریافت می‌کند. بدون idempotency key، retry به رکورد تکراری یا به‌روزرسانی اشتباه منجر می‌شود. حتی با idempotency key، race condition بین دو فراخوانی هم‌زمان scraper نیاز به قفل دارد. بدون بار ترافیکی واقعی قابل تشخیص نیست.

**تدبیر پیشگیرانه در v3:**
- ستون `idempotency_key` (VARCHAR(64), UNIQUE) روی `sync_audit_log` و `national_licenses`.
- ستون `version` (INT, default 1) روی `applicants` و `national_licenses` برای optimistic concurrency.
- قبل از UPDATE، `SELECT ... FOR UPDATE` یا بررسی `version`: اگر `version` تغییر کرده، retry با backoff.
- در `MojavecClient::doWithRetry()`، retry فقط روی statusهای 429/502/503/504، نه روی 200 (که ممکن است race باشد).

### A.۳. version mismatch و contract drift

**جنبهٔ پنهان:** upstream به‌سرعت تکامل می‌یابد، scraper ممکن است روی نسخهٔ قدیمی باشد. بدون نسخه‌بندی قرارداد، یک تغییر در upstream می‌تواند scraper را بشکند. regressions آهسته ظاهر می‌شوند — upstream به‌روزرسانی می‌شود، scraper تا هفته‌ها کار می‌کند، سپس یک endpoint خاص می‌شکند.

**تدبیر پیشگیرانه در v3:**
- نسخهٔ scraper در `app/Modules/NationalGateway/VERSION` (مثلاً `1.0.0`).
- هدر `X-Scraper-Version` در همهٔ درخواست‌های upstream (برای debug).
- مانیتورینگ `/api/v1/admin/crawl` با آستانهٔ هشدار: اگر نرخ موفقیت scrape زیر ۹۰٪ در یک ساعت، هشدار.
- contract test روی fixture HAR واقعی (`tests/fixtures/har/`) — اگر upstream فرمت را عوض کند، تست fail می‌شود.

### A.۴. lock-in داده‌ای و رکورد یتیم

**جنبهٔ پنهان:** پس از ماه‌ها همگام‌سازی، دادهٔ v3 با `national_license_request_number` پر شده است. اگر upstream قطع شود یا مجوز ملی باطل شود، این رکوردها «یتیم» می‌شوند. این مشکل در زمان قطع فوری نیست؛ در زمانی که تصمیم به جایگزینی upstream گرفته می‌شود آشکار می‌شود.

**تدبیر پیشگیرانه در v3:**
- `national_license_request_number` nullable + قابل null-shifting.
- سیاست «forget» برای upstream: اگر یک مجوز ملی ۳۰ روز در upstream پیدا نشود، `national_license_request_number` به NULL تبدیل می‌شود (با audit در `sync_audit_log` با `action='forgotten'`)، ولی `license_state='صادر شده'` باقی می‌ماند (چون دادهٔ تاریخی است).
- ستون `deleted_at` روی `national_licenses` برای soft-delete.

### A.۵. API endpoint به‌عنوان سطح حملهٔ جدید

**جنبهٔ پنهان:** هر endpoint API که برای خارج ساخته می‌شود، یک سطح حملهٔ جدید است. بدون rate limit سراسری، بدون اعتبارسنجی ورودی متمرکز، بدون schema validation و بدون audit log، یک endpoint نشت‌یافته می‌تواند کل دادهٔ PII متقاضیان را افشا کند. خطر در خود endpoint نیست، در مکانیزم احراز هویت است: یک API key نشت‌یافته به کل سیستم دسترسی می‌دهد.

**تدبیر پیشگیرانه در v3:**
- جدول `api_keys` با `key_hash`, `scopes` (JSON), `rate_limit_per_min`, `expires_at`, `last_used_at`, `created_by`.
- scope-based authorization: هر key فقط به scope‌های خود دسترسی دارد (مثلاً `read:applicants:basic`, `read:applicants:full`).
- rate limit سراسری روی API: ۶۰ req/min برای هر key.
- audit log دسترسی به PII در `api_access_log` (با `key_id`, `endpoint`, `entity_id`, `accessed_at`, `ip`).
- whitelist فیلدهای قابل افشا در هر scope.

### A.۶. بازیابی فاجعه و circuit breaker

**جنبهٔ پنهان:** upstream قطع می‌شود (سرور خارجی از کار می‌افتد، شبکه قطع می‌شود). بدون circuit breaker، scraper به‌طور بی‌وقفه retry می‌کند، صف پر می‌شود، دیسک پر می‌شود و v3 خودش از کار می‌افتد. بدون circuit breaker، قطع upstream به قطع v3 منجر می‌شود — یک شکست موضعی به فاجعهٔ سراسری تبدیل می‌شود.

**تدبیر پیشگیرانه در v3:**
- `CircuitBreaker` با سه حالت: closed (عادی)، open (متوقف، در صف می‌ماند)، half-open (آزمایشی).
- آستانه: ۳ خطای متوالی در ۶۰ ثانیه → open.
- cooldown: ۵ دقیقه → half-open.
- در حالت open، scraper در صف می‌ماند (JobQueue با `status='queued'`، `attempts` افزایش نمی‌یابد).
- v3 در حالت degraded ادامه می‌دهد: صفحات عمومی، پنل مدیریت، ثبت‌نام، پیگیری — همه بدون upstream کار می‌کنند.
- مانیتورینگ `/health` شامل وضعیت circuit breaker.

### A.۷. drift schema در مخزن جدید

**جنبهٔ پنهان:** وقتی در یک مخزن جدید توسعه می‌دهیم، چه کسی مالک schema است؟ اگر دو توسعه‌دهنده (یا دو ماژول) schema را هم‌زمان تغییر دهند، drift ایجاد می‌شود. بدون ماتریس مالکیت روشن، تغییرات هم‌زمان در دو ماژول به conflicting FK یا نوع‌های ناسازگار منجر می‌شود.

**تدبیر پیشگیرانه در v3:**
- ماتریس مالکیت schema در `docs/SCHEMA_OWNERSHIP.md` (ضمیمهٔ D).
- هر جدول متعلق به یک ماژول است: `applicants` → `Modules/Core`, `national_licenses` → `Modules/NationalGateway`, `sync_audit_log` → `Modules/NationalGateway`, `api_keys` → `Modules/Api`, و غیره.
- migration‌های هر ماژول در `app/Modules/<Name>/migrations/`.
- قبل از merge هر migration، بررسی ماتریس مالکیت در CI.

### A.۸. نبود contract test

**جنبهٔ پنهان:** بدون contract test، هر تغییر در v3 می‌تواند scraper یا API را بشکند. contract test‌ها قرارداد را به‌صورت اجرایی ثبت می‌کنند. نبود contract test به‌معنای آن است که یکپارچه‌سازی فقط در زمان deploy آزمون می‌شود — یعنی در تولید.

**تدبیر پیشگیرانه در v3:**
- پوشهٔ `tests/contract/` با fixture‌های request/response.
- برای هر endpoint `/api/v1/*`: یک fixture با request نمونه + response مورد انتظار.
- اجرای contract test در CI قبل از merge.
- اگر پاسخ واقعی با fixture هم‌خوان نبود، fail.

### A.۹. مالکیت داده و حریم خصوصی

**جنبهٔ پنهان:** scraper به PII متقاضیان (کد ملی، موبایل، آدرس) دسترسی پیدا می‌کند (به‌صورت ماسک‌شده از upstream، ولی به‌صورت کامل در v3). اگر API خارجی فعال شود، انتقال PII ممکن است نیازمند رضایت صریح کاربر یا انطباق با قوانین حفاظت از داده باشد. این مشکل فنی نیست، حقوقی است؛ اما در زمان ممیزی یا شکایت کاربر آشکار می‌شود.

**تدبیر پیشگیرانه در v3:**
- minimum-necessary principle: فقط دادهٔ لازم به API برود (whitelist فیلدها).
- audit log دسترسی به PII در `api_access_log`.
- در تنظیمات متقاضی، گزینهٔ «رضایت به اشتراک داده با سیستم‌های خارجی» (default: false).
- اگر رضایت false، endpoint `/api/v1/applicants/{code}` حتی با API key معتبر، فقط فیلدهای عمومی (tracking_code, license_state, license_valid_until) برمی‌گرداند، نه PII.

### A.۱۰. debt فنی پس از refactor

**جنبهٔ پنهان:** refactor v3.0.0 رفع‌های زیادی وارد می‌کند، اما توسعهٔ فازبندی debt جدید وارد می‌کند. اگر فازها بدون نظم اجرا شوند، debt انباشته می‌شود و خود به یک refactor دیگر نیاز می‌شود. refactor بدون نظم، به‌جای کاهش debt، آن را جابجا می‌کند.

**تدبیر پیشگیرانه در v3:**
- در پایان هر فاز، بازبینی debt در `docs/DEBT_REVIEW.md`.
- اگر debt انباشته شده، فاز بعدی باید شامل «پاکسازی debt» باشد.
- در CHANGELOG، هر فاز با لیست «debt introduced» و «debt paid» ذکر می‌شود.

### A.۱۱. تکامل موازی و merge conflict فرهنگی

**جنبهٔ پنهان:** وقتی v3 و scraper هم‌زمان توسعه می‌یابند، سبک کد، قراردادها و اولویت‌ها واگرا می‌شوند. این drift فرهنگی در کد دیده نمی‌شود، اما در زمان ادغام به conflict‌های طراحی تبدیل می‌شود.

**تدبیر پیشگیرانه در v3:**
- سند قرارداد مشترک در `docs/CODING_CONVENTIONS.md`.
- مالکیت روشن: هر ماژول یک maintainer دارد.
- code review الزامی برای هر تغییر در `app/Shared/` یا `app/Modules/NationalGateway/`.

### A.۱۲. single point of failure در Database singleton

**جنبهٔ پنهان:** `Connection::getInstance()` یک اتصال PDO واحد است. اگر این اتصال قطع شود، کل سیستم از کار می‌افتد. رشد ترافیک scraper می‌تواند DB را برای کاربران کند کند.

**تدبیر پیشگیرانه در v3 (مورد آینده):**
- pool اتصال (در نسخهٔ بعدی، با `PDO` wrapper که چندین اتصال نگه می‌دارد).
- read replica برای کوئری‌های خواندنی (مثلاً صفحات عمومی از replica، نوشتن از primary).
- connection retry با backoff در `Connection::boot()`.
- timeout روی queryها (`PDO::ATTR_TIMEOUT`).

---

## ضمیمهٔ فنی B: بهبود schema

### B.۱. جدول جدید `integration_log` (در کنار `sync_audit_log`)

`sync_audit_log` (از سند v3 اصلی) تصمیم‌های اتصال طلایی را لاگ می‌کند. `integration_log` عمومی‌تر است: همهٔ تعاملات با سیستم‌های خارجی را لاگ می‌کند.

```sql
CREATE TABLE integration_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(64) NOT NULL,           -- 'applicant', 'national_license', 'lookup'
    entity_id VARCHAR(64) NOT NULL,             -- tracking_code یا request_number
    direction ENUM('inbound','outbound') NOT NULL,
    system VARCHAR(64) NOT NULL,                -- 'national_gateway', 'sms', 'payment', 'external_api'
    status ENUM('success','failed','pending','conflict_skipped','forgotten') NOT NULL,
    gateway_ref VARCHAR(128),                   -- شناسهٔ خارجی (مثلاً request_number)
    payload_hash CHAR(64),                       -- SHA-256(payload) برای idempotency
    idempotency_key VARCHAR(64),                -- کلید یکتای عملیات
    latency_ms INT,
    error TEXT,
    operator_id INT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_il_idem (idempotency_key),
    INDEX idx_il_entity (entity_type, entity_id),
    INDEX idx_il_direction_status (direction, status, created_at),
    INDEX idx_il_gateway_ref (gateway_ref),
    INDEX idx_il_payload_hash (payload_hash),
    FOREIGN KEY (operator_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### B.۲. ستون‌های جدید روی `applicants`

```sql
ALTER TABLE applicants
    ADD COLUMN version INT NOT NULL DEFAULT 1 COMMENT 'optimistic concurrency',
    ADD COLUMN deleted_at DATETIME NULL COMMENT 'soft-delete',
    ADD COLUMN consent_external_share TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'رضایت اشتراک با خارج',
    ADD INDEX idx_applicants_deleted (deleted_at);
```

### B.۳. ستون‌های جدید روی `national_licenses`

```sql
ALTER TABLE national_licenses
    ADD COLUMN version INT NOT NULL DEFAULT 1,
    ADD COLUMN deleted_at DATETIME NULL,
    ADD COLUMN idempotency_key VARCHAR(64),
    ADD UNIQUE KEY uq_nl_idem (idempotency_key);
```

### B.۴. ستون `idempotency_key` روی `sync_audit_log`

```sql
ALTER TABLE sync_audit_log
    ADD COLUMN idempotency_key VARCHAR(64),
    ADD UNIQUE KEY uq_sal_idem (idempotency_key);
```

### B.۵. جدول جدید `api_keys` (scope-based authorization)

```sql
CREATE TABLE api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_hash CHAR(64) NOT NULL UNIQUE,           -- SHA-256(key) — هرگز خود key ذخیره نمی‌شود
    label VARCHAR(128) NOT NULL,                  -- توضیح (مثلاً 'gateway-sync-key')
    scopes JSON NOT NULL,                          -- ['read:applicants:basic', 'read:licenses']
    rate_limit_per_min INT NOT NULL DEFAULT 60,
    expires_at DATETIME,
    last_used_at DATETIME,
    last_used_ip VARCHAR(45),
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_keys_expires (expires_at),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_access_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_id INT NOT NULL,
    endpoint VARCHAR(128) NOT NULL,
    method VARCHAR(8) NOT NULL,
    entity_id VARCHAR(64),
    scopes_used JSON,
    response_status INT,
    latency_ms INT,
    ip VARCHAR(45),
    accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aal_key (key_id, accessed_at),
    INDEX idx_aal_entity (entity_id),
    FOREIGN KEY (key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### B.۶. جدول `migrations` (runner واقعی)

```sql
CREATE TABLE migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    module VARCHAR(64) NOT NULL,                  -- 'core', 'national_gateway', 'api', ...
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checksum CHAR(64) NOT NULL                    -- SHA-256(محتوای فایل)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### B.۷. تعداد کل جداول به‌روزرسانی‌شدهٔ v3

با افزودن این بهبودها، تعداد جداول v3 از ۳۲ به **۳۵** می‌رسد:
- ۳۲ جدول اصلی (از سند v3 نسخهٔ 1.0)
- ۳۳: `integration_log`
- ۳۴: `api_keys`
- ۳۵: `api_access_log`

(جدول `migrations` به‌عنوان جدول Meta شمارش نمی‌شود، ولی در عمل جداول ۳۶تایی است.)

---

## ضمیمهٔ فنی C: ACL، Circuit Breaker و Degraded Mode

### C.۱. Anti-Corruption Layer (ACL)

به‌جای دسترسی مستقیم `NationalSyncOrchestrator` به `applicants`، یک لایهٔ adapter قرار می‌گیرد.

```
[NationalSyncOrchestrator]
        │
        ▼
[ApplicantSyncAdapter]  ← ACL: ترجمهٔ مدل داده + اعتبارسنجی + circuit breaker
        │
        ├─ translateUpstreamToDomain(License $national): ApplicantDelta
        ├─ validateConsent(Applicant $a): bool
        ├─ checkConflict(Applicant $a, ApplicantDelta $d): ConflictResolution
        └─ applyDelta(Applicant $a, ApplicantDelta $d, string $idempotencyKey): void
                │
                ▼
        [ApplicantRepositoryInterface]  ← Interface، نه مدل concreto
                │
                ▼
        [PdoApplicantRepository]  ← پیاده‌سازی PDO
                │
                ▼
        [applicants table]
```

**مزایای ACL:**
۱. اگر upstream تغییر کند، فقط adapter به‌روزرسانی می‌شود، نه دامنهٔ v3.
۲. اعتبارسنجی متمرکز (consent، conflict، idempotency) در یک مکان.
۳. تست‌پذیری: adapter با mock repository قابل تست است.

### C.۲. `CircuitBreaker` (کلاس کامل)

```php
<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Infrastructure;

final class CircuitBreaker
{
    private CircuitState $state = CircuitState::Closed;
    private int $failureCount = 0;
    private ?\DateTimeImmutable $openedAt = null;
    private ?\DateTimeImmutable $lastFailureAt = null;

    public function __construct(
        private readonly int $failureThreshold = 3,    // ۳ خطای متوالی → open
        private readonly int $failureWindowSeconds = 60, // در پنجرهٔ ۶۰s
        private readonly int $cooldownSeconds = 300,    // ۵ دقیقه cooldown → half-open
    ) {}

    public function allowRequest(): bool
    {
        return match ($this->state) {
            CircuitState::Closed => true,
            CircuitState::Open => $this->tryTransitionToHalfOpen(),
            CircuitState::HalfOpen => true, // یک درخواست آزمایشی
        };
    }

    public function recordSuccess(): void
    {
        $this->failureCount = 0;
        $this->state = CircuitState::Closed;
        $this->openedAt = null;
    }

    public function recordFailure(): void
    {
        $this->lastFailureAt = new \DateTimeImmutable();
        $this->failureCount++;
        if ($this->failureCount >= $this->failureThreshold) {
            $this->state = CircuitState::Open;
            $this->openedAt = new \DateTimeImmutable();
        }
    }

    private function tryTransitionToHalfOpen(): bool
    {
        if ($this->openedAt === null) return false;
        $elapsed = (new \DateTimeImmutable())->getTimestamp() - $this->openedAt->getTimestamp();
        if ($elapsed >= $this->cooldownSeconds) {
            $this->state = CircuitState::HalfOpen;
            return true;
        }
        return false;
    }

    public function state(): CircuitState { return $this->state; }
    public function failureCount(): int { return $this->failureCount; }
}

enum CircuitState: string {
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
```

### C.۳. Degraded Mode

در حالت open circuit breaker، v3 در حالت degraded ادامه می‌دهد:

| قابلیت | حالت عادی (Closed) | حالت Degraded (Open) |
|---|---|---|
| صفحات عمومی | ✓ | ✓ |
| پنل مدیریت | ✓ | ✓ (با هشدار «درگاه ملی در دسترس نیست») |
| پنل کاربری | ✓ | ✓ |
| ثبت‌نام | ✓ | ✓ |
| پیگیری | ✓ | ✓ |
| گزارش‌گیری | ✓ | ✓ |
| `/admin/sync/status` | ✓ آخرین چرخه | ✓ با برچسب «آخرین چرخه: N دقیقه پیش، circuit open» |
| `/api/v1/licenses/search` | ✓ از upstream | ✗ 503 با `{error: 'upstream_unavailable'}` |
| `/api/v1/licenses/{id}/track` | ✓ scrape | ✗ 503 |
| اتصال طلایی (چرخهٔ sync) | ✓ | متوقف، در صف می‌ماند |
| `/license/{rn}` (صفحهٔ جزئیات) | ✓ از کش | ✓ از کش محلی (با هشدار «دادهٔ upstream به‌روز نیست») |

`/health` شامل `circuit_breaker_state` و `last_upstream_success_at` است.

---

## ضمیمهٔ فنی D: ماتریس مالکیت Schema + ModuleInterface + Monorepo

### D.۱. ماتریس مالکیت Schema

| جدول | مالک (ماژول) | خوانده‌شده توسط | نوشته‌شده توسط |
|---|---|---|---|
| `users` | `Modules/Core` | Core, Api, NationalGateway (read-only) | Core |
| `persons` | `Modules/Core` | Core, NationalGateway (via ACL) | Core, NationalGateway (via ACL) |
| `applicants` | `Modules/Core` | Core, NationalGateway (via ACL), Api | Core, NationalGateway (via ACL) |
| `service_types` | `Modules/Core` | Core | Core |
| `job_details` | `Modules/Core` | Core | Core |
| `property_details` | `Modules/Core` | Core, NationalGateway (read) | Core |
| `documents` | `Modules/Core` | Core | Core |
| `status_log` | `Modules/Core` | Core, NationalGateway (read) | Core, NationalGateway (via ACL, triggered_by='auto_sync') |
| `payments`, `license_files`, ... | `Modules/Core` | Core | Core |
| `national_licenses` | `Modules/NationalGateway` | Core (read), NationalGateway, Api | NationalGateway |
| `national_lookups` | `Modules/NationalGateway` | NationalGateway, Api | NationalGateway |
| `sync_audit_log` | `Modules/NationalGateway` | NationalGateway, Api (admin) | NationalGateway |
| `integration_log` | `Modules/Shared` | همه (read) | همه (write) |
| `api_keys`, `api_access_log` | `Modules/Api` | Api | Api |
| `migrations` | `Modules/Shared` | MigrationRunner | MigrationRunner |
| `jobs`, `batches`, `events`, `worker_state` | `Modules/Queue` | Queue, Api (admin) | Queue |

### D.۲. `ModuleInterface` (قرارداد رسمی)

```php
<?php
declare(strict_types=1);

namespace App\Modules;

interface ModuleInterface
{
    /** نام یکتای ماژول (مثل 'national_gateway'). */
    public function name(): string;

    /** نسخهٔ ماژول (SemVer، مثل '1.0.0'). */
    public function version(): string;

    /** ثبت سرویس‌ها و وابستگی‌ها در Container (قبل از boot). */
    public function register(ModuleLoader $loader): void;

    /** راه‌اندازی پس از ثبت همهٔ ماژول‌ها. */
    public function boot(ModuleLoader $loader): void;

    /** مسیرهای ماژول (به Router افزوده می‌شوند). */
    public function routes(): array;

    /** فهرست فایل‌های مهاجرت (نسبی به مسیر ماژول). */
    public function migrations(): array;

    /** مشترک‌های رویداد (event => handler). */
    public function subscribers(): array;

    /** مالکیت جداول این ماژول (برای ماتریس مالکیت). */
    public function ownedTables(): array;
}
```

### D.۳. ساختار منطقی Monorepo در v3

```
app/
├── Modules/
│   ├── Core/                      # وارث v2 (applicants, persons, payments, ...)
│   │   ├── Controllers/
│   │   ├── Services/
│   │   ├── Domain/
│   │   ├── Persistence/
│   │   ├── migrations/
│   │   ├── Module.php             # implements ModuleInterface
│   │   └── README.md
│   ├── NationalGateway/           # وارث gateway (MojavecClient, TrackScraper, sync)
│   │   ├── Controllers/
│   │   ├── Services/
│   │   ├── Infrastructure/
│   │   ├── Domain/
│   │   ├── migrations/
│   │   ├── Module.php
│   │   └── README.md
│   ├── Api/                       # لایهٔ API + api_keys
│   │   ├── Controllers/
│   │   ├── Middleware/
│   │   ├── migrations/
│   │   ├── Module.php
│   │   └── README.md
│   ├── Queue/                     # JobQueue + EventBus + Worker
│   │   ├── Jobs/
│   │   ├── Events/
│   │   ├── migrations/
│   │   ├── Module.php
│   │   └── README.md
│   └── Reporting/                 # CSV/PDF
│       ├── ...
├── Shared/                        # معادل packages/shared/
│   ├── Contracts/                 # interface‌های مشترک (Repository interfaces, DTO)
│   ├── Support/                   # Config, Logger, PersianDate, Normalizer, ...
│   ├── Domain/                    # Value Objects مشترک
│   └── Persistence/               # Connection, MigrationRunner
├── Container.php
└── Http/                          # Router, Session, Csrf, Request, Response
```

### D.۴. `ModuleLoader`

```php
<?php
declare(strict_types=1);

namespace App\Modules;

final class ModuleLoader
{
    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    public function register(ModuleInterface $module): void
    {
        $this->modules[$module->name()] = $module;
    }

    public function bootAll(): void
    {
        // ۱. register همه
        foreach ($this->modules as $module) {
            $module->register($this);
        }
        // ۲. boot همه (پس از ثبت همه)
        foreach ($this->modules as $module) {
            $module->boot($this);
        }
    }

    public function collectRoutes(): array
    {
        $routes = [];
        foreach ($this->modules as $module) {
            $routes = array_merge($routes, $module->routes());
        }
        return $routes;
    }

    public function collectMigrations(): array
    {
        $migrations = [];
        foreach ($this->modules as $module) {
            foreach ($module->migrations() as $file) {
                $migrations[] = ['module' => $module->name(), 'file' => $file];
            }
        }
        return $migrations;
    }

    public function collectSubscribers(): array
    {
        $subs = [];
        foreach ($this->modules as $module) {
            $subs = array_merge($subs, $module->subscribers());
        }
        return $subs;
    }

    public function get(string $name): ModuleInterface { return $this->modules[$name]; }
}
```

---

## ضمیمهٔ فنی E: Contract Test + CI/CD + Structured Logging

### E.۱. Contract Test

```
tests/contract/
├── Applicants/
│   ├── get_by_tracking_code_basic.json       # request + expected response (scope: read:applicants:basic)
│   ├── get_by_tracking_code_full.json        # scope: read:applicants:full
│   ├── get_by_national_code.json
│   └── get_status.json
├── Licenses/
│   ├── search_basic.json
│   ├── search_with_filter.json
│   ├── get_by_id.json
│   ├── get_track.json
│   └── save_all.json
├── Lookups/
│   ├── provinces.json
│   └── townships.json
├── Sync/
│   ├── status.json
│   ├── pending.json
│   └── run.json
└── run.php                                    # اجرای همهٔ contract test‌ها
```

**فرمت fixture:**

```json
{
  "name": "get applicant by tracking code (basic scope)",
  "request": {
    "method": "GET",
    "path": "/api/v1/applicants/HG-ABC12345",
    "headers": {"X-API-Key": "<key-with-scope:read:applicants:basic>"}
  },
  "expected": {
    "status": 200,
    "headers": {"Content-Type": "application/json"},
    "body": {
      "tracking_code": "HG-ABC12345",
      "national_code": "072059****",
      "license_state": "صادر شده",
      "license_valid_until": "2027-05-15"
    },
    "forbidden_fields": ["mobile", "first_name", "last_name", "password", "file_path"]
  }
}
```

`tests/contract/run.php` fixture‌ها را می‌خواند، درخواست را اجرا می‌کند، و پاسخ را با expected مقایسه می‌کند. اگر هم‌خوان نبود، fail.

### E.۲. CI/CD با GitHub Actions

`.github/workflows/ci.yml`:

```yaml
name: CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mariadb:10.11
        env:
          MYSQL_ROOT_PASSWORD: test
          MYSQL_DATABASE: mojavez_v3_test
        ports: ['3306:3306']
        options: --health-cmd="mysqladmin ping" --health-interval=10s
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: {php-version: '8.2', extensions: pdo_mysql, mbstring, fileinfo, gd, curl}
      - run: mysql -h127.0.0.1 -uroot -ptest mojavez_v3_test < database/schema.sql
      - run: php scripts/migrate.php --all
      - run: php tests/run.php
      - run: php tests/contract/run.php
      - run: ./tests/smoke-test.sh
      - run: python3 tools/static-analysis.py
      - run: python3 scripts/test_track_scraper.py
      - run: python3 scripts/pattern_matcher.py
```

### E.۳. `StructuredLogger`

```php
<?php
declare(strict_types=1);

namespace App\Support;

final class StructuredLogger
{
    public const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    public function __construct(
        private readonly string $logPath,
        private readonly string $minimumLevel = 'info'
    ) {}

    public function log(string $level, string $message, array $context = []): void
    {
        if (array_search($level, self::LEVELS) < array_search($this->minimumLevel, self::LEVELS)) {
            return;
        }
        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'request_id' => $_SERVER['X_REQUEST_ID'] ?? null,
            'module' => $context['_module'] ?? null,
        ];
        unset($context['_module']);
        file_put_contents(
            $this->logPath,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    public function info(string $m, array $c = []): void { $this->log('info', $m, $c); }
    public function warning(string $m, array $c = []): void { $this->log('warning', $m, $c); }
    public function error(string $m, array $c = []): void { $this->log('error', $m, $c); }
    public function critical(string $m, array $c = []): void { $this->log('critical', $m, $c); }
    public function debug(string $m, array $c = []): void { $this->log('debug', $m, $c); }

    /** metrics: شمارش رویدادها با نام و مقدار */
    public function metric(string $name, float $value, array $tags = []): void
    {
        $this->log('info', 'metric', ['metric' => $name, 'value' => $value, 'tags' => $tags]);
    }
}
```

لاگ‌ها به‌صورت JSON خط‌به‌خط در `storage/logs/app-YYYY-MM-DD.log`. هر خط شامل `timestamp`, `level`, `message`, `context`, `request_id`, `module`. این قابلیت grep با `jq` و ingestion در ELK/Loki را فراهم می‌کند.

---

## ضمیمهٔ فنی F: Rollout یک‌طرفه اول، دوطرفه بعد

### F.۱. استراتژی rollout

به‌جای فعال‌سازی کامل اتصال طلایی از روز اول، rollout به دو مرحله تقسیم می‌شود:

**مرحلهٔ ۱ (v3.0.0): همگام‌سازی یک‌طرفه ورودی (upstream → v3، فقط خواندنی از upstream)**
- scraper از درگاه ملی می‌خواند.
- `PatternMatcher` مجوز ملی را به متقاضی محلی وصل می‌کند.
- فقط در حالت `AutoConfirmed` (confidence ≥ ۹۰) به‌صورت خودکار وضعیت تغییر می‌کند.
- در حالت `OperatorFlag`، اپراتور manually تأیید می‌کند.
- **هیچ نوشتهایی به upstream ارسال نمی‌شود.** این ریسک drift دوطرفه را حذف می‌کند.

**مرحلهٔ ۲ (v3.1.0، پس از تثبیت مرحلهٔ ۱): همگام‌سازی دوطرفه با مکانیزم‌های حل تعارض**
- اگر upstream در آینده endpoint نوشتنی اضافه کرد (مثلاً webhook از ملی به v3)، فعال می‌شود.
- مکانیزم حل تعارض: last-write-wins با `updated_at` timestamp + source-of-truth برای هر فیلد.
- audit کامل در `integration_log` با `direction='inbound'` و `'outbound'`.

### F.۲. آستانهٔ گذار از مرحلهٔ ۱ به ۲

پیش از فعال‌سازی مرحلهٔ ۲، چهار شرط باید برقرار باشد:
۱. مرحلهٔ ۱ حداقل ۳ ماه در تولید بدون فاجعه اجرا شده باشد.
۲. نرخ false positive در `AutoConfirmed` زیر ۰.۱٪ باشد (از `sync_audit_log` با `action='operator_rejected'` پس از `auto_confirmed`).
۳. نرخ circuit breaker open در ۳۰ روز اخیر زیر ۵٪ باشد.
۴. contract test برای endpoint‌های دوطرفه کامل باشد.

### F.۳. fallback به مرحلهٔ ۱

در صورت بروز فاجعه در مرحلهٔ ۲، با یک flag در `settings` (`sync_two_way_enabled = false`) به مرحلهٔ ۱ بازگردانی می‌شود — بدون نیاز به deploy جدید.

---

## 6. راهنمای نهایی فایل‌ها

با توجه به چهار فایل موجود، جدول زیر تعیین می‌کند که در نسخهٔ نهایی mojavez-v3 کدام فایل‌ها در اختیار توسعه‌دهندگان و ایجنت‌های هوش مصنوعی قرار گیرد:

### ۶.۱. ماتریس فایل‌ها

| # | فایل | نقش | در اختیار | اولویت | مرحله |
|---|---|---|---|---|---|
| ۱ | **`00-MASTER.md`** (سند سوم، نسخهٔ 1.0) | **سند اصلی معماری و طراحی v3** — مرجع اول برای ساخت mojavez-v3 از صفر، شامل همهٔ بهبودها | توسعه‌دهندگان اصلی + ایجنت‌های هوش مصنوعی | **الزامی** | از روز اول |
| ۲ | **`01-v2-reference.md`** (سند اول) | **مرجع دادهٔ v2** — ۲۸ جدول، گردش‌کار ۱۸ وضعیتی، امنیت، استقرار، نرخ‌نامه، ویزارد ثبت‌نام | توسعه‌دهندگان ماژول Core + ایجنت‌ها | **الزامی** (پیوست مرجع) | هنگام پیاده‌سازی ماژول Core |
| ۳ | **`02-gateway-reference.md`** (سند دوم) | **مرجع scraper** — `MojavecClient`, `TrackScraper`, RSC parser، الگوریتم تطبیق الگویی، endpointهای upstream، مشکلات امنیتی gateway | توسعه‌دهندگان ماژول NationalGateway + ایجنت‌ها | **الزامی** (پیوست مرجع) | هنگام پیاده‌سازی ماژول NationalGateway |
| ۴ | **`03-strategy-and-risk.md`** (سند چهارم) | **مرجع استراتژی و ریسک** — جوانب پنهان، ACL، circuit breaker، idempotency، ماتریس مالکیت، contract test، ModuleInterface، rollout تدریجی | توسعه‌دهندگان اصلی + ایجنت‌ها | **الزامی** (پیوست استراتژی) | هنگام طراحی معماری و نقشهٔ راه |

### ۶.۲. توصیهٔ نهایی: تک سند اصلی + سه پیوست مرجع

**توصیهٔ صریح:** در نسخهٔ نهایی mojavez-v3، **یک سند اصلی واحد** در اختیار توسعه‌دهندگان و ایجنت‌ها قرار گیرد که تمام بهبودهای این سند مقایسه را در خود دارد (یعنی سند v3 نسخهٔ 1.0 = سند v3 + ضمیمه‌های A تا F این سند مقایسه). سه سند دیگر به‌عنوان **پیوست‌های مرجع تاریخی/فنی** نگه‌داری شوند و در سند اصلی به آن‌ها ارجاع داده شود.

**ساختار پیشنهادی مستندات نهایی v3:**

```
docs/
├── 00-MASTER.md                          # سند اصلی (v3 نسخهٔ 1.0 — ادغام طراحی بتنی + بهبودها)
│                                            در اختیار همه از روز اول
├── 01-v2-reference.md                    # = Mojavez-v2-Integration-Document.md (سند اول)
│                                            پیوست مرجع برای ماژول Core
├── 02-gateway-reference.md               # = mojavez-gateway.md (سند دوم)
│                                            پیوست مرجع برای ماژول NationalGateway
├── 03-strategy-and-risk.md               # = سند_توسعه_mojavez_v2.md (سند چهارم)
│                                            پیواست مرجع برای استراتژی و ریسک
├── 04-comparison-and-improvements.md     # = این سند (مقایسه + بهبودها)
│                                            سند تحلیلی برای درک چرایی تصمیمات
├── SCHEMA_OWNERSHIP.md                   # ماتریس مالکیت schema (از ضمیمهٔ D)
├── CODING_CONVENTIONS.md                 # قراردادهای کدنویسی
├── integration-contract.md               # قرارداد یکپارچه‌سازی نسخه‌دار
└── DEBT_REVIEW.md                        # بازبینی debt در پایان هر فاز
```

### ۶.۳. دستورالعمل استفاده برای ایجنت‌های هوش مصنوعی

هنگام واگذاری پیاده‌سازی mojavez-v3 به ایجنت‌های هوش مصنوعی، دستورالعمل زیر به آن‌ها داده شود:

۱. **ابتدا `00-MASTER.md` را به‌طور کامل بخوان.** این سند اصلی است و همهٔ تصمیمات طراحی را در خود دارد.
۲. **هنگام پیاده‌سازی ماژول `Core`** (فاز ۲ نقشهٔ راه): `01-v2-reference.md` را به‌عنوان مرجع داده بخوان.
۳. **هنگام پیاده‌سازی ماژول `NationalGateway`** (فاز ۳ نقشهٔ راه): `02-gateway-reference.md` را به‌عنوان مرجع scraper بخوان.
۴. **هنگام طراحی معماری و تصمیمات راهبردی**: `03-strategy-and-risk.md` را برای آگاهی از جوانب پنهان بخوان.
۵. **در صورت سؤال دربارهٔ چرایی یک تصمیم**: `04-comparison-and-improvements.md` را برای تحلیل بخوان.
۶. **هنگام نوشتن migration**: ماتریس مالکیت در `SCHEMA_OWNERSHIP.md` را رعایت کن.
۷. **هنگام نوشتن endpoint API**: contract را در `integration-contract.md` رعایت کن و contract test در `tests/contract/` اضافه کن.

### ۶.۴. آنچه هر سند «نمی‌تواند» جایگزین کند

| سند | نمی‌تواند جایگزین |
|---|---|
| `00-MASTER.md` | هیچ‌کدام — این سند اصلی است |
| `01-v2-reference.md` | سند اصلی — فقط مرجع دادهٔ v2 است |
| `02-gateway-reference.md` | سند اصلی — فقط مرجع scraper است |
| `03-strategy-and-risk.md` | سند اصلی — فقط استراتژی و ریسک است (به‌دلیل عدم دسترسی به gateway) |
| `04-comparison-and-improvements.md` | سند اصلی — فقط تحلیل مقایسه است |

---

## 7. توصیه‌های نهایی

بر اساس تحلیل کامل چهار سند، توصیه‌های نهایی زیر ارائه می‌شود:

### ۷.۱. توصیهٔ اول: سند اصلی v3 را به‌عنوان نسخهٔ 1.0 نهایی تدوین کن

سند `00-MASTER.md` (نسخهٔ 1.0) با افزودن ضمیمه‌های A تا F این سند مقایسه، نسخهٔ 1.0 نهایی را تشکیل می‌دهد. این بهبودها ۱۶ مورد است و همگی از سند چهارم استخراج شده‌اند. نسخهٔ 1.0 باید:
- بخش ۲۳ جدید «جوانب پنهان و تدابیر پیشگیرانه» (ضمیمهٔ A) را داشته باشد.
- بهبودهای schema (ضمیمهٔ B) را در بخش ۷ (پایگاه داده) ادغام کند.
- ACL + Circuit Breaker + Degraded Mode (ضمیمهٔ C) را در بخش ۴ (معماری) و ۸ (ماژول درگاه ملی) ادغام کند.
- ماتریس مالکیت + ModuleInterface + Monorepo (ضمیمهٔ D) را در بخش ۵ (ساختار مخزن) ادغام کند.
- Contract Test + CI/CD + Structured Logging (ضمیمهٔ E) را در بخش ۱۸ (تست) ادغام کند.
- Rollout یک‌طرفه اول، دوطرفه بعد (ضمیمهٔ F) را در بخش ۱۹ (نقشهٔ راه) ادغام کند.

### ۷.۲. توصیهٔ دوم: سه سند دیگر را به‌عنوان پیوست مرجع نگه دار

سه سند اول، دوم، و چهارم به‌عنوان پیوست مرجع در `docs/` نگه‌داری شوند. هرگز حذف نشوند — آن‌ها دانش تاریخی و فنی دارند که در سند اصلی خلاصه شده ولی گاهی نیاز به مرجع کامل است.

### ۷.۳. توصیهٔ سوم: برای ایجنت‌های هوش مصنوعی، دستورالعمل استفاده صریح بده

هنگام واگذاری پیاده‌سازی به ایجنت‌ها، دستورالعمل بخش ۶.۳ را به آن‌ها بده. این تضمین می‌کند که ایجنت‌ها به‌ترتیب درست و با اولویت درست اسناد را می‌خوانند.

### ۷.۴. توصیهٔ چهارم: قرارداد یکپارچه‌سازی نسخه‌دار بساز

قبل از شروع فاز ۳ (ماژول NationalGateway)، `docs/integration-contract.md` را بساز که endpoint‌های upstream، payload‌ها، نوع‌ها، کد وضعیت‌ها و نسخه‌بندی را تعریف کند. این قرارداد مرجع رسمی بین scraper و upstream است.

### ۷.۵. توصیهٔ پنجم: rollout را تدریجی نگه دار

حتی با وجود طراحی کامل اتصال طلایی، rollout را به دو مرحله تقسیم کن (ضمیمهٔ F). مرحلهٔ ۱ (یک‌طرفه ورودی) حداقل ۳ ماه در تولید بدون فاجعه اجرا شود، سپس مرحلهٔ ۲ (دوطرفه) فعال شود. این تقلیل ریسک از فاجعهٔ تولید جلوگیری می‌کند.

### ۷.۶. توصیهٔ ششم: ماتریس مالکیت schema را از روز اول اعمال کن

حتی در یک پروژهٔ واحد با یک توسعه‌دهنده، ماتریس مالکیت schema (ضمیمهٔ D) را از روز اول اعمال کن. این جلوگیری از drift schema در آینده است، وقتی چند توسعه‌دهنده یا چند ایجنت هم‌زمان کار می‌کنند.

---

## جمع‌بندی

این سند مقایسه، چهار سند ورودی را تحلیل کرد:

۱. **`01-v2-reference.md`** — مرجع دادهٔ v2 (۲۸ جدول، گردش کار، امنیت).
۲. **`02-gateway-reference.md`** — مرجع scraper (MojavecClient, TrackScraper, RSC parser).
۳. **`00-MASTER.md`** (سند من، نسخهٔ 1.0) — سند اصلی معماری v3 از صفر.
۴. **`03-strategy-and-risk.md`** (سند کاربر) — مرجع استراتژی و ریسک (جوانب پنهان، ACL، circuit breaker).

مقایسه نشان داد که دو دیدگاه **مکمل** هستند، نه متعارض:
- سند v3 من با دانش کامل دو پروژه، طراحی بتنی ارائه داد.
- سند چهارم با تمرکز بر جوانب پنهان، تدابیر پیشگیرانه را با عمق تحلیلی ارائه داد.

۱۶ بهبود از سند چهارم استخراج شد (ضمیمه‌های A تا F) که همگی به سند v3 نسخهٔ 1.0 اضافه شده‌اند.

**تصمیم نهایی فایل‌ها:** در نسخهٔ نهایی mojavez-v3، **یک سند اصلی واحد** (`00-MASTER.md` = v3 نسخهٔ 1.0) در اختیار توسعه‌دهندگان و ایجنت‌ها قرار گیرد. سه سند دیگر به‌عنوان **پیوست‌های مرجع** در `docs/` نگه‌داری شوند و در سند اصلی به آن‌ها ارجاع داده شود. این ساختار تضمین می‌کند که:
- یک منبع حقیقت واحد برای طراحی وجود دارد (جلوگیری از drift تصمیمات).
- دانش تاریخی و فنی هر سه سند دیگر حفظ می‌شود (مرجع در صورت نیاز).
- ایجنت‌های هوش مصنوعی با دستورالعمل صریح می‌دانند کدام سند را کِی بخوانند.

---

*پایان سند مقایسه.*
