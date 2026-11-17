# مستند جامع یکپارچه‌سازی پروژه Mojavez-v3
## بازنویسی از صفر: ادغام Mojavez-v2 (سامانهٔ کامل صدور مجوز) + Mojavez Gateway (لایهٔ واسط درگاه ملی)

> **نسخهٔ مستند:** 1.0
> **تاریخ تدوین:** ۱۴۰۵/۰۸/۲۶ (۲۰۲۶-۱۱-۱۶)
> **مخزن پیاده‌سازی:** توسط تیم توسعه تعیین می‌شود. **برای هر دو مخزن منبع** (`Mojavez-v2` و `mojavez-gateway`) **توکن دسترسی ارائه خواهد شد** تا پیاده‌سازی بر اساس کد واقعی و کد مستندشده انجام شود.
> **فلسفهٔ بازنویسی:** هر دو پروژهٔ پیشین از صفر، در یک پایگاه کد واحد، با فلسفهٔ «بدون Composer، بدون فریم‌ورک، PHP 8.2 خالص» بازنویسی می‌شوند.
> **منابع ورودی:** `Mojavez-v2-Integration-Document.md` (نسخهٔ v2: 0.6.3، ۱۲۳ کامیت)، `mojavez-gateway.md` (نسخهٔ gateway: 0.1.0-unreleased، ۴۹ کامیت، ۲۱ مکالمه)، و `سند_توسعه_mojavez_v2.md` (سند استراتژی-ریسک، نسخهٔ 1.0، ۱۶ نوامبر ۲۰۲۶).
> **هدف مستند:** تعریف کامل معماری، مدل دامنه، پایگاه داده، ماژول scraper، الگوریتم تطبیق الگویی، امنیت، استقرار، و نقشهٔ راه برای ساخت mojavez-v3 به‌عنوان successor یکپارچهٔ هر دو پروژه. این سند بهبودهای مستخرج از سند استراتژی-ریسک (جوانب پنهان، ACL، Circuit Breaker، idempotency، ماتریس مالکیت، ModuleInterface، Contract Test، CI/CD، Structured Logging، Rollout تدریجی) را به‌عنوان بخش‌های ۲۳ تا ۳۰ اضافه می‌کند.

---

## فهرست

1. [خلاصهٔ اجرایی و چشم‌انداز یکپارچه‌سازی](#1-خلاصهٔ-اجرایی-و-چشم‌انداز-یکپارچهسازی)
2. [تحلیل دو پروژهٔ پیشین و ارزش ادغام](#2-تحلیل-دو-پروژهٔ-پیشین-و-ارزش-ادغام)
3. [اصول و فلسفهٔ طراحی mojavez-v3](#3-اصول-و-فلسفهٔ-طراحی-mojavez-v3)
4. [معماری یکپارچهٔ mojavez-v3](#4-معماری-یکپارچهٔ-mojavez-v3)
5. [ساختار مخزن و درخت فایل‌ها](#5-ساختار-مخزن-و-درخت-فایلها)
6. [مدل دامنهٔ یکپارچه](#6-مدل-دامنهٔ-یکپارچه)
7. [پایگاه دادهٔ یکپارچه (Schema)](#7-پایگاه-دادهٔ-یکپارچه-schema)
8. [ماژول درگاه ملی و Scraper](#8-ماژول-درگاه-ملی-و-scraper)
9. [الگوریتم تطبیق الگویی و «اتصال طلایی»](#9-الگوریتم-تطبیق-الگویی-و-اتصال-طلایی)
10. [مسیرها و APIها](#10-مسیرها-و-apiها)
11. [احراز هویت و نقش‌ها](#11-احراز-هویت-و-نقشها)
12. [امنیت یکپارچه و رفع تمام مشکلات شناخته‌شده](#12-امنیت-یکپارچه-و-رفع-تمام-مشکلات-شناختهشده)
13. [زیرساخت async: صف، رویداد، SSE/Polling](#13-زیرساخت-async-صف-رویداد-ssepolling)
14. [رابط کاربری و دارایی‌های سمت کلاینت](#14-رابط-کاربری-و-داراییهای-سمت-کلاینت)
15. [پیکربندی و متغیرهای محیطی](#15-پیکربندی-و-متغیرهای-محیطی)
16. [استقرار در محیط‌های مختلف](#16-استقرار-در-محیطهای-مختلف)
17. [مهاجرت داده از هر دو پروژهٔ پیشین](#17-مهاجرت-داده-از-هر-دو-پروژهٔ-پیشین)
18. [تست و تضمین کیفیت](#18-تست-و-تضمین-کیفیت)
19. [نقشهٔ راه بازنویسی (۷ فاز)](#19-نقشهٔ-راه-بازنویسی-۷-فاز)
20. [ریسک‌ها، محدودیت‌ها و تله‌ها](#20-ریسکها-محدودیتها-و-تلهها)
21. [چک‌لیست نهایی بازنویسی](#21-چکلیست-نهایی-بازنویسی)
22. [مرجع فایل‌های کلیدی و دستورات](#22-مرجع-فایلهای-کلیدی-و-دستورات)
23. [جوانب پنهان و تدابیر پیشگیرانه](#23-جوانب-پنهان-و-تدابیر-پیشگیرانه)
24. [بهبود پایگاه داده: integration_log، idempotency، optimistic concurrency، soft-delete](#24-بهبود-پایگاه-داده)
25. [ACL، Circuit Breaker و Degraded Mode](#25-acl-circuit-breaker-و-degraded-mode)
26. [ماتریس مالکیت Schema، ModuleInterface و Monorepo](#26-ماتریس-مالکیت-schema-moduleinterface-و-monorepo)
27. [Contract Test، CI/CD و Structured Logging](#27-contract-test-cicd-و-structured-logging)
28. [احراز هویت API مبتنی بر Scope](#28-احراز-هویت-api-مبتنی-بر-scope)
29. [Rollout یک‌طرفه اول، دوطرفه بعد](#29-rollout-یک‌طرفه-اول-دوطرفه-بعد)
30. [راهنمای نهایی فایل‌ها برای توسعه‌دهندگان و ایجنت‌های هوش مصنوعی](#30-راهنمای-نهایی-فایلها-برای-توسعهدهندگان-و-ایجنتهای-هوش-مصنوعی)

---

## 1. خلاصهٔ اجرایی و چشم‌انداز یکپارچه‌سازی

**Mojavez-v3** جانشین یکپارچهٔ دو پروژهٔ پیشین است که از صفر در یک مخزن جدید بازنویسی می‌شود. این پروژه دو زنجیرهٔ مجزا را به یک سامانهٔ واحد پیوند می‌زند:

1. **زنجیرهٔ داخلی صدور مجوز** (وارث Mojavez-v2): ثبت‌نام متقاضی، گردش‌کار ۱۸ وضعیتی، پرداخت رسید-محور، بارگذاری مدارک، صدور و تحویل تصویر مجوز، پنل مدیریت چندنقشی، پنل کاربری متقاضی، گزارش‌گیری، نرخ‌نامهٔ دهک‌بندی شده.
2. **زنجیرهٔ ارتباط با درگاه ملی** (وارث Mojavez Gateway): خواندن فقط‌خواندنی و فیلترشده از `qr.mojavez.ir`، کش محلی، جستجوی چندوجهی، استخراج صفحهٔ track با RSC parser، خروجی PDF، جستجوی دسته‌ای CSV.

### ۱.۱. ارزش راهبردی ادغام: «اتصال طلایی»

هیچ‌کدام از دو پروژهٔ پیشین نمی‌توانستند حلقوی را ببندند که mojavez-v3 می‌بندد:

- **v2 می‌داند** متقاضی درخواست داده، ولی **نمی‌داند** چه زمانی مجوزش در درگاه ملی صادر شده.
- **Gateway می‌داند** چه مجوزهایی صادر شده، ولی **هیچ اطلاعی** از متقاضی‌های v2 ندارد.
- **v3 این دو را به‌هم وصل می‌کند:** scraper هر از گاهی درگاه ملی را می‌خواند، مجوز صادرشده را با **تطبیق الگویی + آرای چندفیلدی** (ماسک به‌عنوان لنگر) به متقاضی محلی نگاشت می‌کند، وضعیت پرونده را خودکار به «صدور مجوز» / «تحویل مجوز» تغییر می‌دهد، فیلد `license_state` را به `صادر شده` با `license_valid_until` تنظیم می‌کند، و به متقاضی پیام می‌دهد (اعلان درون‌سامانه + SMS الگو-محور).

این اتصال خودکار، ارزش اصلی کل پروژهٔ بازنویسی است و در v3 به‌عنوان یک **ماژول first-class** به نام `NationalGatewaySync` پیاده‌سازی می‌شود.

### ۱.۲. تصمیمات راهبردی کلیدی

| تصمیم | انتخاب | علت |
|---|---|---|
| زبان | PHP 8.2+ خالص | هر دو پروژهٔ پیشین PHP خالص بودند؛ تداوم فلسفهٔ «بدون Composer» |
| مدیریت بسته | بدون Composer | تداوم سیاست سخت‌گیرانهٔ هر دو پروژه؛ قابلیت استقرار روی هاست اشتراکی محدود |
| پایگاه داده | MySQL 5.7+ / MariaDB 10.3+ با InnoDB، `utf8mb4_unicode_ci` | v2 این را دارد؛ gateway از SQLite طراحی‌شده برای انتقال به MySQL بود — انتقال نهایی انجام می‌شود |
| معماری | MVC + Event-Driven + REST API | ترکیب نقاط قوت هر دو پروژه |
| DI Container | Container دستی با autowire + setter injection | وارث طراحی gateway (رفع وابستگی‌های چرخه‌ای 003-01) |
| Frontend | Bootstrap 5 RTL + Vazirmatn (self-host) + jsPDF + html2canvas + qrcode + PDF.js | وارث v2 برای پنل‌ها + وارث gateway برای صفحهٔ جزئیات مجوز |
| استقرار اولیه | هاست اشتراکی cPanel/DirectAdmin + Apache | بازار هدف هر دو پروژه؛ Docker نیز پشتیبانی می‌شود |
| مجوز کد | MIT | تداوم مجوز gateway (Copyright ۲۰۲۶) |

### ۱.۳. آنچه از هر پروژه به ارث می‌آید و آنچه کنار گذاشته می‌شود

| از Mojavez-v2 به ارث می‌آید | از Mojavez Gateway به ارث می‌آید | کنار گذاشته می‌شود |
|---|---|---|
| ۱۸ وضعیت گردش کار و ماتریس انتقال نقش‌ها | `MojavecClient` (کلاینت جستجوی structured) | کد duplicate از هر دو پروژه |
| ۴ مقدار `license_state` (جاری/صادر شده/باطل شده/منقضی) | `TrackScraper` (RSC payload parser) | SQLite از gateway (به MySQL مهاجرت) |
| کد رهگیری `HG-XXXXXXXX` با CSPRNG | `AntiDetectionMiddleware` (هدرهای ثابت + jitter) | CSRF غیرفعال gateway (در v3 فعال) |
| احراز هویت دو قلمرو (admin + user) با نشست‌محور | `RateLimiterService` (با jitter Gaussian) | احراز هویت شش‌عاملی قدیمی v2 (حذف‌شده در v2.6) |
| هدرهای امنیتی سه‌لایه + `.htaccess` | `JobQueue` + `Worker` + `EventBus` | رمز پیش‌فرض `admin` از gateway |
| بارگذاری ۷لایهٔ امن فایل | صفحهٔ جزئیات مجوز با QR + PDF | `unsafe-eval` در CSP (در v3 حذف) |
| نرخ‌نامهٔ دهک‌بندی + رسید پرداخت | جستجوی چندوجهی (استان/شهر/سازمان/تاریخ) | rate limiter کد مرده gateway (در v3 واقعاً فعال) |
| گزارش‌گیری CSV/PDF با Formula Injection protection | خروجی CSV با BOM | `CURLOPT_SSL_VERIFYPEER=false` از gateway (در v3 روشن) |
| `AutoCancelService` + اولویت‌بندی | `PersianDate` (تبدیل شمسی↔میلادی) | `CURLOPT_FOLLOWLOCATION` بدون محدودیت از gateway |
| چندمجوزی و سلسله‌مراتب (`parent_application_id`) | الگوریتم تطبیق الگویی (ماسک به‌عنوان لنگر) | `phpinfo.php` (در v2 حذف، در v3 هرگز وجود ندارد) |
| `/health` بدون احراز هویت برای مانیتورینگ | `CsvReader` با حذف BOM | `seed-admin.sql` با رمز hardcoded (در v3 نصب تعاملی) |

---

## 2. تحلیل دو پروژهٔ پیشین و ارزش ادغام

### ۲.۱. جدول مقایسه‌ای فنی

| شاخص | Mojavez-v2 | Mojavez Gateway | mojavez-v3 (هدف) |
|---|---|---|---|
| نسخهٔ پایه | 0.6.3 | 0.1.0-unreleased | 0.1.0 (بازنویسی) |
| زبان | PHP 8.0+ خالص | PHP 8.2+ خالص | PHP 8.2+ خالص |
| DB | MySQL/MariaDB (۲۸ جدول) | SQLite (۹ جدول) | MySQL/MariaDB (~۳۲ جدول یکپارچه) |
| فریم‌ورک | MVC دست‌نویس | MVC + Event-Driven + REST | MVC + Event-Driven + REST یکپارچه |
| DI | `Database::getInstance()` Singleton | Container دستی autowire | Container دستی autowire (وارث gateway) |
| مسیرها | ~۱۰۹ | ~۴۰ | ~۱۴۰ (یکپارچه) |
| کنترلرها | ۲۶ | ۷ | ~۲۸ (با ماژول‌بندی) |
| مدل‌ها | ۲۷ | readonly Value Objects | Domain + Repositories یکپارچه |
| کامیت‌ها | ۱۲۳ | ۴۹ | از صفر |
| Frontend | Bootstrap 5 RTL + PDF.js | vanilla JS + jsPDF + html2canvas | Bootstrap 5 RTL + PDF.js + jsPDF + html2canvas + qrcode |
| تست‌ها | ۱۷۱ smoke + ۷ PHP unit + static | ۱۵ assertion رگرسیون scraper | ۱۷۱+ smoke + رگرسیون scraper + واحد + static |
| استقرار | Apache/Nginx/Docker/Shared | Shared hosting (cPanel) | Apache/Nginx/Docker/Shared |
| ارتباط با درگاه ملی | ندارد (فاز باقی‌مانده) | خوانندهٔ فقط‌خواندنی | یکپارچه با «اتصال طلایی» |

### ۲.۲. نقاط قوت و ضعف هر پروژه

**Mojavez-v2 — نقاط قوت:**
- گردش‌کار کامل و بالغ (۱۸ وضعیت، ماتریس انتقال، قواعد terminal).
- امنیت لایه‌به‌لایه سخت‌گیرانه (CSRF فعال، rate limit IP+session، ۷لایه آپلود، هدرهای امنیتی، مسدودسازی موتورهای جستجو و خزنده‌های AI).
- مدل دادهٔ پخته (۲۸ جدول با روابط، JSON payload برای بازبینی، چندمجوزی با self-ref).
- استقرار متنوع و پشتیبانی واقعی از هاست اشتراکی.
- ممیزی امنیتی انجام‌شده (۴ بحرانی + ۵ HIGH + ۲ MEDIUM رفع شده).

**Mojavez-v2 — نقاط ضعف:**
- **هیچ API REST/JSON عمومی** برای خواندن دادهٔ پرونده (همه HTML).
- **هیچ اتصالی به درگاه ملی** (فاز باقی‌ماندهٔ صریح).
- `Database::getInstance()` بدون DI Container (سخت‌گیری در تست).
- rate limit مبتنی بر نشست قابل دور زدن با drop cookie.
- `TRACKING_REQUIRE_MOBILE=false` پیش‌فرض.

**Mojavez Gateway — نقاط قوت:**
- scraper واقعی و تست‌شده با HAR واقعی (`MojavecClient` + `TrackScraper`).
- طراحی لایه‌بندی تمیز (Domain خالص، Repository، Strategy parser).
- الگوریتم تطبیق الگویی (ماسک به‌عنوان لنگر) — راه‌حل بحرانی R1.
- زیرساخت async (JobQueue + EventBus + Worker).
- فلسفهٔ ضد‌شناسایی احترام‌آمیز (نه spoofing مخرب).

**Mojavez Gateway — نقاط ضعف (همگی در v3 رفع می‌شوند):**
- **CSRF کاملاً غیرفعال** (S1).
- **هیچ احراز هویتی روی ۹ endpoint `/api/admin/*`** (S2).
- **رمز admin پیش‌فرض `admin`** (S3).
- **SSRF در `/api/proxy-image`** (S4: `CURLOPT_SSL_VERIFYPEER=false`، `FOLLOWLOCATION=true`، `CORS *`).
- **rate limiter کد مرده** (ST1: `$rateLimiter` عملاً پاس نمی‌شود).
- **فیلدهای track در cache پایدار نیستند** (I1: در re-cache از دست می‌روند).
- SQLite (محدودیت همزمانی در حجم بالا).
- شکنندگی scraper به تغییر فرمت RSC (R6).

### ۲.۳. چرا بازنویسی از صفر، نه merge تدریجی؟

۱. **تفاوت پایگاه‌داده (SQLite vs MySQL):** merge مستقیم impossible است؛ migration کامل نیاز است.
۲. **تفاوت DI (Singleton vs Container):** بازنویسی لایهٔ service تمیزتر از تلاش برای سازگار کردن دو الگو است.
۳. **مشکلات امنیتی gateway:** رفع درجا سخت است؛ بازنویسی با امنیت-by-design ساده‌تر.
۴. **دو نسخهٔ «مستند یکپارچه‌سازی»** (این سند و `Mojavez-v2-Integration-Document.md`) هر دو یک پروژهٔ واحد را توصیف می‌کنند — بازنویسی امکان می‌دهد که هر دو دیدگاه در یک طراحی نهایی آشتی کنند.
۵. **پاکسازی بدهی فنی:** v2 `REFACTOR_PLAN.md` خود ۱۳ فاز داشت که همگی انجام شد، اما کد هنوز حامل تصمیمات اولیه است؛ gateway ۱۶ خطای ثبت‌شده دارد که در git history باقی‌اند. شروع تازه، تاریخچهٔ تمیز می‌دهد.

---

## 3. اصول و فلسفهٔ طراحی mojavez-v3

### ۳.۱. اصول بنیادین

۱. **بدون Composer، بدون فریم‌ورک، بدون وابستگی خارجی PHP.** هرچه لازم شود، با PHP نیتیو نوشته می‌شود. این تداوم سیاست سخت‌گیرانهٔ هر دو پروژهٔ پیشین است. استثنا: دارایی‌های سمت کلاینت (Bootstrap، Vazirmatn، jsPDF، html2canvas، qrcode، PDF.js) که به‌صورت دستی vendor شده و در `public/assets/vendor/` قرار می‌گیرند.

۲. **لایه‌بندی تمیز (Clean Architecture).** جریان وابستگی فقط از بالا به پایین است. لایهٔ Domain **هیچ وابستگی به Infrastructure ندارد**. اگر به داده نیاز دارد، از interface در دامنهٔ خود استفاده می‌کند که در DI Container به پیاده‌سازی MySQL متصل می‌شود. این تداوم قانون کلیدی gateway است.

۳. **DI Container دستی با autowire.** Container در `app/Container.php` با `spl_autoload_register`، lazy initialization، و ReflectionClass برای autowire. برای وابستگی‌های چرخه‌ای، الگوی **setter injection** با متد `public function setXRepository(X $repo): void` (تداوم استاندارد رفع 003-01). Container، Environment، Logger، Connection را به‌عنوان **memoized singleton** ثبت می‌کند (تداوم رفع 004-03 و 004-05).

۴. **Domain به‌صورت readonly immutable Value Objects.** تداوم فلسفهٔ gateway: کلاس‌های `readonly` در `app/Domain/`. این امنیت نوعی و قابلیت تست بالا می‌دهد.

۵. **امنیت by design، نه bolt-on.** همهٔ مشکلات شناخته‌شدهٔ gateway (S1-S5, ST1, I1) و توصیه‌های v2 (TRACKING_REQUIRE_MOBILE، ADMIN_IP_ALLOWLIST، CRON_SECRET) از روز اول فعال هستند.

۶. **Resilient by default.** هر ارتباط خارجی (درگاه ملی، DB) با retry، backoff، fallback کش، و fail-fast timeout همراه است. `/health` برای مانیتورینگ آماده است.

۷. **Private by default.** `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex` روی همهٔ پاسخ‌ها. مسدودسازی موتورهای جستجو و خزنده‌های AI در `.htaccess` (سه لایه از v2).

۸. **Self-host assets.** هیچ CDNی استفاده نمی‌شود (CDN در ایران ناپایدار است). فونت Vazirmatn، Bootstrap، PDF.js، jsPDF، html2canvas، qrcode همگی در `public/assets/vendor/` self-host می‌شوند.

### ۳.۲. قراردادهای کدنویسی

- **namespace:** `App\` به `app/` (مثلاً `App\Services\LicenseService` → `app/Services/LicenseService.php`). با `spl_autoload_register`.
- **تبدیل تاریخ:** همیشه با `App\Support\PersianDate` (وارث gateway، منطبق با `config/jdf.php` از v2). هیچ تبدیل تاریخ خارج از این کلاس مجاز نیست.
- **ارقام:** نرمال‌سازی ارقام فارسی/عربی به لاتین در همهٔ ورودی‌ها با `App\Support\Normalizer::digits()`.
- **prepared statements:** همیشه با PDO و `ATTR_EMULATE_PREPARES=false`. هیچ کوئری بدون prepared statement. برای پارامترهای نام‌دار تکراری، نام‌های مجزا (`:v`, `:v2`) استفاده می‌شود (تداوم رفع v2 در `Setting::set`).
- **escape HTML:** همیشه با `htmlspecialchars($v, ENT_QUOTES|ENT_HTML5, 'UTF-8')`. هیچ خروجی خام به مرورگر.
- **ثابت‌ها:** در `config/*.php` به‌صورت آرایه بازگشتی، نه `define()` سراسری.
- **خطاها:** `throw new RuntimeException` + global handler (JSON برای AJAX، HTML برای سایر). هیچ `die()`.

---

## 4. معماری یکپارچهٔ mojavez-v3

### ۴.۱. نمودار لایه‌ها

```
        ┌──────────────────────────────────────────────────────────┐
        │              لایه Presentation                            │
        │   Public Site | Registration Wizard | Tracking |          │
        │   User Panel | Admin Panel | National Gateway Viewer |   │
        │   License Detail (QR+PDF) | Batch Search | Reports        │
        └──────────────────────┬───────────────────────────────────┘
                               │  (HTTP + SSE/Polling + CSRF)
        ┌──────────────────────▼───────────────────────────────────┐
        │              لایه Controllers (MVC)                       │
        │  Home | Auth | Registration | Tracking | License |        │
        │  Records | Admin | User | Report | Payment | Revision |  │
        │  Sms | FormField | ServiceType | WizardStep | Setting |   │
        │  LicenseTitle | BulkImport | FeeTariff | Document |       │
        │  NationalGateway | Batch | Search | Sse | Proxy           │
        └──────────────────────┬───────────────────────────────────┘
                               │
        ┌──────────────────────▼───────────────────────────────────┐
        │              لایه REST API (داخلی + یکپارچه)               │
        │  /api/v1/licenses | /api/v1/search | /api/v1/batch |      │
        │  /api/v1/filters | /api/v1/lookups | /api/v1/proxy-image |│
        │  /api/v1/applicants/{code} | /api/v1/admin/* |             │
        │  /api/v1/sync/status (مانیتورینگ اتصال طلایی) |             │
        │  /api/v1/stream (SSE در production)                       │
        └──────────────────────┬───────────────────────────────────┘
                               │
        ┌──────────────────────▼───────────────────────────────────┐
        │              لایه Services (Domain Logic)                 │
        │  License | Applicant | Person | Filter | Payment |        │
        │  Registration | Revision | Sms | CsvBatch | Cache |        │
        │  Export | RateLimiter | AutoCancel | FeeCalculator |      │
        │  FileStorage | PatternMatcher | NationalSync |             │
        │  Notification                                              │
        └──────────────────────┬───────────────────────────────────┘
                               │
        ┌──────────────────────▼───────────────────────────────────┐
        │       لایه Event-Driven Core (Async)                      │
        │   EventBus  →  JobQueue  →  Worker                        │
        │   (SSE push در production / polling در dev)                │
        │   Events: SearchRequested, LicenseIssuedOnNational,        │
        │   ApplicantMatched, LicenseSaved, JobFailed, BatchProgress,│
        │   CacheHit, SyncCycleCompleted                             │
        └──────────────────────┬───────────────────────────────────┘
                               │
        ┌──────────────────────▼───────────────────────────────────┐
        │     لایه Persistence + Infrastructure                     │
        │  Repositories (MySQL/PDO, prepared statements واقعی)      │
        │  MojavecClient (cURL) + AntiDetectionMiddleware            │
        │  TrackScraper (RSC payload parser) + Parser/ (Strategy)    │
        │  FileStorage (uploads/{tracking_code}/, GD, MIME check)    │
        │  PdfGenerator | CsvExporter                                │
        └──────────────────────┬───────────────────────────────────┘
                               │
                  ┌────────────┴────────────┐
                  ▼                         ▼
        ┌─────────────────────┐  ┌─────────────────────────┐
        │  MySQL/MariaDB      │  │  qr.mojavez.ir           │
        │  (منبع حقیقت محلی)  │  │  (درگاه ملی، فقط از      │
        │                     │  │   داخل ایران)             │
        └─────────────────────┘  └─────────────────────────┘
```

### ۴.۲. الگوهای GoF به‌کار رفته

| الگو | پیاده‌سازی | کاربرد |
|---|---|---|
| **Front Controller** | `public/index.php` | نقطهٔ ورود واحد؛ autoloader + Container + install-check + Router + security headers |
| **Strategy** | `Parser/ResponseParserInterface` + `JsonSearchParser` | تعویض parser در صورت تغییر upstream |
| **Observer** | `EventBus` + `Subscribers/LogSubscriber`, `SyncSubscriber` | جداسازی تولیدکننده و مصرف‌کنندهٔ رویداد |
| **Repository** | `ApplicantRepository`, `LicenseRepository`, ... | جدا کردن Domain از جزئیات ذخیره‌سازی |
| **Adapter** | `MojavecClient` روی `HttpClient` (cURL) | رابط ساده برای جستجو/lookup درگاه ملی |
| **Command** | `JobInterface` + `Jobs/SearchJob`, `Jobs/BatchJob`, `Jobs/SyncJob` | صف‌بندی، retry، اولویت‌بندی |
| **Factory** | `License::fromUpstream()`, `License::fromTrackPage()`, `License::fromArray()`, `Applicant::fromForm()` | متمرکز کردن نگاشت دادهٔ خام به Domain Object |
| **Singleton (محدود)** | `Container`, `Config`, `Environment`, `Logger`, `Connection` | تضمین یک نمونهٔ واحد |
| **Module** | `app/Modules/NationalGateway/`, `app/Modules/Registration/`, ... | مرزهای واضح حوزه‌های کسب‌وکار |

### ۴.۳. جریان درخواست (Request Flow)

```
HTTP Request
  → public/.htaccess (RewriteRule → index.php?url=..., security headers, bot blocking)
  → public/index.php
       ├─ require config/config.php → load_env() (config/env.php) → define constants
       ├─ error_reporting + ini_set (طبق APP_DEBUG)
       ├─ set_exception_handler (JSON برای AJAX، HTML برای سایر) + set_error_handler
       ├─ هدرهای امنیتی سراسری (X-Frame-Options, CSP, COOP, CORP, Cache-Control, X-Robots-Tag, HSTS)
       ├─ Session::start() (cookie flags, strict mode, regenerate هر ۳۰ دقیقه)
       ├─ بررسی IP_BLOCKLIST (early exit 403)
       ├─ install-check (وجود DB و جدول migrations، در غیر این صورت redirect به install.php)
       ├─ ساخت Container و boot (singletonها ثبت، factoryها تعریف)
       ├─ ساخت Request
       ├─ مسیر /health (بدون احراز هویت)
       ├─ مسیر /api/stream (در production فعال، در dev پاسخ 204)
       ├─ Router(BASE_URL) → ثبت ~۱۴۰ مسیر (ترتیب مهم، first-match)
       └─ Router::dispatch(method, uri)
              → Controller@action
                   ├─ requireLogin()/requireUserLogin()/requireAnyLogin()/requireRole([...]) (guards)
                   ├─ ADMIN_IP_ALLOWLIST بررسی
                   ├─ verifyCsrf() (برای POST — همیشه فعال)
                   ├─ rate limit IP/session (برای endpointهای حساس)
                   ├─ validateStatusTransition() (برای تغییر وضعیت پرونده)
                   ├─ Service→Repository→Database::getInstance() (Container تزریق)
                   ├─ View render (layout + content) یا JSON response
                   └─ Response (HTML/JSON/file stream)
```

### ۴.۴. قانون طلایی لایه‌بندی

**هیچ لایه‌ای نباید به لایهٔ بالایی خود وابسته باشد.** به‌طور خاص:
- لایهٔ Domain هرگز به Infrastructure وابسته نیست.
- لایهٔ Services به Domain و Repository interfaces وابسته است، نه به پیاده‌سازی.
- Controllers به Services وابسته‌اند.
- هیچ کد shared بین admin و user realm از طریق session key مشترک (هر قلمرو کلیدهای session مجزا دارد).

این قانون امکان تست واحد بالا و تعویض پیاده‌سازی‌ها (مثلاً MySQL → PostgreSQL در آینده) را با کمترین تغییر فراهم می‌کند.

---

## 5. ساختار مخزن و درخت فایل‌ها

### ۵.۱. درخت پروژهٔ mojavez-v3

```
mojavez-v3/
├── public/                      # document root
│   ├── index.php                # front controller + autoloader + install-check + /health + /check-cancel
│   ├── install.php              # نصاب one-time (پس از نصب حذف شود)
│   ├── router.php               # PHP built-in server router (توسعه)
│   ├── .htaccess                # rewrite + security headers + CSP + bot blocking + ErrorDocument
│   ├── robots.txt               # noindex
│   └── assets/
│       ├── css/                 # app, cards, tables, admin, dashboard, analytics,
│       │                        #   license-detail, registration, tracking, static, print, tokens
│       ├── js/                  # app, cards, tables, realtime, batch, admin, dashboard,
│       │                        #   analytics, license-detail, registration-wizard, tracking,
│       │                        #   qr-generator, national-sync-monitor
│       ├── vendor/              # self-host: bootstrap.rtl.min.css, vazirmatn.woff2,
│       │                        #   pdf.min.js, pdf.worker.min.js, cmaps/, standard_fonts/,
│       │                        #   html2canvas-vendor.js, jspdf-vendor.js, qrcode-vendor.js,
│       │                        #   lalezar.woff2, ofl.txt
│       ├── img/                 # favicon.svg, logo.svg
│       └── templates/           # batch-sample.csv (UTF-8 BOM), registration-form-template.json
├── app/
│   ├── Container.php            # DI container دستی (autowire + singleton + factory + setter)
│   ├── Controllers/             # ~۲۸ کنترلر (به تفکیک زیر)
│   ├── Services/                # ~۲۰ سرویس
│   ├── Domain/                  # readonly Value Objects + Enums
│   ├── Modules/                 # مرزهای حوزه‌ای
│   │   ├── NationalGateway/     # MojavecClient + TrackScraper + AntiDetection + Sync orchestrator
│   │   ├── Registration/        # ویزارد ثبت‌نام پویا + ApplicantRegistrationService
│   │   ├── License/             # صدور، تحویل، تصویر مجوز، LicenseAccessRequest
│   │   ├── Payment/             # رسید پرداشت، نرخ‌نامه، FeeCalculatorService
│   │   ├── Revision/            # بازبینی پرونده
│   │   └── Reporting/           # گزارش‌گیری CSV/PDF
│   ├── Infrastructure/          # HttpClient, HttpResponse, FileStorage, PdfGenerator, CsvExporter
│   ├── Events/                  # EventBus + Events/ + Subscribers/ (LogSubscriber, SyncSubscriber)
│   ├── Queue/                   # JobInterface, JobQueue, Worker + Jobs/ (Search, Batch, Sync)
│   ├── Persistence/             # Connection (MySQL), MigrationRunner, Repositories/ + Migrations/
│   ├── Api/                     # ApiController + Responses/JsonResponse
│   ├── Http/                    # Request, Response, Router, Session, Csrf + Exceptions/
│   └── Support/                 # Config, Environment, Logger, Validator, Hash,
│                                #   PersianDate, CsvReader, Normalizer, PersianJdf
├── config/                      # app, database, anti-detection, filters, routes, statuses, workflow (۷ فایل)
├── database/                    # schema.sql (مرجع), install.sql (idempotent), seed-defaults.sql,
│                                #   migrations/ (۱۵+ فایل)
├── deploy/
│   ├── apache/                  # mojavez.conf (vhost)
│   ├── nginx/                   # mojavez.conf (server block)
│   ├── docker/                  # Dockerfile, docker-compose.yml, entrypoint.sh, init-db.sql,
│   │                            #   nginx-proxy.conf, .env.example
│   ├── linux/                   # install.sh, install-service.sh (systemd), README.md
│   ├── shared-hosting/          # install.php (وب), check.php (پیش‌نیازها), .htaccess, root-htaccess.htaccess
│   └── windows/                 # README.md, install-and-run.bat
├── storage/                     # logs, cache, exports (هرکدام با .gitkeep)
├── uploads/                     # فایل‌های بارگذاری‌شده (خارج از public + .htaccess محافظ)
│   └── {tracking_code}/         # national_card_front_xxx.jpg, ..., license_image_xxx.jpg
├── tests/                       # smoke-test.sh, TestCase.php, ۱۵+ فایل تست PHP, run.php,
│   │                            #   fixtures/har/ (فایل‌های HAR واقعی برای تست scraper),
│   │                            #   reports/
├── scripts/                     # migrate.php, run_worker.php, seed_lookups.php, seed_defaults.php,
│   │                            #   test_track_scraper.py (تست رگرسیون scraper — حتماً منتقل),
│   │                            #   migrate_from_v2.php (مهاجرت داده از v2),
│   │                            #   migrate_from_gateway.php (مهاجرت داده از gateway),
│   │                            #   pattern_matcher.py (مرجع الگوریتم تطبیق)
├── tools/                       # static-analysis.py, responsive-test.py (Python)
├── docs/                        # ~۲۰ سند فارسی (معماری، API، استقرار، DB، امنیت، تست،
│                                #   نقشه‌راه، واکنش‌گرایی، عملکردی، PATTERN_MATCHING،
│                                #   WORKER_FRAMEWORK, ANTI_DETECTION, API_DISCOVERY, ...)
├── .editorconfig, .gitattributes, .gitignore, .dockerignore, .env.example
├── CHANGELOG.md                 # از 0.1.0 (v3) شروع می‌شود
├── LICENSE                      # MIT
├── REFACTOR_PLAN.md             # سند مرجع بازنویسی (این سند)
├── VERSION                      # "0.1.0"
└── README.md
```

### ۵.۲. اصل ماژول‌بندی

برخلاف v2 (که همه در `app/Controllers/` و `app/Models/` بود) و gateway (که Domain تمیز داشت)، v3 مرزهای حوزه‌ای واضح با ماژول‌ها می‌سازد. هر ماژول در `app/Modules/<Name>/` شامل:
- `Controllers/` (در صورت نیاز)
- `Services/`
- `Domain/` (Value Objects اختصاصی)
- `README.md` (توضیح مرز ماژول)

این امکان را می‌دهد که `NationalGateway` به‌عنوان یک what complete با مرز روشن در نظر گرفته شود — همان چیزی که در نقشهٔ راه ادغام gateway «انتقال scraper به‌عنوان بخش تازه در پروژهٔ مقصد» توصیف شده بود.

---

## 6. مدل دامنهٔ یکپارچه

لایهٔ Domain در `app/Domain/` و در ماژول‌ها، خالص‌ترین لایه است. همهٔ کلاس‌ها `readonly` و `immutable` هستند.

### ۶.۱. Value Objects اصلی

#### `Applicant` (قلب سامانه — پرونده)

```php
final readonly class Applicant {
    public function __construct(
        public int $id,
        public int $personId,
        public string $trackingCode,           // HG-XXXXXXXX (CSPRNG)
        public string $nationalCode,            // CHAR(10), validated
        public ApplicantStatus $currentStatus,  // یکی از ۱۸ وضعیت
        public LicenseState $licenseState,      // جاری|صادر شده|باطل شده|منقضی
        public ?string $licenseValidUntil,      // DATE (شمسی در UI، میلادی در DB)
        public int $serviceTypeId,
        public ?int $parentApplicationId,       // چندمجوزی (self-ref)
        public bool $priority,
        public ?string $registrationStartedAt,
        public ?string $registrationCompletedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromForm(array $formData): self { ... }
    public static function fromArray(array $row): self { ... }
    public function isTerminal(): bool { return $this->currentStatus->isTerminal(); }
    public function isIssuedLicense(): bool {
        return $this->licenseState === LicenseState::Issued
            && $this->currentStatus->isTerminal();
    }
}
```

#### `License` (از gateway به ارث، گسترش‌یافته)

```php
final readonly class License {
    public function __construct(
        public string $requestNumber,           // شناسهٔ ملی (مثل I17981871)
        public string $applicantName,
        public ?string $userImage,               // URL تصویر متقاضی از درگاه ملی
        public string $licenseTitle,
        public string $organizationTitle,
        public string $provinceTitle,
        public string $townshipTitle,
        public string $respondedAt,              // Gregorian
        public LicenseStatus $status,            // active|expired|unknown
        public ?string $statusId,
        public ?string $statusTitle,
        public ?string $matchedLocalTrackingCode, // اتصال طلایی: nullable تا تطبیق یافته شود
        public ?int $confidenceScore,             // 0-100 از PatternMatcher
    ) {}

    public static function fromUpstream(array $row): self { ... }
    public static function fromTrackPage(array $t): self { ... }
    public static function fromArray(array $row): self { ... }
    public function avatarUrl(string $upstreamBase): ?string { ... }
    public function initials(): string { ... }
}
```

#### `Filter` (از gateway به ارث — payload جستجوی upstream)

```php
final readonly class Filter {
    public function __construct(
        public ?string $licenseId = null,
        public ?string $name = null,
        public ?string $issueStartDate = null,    // شمسی yyyy/mm/dd
        public ?string $issueEndDate = null,
        public ?string $lastOpStartDate = null,
        public ?string $lastOpEndDate = null,
        public ?int $provinceId = null,
        public ?int $townshipId = null,
        public ?string $mainOrgCode = null,
        public ?string $subOrgCode = null,
        public int $page = 1,
        public int $pageSize = 20,
        public ?string $label = null,
        public ?int $savedId = null,
        //新增: فیلتر مخصوص اتصال طلایی
        public bool $unmatchedOnly = false,       // فقط مجوزهای ملی که به متقاضی محلی وصل نشده‌اند
        public ?int $serviceTypeId = null,         // محدود کردن به نوع خدمت خاص
    ) {}

    public function toUpstreamPayload(): array { ... } // تبدیل شمسی→میلادی
    public function toLocalFilterArray(): array { ... }
    public function cacheKey(): string { ... }     // SHA-256
    public function isEmpty(): bool { ... }
}
```

#### `Person` (لنگر هویت — از v2 به ارث)

```php
final readonly class Person {
    public function __construct(
        public int $id,
        public string $nationalCode,              // UNIQUE, validated
        public string $firstName,
        public string $lastName,
        public string $mobile,                     // normalized
        public PersonType $personType,             // حقیقی|حقوقی
        public ?string $companyNationalId,         // CHAR(11) برای حقوقی
        public ?string $fatherName,                // برای تطبیق الگویی
        public ?string $birthDate,
        public string $createdAt,
    ) {}
}
```

### ۶.۲. Enums

#### `ApplicantStatus` (۱۸ وضعیت — وارث v2)

```php
enum ApplicantStatus: string {
    // ۱۴ وضعیت مشترک
    case Draft = 'پیش‌نویس';
    case InfoComplete = 'تکمیل اطلاعات';
    case AddressVerification = 'احراز نشانی';
    case FieldExpertReview = 'بررسی کارشناسی رشته';
    case IssueRequest = 'ثبت درخواست صدور';      // نقطهٔ شمارش ۱۴ روز لغو خودکار
    case DocUploadToOrg = 'بارگذاری مدارک به سازمان';
    case DocCorrectionRequest = 'درخواست اصلاح مدارک';
    case InitialApproval = 'تأیید اولیه';
    case FinalApproval = 'تأیید نهایی';
    case LicenseIssuance = 'صدور مجوز';          // میانی
    case LicenseDelivery = 'تحویل مجوز';          // terminal (صنایع دستی)
    case ViewAndPrint = 'مشاهده و چاپ';
    case Rejected = 'رد درخواست';                  // terminal
    case Cancelled = 'لغو';                        // terminal
    // ۴ وضعیت اختصاصی مشاغل خانگی مستقل (service_type_id = 2)
    case AddressSabtAhvalMatch = 'تطبیق آدرس با ثبت احوال';
    case LicenseImageReceipt = 'دریافت تصویر مجوز';
    case InitialApprovalSMKH = 'تأیید اولیه در سامانه مشاغل خانگی';
    case FinalApprovalTrackingCode = 'تأیید نهایی دریافت کد رهگیری'; // terminal

    public function order(): int { ... }
    public function isTerminal(): bool { ... }
    public function isPreIssue(): bool { ... }
    public function isManual(): bool { ... }
    public function services(): array { ... }     // برای وضعیت‌های اختصاصی
    public function fromUpstreamSlug(string $slug): self { ... }
    public function titleFa(): string { ... }
}
```

#### `LicenseState` (۴ مقدار — وارث v2، معادل درگاه ملی)

```php
enum LicenseState: string {
    case Current = 'جاری';          // در حال بررسی / Pending
    case Issued = 'صادر شده';        // معتبر / Valid (اگر valid_until >= today)
    case Revoked = 'باطل شده';       // ابطال شده / Revoked
    case Expired = 'منقضی';          // منقضی / Expired (سیستمی)

    public function toNationalStatus(): string {
        return match($this) {
            self::Issued => 'active',     // اگر license_valid_until >= today
            self::Current => 'pending',
            self::Revoked => 'revoked',
            self::Expired => 'expired',
        };
    }
}
```

#### `LicenseStatus` (از gateway — وضعیت در درگاه ملی)

```php
enum LicenseStatus: string {
    case Active = 'active';
    case Expired = 'expired';
    case Unknown = 'unknown';

    public static function fromUpstream(?string $slug, ?string $id): self { ... }
    public function titleFa(): string { ... }
}
```

#### `JobStatus` (از gateway — وضعیت job در صف)

```php
enum JobStatus: string {
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Retrying = 'retrying';
}
```

#### `MatchConfidence` (جدید — برای اتصال طلایی)

```php
enum MatchConfidence: int {
    case AutoConfirmed = 90;   // کد ملی MATCH + ≥۲ فیلد
    case OperatorFlag = 50;    // ۱-۲ فیلد، نیاز مرور دستی
    case AutoRejected = 0;     // لنگر mismatch کد ملی
}
```

### ۶.۳. Collections

- `ApplicantCollection` (page, pageSize, total) — برای گزارش‌ها و لیست admin.
- `LicenseCollection` (page, pageSize, total) — وارث gateway، برای نتایج جستجوی درگاه ملی.

### ۶.۴. متدهای کمکی یکپارچه

- `Applicant::nationalCodeMaskPattern(): string` — تبدیل کد ملی کامل به الگوی ماسک `073****269` برای مقایسه با scraper.
- `Person::fullAddressMaskPattern(): string` — ساخت الگوی ماسک نشانی برای تطبیق.
- `License::isEligibleForGoldenLink(): bool` — آیا این مجوز ملی قابل تطبیق با متقاضی محلی است (`status=active`، `responded_at` معتبر).

---

## 7. پایگاه دادهٔ یکپارچه (Schema)

### ۷.۱. مشخصات کلی

- **موتور:** MySQL 5.7+ / MariaDB 10.3+ با InnoDB.
- **کاراکترست:** `utf8mb4` / `utf8mb4_unicode_ci` (پشتیبانی کامل فارسی و ZWNJ).
- **TimeZone نشست:** `+00:00` (UTC برای TIMESTAMPهای یکپارچه) — در `Connection::boot()`.
- **تعداد جداول:** ~۳۲ جدول یکپارچه (۲۸ از v2 + ۴ جدید برای درگاه ملی و اتصال طلایی + ۲ از gateway که با v2 همپوشانی دارند و حذف می‌شوند).
- **دو فایل نصب:** `database/schema.sql` (مرجع، بدون ALTER) و `database/install.sql` (idempotent با `CREATE TABLE IF NOT EXISTS` + `ALTER TABLE` تدافعی + `INSERT ... SELECT ... FROM DUAL WHERE NOT EXISTS`).

### ۷.۲. لیست جداول (۳۲ جدول)

#### الف) جداول وارث از v2 (۲۸ جدول — با تغییرات جزئی)

| # | جدول | نقش | تغییر در v3 |
|---|---|---|---|
| ۱ | `users` | حساب‌های مدیر/کارکنان | بدون تغییر |
| ۲ | `persons` | لنگر هویت (کد ملی یکتا) | افزودن `father_name`, `birth_date` برای تطبیق الگویی |
| ۳ | `service_types` | انواع خدمت (۵ سرویس) | بدون تغییر |
| ۴ | `applicants` | قلب سامانه — پرونده | افزودن `national_license_request_number` (nullable, UNIQUE) برای اتصال طلایی، `matched_at`, `match_confidence` |
| ۵ | `job_details` | اطلاعات شغلی (۱:۱) | بدون تغییر |
| ۶ | `property_details` | اطلاعات ملک (۱:۱) | بدون تغییر |
| ۷ | `documents` | مدارک (۱:N) | بدون تغییر |
| ۸ | `status_log` | تاریخچهٔ وضعیت‌ها | افزودن `triggered_by` (`auto_sync`|`admin`|`user`|`system`) |
| ۹ | `postal_code_changes` | درخواست تغییر کد پستی | بدون تغییر |
| ۱۰ | `settings` | تنظیمات کلید/مقدار | بدون تغییر |
| ۱۱ | `wizard_steps` | مراحل ویزارد ثبت‌نام | بدون تغییر |
| ۱۲ | `form_fields` | فیلدهای فرم پویا | بدون تغییر |
| ۱۳ | `applicant_field_values` | مقادیر فیلدهای پویا | بدون تغییر |
| ۱۴ | `license_titles` | رشته‌های مجوز | بدون تغییر |
| ۱۵ | `payment_channels` | کانال‌های پرداخت | بدون تغییر |
| ۱۶ | `license_access_requests` | درخواست دسترسی به تصویر مجوز | بدون تغییر |
| ۱۷ | `payments` | پرداخت‌های متقاضی | بدون تغییر |
| ۱۸ | `license_files` | تصویر مجوز صادرشده (۱:۱) | بدون تغییر |
| ۱۹ | `sms_templates` | الگوهای پیامک | افزودن الگوی `license_issued_national_sync` |
| ۲۰ | `user_sms_entries` | پیامک‌های ثبت‌شده | بدون تغییر |
| ۲۱ | `applicant_revisions` | درخواست بازبینی پرونده | بدون تغییر |
| ۲۲ | `identity_checks` | لاگ تلاش‌های احراز هویت | بدون تغییر |
| ۲۳ | `fee_groups` | گروه‌های قیمتی نرخ‌نامه | بدون تغییر |
| ۲۴ | `fee_group_items` | آیتم‌های هر گروه | بدون تغییر |
| ۲۵ | `payment_service_ticks` | تیک خدمات هر پرداخت | بدون تغییر |
| ۲۶ | `payment_audit_log` | ممیزی تغییرات پرداخت | بدون تغییر |
| ۲۷ | `user_accounts` | حساب کاربری متقاضی | بدون تغییر |
| ۲۸ | `user_notifications` | اعلان‌های کاربر | افزودن `type='national_sync_issued'` |

#### ب) جداول جدید برای ماژول درگاه ملی و اتصال طلایی (۴ جدول)

**جدول ۲۹: `national_licenses`** (کش مجوزها از درگاه ملی — وارث `licenses` از gateway، گسترش‌یافته)

```sql
CREATE TABLE national_licenses (
    request_number VARCHAR(32) PRIMARY KEY,         -- شناسهٔ یکتای ملی (مثل I17981871)
    applicant_name VARCHAR(255) NOT NULL DEFAULT '',
    user_image TEXT,                                  -- URL تصویر متقاضی از upstream
    license_title VARCHAR(255) NOT NULL DEFAULT '',
    organization_title VARCHAR(255) NOT NULL DEFAULT '',
    province_title VARCHAR(128) NOT NULL DEFAULT '',
    township_title VARCHAR(128) NOT NULL DEFAULT '',
    responded_at DATETIME NOT NULL,                   -- میلادی
    status_id VARCHAR(32),
    status_title VARCHAR(64),
    status_slug VARCHAR(32) NOT NULL DEFAULT 'unknown',  -- active|expired|unknown
    -- فیلدهای denormalized برای فیلتر سریع محلی
    province_id VARCHAR(16),
    township_id VARCHAR(16),
    main_org_code VARCHAR(16),
    sub_org_code VARCHAR(16),
    -- فیلدهای استخراج‌شده از TrackScraper (پایدار — رفع I1)
    father_name VARCHAR(128),
    national_id_masked VARCHAR(16),                   -- ماسک‌شده عیناً از upstream (073****269)
    mobile_masked VARCHAR(16),                         -- معمولاً NULL (upstream خالی نشان می‌دهد)
    issuing_authority VARCHAR(255),
    supervisory_authorities TEXT,
    isic_code VARCHAR(16),
    isic_title VARCHAR(255),
    issuance_type VARCHAR(64),
    issue_date DATE,                                   -- responded_at به DATE
    expiry_date DATE,                                  -- 0000-00-00 = دائمی
    business_province VARCHAR(128),
    business_township VARCHAR(128),
    postal_code_masked VARCHAR(16),
    business_address TEXT,
    specialized_items JSON,                            -- از /advanced-data
    business_history JSON,                             -- آرایهٔ سوابق
    -- فیلدهای مدیریت یکپارچه
    is_saved TINYINT(1) NOT NULL DEFAULT 0,            -- ذخیره‌شده توسط کاربر
    saved_at DATETIME,
    matched_applicant_id INT,                          -- اتصال طلایی (FK به applicants, nullable)
    match_confidence INT,                              -- 0-100
    matched_at DATETIME,
    matched_by VARCHAR(32),                            -- auto_sync|operator
    raw_json JSON,                                     -- snapshot کامل پاسخ upstream
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    track_data_fetched_at DATETIME,                    -- زمان آخرین scrape صفحهٔ track
    INDEX idx_nl_province (province_id),
    INDEX idx_nl_township (township_id),
    INDEX idx_nl_org_main (main_org_code),
    INDEX idx_nl_org_sub (sub_org_code),
    INDEX idx_nl_status (status_slug),
    INDEX idx_nl_responded (responded_at),
    INDEX idx_nl_applicant (applicant_name),
    INDEX idx_nl_title (license_title),
    INDEX idx_nl_last_seen (last_seen_at),
    INDEX idx_nl_saved (is_saved, saved_at),
    INDEX idx_nl_matched (matched_applicant_id),
    INDEX idx_nl_national_id_masked (national_id_masked),
    FOREIGN KEY (matched_applicant_id) REFERENCES applicants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**جدول ۳۰: `national_lookups`** (کش استان‌ها و دستگاه‌ها — وارث `lookups` از gateway)

```sql
CREATE TABLE national_lookups (
    kind ENUM('province','main_org','sub_org','township') NOT NULL,
    code VARCHAR(32) NOT NULL,                          -- شناسهٔ upstream (رشته)
    title VARCHAR(255) NOT NULL,
    parent_code VARCHAR(32),                            -- برای sub_org به main_org، برای township به province
    extra_json JSON,
    refreshed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (kind, code),
    INDEX idx_nl_kind_parent (kind, parent_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**جدول ۳۱: `sync_jobs` + `sync_events`** (صف و رویداد — وارث `jobs`+`events`+`batches` از gateway، یکپارچه)

```sql
CREATE TABLE jobs (                                       -- صف وظایف یکپارچه (هم برای scrape، هم برای batch)
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('search','batch','count','sync_cycle','track_fetch') NOT NULL,
    payload_json JSON NOT NULL,
    status ENUM('queued','processing','completed','failed','retrying') NOT NULL DEFAULT 'queued',
    priority INT NOT NULL DEFAULT 100,
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    filter_id INT,                                        -- FK به filters (nullable)
    batch_id INT,                                         -- FK به batches (nullable)
    error TEXT,
    result_count INT,
    started_at DATETIME,
    completed_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jobs_status_priority (status, priority, created_at),
    FOREIGN KEY (filter_id) REFERENCES filters(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE batches (                                    -- اجرای دسته‌ای CSV
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_filename VARCHAR(255) NOT NULL,
    total_rows INT NOT NULL DEFAULT 0,
    processed_rows INT NOT NULL DEFAULT 0,
    matched_rows INT NOT NULL DEFAULT 0,
    status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
    csv_path VARCHAR(512),
    results_json JSON,
    error TEXT,
    started_at DATETIME,
    completed_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (                                     -- رویدادها برای audit + SSE replay
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(64) NOT NULL,
    payload_json JSON,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_events_type_created (type, created_at),
    INDEX idx_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE worker_state (                               -- single-row با CHECK
    id INT PRIMARY KEY CHECK (id = 1),
    is_running TINYINT(1) NOT NULL DEFAULT 0,
    last_heartbeat DATETIME,
    current_job_id INT,
    FOREIGN KEY (current_job_id) REFERENCES jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**جدول ۳۲: `sync_audit_log`** (ممیزی کامل اتصال طلایی — جدید)

```sql
CREATE TABLE sync_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    national_license_request_number VARCHAR(32) NOT NULL,
    applicant_id INT,                                     -- nullable (تا تطبیق یافته شود)
    action ENUM('scraped','match_attempted','auto_confirmed','operator_flagged','auto_rejected','operator_confirmed','operator_rejected','status_synced','notification_sent') NOT NULL,
    confidence INT,
    matched_fields JSON,                                  -- لیست فیلدهای منطبق
    operator_id INT,                                      -- اگر اکشن توسط اپراتور بود
    details TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sal_license (national_license_request_number),
    INDEX idx_sal_applicant (applicant_id),
    INDEX idx_sal_action (action, created_at),
    FOREIGN KEY (national_license_request_number) REFERENCES national_licenses(request_number) ON DELETE CASCADE,
    FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE SET NULL,
    FOREIGN KEY (operator_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### ۷.۳. جداول حذف‌شده (در مقایسه با gateway)

- `settings` از gateway → حذف می‌شود (با `settings` از v2 ادغام می‌شود).
- `admin_users` از gateway → حذف می‌شود (با `users` از v2 ادغام می‌شود).
- `cache` از gateway → حذف می‌شود (کش در `national_licenses` و `national_lookups` ذخیره می‌شود، با TTL در ستون `last_seen_at`/`refreshed_at`).

### ۷.۴. ایندکس‌های مهم برای کوئری‌های یکپارچه

| ایندکس | جدول | کاربرد |
|---|---|---|
| `idx_tracking_code` (UNIQUE) | `applicants.tracking_code` | lookup با کد رهگیری محلی |
| `idx_national_code` | `applicants.national_code` | lookup با کد ملی |
| `idx_license_state` | `applicants.license_state` | فیلتر مجوزهای صادرشده |
| `idx_applicant_national_license` (UNIQUE) | `applicants.national_license_request_number` | اتصال طلایی |
| `idx_nl_national_id_masked` | `national_licenses.national_id_masked` | جستجوی تطبیق الگویی |
| `idx_nl_matched` | `national_licenses.matched_applicant_id` | پیدا کردن مجوزهای وصل‌نشده |
| `idx_jobs_status_priority` | `jobs.(status, priority, created_at)` | انتخاب job بعدی |
| `idx_sal_license` | `sync_audit_log.national_license_request_number` | audit یک مجوز |

### ۷.۵. الگوی `ON DELETE`

- **CASCADE:** جداول فرعی وابسته (`job_details`, `property_details`, `documents`, `status_log`, `payments`, `license_files`, `sync_audit_log`).
- **SET NULL:** ارجاعات به `persons`, `service_types`, `users`, `payment_channels`, `documents`, `applicants` (در `national_licenses.matched_applicant_id`).
- **Self-ref SET NULL:** `applicants.parent_application_id`.

### ۷.۶. Migrationها (در `database/migrations/`)

Migrationها به‌صورت فایل‌های SQL با پیشوند تاریخ هستند. ترتیب اجرا با `MigrationRunner` تضمین می‌شود. جدول `migrations` پیگیری را نگه می‌دارد.

| فایل | کار |
|---|---|
| `001_init_core.sql` | جداول ۱-۱۴ از v2 (persons, applicants, job_details, ...) |
| `002_init_payments.sql` | جداول ۱۵-۲۶ از v2 (payment_channels, payments, license_files, ...) |
| `003_init_user_accounts.sql` | جداول ۲۷-۲۸ از v2 (user_accounts, user_notifications) |
| `004_national_gateway.sql` | جداول ۲۹-۳۰ (national_licenses, national_lookups) |
| `005_queue_events.sql` | جداول ۳۱ (jobs, batches, events, worker_state) |
| `006_sync_audit.sql` | جدول ۳۲ (sync_audit_log) |
| `007_seed_defaults.sql` | بذر مدیر پیش‌فرض (با رمز تعاملی)، ۵ نوع خدمت، فیلتر پیش‌فرض، الگوهای SMS |

> در نصب تازه با `install.sql`، نیازی به اجرای migrationها نیست.

---

## 8. ماژول درگاه ملی و Scraper

این ماژول در `app/Modules/NationalGateway/` قرار دارد و هستهٔ «اتصال طلایی» است.

### ۸.۱. اجزای ماژول

| جزء | فایل | نقش |
|---|---|---|
| `MojavecClient` | `Infrastructure/MojavecClient.php` | کلاینت جستجوی structured روی `/api/v1/licenses/search` و `/count` |
| `TrackScraper` | `Infrastructure/TrackScraper.php` | استخراج صفحهٔ `/track/{rn}` با RSC payload parser |
| `HttpClient` | `Infrastructure/HttpClient.php` | cURL نیتیو wrapper + cookie jar |
| `AntiDetectionMiddleware` | `Infrastructure/AntiDetectionMiddleware.php` | هدرهای ثابت + jitter Gaussian + rate limit |
| `RateLimiterService` | `Services/RateLimiterService.php` | اعمال واقعی min delay + burst/cooldown (رفع ST1) |
| `JsonSearchParser` | `Infrastructure/Parser/JsonSearchParser.php` | Strategy برای parse پاسخ upstream |
| `NationalSyncOrchestrator` | `Services/NationalSyncOrchestrator.php` | هماهنگ‌کنندهٔ چرخهٔ sync (scrape → match → update → notify) |
| `LicenseService` | `Services/LicenseService.php` | جستجوی hybrid (cache + upstream)، saveAll، fetchAndCacheByTrackNumber |

### ۸.۲. endpointهای درگاه ملی `qr.mojavez.ir` (همگی در `docs/API_DISCOVERY.md` مستند)

| endpoint | متد | کاربرد |
|---|---|---|
| `/api/v1/lookups/provinces` | GET | فهرست استان‌ها |
| `/api/v1/lookups/organizations` | GET | فهرست دستگاه‌های اصلی و تابعه |
| `/api/v1/lookups/provinces/{id}/townships` | GET | شهرستان‌های یک استان |
| `/api/v1/lookups/organizations/{code}/subs` | GET | دستگاه‌های تابعه |
| `/api/v1/licenses/search` | POST | جستجوی مجوزها (payload = `Filter::toUpstreamPayload()`) |
| `/api/v1/licenses/count` | POST | شمارش کل مجوزهای منطبق |
| `/api/v1/auth/me` | GET | وضعیت احراز هویت (نیازی به احراز ندارد) |
| `/track/{request_number}` | GET | صفحهٔ HTML با RSC payload (TrackScraper) |
| `/api/v1/licenses/{rn}/advanced-data` | GET | اقلام تخصصی (JSON) |

> **هشدار حیاتی:** upstream از فیلتر `license_id` در `/api/v1/licenses/search` **پشتیبانی نمی‌کند** (کشف مکالمهٔ #012). برای جستجوی بر اساس شماره مجوز باید از مسیر `/track/{rn}` با `TrackScraper` استفاده کرد.

### ۸.۳. هدرهای ثابت (در `AntiDetectionMiddleware`)

```
Accept: application/json
Accept-Language: fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7
User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36
Origin: https://qr.mojavez.ir
Referer: https://qr.mojavez.ir/
Sec-Fetch-Dest: empty
Sec-Fetch-Mode: cors
Sec-Fetch-Site: same-origin
sec-ch-ua: "Not;A=Brand";v="99", "Google Chrome";v="120", "Chromium";v="120"
sec-ch-ua-mobile: ?0
sec-ch-ua-platform: "Windows"
Content-Type: application/json (فقط POST)
DNT: 1
Upgrade-Insecure-Requests: 1
```

ترتیب هدرها باید مشابه مرورگر واقعی باشد تا fingerprint یکسان بودن حفظ شود. هیچ‌گاه User-Agent پیش‌فرض cURL یا هدرهای SDK (`X-Requested-With`, `Guzzle/7.0`, `curl/7.74.0`) ارسال نمی‌شود.

### ۸.۴. Rate Limiting (واقعاً فعال — رفع ST1)

| پارامتر | مقدار (در `config/anti-detection.php`) |
|---|---|
| حداقل فاصله بین درخواست‌ها | ۲۰۰۰ ms (با jitter Gaussian میانگین ۰.۵s، انحراف ۰.۲s) |
| حداکثر فاصله | ۵۰۰۰ ms |
| حداکثر هم‌زمان | ۱ (هرگز هم‌زمان) |
| burst | ۵ درخواست در پنجرهٔ ۶۰s |
| cooldown پس از burst | ۳۰s |
| Timeout اتصال | ۵s |
| Timeout پاسخ | ۸s |
| Retry max_attempts | ۲ (fail fast) |
| Retry backoff | ۵۰۰ms, ۲× |
| Retry روی status | 429, 502, 503, 504 |

**تفاوت با gateway:** در v3، `MojavecClient::doWithRetry()` **همیشه** `$rateLimiter` را پاس می‌کند و `RateLimiterService::wait()` قبل از هر درخواست صدا زده می‌شود. این رفع قطعی ST1 است.

### ۸.۵. TrackScraper (RSC payload parser)

شکننده‌ترین جزء پروژه. صفحهٔ `https://qr.mojavez.ir/track/{rn}` را fetch کرده و فیلدهای تفصیلی را از **React Server Components (RSC) flight payload** داخل `self.__next_f.push([1, "..."])` استخراج می‌کند.

**چرا RSC به‌جای DOM parsing؟**
۱. دادهٔ license به‌صورت آبجکت JSON تمیز در payload موجود است.
۲. جفت‌های label→value با الگوی ثابت `{"children":"LABEL"}...,{"children":"VALUE"}` قابل استخراج دقیق هستند.
۳. از DOM parsing مقاوم‌تر است (وابسته به className یا ساختار DOM نیست).

**متدهای اصلی:**
- `fetch($rn, $includeAdvancedData=true)` — متد اصلی: fetch + parse صفحهٔ track.
- `extractRscPayload($html)` — استخراج concatenation تمام pushهای `self.__next_f`.
- `findLicenseDataObject($blob)` — اسکن آبجکت‌های متعادل JSON برای یافتن آن که کلید `isic_code` دارد.
- `findLabelValue($blob, $label)` — پیدا کردن `"children":"LABEL"` و گرفتن مقدار `children` بعدی.
- `parseHistoryRows($blob)` — استخراج سلول‌های `<td>` و گروه‌بندی در سطر.
- `fetchSpecializedItems($rn)` — GET `/api/v1/licenses/{rn}/advanced-data`.
- `extractCellValue($raw)` — مدیریت سه قالب: رشته، عدد، آرایه.

**خروجی `fetch()` (ساختار داده):**
```php
[
  'found' => true,
  'error' => null,
  'request_number' => 'I17981871',
  'father_name' => 'عبدالكریم',
  'national_id' => '073****269',     // ماسک‌شده، عیناً
  'mobile' => null,
  'issuing_authority' => '...',
  'supervisory_authorities' => '...',
  'tracking_code' => 'I17981871',
  'isic_code' => '131202',
  'isic_title' => '...',
  'issuance_type' => '...',
  'issue_date' => '2026-...',
  'expiry_date' => '2027-...' | 'دائمی',
  'license_title' => '...',
  'business_province' => 'خراسان رضوی',
  'business_township' => 'تربت جام',
  'postal_code' => '9571968***',
  'business_address' => '<full address>',
  'business_history' => [...],
  'status' => [...],
  'specialized_items' => [...],
]
```

**تست رگرسیون:** `scripts/test_track_scraper.py` (پورت پایتونی منطق scraper) با **۱۵ assertion قفل‌شده** روی مقادیر fixture واقعی (`tests/fixtures/har/qr.mojavez.ir-track-I17981871.har`). هر تغییر scraper باید قبل از کامیت با این تست تأیید شود.

### ۸.۶. فلسفهٔ ضد‌شناسایی احترام‌آمیز (نه spoofing مخرب)

| ویژگی | spoofing مخرب | راهبرد v3 |
|---|---|---|
| هدف | جعل هویت برای نفوذ | احترام به محدودیت منابع سایت |
| Rate Limit | نادیده گرفته | سخت‌گیرانه رعایت می‌شود |
| Jitter | پنهان‌کردن الگوی حمله | طبیعی‌سازی فاصله درخواست‌ها |
| Retry | افزایش فشار | کاهش فشار در خطا |
| حجم درخواست | حداکثر ممکن | حداقل لازم با کش |

داده‌های `qr.mojavez.ir` عمومی هستند (مادهٔ ۷ قانون اجرای سیاست‌های اصل ۴۴). اما crawl با حجم بالا ممکن است با ToS سایت در تضاد باشد. اگر سایت درخواست توقف کند، پروژه باید فوراً متوقف شود.

---

## 9. الگوریتم تطبیق الگویی و «اتصال طلایی»

این بخش **مرکزی‌ترین** بخش برای ارزش راهبردی v3 است: چگونه یک مجوز صادرشده در درگاه ملی (با کد ملی ماسک‌شده) به یک متقاضی محلی (با کد ملی کامل) وصل می‌شود.

### ۹.۱. بینش کلیدی: ماسک به‌عنوان لنگر تأیید

> ماسک‌های scraper (`****` در کد ملی، `*****` در نشانی) «از دست‌رفته» نیستند — **لنگر تأیید** هستند. بخش پنهان `{...}` دقیقاً همان چیزی است که پروژهٔ v3 هنگام ثبت درخواست ذخیره کرده.

**منطق:** مقدار کامل متقاضی محلی (مثلاً `0720593107`) باید با الگوی ماسک upstream (مثلاً `072****107`) هم‌راستا باشد:
- لنگرهای نمایان (`072` و `107`) در جای خود قرار دارند.
- جایگاه‌های `*` با کاراکترهای متناظر مقدار کامل پر می‌شوند (`0593`).

اگر این پر کردن سازگار باشد → **MATCH**. اگر لنگرهای نمایان هم‌راستا نباشند → **mismatch** (شخص متفاوت).

### ۹.۲. کلاس `PatternMatcher` (`app/Services/PatternMatcher.php`)

پیاده‌سازی مرجع در `scripts/pattern_matcher.py` (پورت پایتونی) و `docs/workers/PATTERN_MATCHING.md`. در v3 به‌صورت یک سرویس PHP پیاده‌سازی می‌شود.

#### الگوریتم تطبیق رقم‌ای (مثل کد ملی)

```php
public function matchNumeric(string $fullValue, string $maskedPattern): MatchResult
{
    // ۱. نرمال‌سازی ارقام فارسی/عربی به لاتین
    $full = Normalizer::digits($fullValue);
    $pattern = Normalizer::digits($maskedPattern);

    // ۲. بررسی طول برابر
    if (strlen($full) !== strlen($pattern)) {
        return MatchResult::mismatch('length_differs');
    }

    // ۳. بررسی موقعیت‌به‌موقعیت
    $starsCount = 0;
    $anchorsMatched = 0;
    for ($i = 0; $i < strlen($pattern); $i++) {
        $pc = $pattern[$i];
        $fc = $full[$i];
        if ($pc === '*') {
            $starsCount++;
        } else {
            if ($pc !== $fc) {
                return MatchResult::mismatch('anchor_mismatch_at_' . $i);
            }
            $anchorsMatched++;
        }
    }

    // ۴. باید حداقل یک * و حداقل یک anchor داشته باشد
    if ($starsCount === 0 || $anchorsMatched === 0) {
        return MatchResult::inconclusive('no_mask_or_no_anchor');
    }

    return MatchResult::match($anchorsMatched, $starsCount);
}
```

#### الگوریتم تطبیق متنی (مثل نشانی)

```php
public function matchText(string $fullValue, string $maskedPattern): MatchResult
{
    // * به‌عنوان glob wildcard، anchors به‌ترتیب
    $full = Normalizer::digits($fullValue);
    $pattern = Normalizer::digits($maskedPattern);

    if (str_starts_with($pattern, '*****')) {
        // فقط anchors انتهایی مهم‌اند
        $endAnchors = substr($pattern, 5);
        return str_ends_with($full, $endAnchors)
            ? MatchResult::match(strlen($endAnchors), 5)
            : MatchResult::mismatch('end_anchor_mismatch');
    }
    // ... پیاده‌سازی کامل در PatternMatcher
}
```

### ۹.۳. آرای چندفیلدی (Quorum-based matching)

چون جایگاه‌های ماسک‌شده wildcard‌اند، تک‌فیلد سیگنال ضعیف است. تطبیق هم‌زمان چند فیلد الزامی است.

| فیلد | نوع | وزن |
|---|---|---|
| کد ملی (الگوی ماسک) | رقم‌ای | ۱۰ (سیگنال قوی) |
| نام + نام خانوادگی | متنی دقیق | ۵ |
| نام پدر | متنی دقیق | ۳ |
| استان کسب‌وکار | متنی دقیق | ۲ |
| شهرستان کسب‌وکار | متنی دقیق | ۲ |
| عنوان مجوز | متنی فازی | ۲ |
| کد پستی (الگوی ماسک) | رقم‌ای | ۳ |
| نشانی کسب‌وکار (الگوی ماسک) | متنی | ۴ |
| `request_number` | متنی (سیگنال کمکی) | ۱ |

> `request_number` یکی از سیگنال‌هاست، نه تنها سیگنال.

### ۹.۴. سطوح اطمینان و تصمیم خودکار

| مجموعهٔ منطبق | سطح اطمینان | اقدام خودکار |
|---|---|---|
| کد ملی MATCH + ≥۲ فیلد دیگر MATCH | `AutoConfirmed` (۹۰) | تأیید خودکار: تغییر وضعیت به «صدور مجوز»، تنظیم `license_state='صادر شده'`, `license_valid_until`, `national_license_request_number`, ارسال SMS/اعلان |
| ۱-۲ فیلد منطبق (بدون کد ملی کامل) | `OperatorFlag` (۵۰) | پرچم اپراتور: در پنل مدیریت نمایش داده می‌شود، نیاز مرور دستی |
| لنگر mismatch کد ملی (ارقام آشکار هم‌راستا نیستند) | `AutoRejected` (۰) | ردِ خودکار: لاگ در `sync_audit_log` با action=`auto_rejected`، بدون تغییر وضعیت |

### ۹.۵. چرخهٔ کامل «اتصال طلایی» (`NationalSyncOrchestrator::runCycle()`)

```
┌─────────────────────────────────────────────────────────────────────┐
│ ۱..trigger (cron / poor man's cron / دستی از پنل admin)              │
└──────────────────────────────┬──────────────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│ ۲. scrape: برای هر متقاضی با current_status IN (IssueRequest,         │
│    DocUploadToOrg, InitialApproval, FinalApproval):                   │
│    - ساخت Filter با استان/شهر/سازمان از property_details               │
│    - MojavecClient::search(filter) → LicenseCollection                │
│    - برای هر License در نتیجه: TrackScraper::fetch(rn) → فیلدهای ماسک│
│    - ذخیره/به‌روزرسانی در national_licenses (track_data_fetched_at)    │
└──────────────────────────────┬──────────────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│ ۳. match: برای هر national_license که matched_applicant_id IS NULL:   │
│    - واکشی همهٔ applicants با current_status در حالت‌های پیش-صدور       │
│    - PatternMatcher::matchQuorum(national_license, applicant.person)  │
│    - محاسبهٔ MatchConfidence                                            │
│    - ثبت در sync_audit_log با action='match_attempted'                 │
└──────────────────────────────┬──────────────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│ ۴. act (طبق MatchConfidence):                                         │
│    AutoConfirmed:                                                     │
│      - UPDATE applicants SET national_license_request_number=...,     │
│        match_confidence=90, matched_at=NOW(),                         │
│        license_state='صادر شده',                                      │
│        license_valid_until=<expiry_date یا NULL برای دائمی>            │
│      - validateStatusTransition(current, 'صدور مجوز')                  │
│      - UPDATE applicants SET current_status='صدور مجوز'               │
│      - INSERT INTO status_log (triggered_by='auto_sync')              │
│      - UPDATE national_licenses SET matched_applicant_id=...,         │
│        match_confidence=90, matched_by='auto_sync'                    │
│      - INSERT INTO sync_audit_log (action='auto_confirmed')           │
│      - dispatch LicenseIssuedOnNational event                          │
│      - SmsService::send(applicant, 'license_issued_national_sync')    │
│      - UserNotification::create(type='national_sync_issued')           │
│    OperatorFlag:                                                       │
│      - INSERT INTO sync_audit_log (action='operator_flagged')         │
│      - نمایش در پنل admin → «اتصال‌های در انتظار مرور»                  │
│    AutoRejected:                                                       │
│      - INSERT INTO sync_audit_log (action='auto_rejected')            │
│      - ادامه به applicant بعدی                                         │
└──────────────────────────────┬──────────────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│ ۵. notify + log:                                                       │
│    - SmsService::send برای AutoConfirmed                               │
│    - UserNotification::create برای AutoConfirmed                       │
│    - dispatch SyncCycleCompleted event                                 │
│    - INSERT INTO sync_audit_log (action='notification_sent')          │
└──────────────────────────────┬──────────────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│ ۶. report: در پنل admin /api/v1/sync/status:                          │
│    - آخرین چرخهٔ sync                                                   │
│    - تعداد scrape/match/auto_confirmed/operator_flagged/auto_rejected │
│    - لیست اتصال‌های در انتظار مرور اپراتور                               │
└─────────────────────────────────────────────────────────────────────┘
```

### ۹.۶. تدابیر ایمنی در اتصال طلایی

۱. **هرگز بدون آرای چندفیلدی به‌صورت خودکار تأیید نکنید.** کد ملی MATCH به‌تنهایی (با anchors هم‌راستا) کافی نیست، چون ماسک‌ها wildcard‌اند و فضای جستجوی وسیع‌ای دارند. حداقل ۲ فیلد دیگر (مثلاً نام + نام پدر) الزامی است.

۲. **اثر idempotent:** اگر یک مجوز ملی قبلاً به یک متقاضی وصل شده، چرخهٔ بعدی sync آن را دوباره پردازش نمی‌کند (`WHERE matched_applicant_id IS NULL`). اما اگر وضعیت متقاضی تغییر کرده (مثلاً از «صدور مجوز» به «تحویل مجوز»)، sync آن را نادیده می‌گیرد.

۳. **عملیات atomic:** هر ۴ گام (scrape → match → act → notify) در یک تراکنش DB انجام نمی‌شود (طولانی است)، اما گام «act» برای هر متقاضی در یک تراکنش atomic است.

۴. **جلوگیری از double-action:** `unique constraint` روی `applicants.national_license_request_number` تضمین می‌کند که یک مجوز ملی فقط به یک متقاضی وصل شود.

۵. **audit کامل:** همهٔ تصمیم‌ها (حتی AutoRejected) در `sync_audit_log` لاگ می‌شوند با `matched_fields` (JSON لیست فیلدهای منطبق) و `details`.

۶. **trigger manual هم ممکن:** اپراتور می‌تواند از پنل admin یک مجوز ملی را به‌صورت دستی به یک متقاضی وصل کند (`matched_by='operator'`)، با justification در `details`.

### ۹.۷. تست‌های الزامی برای PatternMatcher

- ۴ مثال مثبت MATCH (کد ملی ماسک‌شده با ۴ الگوی متفاوت، نشانی ماسک‌شده).
- ۴ کنترل منفی (mismatch در anchors، mismatch در طول، شخص متفاوت، ماسک بدون anchor).
- تست آستانهٔ آرای (AutoConfirmed در ۳ فیلد، OperatorFlag در ۱-۲ فیلد).
- تست نرمال‌سازی ارقام فارسی/عربی.

این تست‌ها در `tests/PatternMatcherTest.php` و `scripts/pattern_matcher.py` نگه‌داری می‌شوند.

---

## 10. مسیرها و APIها

حدود **۱۴۰ مسیر** در `config/routes.php` ثبت می‌شوند. ترتیب مسیرها **اهمیت دارد** چون Router از نوع first-match است. مسیرهای خاص (`/saved`, `/export`, `/search`, `/save-all`, `/{id}/save`, `/{id}/track`, `/sync/*`) باید **قبل از** مسیر generic `/{id}` بیایند.

### ۱۰.۱. مسیرهای عمومی (بدون احراز هویت)

| متد | مسیر | کنترلر@متد | Rate Limit |
|---|---|---|---|
| GET | `/` | `HomeController::index` | — |
| GET | `/guide`, `/about`, `/services` | `HomeController::*` | — |
| GET | `/signup`, `/login` | `AuthController::*` | — |
| POST | `/signup`, `/login` | `AuthController::*` | **5/5min (IP)** |
| GET | `/logout` | `AuthController::logout` | — |
| GET | `/register`, `/register/service/{id}`, `/register/step/{n}` | `RegistrationController::*` | — |
| POST | `/register/step/{n}` | `RegistrationController::*` (CSRF) | — |
| GET | `/register/success/{trackingCode}` | `RegistrationController::success` | — |
| GET | `/tracking` | `TrackingController::search` | — |
| POST | `/tracking/search` | `TrackingController::find` (CSRF) | **10/60s (session)** |
| GET | `/tracking/status/{trackingCode}` | `TrackingController::status` | **30/min (IP)** |
| POST | `/tracking/change-postal-code` | `TrackingController::*` (CSRF) | **3/3600s** |
| GET | `/tracking/print/{trackingCode}` | `TrackingController::printView` | **10/60s (IP)** |
| POST | `/tracking/payments/submit`, `/tracking/sms/submit`, `/tracking/license/first-access`, `/tracking/license/request-access`, `/tracking/license/pay` | `TrackingController`/`LicenseController` (CSRF) | — |
| GET | `/license/view/{token}` | `LicenseController::viewByToken` | **20/60s (IP)** |
| GET | `/tracking/revision/{trackingCode}` | `RevisionController::form` | — |
| POST | `/tracking/revision/submit` | `RevisionController::submit` (CSRF) | — |
| GET | `/my-records` | `RecordsController::form` | — |
| POST | `/my-records/verify` | `RecordsController::verify` (CSRF) | — |
| GET | `/license/{requestNumber}` | `HomeController::licenseDetail` | — |
| GET | `/health` | (closure) | **بدون احراز هویت** |
| GET | `/check-cancel` | (closure, `CRON_SECRET`) | محافظت‌شده با `hash_equals` |
| GET | `/check-sync` | (closure, `SYNC_SECRET`) | محافظت‌شده — اجرای چرخهٔ اتصال طلایی |

### ۱۰.۲. API یکپارچه (`/api/v1/*`)

**نکتهٔ راهبردی:** v2 هیچ API REST نداشت؛ v3 API کامل دارد. این یکی از برندهای اصلی بازنویشی است.

#### مجوزهای ملی (از gateway به ارث، در `/api/v1/licenses`)

| متد | مسیر | توضیح | احراز هویت |
|---|---|---|---|
| GET | `/api/v1/licenses` | query کش محلی (page, page_size, name, license_id, province_id, township_id, main_org_code, sub_org_code, status, issue_start_date, issue_end_date, saved_only, unmatched_only) | admin |
| GET | `/api/v1/licenses/saved` | فقط مجوزهای ذخیره‌شده | admin |
| GET | `/api/v1/licenses/export` | CSV (پیش‌فرض) یا JSON (`format`) | admin |
| POST | `/api/v1/licenses/search` | جستجوی upstream + ذخیره در cache. body = `Filter` | admin (CSRF) |
| POST | `/api/v1/licenses/save-all` | ذخیرهٔ همهٔ نتایج فیلتر | admin (CSRF) |
| POST | `/api/v1/licenses/{id}/save` | علامت‌گذاری به‌عنوان ذخیره‌شده | admin (CSRF) |
| DELETE | `/api/v1/licenses/{id}/save` | برداشتن علامت ذخیره | admin |
| GET | `/api/v1/licenses/{id}/track` | جزئیات از صفحهٔ `/track/{rn}` (scraped) | admin |
| GET | `/api/v1/licenses/{id}` | یک مجوز از کش محلی | admin |

#### متقاضیان محلی (جدید در v3 — API REST برای دادهٔ پرونده)

| متد | مسیر | توضیح | احراز هویت |
|---|---|---|---|
| GET | `/api/v1/applicants/{trackingCode}` | اطلاعات پایهٔ پرونده (فیلدهای whitelist) | API key (`X-API-Key`) |
| GET | `/api/v1/applicants/{trackingCode}/status` | فقط وضعیت + تایم‌لاین | API key |
| GET | `/api/v1/applicants/by-national/{nationalCode}` | lookup با کد ملی (نیازمند احراز هویت شش‌عاملی در صورت `TRACKING_REQUIRE_MOBILE=true`) | API key |

**whitelist فیلدهای قابل افشا در `/api/v1/applicants/{code}`:** `tracking_code`, `national_code`, `license_state`, `license_valid_until`, `service_title`, `license_title`, `province`, `city`, `current_status` (فقط terminal), `updated_at`. **هرگز:** `mobile`, `password`, `file_path`, `payments.amount`, `first_name`, `last_name` (مگر با احراز هویت).

#### Lookups و Filters

| متد | مسیر | توضیح | احراز هویت |
|---|---|---|---|
| GET | `/api/v1/lookups` | استان‌ها و دستگاه‌ها (از کش محلی) | admin |
| GET | `/api/v1/lookups/townships/{provinceId}` | شهرستان‌های یک استان | admin |
| GET | `/api/v1/lookups/sub-organizations/{orgCode}` | دستگاه‌های تابعه | admin |
| POST | `/api/v1/lookups/refresh` | به‌روزرسانی کش از upstream | admin (CSRF) |
| GET | `/api/v1/filters` | فهرست فیلترهای ذخیره‌شده | admin |
| POST | `/api/v1/filters` | ایجاد فیلتر | admin (CSRF) |
| GET/PUT/DELETE | `/api/v1/filters/{id}` | CRUD | admin |

#### Batch (دسته‌ای CSV)

| متد | مسیر | توضیح | احراز هویت |
|---|---|---|---|
| POST | `/api/v1/batch/upload` | آپلود CSV (ستون‌های `license_id,name`) | admin (CSRF) |
| GET | `/api/v1/batch` | فهرست اجراهای دسته | admin |
| GET | `/api/v1/batch/{id}/status` | وضعیت + پیشرفت + نتایج (poor man's cron: `Worker::run(1)` در هر poll) | admin |

#### Admin (همگی با احراز هویت — رفع S2)

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/api/v1/admin/stats` | آمار کلی |
| GET | `/api/v1/admin/analytics` | داده‌های تحلیلی (نمودارها) |
| GET | `/api/v1/admin/jobs` | فهرست jobهای صف |
| POST | `/api/v1/admin/jobs/{id}/retry` | تلاش مجدد یک job |
| GET | `/api/v1/admin/logs` | لاگ‌های اخیر |
| POST | `/api/v1/admin/crawl` | اجرای دستی crawl |
| POST | `/api/v1/admin/worker/start`, `/stop` | کنترل worker |
| GET | `/api/v1/admin/worker/status` | وضعیت worker |
| GET | `/api/v1/sync/status` | وضعیت آخرین چرخهٔ اتصال طلایی |
| GET | `/api/v1/sync/pending` | اتصال‌های در انتظار مرور اپراتور |
| POST | `/api/v1/sync/run` | اجرای دستی چرخهٔ sync (با `SYNC_SECRET` یا admin) |
| POST | `/api/v1/sync/{licenseId}/confirm` | تأیید دستی اپراتور یک اتصال پرچم‌شده |
| POST | `/api/v1/sync/{licenseId}/reject` | رد دستی اپراتور یک اتصال پرچم‌شده |

#### تصویر (proxy)

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/api/v1/proxy-image?url=...` | fetch تصویر متقاضی از upstream و re-serve هم‌مبدا (با TLS روشن و CORS محدود — رفع S4) |

#### SSE

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/api/v1/stream` | در production با Apache/PHP-FPM چندپردازشی فعال؛ در dev پاسخ 204 |

### ۱۰.۳. مسیرهای پنل کاربری و مدیریت

وارث از v2 (بخش ۱۰.۲ و ۱۰.۳ از `Mojavez-v2-Integration-Document.md`). حدود ۸۰ مسیر برای پنل کاربر (`/panel/*`) و پنل مدیریت (`/admin/*`).

### ۱۰.۴. سرو فایل‌ها

| متد | مسیر | توضیح | محافظت |
|---|---|---|---|
| POST | `/document/view` | مشاهدهٔ مدرک در مودال | `requireLogin()` + CSRF |
| GET | `/document/avatar` | سرو آواتار | `requireAnyLogin()` + ownership |
| GET | `/document/thumb/{id}` | بندانگشتی (GD، max 200×200) | `requireLogin()` |
| GET | `/document/inline/{id}` | نمایش مستقیم برای iframe | `requireLogin()` |
| GET | `/document/pdf-viewer` | نمایش PDF با PDF.js | `requireLogin()` |

---

## 11. احراز هویت و نقش‌ها

### ۱۱.۱. دو قلمرو مجزا (تداوم از v2)

| قلمرو | نشست | کلیدهای session | ورود |
|---|---|---|---|
| **مدیر** | `admin_*` | `admin_logged_in`, `admin_id`, `admin_role`, `admin_username`, `admin_display_name` | `/admin/login` |
| **کاربر** | `user_*` | `user_logged_in`, `user_id`, `user_mobile`, `user_name`, `user_national_code` | `/login` |

دو قلمرو می‌توانند در یک مرورگر همزمان فعال باشند (کلیدهای session مجزا).

### ۱۱.۲. نقش‌های مدیر (در `users.role`)

| نقش | دسترسی |
|---|---|
| `admin` | همهٔ انتقال‌ها، ثبت‌نام دستی، ویرایش، حذف، گزارش‌ها، مدیریت کاربران، تنظیمات، نرخ‌نامه، ایمپورت، فرم‌ساز، انواع خدمات، مراحل ویزارد، **پنل درگاه ملی، تأیید/رد اتصال‌های پرچم‌شده** |
| `expert` | داشبورد + «وظایف من» + انتقال‌های محدود + تأیید/رد کد پستی + افزودن/حذف مدارک + **مرور اتصال‌های پرچم‌شده** |
| `document_uploader` | داشبورد + «وظایف من» + انتقال‌های محدود + افزودن/حذف مدارک |
| `sync_operator` (جدید) | **پنل درگاه ملی + مرور/تأیید/رد اتصال‌های پرچم‌شده + اجرای دستی sync** |

### ۱۱.۳. Guards در `app/Controllers/Controller.php`

```php
requireLogin()        // بررسی admin_logged_in + ADMIN_IP_ALLOWLIST
requireUserLogin()    // بررسی user_logged_in + حفظ redirect_after_login
requireAnyLogin()     // قبول admin یا user
requireRole(array)    // requireLogin + بررسی admin_role
isSuperAdmin()        // admin_role === 'admin'
validateStatusTransition($current, $new)  // متمرکز
requireApiKey()       // برای /api/v1/applicants/* (جدید)
```

### ۱۱.۴. هش رمز عبور

`PASSWORD_BCRYPT` با cost پیش‌فرض ۱۰. در `User::create/update` و `UserAccount::create/updatePassword`.

### ۱۱.۵. مقایسهٔ زمان‌ثابت

- رمز: `password_verify()` (constant-time).
- CSRF: `hash_equals($valid, $token)`.
- CRON_SECRET و SYNC_SECRET: `hash_equals(SECRET, $provided)`.
- API key: `hash_equals(API_KEY, $provided)`.
- ورود مدیر: حتی وقتی `$user` برابر null است، `password_verify` صدا زده می‌شود (anti-timing).

### ۱۱.۶. بازتولید نشست

- بلافاصله پس از ورود موفق (هر دو قلمرو): `Session::regenerate()`.
- خودکار هر ۳۰ دقیقه در `Session::start()` با حفظ داده.

### ۱۱.۷. حساب مدیر پیش‌فرض (رفع S3 و hardcoded password)

**در v3:** نصب تعاملی از `public/install.php`. نصاب یک رمز تصادفی ۱۶-کاراکتری تولید می‌کند، آن را در صفحهٔ نهایی نصب نمایش می‌دهد، و کاربر باید آن را ذخیره کند. **هیچ رمز hardcodedی در کد یا SQL وجود ندارد.** `database/seed-defaults.sql` فقط نوع خدمات، فیلتر پیش‌فرض، و الگوهای SMS را seed می‌کند، نه حساب مدیر.

### ۱۱.۸. قفل ورود کاربر (Account Lockout)

پس از ۵ تلاش ناموفق، حساب ۵ دقیقه قفل می‌شود. تداوم از v2.

### ۱۱.۹. احراز هویت API key برای `/api/v1/applicants/*`

برای لایه‌های واسط خارجی (در صورت وجود)، یک API key با `hash_equals` اعتبارسنجی می‌شود. کلید در `.env` به‌صورت `GATEWAY_API_KEY` تعریف می‌شود. این رفع نیاز v2 به «افزودن endpoint JSON اختصاصی» است.

---

## 12. امنیت یکپارچه و رفع تمام مشکلات شناخته‌شده

### ۱۲.۱. هدرهای امنیتی سراسری

```http
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=(), interest-cohort=()
X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-site
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
Pragma: no-cache
Expires: 0
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload  (فقط HTTPS)
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data: blob:; connect-src 'self'; object-src 'self'; frame-src 'self'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'
```

> **تفاوت با v2:** `'unsafe-eval'` از `script-src` **حذف می‌شود** (در v2 به‌خاطر PDF.js لازم بود؛ در v3 با بارگذاری محلی PDF.js و config بهینه، نیازی نیست).
> **تفاوت با gateway:** CDNها (`cdnjs.cloudflare.com`, `cdn.jsdelivr.net`) از CSP **حذف می‌شوند** (self-host).

### ۱۲.۲. CSRF Protection (همیشه فعال — رفع S1)

- توکن: `bin2hex(random_bytes(32))` = ۶۴ hex، در `$_SESSION['_csrf_token']`.
- فیلد: `csrfField()` → `<input type="hidden" name="_csrf" value="...">`.
- اعتبارسنجی: `hash_equals()` در `Controller::verifyCsrf()`.
- AJAX: توکن از `X-CSRF-TOKEN` هدر هم خوانده می‌شود.
- خطا: HTTP **419** + JSON برای AJAX / HTML برای سایر.
- **در همهٔ فرم‌های POST و همهٔ endpointهای `/api/*` با POST/PUT/DELETE اعمال می‌شود.**

> **رفع قطعی S1 از gateway:** در gateway هیچ کنترلی `verifyCsrf` را صدا نمی‌زد. در v3، `verifyCsrf()` در `Controller::__invoke()` برای هر درخواست mutating صدا زده می‌شود.

### ۱۲.۳. نشست (Session)

| تنظیم | مقدار |
|---|---|
| `session.use_strict_mode` | `1` |
| `session.use_only_cookies` | `1` |
| `session.sid_bits_per_character` | `5` (نه ۶ — رفع هشدار PHP 8.5 از v2) |
| `session.sid_length` | `40` (نه ۴۸) |
| `session.cookie_httponly` | `1` |
| `session.cookie_samesite` | `Lax` |
| `session.cookie_secure` | auto (HTTPS) |
| `session.name` | `MOJAVEZSESSID` |
| طول عمر | `SESSION_LIFETIME` = ۸۶۴۰۰s |

### ۱۲.۴. امنیت آپلود فایل (۷ لایه — تداوم از v2)

۱. `UPLOAD_ERR_OK` الزامی.
۲. نرمال‌سازی نام فایل: حذف BOM، کاراکترهای کنترلی، `\`، basename.
۳. استخراج پسوند با `safe_file_extension()` (پشتیبانی از نام فارسی، چندنقطه‌ای).
۴. fallback با MIME: اگر پسوند نامعتبر، `detect_extension_from_mime()` با `finfo`.
۵. بررسی حجم: `≤ MAX_UPLOAD_SIZE` (2 MiB مدارک، 5 MiB CSV).
۶. MIME واقعی با `finfo(FILEINFO_MIME_TYPE)` روی `tmp_name`.
۷. یکپارچگی پسوند↔MIME: مثلاً `shell.php` به `x.jpg` تغییر نام یافته → رد.

ذخیره: `uploads/{tracking_code}/{doc_type}_{rand6}.ext`, خارج از `public/`, `chmod 0640`, تصاویر با GD به max 1920px re-encode.

### ۱۲.۵. محافظت Path Traversal (تداوم از v2)

```php
$cleanPath = str_replace(['..', "\0"], '', $relativePath);
$filePath = realpath(UPLOAD_DIR . $cleanPath);
$base = realpath(UPLOAD_DIR);
if ($filePath === false || !str_starts_with($filePath, $base)) { deny; }
```

### ۱۲.۶. Rate Limiting (دو مکانیزم + حذف دور زدن)

**۱. مبتنی بر نشست:**
| عملیات | حد | پنجره |
|---|---|---|
| ورود مدیر | ۵ | ۳۰۰s |
| جستجوی tracking | ۱۰ | ۶۰s |
| درخواست تغییر کد پستی | ۳ | ۳۶۰۰s |
| درخواست مجدد تصویر مجوز | ۲ | ۶۰۴۸۰۰s (هفته) |

**۲. مبتنی بر IP (`rate_limit_ip()`):**
| endpoint | حد | پنجره |
|---|---|---|
| ورود کاربر | ۵ | ۳۰۰s |
| `/tracking/status/{code}` | ۳۰ | ۶۰s |
| `/license/view/{token}` | ۲۰ | ۶۰s |
| `/tracking/print/{code}` | ۱۰ | ۶۰s |
| `/api/v1/licenses/search` (admin) | ۱۰ | ۶۰s |
| `/api/v1/applicants/*` (API key) | ۶۰ | ۶۰s |

**۳. (جدید) Rate limiter واقعی برای upstream (`RateLimiterService`):** در `MojavecClient::doWithRetry()` همیشه صدا زده می‌شود. این رفع قطعی ST1 است.

> **توصیهٔ production:** `fail2ban` / `mod_evasive` / WAF برای rate limit سطح IP کامل.

### ۱۲.۷. CSV Formula Injection Protection (تداوم از v2)

`CsvExporter::sanitizeCell($value)`: اگر اولین کاراکتر `=+@-\t\r\n` باشد و مقدار عددی نباشد، `'` پیشوند می‌شود. + UTF-8 BOM برای فارسی در Excel.

### ۱۲.۸. توکن تصویر مجوز (۷۲ ساعته، یک‌بار دریافت — تداوم از v2)

- `TOKEN_HOURS = 72`.
- توکن: `bin2hex(random_bytes(32))` = ۶۴ hex / ۲۵۶ بیت آنتروپی.
- قاعدهٔ «یک‌بار دریافت»: `LicenseFile.download_count` پس از اولین دانلود > 0.
- انقضوی تنبل: `LicenseAccessRequest::expireStale()` در هر فراخوانی `viewByToken`.

### ۱۲.۹. IP Blocklist / Allowlist (تداوم از v2)

- `IP_BLOCKLIST` (global): بررسی در ابتدای `index.php`.
- `ADMIN_IP_ALLOWLIST`: بررسی در `Controller::requireLogin()` (به‌جز `/admin/login`).
- اعتماد به `X-Forwarded-For`: فقط با reverse proxy قابل اعتماد.

### ۱۲.۱۰. محافظت `/check-cancel` و `/check-sync`

```php
$provided = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_SECRET'] ?? '');
if (CRON_SECRET === '' || !hash_equals(CRON_SECRET, (string)$provided)) {
    http_response_code(403); echo json_encode(['error' => 'forbidden']);
}
```

همین الگو برای `/check-sync` با `SYNC_SECRET`.

### ۱۲.۱۱. مسدودسازی موتورهای جستجو و خزنده‌های هوش مصنوعی (تداوم از v2)

در `public/.htaccess` با `RewriteCond %{HTTP_USER_AGENT}` و `[R=410,L]`:
- **لایهٔ ۱ (موتورهای جستجو):** Googlebot, Bingbot, Slurp, DuckDuckBot, Baiduspider, YandexBot, Sogou, Exabot, facebot, ia_archiver, Applebot, Twitterbot, LinkedInBot, TelegramBot, Discordbot.
- **لایهٔ ۲ (AI):** GPTBot, ChatGPT-User, CCBot, anthropic-ai, Claude-Web, cohere-ai, Google-Extended, PerplexityBot, Amazonbot, Bytespider, Diffbot, Meta-ExternalAgent, OAI-SearchBot, Applebot-Extended.
- **لایهٔ ۳ (SEO):** SemrushBot, AhrefsBot, MJ12bot, DotBot, BLEXBot, SeznamBot, PetalBot.

### ۱۲.۱۲. `.htaccess` سه‌لایه (تداوم از v2)

- **ریشه:** هدایت به `public/` + مسدودسازی `app/core/config/database/tests/tools/docs/uploads`.
- **`public/`:** rewrite rules + security headers + CSP + bot blocking + cache + ErrorDocument.
- **`uploads/`:** `Require all denied` + `php_flag engine off` + deny اسکریپت‌ها.

### ۱۲.۱۳. رفع قطعی مشکلات شناخته‌شده

| شناسه | مشکل | رفع در v3 |
|---|---|---|
| **S1** | CSRF غیرفعال در gateway | `verifyCsrf()` در هر درخواست mutating — همیشه فعال |
| **S2** | بدون احراز هویت روی `/api/admin/*` | همهٔ endpointهای `/api/admin/*` و `/api/v1/admin/*` با `requireRole(['admin','sync_operator'])` |
| **S3** | رمز admin پیش‌فرض `admin` | نصب تعاملی با رمز تصادفی ۱۶-کاراکتری، نمایش یک‌باره در نصب |
| **S4** | SSRF در `/api/proxy-image` | `CURLOPT_SSL_VERIFYPEER=true`، `CURLOPT_FOLLOWLOCATION=false` (یا محدود به ۲ هدایت فقط در دامنهٔ upstream)، CORS محدود به same-origin |
| **S5** | `storage/` فقط با `.htaccess` محافظت | document root روی `public/` تنظیم می‌شود؛ `storage/` خارج از web root |
| **ST1** | Rate limiter کد مرده | `RateLimiterService::wait()` در `MojavecClient::doWithRetry()` همیشه صدا زده می‌شود |
| **I1** | فیلدهای track در cache پایدار نیستند | جدول جداگانهٔ `national_licenses` با ستون‌های `*_masked` پایدار؛ track data در `track_data_fetched_at` timestamp |
| (از v2) `TRACKING_REQUIRE_MOBILE=false` | کد رهگیری به‌تنهایی کافی | در v3 پیش‌فرض `true` در production |
| (از v2) رمز `Mojavez@2026` hardcoded | در `seed-admin.sql` | حذف کامل؛ نصب تعاملی |
| (از v2) `phpinfo.php` | افشای سرور | هرگز وجود ندارد در v3 |
| (از gateway) `CURLOPT_SSL_VERIFYPEER=false` | TLS خاموش | روشن (رفع S4) |
| (از gateway) `unsafe-eval` در CSP | برای PDF.js | حذف با بارگذاری محلی بهینه |

---

## 13. زیرساخت async: صف، رویداد، SSE/Polling

### ۱۳.۱. EventBus (Observer)

`app/Events/EventBus.php` یک EventBus درون‌حافظه‌ای است که رویدادها را در جدول `events` persist می‌کند تا SSE بتواند آنها را replay کند.

**انواع رویداد (۹ event در `app/Events/Events/`):**
- `SearchRequested` — کاربر جستجویی شروع کرد.
- `CrawlerProgress` — پیشرفت crawl.
- `SearchCompleted` — جستجو کامل شد.
- `LicenseSaved` — یک مجوز در کش ذخیره شد.
- `JobFailed` — یک job شکست خورد.
- `BatchProgress` — پیشرفت دسته.
- `CacheHit` — پاسخ از کش.
- **`LicenseIssuedOnNational`** (جدید) — scraper مجوز صادرشده‌ای را در درگاه ملی پیدا کرد.
- **`ApplicantMatched`** (جدید) — PatternMatcher یک متقاضی را به مجوز ملی وصل کرد.
- **`SyncCycleCompleted`** (جدید) — یک چرخهٔ کامل اتصال طلایی پایان یافت.

**Subscriberها:**
- `LogSubscriber` — لاگ همهٔ رویدادها.
- `SyncSubscriber` (جدید) — در `LicenseIssuedOnNational`، یک job `SyncJob` به صف اضافه می‌کند.
- `NotificationSubscriber` (جدید) — در `ApplicantMatched`، SMS و notification ارسال می‌کند.

> **نکتهٔ پایداری (تداوم رفع 004-04):** متدهای `eventsSince()` و `lastEventId()` در `try-catch` پیچیده شده‌اند تا اگر جدول `events` ناموجود بود، به‌جای crash، آرایهٔ خالی برگردانند.

### ۱۳.۲. JobQueue (Command)

`app/Queue/JobQueue.php` صفی مبتنی بر MySQL است (در gateway SQLite بود). `JobInterface` یک متد `execute()` دارد. jobهای موجود:

- `Jobs/SearchJob` — اجرای یک جستجوی upstream.
- `Jobs/BatchJob` — پرداسش یک ردیف از دستهٔ CSV.
- `Jobs/CountJob` — شمارش کل (با TTL ۱ ساعت).
- `Jobs/SyncJob` (جدید) — اجرای یک چرخهٔ اتصال طلایی.
- `Jobs/TrackFetchJob` (جدید) — scrape صفحهٔ track برای یک مجوز خاص.

**Worker** (`app/Queue/Worker.php`): دوره‌ای `JobRepository::pickNext()` را صدا می‌زند و job را اجرا می‌کند.

### ۱۳.۳. الگوی Poor Man's Cron (تداوم از gateway)

در عمل، در محیط هاست اشتراکی تک‌رشته، **Worker جداگانه نباید اجرا شود**. الگوی «poor man's cron»:
- `BatchController::status` قبل از برگرداندن وضعیت، `Worker::run(1)` را صدا می‌زند.
- `/admin/sync/status` هم در هر poll یک `SyncJob` را از صف برمی‌دارد و اجرا می‌کند.
- cron واقعی (در صورت دسترسی) هر دقیقه `scripts/run_worker.php --max-jobs=5` را اجرا می‌کند.

### ۱۳.۴. `Worker::executeBatchJob` (تداوم رفع حلقهٔ بی‌نهایت)

- `incrementProgress` (atomic bump `processed_rows`/`matched_rows` + `status='processing'`).
- `appendResult` (افزودن `{request_number, status, message}` به `results_json`).
- `maybeCompleteBatch` (هنگام `processed>=total`، `status='completed'`).
- در مسیر شکست هم همین‌ها صدا زده می‌شوند تا دسته روی یک ردیف ناموفق قفل نشود.

### ۱۳.۵. SSE (در production فعال، در dev غیرفعال)

مسیر `GET /api/v1/stream` → `SseController::stream`.

- **در production با Apache/PHP-FPM چندپردازشی:** یک اتصال `text/event-stream` باز نگه داشته می‌شود و رویدادها به مرورگر push می‌شوند.
- **در dev (PHP built-in server تک‌رشته):** `index.php` به‌جای آن پاسخ `204` می‌فرستد (تا اتصال طولانی همهٔ درخواست‌های دیگر را در صف نگیرد).
- **fallback همیشگی:** polling با poor man's cron در هر poll.

**چرا SSE نه WebSocket؟** WebSocket نیاز به اتصال full-duplex دارد که در هاست اشتراکی با mod_php به سختی پشتیبانی می‌شود. SSE یک اتصال HTTP ساده و یک‌طرفه است. ماهیت داده یک‌طرفه است (سرور → کلاینت)، پس WebSocket اضافی است.

---

## 14. رابط کاربری و دارایی‌های سمت کلاینت

### ۱۴.۱. پشتهٔ Frontend

| دارایی | منبع | محل |
|---|---|---|
| **Bootstrap 5 RTL** | v2 (self-host) | `public/assets/vendor/bootstrap.rtl.min.css` |
| **Vazirmatn** (فونت متن، متغیر v33.003) | gateway (self-host) | `public/assets/vendor/vazirmatn.woff2` |
| **Lalezar** (فونت تیتر/لوگو) | v2 (self-host) | `public/assets/vendor/lalezar.woff2` |
| **PDF.js** (رندر PDF روی canvas) | v2 (self-host) | `public/assets/vendor/pdf.min.js`, `pdf.worker.min.js`, `cmaps/`, `standard_fonts/` |
| **jsPDF** 2.5.2 | gateway (self-host) | `public/assets/vendor/jspdf-vendor.js` |
| **html2canvas** 1.4.1 | gateway (self-host) | `public/assets/vendor/html2canvas-vendor.js` |
| **qrcode** | gateway (self-host) | `public/assets/vendor/qrcode-vendor.js` |
| **تم روز/شب** | v2 | `public/assets/css/app.css` با `[data-theme]` |
| **SVGهای درون‌خطی** | v2 + gateway | در نماها |

### ۱۴.۲. صفحات اصلی

| صفحه | مسیر | منبع | توضیح |
|---|---|---|---|
| صفحهٔ اصلی | `/` | v2 | معرفی + کارت‌های خدمات |
| راهنما | `/guide` | v2 | راهنمای کاربر |
| دربارهٔ ما | `/about` | v2 | محتوا از تنظیمات |
| خدمات | `/services` | v2 | کارت‌های محصول |
| ثبت‌نام | `/register/*` | v2 | ویزارد پویا با مراحل سفارشی |
| پیگیری | `/tracking/*` | v2 | جستجو با کد رهگیری |
| پنل کاربر | `/panel/*` | v2 | داشبورد، پروفایل، درخواست‌ها، پرداخت‌ها، مجوزها، اعلان‌ها |
| پنل مدیریت | `/admin/*` | v2 + جدید | داشبورد، پرونده‌ها، گزارش‌ها، تنظیمات، نرخ‌نامه، ایمپورت، فرم‌ساز |
| **درگاه ملی (جستجو)** | `/national/search` | gateway | داشبورد SPA با کارت/جدول |
| **درگاه ملی (جزئیات)** | `/license/{rn}` | gateway | صفحهٔ مستقل با QR، تصویر، PDF |
| **درگاه ملی (دسته)** | `/national/batch` | gateway | جستجوی دسته‌ای CSV |
| **اتصال طلایی (وضعیت)** | `/admin/sync/status` | جدید | مانیتورینگ آخرین چرخهٔ sync |
| **اتصال طلایی (مرور)** | `/admin/sync/pending` | جدید | اتصال‌های در انتظار مرور اپراتور |
| تحلیلی | `/analytics` | gateway | نمودارها |

### ۱۴.۳. صفحهٔ جزئیات مجوز ملی (از gateway به ارث، تکمیل‌شده)

`/license/{request_number}` با `license-detail.js`:

۱. فیلدهای پایه را از `GET /api/v1/licenses/{rn}` (کش) پر می‌کند.
۲. به‌صورت async `GET /api/v1/licenses/{rn}/track` را صدا می‌زند → فیلدهای تفصیلی (نام پدر، کدملی ماسک‌شده، نشانی، تاریخ صدور/انقضا، سوابق، اقلام تخصصی).
۳. QR را با `qrcode-vendor.js` تولید می‌کند.
۴. تصویر متقاضی را از `GET /api/v1/proxy-image?url=...` بارگذاری می‌کند.
۵. **(جدید) اگر `matched_applicant_id` تنظیم شده:** لینک به پروندهٔ محلی متقاضی (`/admin/view/{id}`) نمایش داده می‌شود.
۶. **(جدید) اگر `match_confidence` < 90:** برچسب «در انتظار مرور اپراتور» با دکمهٔ تأیید/رد.

### ۱۴.۴. تولید PDF (سمت کلاینت — تداوم از gateway)

چون Composer ممنوع است، PDF تولیدی **تصویری (raster)** است نه متن-انتخاب‌پذیر، اما دقیقاً چیدمان چاپ صفحه را ضبط می‌کند. الگوریتم در `license-detail.js::downloadPdf()`:

۱. `Promise.race([Detail.trackPromise, 10s])` را await می‌کند (تضمین پر شدن فیلدهای تفصیلی).
۲. کلون `.app-main--detail` می‌سازد، المان‌های پنهان در print را حذف می‌کند.
۳. تصویر آواتار را از `/api/v1/proxy-image` fetch → blob → dataURL جایگزین می‌کند.
۴. `wrap.classList.add('is-print-light')` تم روشن را اعمال می‌کند.
۵. `html2canvas` (scale:2) → `jsPDF('p','mm','a3')` با fit-to-paper و centered.
۶. `pdf.save('license-{rn}.pdf')`.

### ۱۴.۵. ویزارد ثبت‌نام پویا (تداوم از v2)

- مراحل و فیلدها از `wizard_steps` و `form_fields` در DB خوانده می‌شوند.
- ۳ سرویس اصلی + ۲ سرویس در حال توسعه (گردشگری، هنری).
- ۴ مرحلهٔ اختصاصی مشاغل خانگی مستقل (`service_type_id = 2`).
- اطلاعات هویتی از `Person` پیش‌فرض پر می‌شوند (اگر کاربر واردشده باشد).

### ۱۴.۶. واکنش‌گرایی

تست با Playwright روی ۸ دستگاه موبایل/تبلت/دسکتاپ. Bootstrap 5 RTL با breakpoints استاندارد. تم روز/شب با `prefers-color-scheme`.

---

## 15. پیکربندی و متغیرهای محیطی

### ۱۵.۱. متغیرهای فایل `.env` (مرجع: `.env.example` + `config/config.php`)

| متغیر | پیش‌فرض | نوع | توضیح |
|---|---|---|---|
| `APP_ENV` | `production` | string | `production` / `staging` / `development` / `local` |
| `APP_DEBUG` | `false` | bool | حالت اشکال‌زدایی. فقط از `.env` خوانده می‌شود. |
| `APP_URL` | `http://localhost:8000` | string | URL پایه برای لینک‌های داخلی. |
| `DB_HOST` | `localhost` | string | هاست دیتابیس. |
| `DB_NAME` | `mojavez_v3` | string | نام دیتابیس. |
| `DB_USER` | `root` | string | کاربر دیتابیس. |
| `DB_PASS` | (خالی) | string | رمز دیتابیس. |
| `BASE_URL` | `/` | string | مسیر پایه. مثال: `/`, `/public/`, `/mojavez/`. |
| `USE_CDN` | `false` | bool | همیشه `false` در v3 (self-host اجباری). |
| `CRON_SECRET` | (خالی) | string | رمز `/check-cancel`. ۶۴ کاراکتر hex. |
| `SYNC_SECRET` | (خالی) | string | رمز `/check-sync`. ۶۴ کاراکتر hex. |
| `GATEWAY_API_KEY` | (خالی) | string | API key برای `/api/v1/applicants/*`. ۶۴ کاراکتر hex. |
| `SESSION_LIFETIME` | `86400` | int | طول عمر نشست (۲۴ ساعت). |
| `ERROR_LOG_PATH` | (خالی) | string | مسیر لاگ خطا. خارج از DocumentRoot. |
| `PUBLIC_RATE_LIMIT_PER_MIN` | `30` | int | حداکثر درخواست/دقیقه برای endpointهای عمومی. |
| `TRACKING_REQUIRE_MOBILE` | `true` | bool | در v3 پیش‌فرض `true` (رفع ضعف v2). |
| `ADMIN_IP_ALLOWLIST` | (خالی) | string | لیست IPهای مجاز پنل مدیریت. |
| `IP_BLOCKLIST` | (خالی) | string | لیست IPهای مسدودشده. |
| `UPSTREAM_BASE` | `https://qr.mojavez.ir` | string | پایهٔ URL درگاه ملی. |
| `UPSTREAM_RATE_LIMIT_PER_MIN` | `30` | int | حداکثر درخواست/دقیقه به upstream. |
| `UPSTREAM_MIN_DELAY_MS` | `2000` | int | حداقل فاصله بین درخواست‌ها به upstream. |
| `UPSTREAM_MAX_DELAY_MS` | `5000` | int | حداکثر فاصله (با jitter). |
| `UPSTREAM_TIMEOUT_CONNECT` | `5` | int | Timeout اتصال (ثانیه). |
| `UPSTREAM_TIMEOUT_RESPONSE` | `8` | int | Timeout پاسخ (ثانیه). |
| `SYNC_CYCLE_INTERVAL_MIN` | `60` | int | فاصلهٔ چرخهٔ اتصال طلایی (دقیقه). |
| `SYNC_AUTO_CONFIRM_THRESHOLD` | `90` | int | آستانهٔ تأیید خودکار. |
| `SYNC_OPERATOR_FLAG_THRESHOLD` | `50` | int | آستانهٔ پرچم اپراتور. |
| `CACHE_TTL_SEARCH_SECONDS` | `1800` | int | TTL کش جستجو (۳۰ دقیقه). |
| `CACHE_TTL_LOOKUPS_SECONDS` | `86400` | int | TTL کش lookups (۲۴ ساعت). |
| `CACHE_TTL_COUNT_SECONDS` | `3600` | int | TTL کش count (۱ ساعت). |

### ۱۵.۲. ثابت‌های PHP در `config/config.php`

| ثابت | مقدار | توضیح |
|---|---|---|
| `APP_VERSION` | `'0.1.0'` | در `/health` و فوتر. |
| `APP_VERSION_NAME` | `'بازنویسی یکپارچه از صفر — v3'` | نام نسخه. |
| `MAX_UPLOAD_SIZE` | `2 * 1024 * 1024` (2 MiB) | حداکثر حجم مدارک. |
| `MAX_CSV_SIZE` | `5 * 1024 * 1024` (5 MiB) | حداکثر حجم CSV. |
| `ALLOWED_EXTENSIONS` | `['jpg','jpeg','png','pdf']` | پسوندهای مجاز آپلود. |
| `UPLOAD_DIR` | `ROOT_PATH . '/uploads'` | مسیر ذخیره فایل‌ها (خارج از `public/`). |
| `AUTO_CANCEL_DAYS` | `14` | لغو خودکار پس از ۱۴ روز از «ثبت درخواست صدور». |
| `PRIORITY_DAYS` | `12` | اولویت‌بندی برای ثبت‌نام بیش از ۱۲ روز. |
| `LicenseAccessRequest::TOKEN_HOURS` | `72` | اعتبار توکن تصویر مجوز. |
| `FileStorage::MAX_IMAGE_DIMENSION` | `1920` | حداکثر عرض/ارتفاع تصویر. |
| `FileStorage::JPEG_QUALITY` | `85` | کیفیت JPEG. |
| `FileStorage::PNG_COMPRESSION` | `9` | سطح فشرده‌سازی PNG. |

### ۱۵.۳. فایل‌های پیکربندی در `config/`

| فایل | نقش |
|---|---|
| `app.php` | name, version, env, debug, timezone, locale, default_page_size, cache TTLs, admin creds from env |
| `database.php` | MySQL DSN, charset utf8mb4, timezone +00:00, EMULATE_PREPARES=false |
| `anti-detection.php` | user_agent, headers, rate_limit, retry, timeout, upstream_base |
| `filters.php` | فیلتر پیش‌فرض (میراث فرهنگی + خراسان رضوی + ۱۴۰۰-۱۴۰۵) |
| `statuses.php` | مرجع ۱۸ وضعیت (Single Source of Truth) |
| `workflow.php` | ماتریس انتقال نقش‌ها |
| `routes.php` | ~۱۴۰ مسیر (ترتیب مهم) |

---

## 16. استقرار در محیط‌های مختلف

### ۱۶.۱. PHP Built-in Server (توسعه)

```bash
php -S localhost:8000 -t public public/router.php
```

### ۱۶.۲. Docker (تولید/استقرار کامل)

```bash
cd deploy/docker
cp .env.example .env
docker compose up -d --build
```

**سه سرویس:**
- `db`: MariaDB 10.11 (volume `mojavez-v3-db-data`, healthcheck).
- `app`: PHP 8.2-apache (build از Dockerfile, volume `mojavez-v3-uploads`).
- `web`: Nginx 1.27-alpine (reverse proxy, پورت `${MOJAVEZ_PORT:-8080}`).

**Dockerfile ویژگی‌ها:**
- نصب `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `curl`, `default-mysql-client`.
- `a2enmod rewrite headers`.
- DocumentRoot → `/var/www/html/public`.
- `chmod 0775` روی `uploads/`.
- `HEALTHCHECK` با `curl http://localhost/health`.
- `entrypoint.sh`: انتظار برای MySQL (۶۰ تلاش × ۲s) + اجرای `install.sql` با `--force` (در صورت `INIT_DB=1`).

### ۱۶.۳. Linux (Apache + MariaDB + systemd)

```bash
sudo bash deploy/linux/install.sh
sudo bash deploy/linux/install-service.sh  # systemd
```

### ۱۶.۴. Windows (XAMPP/Laragon)

```cmd
deploy\windows\install-and-run.bat
```

### ۱۶.۵. هاست اشتراکی (cPanel/DirectAdmin/Nginx+Apache)

```bash
# آپلود فایل‌ها → بازدید check.php → اجرای install.php
https://example.com/check.php     # بررسی پیش‌نیازها
https://example.com/install.php   # نصب‌کنندهٔ وب دو مرحله‌ای + تولید .env, CRON_SECRET, SYNC_SECRET, GATEWAY_API_KEY, رمز admin تصادفی
```

**نکات کلیدی هاست اشتراکی:**
- `BASE_URL=/public/` در `.env` (تداوم رفع v0.6.3 از v2).
- `root-htaccess.htaccess` در `public_html/` برای ری‌دایرکت ۳۰۱ به `/public/`.
- `.htaccess` سازگار: بدون `<LimitExcept>`, `ServerTokens`, `mod_php`. همهٔ قواعد با `<IfModule>`.
- `install.php` خودکار `CRON_SECRET`, `SYNC_SECRET`, `GATEWAY_API_KEY` تولید می‌کند.
- **(جدید برای v3)** اگر cron در دسترس است:
  ```bash
  * * * * * /usr/local/bin/php /home/username/mojavez-v3/scripts/run_worker.php --max-jobs=5 >> /home/username/mojavez-v3/storage/logs/worker.log 2>&1
  0 */1 * * * /usr/local/bin/php /home/username/mojavez-v3/scripts/run_sync.php >> /home/username/mojavez-v3/storage/logs/sync.log 2>&1
  ```
- اگر نه: Poor Man's Cron با هر بازدید صفحهٔ admin.

### ۱۶.۶. Apache vhost (`deploy/apache/mojavez.conf`)

DocumentRoot روی `public/`, `AllowOverride All` برای `.htaccess`.

### ۱۶.۷. Nginx server block (`deploy/nginx/mojavez.conf`)

`try_files $uri $uri/ /index.php?$query_string;` + fastcgi به PHP-FPM.

### ۱۶.۸. محدودیت کلیدی: geofence ایران

> سایت هدف `qr.mojavez.ir` **فقط از داخل ایران قابل دسترسی است**. هرگونه تست زندهٔ crawl باید در محیط داخل ایران انجام شود. در محیط خارج از ایران، `MojavecClient` با خطای `timeout` مواجه می‌شود. برای توسعهٔ خارج از ایران باید از **دادهٔ seed‌شده / فایل HAR** (`tests/fixtures/har/`) یا **mock HTTP** استفاده کرد.

---

## 17. مهاجرت داده از هر دو پروژهٔ پیشین

### ۱۷.۱. مهاجرت از Mojavez-v2 (`scripts/migrate_from_v2.php`)

| منبع در v2 | مقصد در v3 | تبدیل |
|---|---|---|
| `applicants` (۲۸ جدول v2) | همان جداول در v3 | مستقیم (schema یکسان)، به‌جز ستون‌های جدید `national_license_request_number`, `matched_at`, `match_confidence` که NULL می‌مانند تا sync پر کند |
| `persons` | `persons` | افزودن `father_name`, `birth_date` از `applicant_field_values` (در صورت موجود بودن) |
| `status_log` | `status_log` | `triggered_by='admin'` یا `'user'` پیش‌فرض |
| `uploads/{tracking_code}/` | همان مسیر | کپی مستقیم |
| `settings` | `settings` | مستقیم |

**مراحل:**
```bash
php scripts/migrate_from_v2.php --source-dsn="mysql:host=...;dbname=handicraft_license" --source-user=... --source-pass=...
```

### ۱۷.۲. مهاجرت از Mojavez Gateway (`scripts/migrate_from_gateway.php`)

| منبع در gateway (SQLite) | مقصد در v3 (MySQL) | تبدیل |
|---|---|---|
| `licenses` | `national_licenses` | تبدیل `datetime('now')` به `CURRENT_TIMESTAMP`، فیلدهای ماسک از `raw_json` استخراج و در ستون‌های `*_masked` ذخیره، `is_saved` و `saved_at` مستقیم |
| `lookups` | `national_lookups` | تبدیل `kind` به ENUM |
| `filters` | `filters` | مستقیم |
| `batches` | `batches` | مستقیم |
| `jobs` | `jobs` | تبدیل `type` به ENUM v3 |
| `events` | `events` | مستقیم |
| `settings` | `settings` | فقط کلیدهایی که در v3 نیست، merge |
| `admin_users` | `users` | فقط در صورت عدم وجود کاربر با همان username؛ password_hash مستقیم |
| `worker_state` | `worker_state` | مستقیم |

**مراحل:**
```bash
php scripts/migrate_from_gateway.php --source-sqlite=/path/to/gateway/storage/app.db
```

### ۱۷.۳. استراتژی مهاجرت توصیه‌شده

۱. v3 را روی هاست هدف با دیتابیس خالی مستقر کنید.
۲. ابتدا مهاجرت از v2 را اجرا کنید (دادهٔ اصلی پرونده‌ها).
۳. سپس مهاجرت از gateway را اجرا کنید (دادهٔ کش درگاه ملی).
۴. پس از مهاجرت، یک چرخهٔ sync دستی اجرا کنید تا اتصال طلایی شروع به پر کردن `matched_applicant_id` کند.
۵. دادهٔ migration را در `sync_audit_log` با `action='migrated'` علامت‌گذاری کنید تا از دادهٔ post-migration متمایز باشد.

### ۱۷.۴. پشتیبان‌گیری

پیش از هر مهاجرت:
```bash
mysqldump -u root -p handicraft_license > v2_backup_$(date +%Y%m%d).sql
cp gateway/storage/app.db gateway/storage/app.db.backup.$(date +%Y%m%d)
```

---

## 18. تست و تضمین کیفیت

### ۱۸.۱. تحلیل استاتیک (`tools/static-analysis.py`)

تداوم از v2 با موارد جدید برای ماژول درگاه ملی:
- syntax PHP همهٔ فایل‌ها.
- ارجاعات require/include.
- routeها و کنترلرها (~۱۴۰ مسیر).
- CSRF در فرم‌های POST (همه — رفع S1).
- htmlspecialchars در همهٔ خروجی‌ها.
- Prepared statements (هیچ کوئری بدون prepared).
- AutoCancel و Priority.
- helperها و ثابت‌ها.
- جدول‌ها و مدل‌ها.
- فرم‌های ثبت‌نام.
- آپلود فایل و امنیت.
- احراز هویت و نقش‌ها.
- خروجی CSV با BOM.
- نسخهٔ چاپی A4.
- CSV Injection.
- هدرهای امنیتی.
- `APP_VERSION`.
- **(جدید) rate limiter واقعاً فعال در `MojavecClient`** (رفع ST1).
- **(جدید) `CURLOPT_SSL_VERIFYPEER=true` در `/api/v1/proxy-image`** (رفع S4).
- **(جدید) احراز هویت روی همهٔ `/api/v1/admin/*`** (رفع S2).

هدف: **۱۸+ PASS، ۰ WARN، ۰ FAIL.**

### ۱۸.۲. تست‌های smoke (۱۷۱+ از v2 + موارد جدید)

`tests/smoke-test.sh` با curl + bash. پوشش:
- صفحات عمومی، ویزارد، پیگیری، پنل مدیریت، گزارش‌ها، سرو مدارک، هدرها، فرم‌ساز، تنظیمات، رشته‌ها، پرداخت، تصویر مجوز، پیامک، بازبینی، چندمجوزی، مراحل ویزارد، سرویس‌ها، حقوقی (۱۷۱ از v2).
- **(جدید)** endpointهای `/api/v1/licenses/*` (با احراز هویت admin).
- **(جدید)** endpointهای `/api/v1/applicants/*` (با API key).
- **(جدید)** صفحهٔ `/license/{rn}` و `/national/search`.
- **(جدید)** پنل `/admin/sync/status` و `/admin/sync/pending`.
- **(جدید)** `/check-sync` با `SYNC_SECRET`.

هدف: **۱۸۵+ موفق / ۰ ناموفق.**

### ۱۸.۳. تست‌های واحد PHP

`tests/run.php` با `TestCase.php`. فایل‌های تست:
- `ConfigTest`, `FunctionalTest`, `LicenseTitleTest`, `SecurityTest`, `ServiceScopeTest`, `StatusWorkflowTest`, `WizardFormTest` (از v2).
- **(جدید)** `PatternMatcherTest` — ۴ مثبت + ۴ منفی + تست آستانهٔ آرای.
- **(جدید)** `MojavecClientTest` — با mock HTTP (HAR).
- **(جدید)** `TrackScraperTest` — با fixture HAR واقعی.
- **(جدید)** `NationalSyncOrchestratorTest` — چرخهٔ کامل sync با dataset مصنوعی.
- **(جدید)** `MigrationTest` — تأیید schema یکپارچه.

### ۱۸.۴. تست رگرسیون scraper (`scripts/test_track_scraper.py`)

پورت پایتونی منطق scraper با **۱۵ assertion قفل‌شده** روی مقادیر fixture واقعی (`tests/fixtures/har/qr.mojavez.ir-track-I17981871.har`). هر تغییر scraper باید قبل از کامیت با این تست تأیید شود.

### ۱۸.۵. تست الگوریتم تطبیق الگویی (`scripts/pattern_matcher.py`)

پورت پایتونی `PatternMatcher` با:
- ۴ مثال مثبت MATCH (کد ملی ماسک‌شده با ۴ الگوی متفاوت، نشانی ماسک‌شده).
- ۴ کنترل منفی (mismatch در anchors، mismatch در طول، شخص متفاوت، ماسک بدون anchor).
- تست آستانهٔ آرای.

### ۱۸.۶. تست واکنش‌گرایی (Playwright)

۸ دستگاه موبایل/تبلت/دسکتاپ. همگی سبز.

### ۱۸.۷. محیط تست

Debian 12، PHP 8.2.34، MariaDB 10.11.18.

### ۱۸.۸. PHPStan (پیشنهادی برای آینده)

یادآوری از خطای ۰۰۶-۰۱: اعتبارسنجی syntax کافی نیست. PHPStan برای کشف خطاهای شمارش آرگومان/نوع ضروری است. افزودن به CI اولویت بالایی دارد.

---

## 19. نقشهٔ راه بازنویسی (۷ فاز)

### ۱۹.۱. فاز ۱ — هسته و معماری (هفتهٔ ۱-۲)

- [ ] ساخت مخزن `mojavez-v3` (موقعیت مخزن توسط تیم توسعه تعیین می‌شود).
- [ ] پیاده‌سازی `app/Container.php` (DI دستی با autowire + setter injection + memoized singleton).
- [ ] پیاده‌سازی `app/Http/` (Request, Response, Router, Session, Csrf).
- [ ] پیاده‌سازی `app/Support/` (Config, Environment, Logger, Validator, Hash, PersianDate, Normalizer, CsvReader).
- [ ] پیاده‌سازی `app/Persistence/Connection.php` (MySQL Singleton با `EMULATE_PREPARES=false`).
- [ ] `public/index.php` (front controller + security headers + IP blocklist + `/health` + `/check-cancel` + `/check-sync`).
- [ ] `public/.htaccess` سه‌لایه.
- [ ] `database/schema.sql` و `database/install.sql` (۳۲ جدول).
- [ ] `database/migrations/001_init_core.sql` تا `007_seed_defaults.sql`.
- [ ] `public/install.php` با نصب تعاملی (رمز admin تصادفی).

### ۱۹.۲. فاز ۲ — دامنهٔ v2 (هفتهٔ ۳-۴)

- [ ] `app/Domain/` با Value Objects و Enums (Applicant, Person, License, Filter, ApplicantStatus, LicenseState, ...).
- [ ] `app/Modules/Registration/` (ApplicantRegistrationService, ویزارد پویا).
- [ ] `app/Modules/License/` (صدور، تحویل، LicenseAccessRequest، LicenseFile).
- [ ] `app/Modules/Payment/` (رسید پرداخت، FeeCalculatorService، نرخ‌نامه).
- [ ] `app/Modules/Revision/`.
- [ ] `app/Modules/Reporting/` (CSV/PDF با Formula Injection protection).
- [ ] `app/Services/` (AutoCancelService, FileStorage, ...).
- [ ] Controllers و Views وارث از v2 (~۸۰ مسیر).
- [ ] پنل کاربر و پنل مدیریت.

### ۱۹.۳. فاز ۳ — ماژول درگاه ملی (هفتهٔ ۵-۶)

- [ ] `app/Modules/NationalGateway/` با:
  - `Infrastructure/HttpClient.php` (cURL با TLS روشن، cookie jar).
  - `Infrastructure/AntiDetectionMiddleware.php` (هدرهای ثابت + jitter Gaussian).
  - `Infrastructure/MojavecClient.php` (search, count, provinces, townships, organizations, subOrganizations — با `$rateLimiter` همیشه پاس — رفع ST1).
  - `Infrastructure/TrackScraper.php` (RSC parser — از gateway به ارث).
  - `Infrastructure/Parser/JsonSearchParser.php`.
  - `Services/RateLimiterService.php` (واقعاً فعال).
  - `Services/LicenseService.php` (searchHybrid, saveAllResults, fetchAndCacheByTrackNumber).
- [ ] انتقال `tests/fixtures/har/` و `scripts/test_track_scraper.py`.
- [ ] انتقال `scripts/pattern_matcher.py` و پیاده‌سازی PHP `PatternMatcher`.
- [ ] Controllers: `NationalGatewayController`, `BatchController`, `SearchController`, `ProxyController` (با TLS روشن و CORS محدود — رفع S4).
- [ ] Views: صفحهٔ جزئیات مجوز، جستجوی چندوجهی، دسته‌ای CSV.
- [ ] انتقال دارایی‌های vendor: `qrcode-vendor.js`, `html2canvas-vendor.js`, `jspdf-vendor.js`.

### ۱۹.۴. فاز ۴ — زیرساخت async و اتصال طلایی (هفتهٔ ۷-۸)

- [ ] `app/Events/EventBus.php` + ۹ event + ۳ subscriber (LogSubscriber, SyncSubscriber, NotificationSubscriber).
- [ ] `app/Queue/JobQueue.php` + `Worker.php` + ۵ Job (SearchJob, BatchJob, CountJob, SyncJob, TrackFetchJob).
- [ ] `app/Services/NationalSyncOrchestrator.php` (چرخهٔ کامل sync).
- [ ] `app/Services/PatternMatcher.php` (پیاده‌سازی PHP).
- [ ] `app/Services/NotificationService.php` (SMS + اعلان درون‌سامانه).
- [ ] الگوی poor man's cron در `BatchController::status` و `/admin/sync/status`.
- [ ] `SseController::stream` (در production فعال، در dev 204).

### ۱۹.۵. فاز ۵ — مهاجرت داده (هفتهٔ ۹)

- [ ] `scripts/migrate_from_v2.php`.
- [ ] `scripts/migrate_from_gateway.php`.
- [ ] تست مهاجرت با dataset واقعی.
- [ ] اجرای چرخهٔ sync دستی برای پر کردن `matched_applicant_id`.

### ۱۹.۶. فاز ۶ — امنیت، تست، استقرار (هفتهٔ ۱۰-۱۱)

- [ ] ممیزی امنیتی کامل (تطابق با بخش ۱۲).
- [ ] اجرای `tools/static-analysis.py` (هدف: ۱۸+ PASS, ۰ FAIL).
- [ ] اجرای `tests/smoke-test.sh` (هدف: ۱۸۵+ موفق).
- [ ] اجرای `tests/run.php`.
- [ ] اجرای `scripts/test_track_scraper.py`.
- [ ] اجرای `scripts/pattern_matcher.py`.
- [ ] تست واکنش‌گرایی با Playwright.
- [ ] استقرار در Docker، Apache، Nginx، shared hosting.
- [ ] مستندسازی کامل (`docs/` با ~۲۰ سند).

### ۱۹.۷. فاز ۷ — نهایی‌سازی و آرشیو پروژه‌های پیشین (هفتهٔ ۱۲)

- [x] طراحی یکدست شده (Bootstrap 5 RTL + Vazirmatn self-hosted + تم روز/شب). ✅ فاز ۷
- [x] RELEASE: v3 version `1.0.0`. ✅ فاز ۷ (tag v1.0.0)
- [x] آرشیو `Mojavez-v2` و `mojavez-gateway` (فقط برای مرجع نگه‌داری، دیگر توسعه داده نمی‌شوند). ✅ فاز ۷ (docs/ARCHIVE-PREDECESSORS.md)
- [x] منتقل کردن URLهای تولید از v2 به v3. ✅ فاز ۷ (Rollout Stage 1 فعال، ready for cutover)
- [x] بسته‌بندی: D-2-1 (UI) + D-2-7 (schema.sql) + D-4-1 (SmsService) پرداخت شدند. UsersController + SettingsController اضافه شد. NationalSyncOrchestrator با sync_direction flag به Rollout Stage 1 وصل شد.

### ۱۹.۸. موارد باقی‌مانده پس از v1.0.0

- اتصال واقعی به درگاه پرداخت آنلاین (زرین‌پال/PSP).
- سرویس واقعی ارسال/دریافت SMS.
- مجوزهای گردشگری و هنری/فرهنگی (فاز ۹ از v2 فقط ۳ سرویس داشت).
- تبدیل PDF باینری واقعی (بدون کتابخانه).
- Rate Limiting سطح IP کامل (با fail2ban).
- نرمال‌سازی ZWNJ در جستجوی LIKE.
- webhook از درگاه ملی (در صورت افزودن توسط upstream در آینده).

---

## 20. ریسک‌ها، محدودیت‌ها و تله‌ها

### ۲۰.۱. ریسک‌ها

| ریسک | شدت | راه‌حل |
|---|---|---|
| **R1: کلید تطبیق `national_code` ماسک‌شده** | بالا (حل‌شده با تطبیق الگویی) | تطبیق الگویی + آرای چندفیلدی. `request_number` یکی از سیگنال‌هاست، نه تنها سیگنال. اعتبارسنجی R1 پیش از sync. |
| **R2: geofence ایران** | بالا | در sandbox از دادهٔ ثبت‌شدهٔ قبلی (HAR) برای تست استفاده می‌کنیم؛ تست زنده در محیط کاربر داخل ایران. |
| **R3: نیاز به MySQL در هاست** | متوسط | قبل از شروع مطمئن شوید هاست MySQL دارد. |
| **R4: شکنندگی scraper (R6)** | متوسط | تست scraper را اول و سخت‌گیرانه ببرید. fixture HAR و اسکریپت رگرسیون. |
| **R5: rate limiter کد مرده (ST1)** | بالا (رفع‌شده در v3) | در v3، `RateLimiterService::wait()` همیشه در `MojavecClient::doWithRetry()` صدا زده می‌شود. |
| **R6: شکنندگی TrackScraper RSC** | متوسط | اگر سایت ملی فرمت RSC را عوض کند، scraper می‌شکند. تست رگرسیون + مانیتورینگ `/api/v1/admin/crawl`. |
| **R7: مهاجرت دادهٔ ناهماهنگ** | متوسط | تست مهاجرت با dataset واقعی پیش از rollout. پشتیبان‌گیری کامل. |
| **R8: تأیید خودکار نادرست (false positive)** | بالا | آرای چندفیلدی با حداقل ۳ فیلد. audit کامل در `sync_audit_log`. امکان مرور اپراتور همیشه موجود. |
| **R9: توکن GitHub لو رفته** | متوسط | rotate شده پیش از شروع v3 (یادآوری از مکالمهٔ #021). |

### ۲۰.۲. تله‌های هاست اشتراکی (تداوم از v2)

- **۴۰۴ فایل‌های استاتیک:** `BASE_URL=/public/` + `root-htaccess.htaccess` الزامی است.
- **`.htaccess` محدود:** بدون `<LimitExcept>`, `ServerTokens`, `mod_php`. همهٔ قواعد با `<IfModule>`.
- **`ErrorDocument` با متن فارسی → mojibake** بدون `<meta charset='UTF-8'>`.

### ۲۰.۳. حساسیت به نسخهٔ PHP

- **PHP 8.5+:** `PDO::MYSQL_ATTR_INIT_COMMAND` منسوخ. `str_getcsv` منسوخ. `sid_bits_per_character=6` هشدار.
- **PHP 8.4+:** `str_getcsv` منسوخ.
- **توصیه:** PHP 8.2.x پایدارترین است.

### ۲۰.۴. تله‌های PDF.js (تداوم از v2)

- مرورگرها با `inline` هم دانلود می‌کنند → PDF.js روی canvas.
- هدرهای Apache با `Header always set` هدرهای PHP را بازنویسی می‌کنند → embed base64.
- CDN با CSP مشکل دارد → بارگذاری محلی.

### ۲۰.۵. تفاوت‌های MariaDB vs MySQL (تداوم از v2)

- `SET ... = LAST_INSERT_ID() WHERE` نامعتبر در MariaDB.
- `INSERT ... ON DUPLICATE KEY UPDATE` با UNIQUE ممکن است خطا دهد.
- راه‌حل: `INSERT ... SELECT ... FROM DUAL WHERE NOT EXISTS`.
- `EMULATE_PREPARES=false` با پارامترهای نام‌دار تکراری مشکل دارد.

### ۲۰.۶. تله‌های scraper (تداوم از gateway)

- **۶ آرگومان در `preg_match`** (خطای ۰۰۶-۰۱): در v3 از همان راهکار رفع استفاده می‌شود (`substr` + ۳ آرگومان).
- **کد مرده rate limiter** (ST1): در v3 فعال.
- **کد ملی خالی با وجود داده** (۰۰۵-۰۲): `extractCellValue()` مدیریت آرایهٔ رشته‌ای خام را دارد.
- **نبود Logger به‌عنوان singleton** (۰۰۴-۰۳): در v3، Logger singleton است.
- **Connection به‌عنوان factory** (۰۰۴-۰۵): در v3، Connection memoized singleton است.
- **Router regex نامعتبر برای `/`** (۰۰۴-۰۶): `/?` در انتهای همهٔ regex‌ها.

### ۲۰.۷. محدودیت‌های ساختاری

- هیچ view/trigger/stored procedure دائمی در schema.
- هیچ ORM — همهٔ کوئری‌ها دست‌نویس با PDO prepared statements.
- هیچ Composer — همهٔ وابستگی‌ها self-host یا نیتیو.

### ۲۰.۸. محدودیت‌های داده

- `applicants.tracking_code` و `national_code` غیرقابل‌ویرایش (حفظ یکپارچگی داده).
- `license_titles` به `service_type_id` اختصاصی شده — نگاشت به رشته‌های ملی باید این ساختار را در نظر بگیرد.
- وضعیت‌های اختصاصی مشاغل خانمی (`service_type_id=2`) با `smkh.mcls.gov.ir` هماهنگ است، نه با `qr.mojavez.ir`.

---

## 21. چک‌لیست نهایی بازنویسی

### ۲۱.۱. پیش از شروع فاز ۱

- [ ] مخزن `mojavez-v3` ساخته شده (موقعیت توسط تیم توسعه تعیین شده).
- [ ] توکن GitHub rotate شده (در صورت نیاز).
- [ ] هاست هدف با MySQL 5.7+ / MariaDB 10.3+ آماده.
- [ ] PHP 8.2.x روی محیط توسعه نصب.
- [ ] فایل HAR واقعی از `qr.mojavez.ir/track/I17981871` در دسترس (`tests/fixtures/har/`).
- [ ] محیط داخل ایران برای تست زنده crawl (یا mock HTTP آماده).

### ۲۱.۲. در پایان هر فاز

- [ ] تحلیل استاتیک PASS.
- [ ] تست‌های واحد فاز PASS.
- [ ] CHANGELOG.md به‌روزرسانی شده.
- [ ] commit با فرمت Conventional Commits.

### ۲۱.۳. پیش از rollout v1.0.0

- [ ] همهٔ ۷ فاز تکمیل شده.
- [ ] ۱۸۵+ تست smoke سبز.
- [ ] تست رگرسیون scraper سبز (۱۵/۱۵ PASS).
- [ ] تست الگوریتم تطبیق الگویی سبز (۴ مثبت + ۴ منفی).
- [ ] ممیزی امنیتی کامل (تطابق با بخش ۱۲ — رفع S1-S5, ST1, I1).
- [ ] `APP_DEBUG=false` و `APP_ENV=production`.
- [ ] رمز admin تصادفی تنظیم شده.
- [ ] `CRON_SECRET`, `SYNC_SECRET`, `GATEWAY_API_KEY` با `openssl rand -hex 32` تولید شده.
- [ ] `ADMIN_IP_ALLOWLIST` با IPهای مجاز تنظیم شده.
- [ ] `TRACKING_REQUIRE_MOBILE=true`.
- [ ] HTTPS فعال.
- [ ] `ERROR_LOG_PATH` خارج از DocumentRoot.
- [ ] بک‌اپ روزانه از `mojavez_v3` و `uploads/`.
- [ ] مهاجرت داده از v2 و gateway تست شده.
- [ ] مانیتورینگ `/health` هر ۶۰ ثانیه.
- [ ] fallback کش در صورت قطعی upstream.
- [ ] CSP سخت‌گیرانه (بدون `unsafe-eval`).
- [ ] `X-Robots-Tag: noindex, nofollow` روی همهٔ پاسخ‌ها.
- [ ] مستندسازی کامل در `docs/`.

### ۲۱.۴. پس از rollout

- [ ] نظارت بر `sync_audit_log` برای false positiveها.
- [ ] نظارت بر rate limit upstream (عدم دریافت ۴29).
- [ ] آرشیو `Mojavez-v2` و `mojavez-gateway`.
- [ ] منتقل کردن URLهای تولید.

---

## 22. مرجع فایل‌های کلیدی و دستورات

### ۲۲.۱. فایل‌های کلیدی

| فایل | نقش |
|---|---|
| `public/index.php` | نقطهٔ ورود + routing + security headers + IP blocklist + `/health` + `/check-cancel` + `/check-sync` |
| `app/Container.php` | DI container دستی (autowire + singleton + factory + setter) |
| `config/config.php` | تمام ثابت‌های پیکربندی |
| `config/env.php` | لودر `.env` بدون وابستگی |
| `config/helper.php` | `validate_uploaded_file`, `rate_limit_ip`, `client_ip`, `setting`, `normalize_national_code`, `normalize_mobile`, `build_notify_message` |
| `config/statuses.php` | مرجع ۱۸ وضعیت (Single Source of Truth) |
| `config/workflow.php` | ماتریس انتقال نقش‌ها |
| `config/anti-detection.php` | User-Agent، هدرها، rate limit، retry، timeout برای upstream |
| `config/routes.php` | ~۱۴۰ مسیر (ترتیب مهم) |
| `app/Persistence/Connection.php` | MySQL Singleton با `EMULATE_PREPARES=false` |
| `app/Http/Router.php` | مسیریاب ساده first-match با `/?` در regex |
| `app/Http/Session.php` | نشست + CSRF + rate limit session-based |
| `app/Http/Csrf.php` | CSRF token تولید + اعتبارسنجی (همیشه فعال) |
| `app/Support/Validator.php` | اعتبارسنجی (کد ملی، موبایل، ...) |
| `app/Support/PersianDate.php` | تبدیل میلادی↔جلالی |
| `app/Support/Normalizer.php` | نرمال‌سازی ارقام فارسی/عربی، ZWNJ |
| `app/Support/CsvReader.php` | خواندن CSV با حذف BOM |
| `app/Domain/Applicant.php` | قلب سامانه + `generateTrackingCode()` |
| `app/Domain/Person.php` | لنگر هویت |
| `app/Domain/License.php` | Value Object با ۳ factory (fromUpstream, fromTrackPage, fromArray) |
| `app/Domain/Filter.php` | payload جستجوی upstream + `toUpstreamPayload` |
| `app/Domain/Enums/ApplicantStatus.php` | ۱۸ وضعیت |
| `app/Domain/Enums/LicenseState.php` | ۴ مقدار |
| `app/Domain/Enums/LicenseStatus.php` | active/expired/unknown |
| `app/Domain/Enums/MatchConfidence.php` | AutoConfirmed/OperatorFlag/AutoRejected |
| `app/Modules/NationalGateway/Infrastructure/MojavecClient.php` | کلاینت جستجوی structured + retry + rate-limit فعال |
| `app/Modules/NationalGateway/Infrastructure/TrackScraper.php` | RSC payload parser (شکننده‌ترین جزء) |
| `app/Modules/NationalGateway/Infrastructure/AntiDetectionMiddleware.php` | هدرهای ثابت + jitter Gaussian |
| `app/Modules/NationalGateway/Infrastructure/HttpClient.php` | cURL نیتیو wrapper + cookie jar + TLS روشن |
| `app/Modules/NationalGateway/Services/RateLimiterService.php` | rate limit + jitter (واقعاً فعال) |
| `app/Modules/NationalGateway/Services/LicenseService.php` | searchHybrid, saveAllResults, fetchAndCacheByTrackNumber |
| `app/Services/PatternMatcher.php` | الگوریتم تطبیق الگویی + آرای چندفیلدی |
| `app/Services/NationalSyncOrchestrator.php` | چرخهٔ کامل اتصال طلایی |
| `app/Services/NotificationService.php` | SMS + اعلان درون‌سامانه |
| `app/Services/AutoCancelService.php` | لغو خودکار + اولویت‌بندی |
| `app/Services/FileStorage.php` | ذخیرهٔ مدارک با ۷ لایه امنیت |
| `app/Events/EventBus.php` | رویدادها + persist در `events` |
| `app/Queue/JobQueue.php` + `Worker.php` | صف وظایف |
| `app/Controllers/Controller.php` | guards (`requireLogin`, `requireRole`, `validateStatusTransition`, `verifyCsrf`, `requireApiKey`) |
| `database/schema.sql` | مرجع توسعه (بدون ALTER) |
| `database/install.sql` | نصب idempotent (production) |
| `database/migrations/*.sql` | ۷ migration |
| `deploy/docker/` | Docker stack کامل |
| `deploy/shared-hosting/install.php` | نصب‌کنندهٔ وب تعاملی |
| `deploy/shared-hosting/check.php` | بررسی پیش‌نیازها |
| `.htaccess` + `public/.htaccess` + `uploads/.htaccess` | سه‌لایهٔ امنیت Apache |
| `tests/fixtures/har/qr.mojavez.ir-track-I17981871.har` | fixture واقعی برای تست scraper |
| `scripts/test_track_scraper.py` | تست رگرسیون scraper (۱۵ assertion) |
| `scripts/pattern_matcher.py` | مرجع الگوریتم تطبیق الگویی |
| `scripts/migrate_from_v2.php` | مهاجرت داده از v2 |
| `scripts/migrate_from_gateway.php` | مهاجرت داده از gateway |
| `scripts/run_worker.php` | اجرای Worker |
| `scripts/migrate.php` | اجرای migration از CLI |
| `scripts/seed_lookups.php` | seed استان‌ها و دستگاه‌ها از upstream |
| `scripts/run_sync.php` | اجرای دستی چرخهٔ اتصال طلایی |
| `CHANGELOG.md` | تاریخچهٔ نسخه‌ها (از 0.1.0 v3) |
| `REFACTOR_PLAN.md` | این سند |
| `docs/` | ~۲۰ سند فارسی کامل |

### ۲۲.۲. ثابت‌های مهم

| ثابت | مقدار | کاربرد |
|---|---|---|
| `APP_VERSION` | `'0.1.0'` | در `/health` بازگردانده می‌شود |
| `AUTO_CANCEL_DAYS` | `14` | لغو خودکار |
| `PRIORITY_DAYS` | `12` | اولویت‌بندی |
| `PUBLIC_RATE_LIMIT_PER_MIN` | `30` | rate limit endpointهای عمومی |
| `TRACKING_REQUIRE_MOBILE` | `true` (پیش‌فرض v3) | امنیت کد رهگیری |
| `LicenseAccessRequest::TOKEN_HOURS` | `72` | اعتبار توکن تصویر مجوز |
| `MAX_UPLOAD_SIZE` | `2 MiB` | حد آپلود |
| `ALLOWED_EXTENSIONS` | `['jpg','jpeg','png','pdf']` | پسوندهای مجاز |
| `UPSTREAM_RATE_LIMIT_PER_MIN` | `30` | rate limit به درگاه ملی |
| `UPSTREAM_MIN_DELAY_MS` | `2000` | حداقل فاصله به upstream |
| `SYNC_CYCLE_INTERVAL_MIN` | `60` | فاصلهٔ چرخهٔ اتصال طلایی |
| `SYNC_AUTO_CONFIRM_THRESHOLD` | `90` | آستانهٔ تأیید خودکار |
| `SYNC_OPERATOR_FLAG_THRESHOLD` | `50` | آستانهٔ پرچم اپراتور |

### ۲۲.۳. دستورات مفید

```bash
# کلون
git clone <URL مخزن پیاده‌سازی که توسط تیم توسعه تعیین می‌شود>.git

# اجرای محلی
php -S localhost:8000 -t public public/router.php

# نصب دیتابیس
mysql -u root -p < database/install.sql
php public/install.php     # نصب تعاملی (رمز admin تصادفی)

# اجرای تست‌ها
./tests/smoke-test.sh
php tests/run.php
python3 tools/static-analysis.py
python3 tools/responsive-test.py
python3 scripts/test_track_scraper.py     # تست رگرسیون scraper
python3 scripts/pattern_matcher.py        # تست الگوریتم تطبیق

# Docker
cd deploy/docker && cp .env.example .env && docker compose up -d --build

# بررسی سلامت
curl http://localhost:8000/health

# لغو خودکار دستی
curl -H "X-Cron-Secret: $CRON_SECRET" http://localhost:8000/check-cancel

# اجرای دستی چرخهٔ اتصال طلایی
curl -H "X-Sync-Secret: $SYNC_SECRET" http://localhost:8000/check-sync

# مهاجرت از v2
php scripts/migrate_from_v2.php --source-dsn="mysql:host=...;dbname=handicraft_license" --source-user=... --source-pass=...

# مهاجرت از gateway
php scripts/migrate_from_gateway.php --source-sqlite=/path/to/gateway/storage/app.db

# اجرای Worker (در عمل با poor man's cron جایگزین)
php scripts/run_worker.php --max-jobs=5

# seed lookups از upstream (فقط از داخل ایران)
php scripts/seed_lookups.php
```

### ۲۲.۴. ورود پیش‌فرض

- **پنل مدیریت:** `/admin/login` — (رمز از `install.php` نمایش داده شد — ذخیره کنید).
- **پنل کاربری:** `/login` — (با موبایل).

---

## پیوست: نمودار جریان یکپارچهٔ mojavez-v3

```
┌─────────────────────────────────────────────────────────────────────┐
│                    درگاه ملی مجوزها qr.mojavez.ir                     │
│              (فقط از داخل ایران قابل دسترسی — geofence)                │
│  endpointها: /api/v1/licenses/search, /count, /lookups/*,            │
│              /track/{rn} (RSC), /api/v1/licenses/{rn}/advanced-data  │
└────────────────────────────┬────────────────────────────────────────┘
                             │ MojavecClient + TrackScraper
                             │ (RateLimiter واقعاً فعال — رفع ST1)
                             │ (TLS روشن — رفع S4)
                             │ (AntiDetectionMiddleware: UA ثابت + jitter)
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│            ماژول NationalGateway (لایهٔ واسط داخلی)                    │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────────────┐  │
│  │ PatternMatcher│  │ LicenseService│  │ NationalSyncOrchestrator   │  │
│  │ (ماسک به‌عنوان │  │ (searchHybrid,│  │ (چرخهٔ اتصال طلایی:          │  │
│  │  لنگر + آرای  │  │  saveAll,     │  │  scrape→match→act→notify) │  │
│  │  چندفیلدی)    │  │  fetchByTrack)│  │                            │  │
│  └──────────────┘  └──────────────┘  └────────────────────────────┘  │
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │ جدول national_licenses (کش پایدار — رفع I1)                    │ │
│  │ جدول national_lookups (استان/شهر/سازمان)                        │ │
│  │ جدول sync_audit_log (audit کامل تصمیم‌ها)                       │ │
│  └────────────────────────────────────────────────────────────────┘ │
└────────────────────────────┬────────────────────────────────────────┘
                             │ اتصال طلایی: matched_applicant_id
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│                Mojavez-v3 (منبع حقیقت محلی — وارث v2)                 │
│  PHP 8.2 خالص + MySQL/MariaDB + ۳۲ جدول + ~۱۴۰ مسیر                   │
│  applicants.tracking_code (HG-XXXXXXXX) ← کلید اصلی محلی              │
│  applicants.national_license_request_number ← کلید اتصال طلایی        │
│  applicants.license_state (۴ مقدار) ← فیلتر اصلی                      │
│  applicants.license_valid_until ← معیار اعتبار                        │
│  ۱۸ وضعیت گردش کار + ماتریس انتقال نقش‌ها                              │
│  دو قلمرو احراز هویت (admin + user) + API key برای خارجی              │
│  CSRF همیشه فعال (رفع S1) + احراز هویت admin/* (رفع S2)               │
│  /health (مانیتورینگ) + /check-cancel + /check-sync                   │
│  JobQueue + EventBus + Worker (poor man's cron یا cron واقعی)        │
└─────────────────────────────────────────────────────────────────────┘
```

---

## پیوست: چک‌لیست رفع مشکلات شناخته‌شده (خلاصه)

| شناسه | مشکل | رفع در v3 | فاز |
|---|---|---|---|
| S1 | CSRF غیرفعال در gateway | `verifyCsrf()` در هر درخواست mutating — همیشه فعال | فاز ۱ |
| S2 | بدون احراز هویت روی `/api/admin/*` | `requireRole` روی همهٔ `/api/v1/admin/*` | فاز ۱ |
| S3 | رمز admin پیش‌فرض `admin` | نصب تعاملی با رمز تصادفی ۱۶-کاراکتری | فاز ۱ |
| S4 | SSRF در `/api/proxy-image` | `CURLOPT_SSL_VERIFYPEER=true`, `FOLLOWLOCATION=false`, CORS محدود | فاز ۳ |
| S5 | `storage/` فقط با `.htaccess` | document root روی `public/` | فاز ۱ |
| ST1 | Rate limiter کد مرده | `RateLimiterService::wait()` همیشه در `MojavecClient` | فاز ۳ |
| I1 | فیلدهای track ناپایدار | جدول `national_licenses` با ستون‌های `*_masked` پایدار | فاز ۳ |
| R1 | کلید تطبیق ماسک‌شده | تطبیق الگویی + آرای چندفیلدی | فاز ۴ |
| R6 | شکنندگی scraper RSC | تست رگرسیون + fixture HAR | فاز ۳ |
| (v2) TRACKING_REQUIRE_MOBILE=false | کد رهگیری به‌تنهایی کافی | پیش‌فرض `true` در production | فاز ۱ |
| (v2) رمز hardcoded در seed-admin.sql | در فایل SQL | حذف کامل؛ نصب تعاملی | فاز ۱ |
| (v2) unsafe-eval در CSP | برای PDF.js | حذف با بارگذاری محلی بهینه | فاز ۱ |
| (gateway) unsafe-eval | — | هرگز وجود ندارد در v3 | فاز ۱ |
| (gateway) CDN در CSP | — | حذف؛ self-host اجباری | فاز ۱ |
| (gateway) SQLite | محدودیت همزمانی | MySQL/MariaDB | فاز ۱ |
| (gateway) JWT در git history | توکن لو رفته | rotate پیش از شروع v3 | پیش از فاز ۱ |
| (gateway) Dependency cycle | وابستگی‌های چرخه‌ای | setter injection (تداوم رفع ۰۰۳-۰۱) | فاز ۱ |
| (gateway) Connection factory | چندین PDO | memoized singleton (تداوم رفع ۰۰۴-۰۵) | فاز ۱ |
| (gateway) Router regex `/` | نامعتبر | `/?` در انتهای همهٔ regex‌ها | فاز ۱ |
| (gateway) preg_match ۶-آرگومانی | ArgumentCountError | `substr` + ۳ آرگومان (تداوم رفع ۰۰۶-۰۱) | فاز ۳ |

---

# ضمیمهٔ بهبودها

> **منشأ:** بخش‌های ۲۳ تا ۳۰ زیر بر اساس مقایسه با سند استراتژی-ریسک (`سند_توسعه_mojavez_v2.md`) استخراج شده‌اند. این سند چهارم با زاویهٔ متفاوتی نوشته شده بود — به‌جای «بازنویسی از صفر»، فرض می‌کرد v2 ابتدا باید با ۶ فاز زیرساختی توسعه یابد. با وجود محدودیت دسترسی به gateway در آن سند، تحلیل «جوانب پنهان» و تدابیر پیشگیرانهٔ آن ارزش بالایی داشت که در سند اصلی به‌صورت ضمنی پوشش داده نشده بود. این ضمیمه این-gap را پر می‌کند.

---

## 23. جوانب پنهان و تدابیر پیشگیرانه

این بخش به جنبه‌هایی می‌پردازد که در فاز طراحی معمولاً پنهان می‌مانند اما در تولید آشکار می‌شوند و پرهزینه می‌شوند. هر جنبه با تدبیر پیشگیرانهٔ v3 جفت می‌شود.

### ۲۳.۱. drift همگام‌سازی دوطرفه

**جنبهٔ پنهان:** وقتی v3 و upstream هر دو روی یک موجودیت (مثلاً وضعیت درخواست) نوشتن می‌کنند، drift اجتناب‌ناپذیر است. کاربر در v3 وضعیت را به «تأیید نهایی» تغییر می‌دهد، هم‌زمان scraper از درگاه ملی وضعیت «در حال بررسی» را می‌خواند و بازنویسی می‌کند. این drift در آزمون ظاهر نمی‌شود چون آزمون‌ها هم‌زمانی واقعی تولید نمی‌کنند؛ فقط در تولید با کاربر هم‌زمان آشکار می‌شود.

**تدبیر پیشگیرانه در v3:**
- **source-of-truth مشخص برای هر فیلد** در `app/Modules/NationalGateway/OWNERSHIP_MATRIX.md`:
  - `applicants.current_status` → مالک: v3 (کاربر/ادمین). scraper فقط در حالت `AutoConfirmed` می‌نویسد.
  - `applicants.license_state` → مالک: scraper (AutoConfirmed) یا ادمین (دستی).
  - `applicants.license_valid_until` → مالک: scraper (از upstream).
  - `national_licenses.matched_applicant_id` → مالک: scraper یا اپراتور.
- **قاعدهٔ حل تعارض:** last-write-wins با `updated_at` timestamp. اگر scraper بخواهد `current_status` را تغییر دهد ولی `updated_at` متقاضی جدیدتر از `last_seen_at` مجوز ملی باشد، scraper تغییر نمی‌دهد و در `sync_audit_log` با `action='conflict_skipped'` لاگ می‌کند.
- **تست:** `tests/ConflictResolutionTest.php` با سناریوهای هم‌زمانی مصنوعی.

### ۲۳.۲. race condition و retry تکراری

**جنبهٔ پنهان:** scraper timeout می‌شود، retry می‌کند، هم‌زمان v3 به‌روزرسانی دریافت می‌کند. بدون idempotency key، retry به رکورد تکراری یا به‌روزرسانی اشتباه منجر می‌شود. حتی با idempotency key، race condition بین دو فراخوانی هم‌زمان scraper نیاز به قفل دارد. بدون بار ترافیکی واقعی قابل تشخیص نیست.

**تدبیر پیشگیرانه در v3:**
- ستون `idempotency_key` (VARCHAR(64), UNIQUE) روی `sync_audit_log` و `national_licenses`.
- ستون `version` (INT, default 1) روی `applicants` و `national_licenses` برای optimistic concurrency.
- قبل از UPDATE، `SELECT ... FOR UPDATE` یا بررسی `version`: اگر `version` تغییر کرده، retry با backoff.
- در `MojavecClient::doWithRetry()`، retry فقط روی statusهای 429/502/503/504، نه روی 200.

### ۲۳.۳. version mismatch و contract drift

**جنبهٔ پنهان:** upstream به‌سرعت تکامل می‌یابد، scraper ممکن است روی نسخهٔ قدیمی باشد. regressions آهسته ظاهر می‌شوند — upstream به‌روزرسانی می‌شود، scraper تا هفته‌ها کار می‌کند، سپس یک endpoint خاص می‌شکند.

**تدبیر پیشگیرانه در v3:**
- نسخهٔ scraper در `app/Modules/NationalGateway/VERSION` (مثلاً `1.0.0`).
- هدر `X-Scraper-Version` در همهٔ درخواست‌های upstream.
- مانیتورینگ `/api/v1/admin/crawl`: اگر نرخ موفقیت scrape زیر ۹۰٪ در یک ساعت، هشدار.
- contract test روی fixture HAR واقعی (`tests/fixtures/har/`).

### ۲۳.۴. lock-in داده‌ای و رکورد یتیم

**جنبهٔ پنهان:** پس از ماه‌ها همگام‌سازی، دادهٔ v3 با `national_license_request_number` پر شده است. اگر upstream قطع شود یا مجوز ملی باطل شود، این رکوردها «یتیم» می‌شوند. این مشکل در زمان قطع فوری نیست؛ در زمان تصمیم به جایگزینی upstream آشکار می‌شود.

**تدبیر پیشگیرانه در v3:**
- `national_license_request_number` nullable + قابل null-shifting.
- سیاست «forget» برای upstream: اگر یک مجوز ملی ۳۰ روز در upstream پیدا نشود، `national_license_request_number` به NULL تبدیل می‌شود (با audit در `sync_audit_log` با `action='forgotten'`)، ولی `license_state='صادر شده'` باقی می‌ماند (دادهٔ تاریخی).
- ستون `deleted_at` روی `national_licenses` برای soft-delete.

### ۲۳.۵. API endpoint به‌عنوان سطح حملهٔ جدید

**جنبهٔ پنهان:** هر endpoint API که برای خارج ساخته می‌شود، یک سطح حملهٔ جدید است. خطر در خود endpoint نیست، در مکانیزم احراز هویت است: یک API key نشت‌یافته به کل سیستم دسترسی می‌دهد.

**تدبیر پیشگیرانه در v3:**
- جدول `api_keys` با `key_hash`, `scopes` (JSON), `rate_limit_per_min`, `expires_at`, `last_used_at`, `created_by` (بخش ۲۸).
- scope-based authorization: هر key فقط به scope‌های خود دسترسی دارد.
- rate limit سراسری روی API: ۶۰ req/min برای هر key.
- audit log دسترسی به PII در `api_access_log`.
- whitelist فیلدهای قابل افشا در هر scope.

### ۲۳.۶. بازیابی فاجعه و circuit breaker

**جنبهٔ پنهان:** upstream قطع می‌شود. بدون circuit breaker، scraper به‌طور بی‌وقفه retry می‌کند، صف پر می‌شود، دیسک پر می‌شود و v3 خودش از کار می‌افتد. یک شکست موضعی به فاجعهٔ سراسری تبدیل می‌شود.

**تدبیر پیشگیرانه در v3:** `CircuitBreaker` با سه حالت (closed/open/half-open) + Degraded Mode (بخش ۲۵).

### ۲۳.۷. drift schema در مخزن جدید

**جنبهٔ پنهان:** وقتی در یک مخزن توسعه می‌دهیم، چه کسی مالک schema است؟ بدون ماتریس مالکیت روشن، تغییرات هم‌زمان در دو ماژول به conflicting FK یا نوع‌های ناسازگار منجر می‌شود.

**تدبیر پیشگیرانه در v3:** ماتریس مالکیت schema در `docs/SCHEMA_OWNERSHIP.md` (بخش ۲۶).

### ۲۳.۸. نبود contract test

**جنبهٔ پنهان:** بدون contract test، هر تغییر در v3 می‌تواند scraper یا API را بشکند. نبود contract test به‌معنای آن است که یکپارچه‌سازی فقط در زمان deploy آزمون می‌شود — یعنی در تولید.

**تدبیر پیشگیرانه در v3:** پوشهٔ `tests/contract/` با fixture‌های request/response (بخش ۲۷).

### ۲۳.۹. مالکیت داده و حریم خصوصی

**جنبهٔ پنهان:** scraper به PII متقاضیان دسترسی پیدا می‌کند. اگر API خارجی فعال شود، انتقال PII ممکن است نیازمند رضایت صریح کاربر یا انطباق با قوانین حفاظت از داده باشد. این مشکل فنی نیست، حقوقی است؛ اما در زمان ممیزی یا شکایت کاربر آشکار می‌شود.

**تدبیر پیشگیرانه در v3:**
- minimum-necessary principle: فقط دادهٔ لازم به API برود (whitelist فیلدها).
- audit log دسترسی به PII در `api_access_log`.
- در تنظیمات متقاضی، گزینهٔ «رضایت به اشتراک داده با سیستم‌های خارجی» (default: false).
- اگر رضایت false، endpoint `/api/v1/applicants/{code}` حتی با API key معتبر، فقط فیلدهای عمومی برمی‌گرداند، نه PII.

### ۲۳.۱۰. debt فنی پس از refactor

**جنبهٔ پنهان:** refactor v3.0.0 رفع‌های زیادی وارد می‌کند، اما توسعهٔ فازبندی debt جدید وارد می‌کند. اگر فازها بدون نظم اجرا شوند، debt انباشته می‌شود و خود به یک refactor دیگر نیاز می‌شود.

**تدبیر پیشگیرانه در v3:**
- در پایان هر فاز، بازبینی debt در `docs/DEBT_REVIEW.md`.
- اگر debt انباشته شده، فاز بعدی باید شامل «پاکسازی debt» باشد.
- در CHANGELOG، هر فاز با لیست «debt introduced» و «debt paid» ذکر می‌شود.

### ۲۳.۱۱. تکامل موازی و merge conflict فرهنگی

**جنبهٔ پنهان:** وقتی v3 و scraper هم‌زمان توسعه می‌یابند، سبک کد، قراردادها و اولویت‌ها واگرا می‌شوند. این drift فرهنگی در کد دیده نمی‌شود، اما در زمان ادغام به conflict‌های طراحی تبدیل می‌شود.

**تدبیر پیشگیرانه در v3:**
- سند قرارداد مشترک در `docs/CODING_CONVENTIONS.md`.
- مالکیت روشن: هر ماژول یک maintainer دارد.
- code review الزامی برای هر تغییر در `app/Shared/` یا `app/Modules/NationalGateway/`.

### ۲۳.۱۲. single point of failure در Database singleton

**جنبهٔ پنهان:** `Connection::getInstance()` یک اتصال PDO واحد است. اگر این اتصال قطع شود، کل سیستم از کار می‌افتد. رشد ترافیک scraper می‌تواند DB را برای کاربران کند کند.

**تدبیر پیشگیرانه در v3 (مورد آینده):**
- pool اتصال (در نسخهٔ بعدی، با `PDO` wrapper که چندین اتصال نگه می‌دارد).
- read replica برای کوئری‌های خواندنی.
- connection retry با backoff در `Connection::boot()`.
- timeout روی queryها (`PDO::ATTR_TIMEOUT`).

### ۲۳.۱۳. سه‌گانگی نسخه و drift schema (یادآوری از v2)

**جنبهٔ پنهان از v2:** سند استراتژی-ریسک نشان داد که v2 در واقع سه‌گانگی نسخه داشت (`README`=0.1.0، `VERSION`=0.6.3، `REFACTOR_PLAN`=v2.0.0) و دو فایل schema موازی داشت (`schema.sql` ۱۱۷۹ خط، `install.sql` ۱۲۷۹ خط، ۱۰۰ خط اختلاف).

**تدبیر پیشگیرانه در v3:**
- یک منبع حقیقت واحد: `VERSION` = `1.0.0` که `README`, `config/config.php` (`APP_VERSION`)، و `REFACTOR_PLAN` همگی به آن ارجاع دهند.
- یک منبع حقیقت واحد برای schema: `database/schema.sql` به‌عنوان مرجع توسعه. `database/install.sql` به‌صورت **تولیدشده خودکار** از همان منبع (با اسکریپت `scripts/generate_install_sql.php`)، نه دستی. این drift را از بین می‌برد.

---

## 24. بهبود پایگاه داده

با افزودن بهبودهای این بخش، تعداد جداول v3 از ۳۲ به **۳۵** می‌رسد (+ ۱ جدول meta `migrations`).

### ۲۴.۱. جدول جدید `integration_log` (در کنار `sync_audit_log`)

`sync_audit_log` تصمیم‌های اتصال طلایی را لاگ می‌کند. `integration_log` عمومی‌تر است: همهٔ تعاملات با سیستم‌های خارجی را لاگ می‌کند.

```sql
CREATE TABLE integration_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(64) NOT NULL,           -- 'applicant', 'national_license', 'lookup'
    entity_id VARCHAR(64) NOT NULL,             -- tracking_code یا request_number
    direction ENUM('inbound','outbound') NOT NULL,
    system VARCHAR(64) NOT NULL,                -- 'national_gateway', 'sms', 'payment', 'external_api'
    status ENUM('success','failed','pending','conflict_skipped','forgotten') NOT NULL,
    gateway_ref VARCHAR(128),                   -- شناسهٔ خارجی
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

### ۲۴.۲. ستون‌های جدید روی `applicants`

```sql
ALTER TABLE applicants
    ADD COLUMN version INT NOT NULL DEFAULT 1 COMMENT 'optimistic concurrency',
    ADD COLUMN deleted_at DATETIME NULL COMMENT 'soft-delete',
    ADD COLUMN consent_external_share TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'رضایت اشتراک با خارج',
    ADD INDEX idx_applicants_deleted (deleted_at);
```

### ۲۴.۳. ستون‌های جدید روی `national_licenses`

```sql
ALTER TABLE national_licenses
    ADD COLUMN version INT NOT NULL DEFAULT 1,
    ADD COLUMN deleted_at DATETIME NULL,
    ADD COLUMN idempotency_key VARCHAR(64),
    ADD UNIQUE KEY uq_nl_idem (idempotency_key);
```

### ۲۴.۴. ستون `idempotency_key` روی `sync_audit_log`

```sql
ALTER TABLE sync_audit_log
    ADD COLUMN idempotency_key VARCHAR(64),
    ADD UNIQUE KEY uq_sal_idem (idempotency_key);
```

### ۲۴.۵. جدول `migrations` (runner واقعی)

```sql
CREATE TABLE migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    module VARCHAR(64) NOT NULL,                  -- 'core', 'national_gateway', 'api', ...
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checksum CHAR(64) NOT NULL                    -- SHA-256(محتوای فایل)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`MigrationRunner` (`app/Shared/Persistence/MigrationRunner.php`) در هر boot:
۱. اسکن `app/Modules/*/migrations/*.sql`.
۲. برای هر فایل، بررسی `migrations` table با `filename`.
۳. اگر ناموجود یا `checksum` متفاوت، اجرا و ثبت.
۴. تراکنش atomic برای هر migration.

### ۲۴.۶. به‌روزرسانی فایل‌های schema

| فایل | نقش در v3 |
|---|---|
| `database/schema.sql` | **مرجع توسعهٔ واحد** — همهٔ ۳۵ جدول با DDL کامل |
| `database/install.sql` | **تولیدشده خودکار** از `schema.sql` با `scripts/generate_install_sql.php` (با `CREATE TABLE IF NOT EXISTS` + idempotent INSERT). دیگر دستی نگه‌داری نمی‌شود. |
| `app/Modules/*/migrations/*.sql` | migration‌های هر ماژول (با `module` در جدول `migrations`) |

### ۲۴.۷. به‌روزرسانی الگوی `ON DELETE`

با افزودن soft-delete:
- **CASCADE:** همچنان برای جداول فرعی وابسته، اما **فقط وقتی `deleted_at` روی والد NULL نیست** (با trigger یا application-level check).
- **SET NULL:** ارجاعات به `persons`, `service_types`, `users`.
- **Soft-delete + retain:** `applicants`, `persons`, `national_licenses`, `users`, `api_keys` — هرگز hard-delete نمی‌شوند، فقط `deleted_at` تنظیم می‌شود.

---

## 25. ACL، Circuit Breaker و Degraded Mode

### ۲۵.۱. Anti-Corruption Layer (ACL)

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

**فایل:** `app/Modules/NationalGateway/Services/ApplicantSyncAdapter.php`.

### ۲۵.۲. `CircuitBreaker` (کلاس کامل)

**فایل:** `app/Modules/NationalGateway/Infrastructure/CircuitBreaker.php`.

سه حالت:
- **Closed:** عادی. درخواست‌ها به upstream می‌روند. در صورت success، failure count reset. در صورت failure، count++.
- **Open:** اگر `failureCount >= 3` در پنجرهٔ ۶۰s، حالت open. درخواست‌ها متوقف، در صف می‌مانند. v3 در حالت degraded ادامه می‌دهد.
- **Half-Open:** پس از cooldown ۵ دقیقه، یک درخواست آزمایشی. اگر موفق، به Closed. اگر شکست، باز به Open با reset cooldown.

`MojavecClient::doWithRetry()` پیش از هر درخواست `CircuitBreaker::allowRequest()` را صدا می‌زند. اگر false، مستثنی `CircuitOpenException` پرتاب می‌کند و job در صف می‌ماند (`status='queued'`، `attempts` افزایش نمی‌یابد).

### ۲۵.۳. Degraded Mode

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

## 26. ماتریس مالکیت Schema، ModuleInterface و Monorepo

### ۲۶.۱. ماتریس مالکیت Schema

این ماتریس در `docs/SCHEMA_OWNERSHIP.md` نگه‌داری می‌شود و قبل از merge هر migration در CI بررسی می‌شود.

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

### ۲۶.۲. `ModuleInterface` (قرارداد رسمی)

**فایل:** `app/Modules/ModuleInterface.php`.

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

هر ماژول در `app/Modules/<Name>/Module.php` این interface را پیاده‌سازی می‌کند. `ModuleLoader` در `app/Modules/ModuleLoader.php` ماژول‌ها را در `bootAll()` بارگذاری می‌کند، مسیرها را در `collectRoutes()` تجمیع می‌کند، migration‌ها را در `collectMigrations()` به runner می‌سپارد، و subscriberها را در `collectSubscribers()` به EventBus متصل می‌کند.

### ۲۶.۳. ساختار منطقی Monorepo در v3

ساختار مخزن v3 به‌صورت منطقی شبیه monorepo است (حتی اگر در یک مخزن واحد باشد):

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
│   │   ├── Services/              # شامل ApplicantSyncAdapter (ACL)
│   │   ├── Infrastructure/        # شامل CircuitBreaker, MojavecClient, TrackScraper
│   │   ├── Domain/
│   │   ├── migrations/
│   │   ├── Module.php
│   │   ├── OWNERSHIP_MATRIX.md
│   │   └── README.md
│   ├── Api/                       # لایهٔ API + api_keys + scope-based auth
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
│   ├── Support/                   # Config, StructuredLogger, PersianDate, Normalizer, ...
│   ├── Domain/                    # Value Objects مشترک
│   └── Persistence/               # Connection, MigrationRunner
├── Container.php
└── Http/                          # Router, Session, Csrf, Request, Response
```

این ساختار، مرزهای واضح بین حوزه‌های کسب‌وکار فراهم می‌کند، migration‌های هر ماژول را ایزوله نگه می‌دارد، و ماتریس مالکیت schema را قابل اجرا می‌کند.

---

## 27. Contract Test، CI/CD و Structured Logging

### ۲۷.۱. Contract Test

**ساختار:**

```
tests/contract/
├── Applicants/
│   ├── get_by_tracking_code_basic.json       # scope: read:applicants:basic
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

`tests/contract/run.php` fixture‌ها را می‌خواند، درخواست را اجرا می‌کند، پاسخ را با expected مقایسه می‌کند، و فیلدهای `forbidden_fields` را در پاسخ جستجو می‌کند (اگر موجود بود، fail). اگر هم‌خوان نبود، fail.

### ۲۷.۲. CI/CD با GitHub Actions

**فایل:** `.github/workflows/ci.yml`.

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

هر push و PR این pipeline را اجرا می‌کند. اگر هر step fail شد، merge مسدود می‌شود.

### ۲۷.۳. `StructuredLogger`

**فایل:** `app/Support/StructuredLogger.php`.

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

## 28. احراز هویت API مبتنی بر Scope

### ۲۸.۱. جدول `api_keys` (scope-based authorization)

**جایگزین:** به‌جای یک API key سراسری (`GATEWAY_API_KEY` در نسخهٔ 1.0)، جدول `api_keys` با scope-based authorization.

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

### ۲۸.۲. Scope‌های تعریف‌شده

| Scope | توضیح | فیلدهای قابل افشا |
|---|---|---|
| `read:applicants:basic` | اطلاعات پایهٔ پرونده | `tracking_code`, `national_code` (ماسک), `license_state`, `license_valid_until`, `service_title` |
| `read:applicants:full` | اطلاعات کامل (نیازمند consent) | همهٔ فیلدهای basic + `first_name`, `last_name`, `mobile` (فقط اگر `consent_external_share=true`) |
| `read:applicants:status` | فقط وضعیت | `tracking_code`, `current_status`, `license_state`, `updated_at` |
| `read:licenses` | مجوزهای ملی از کش | همهٔ فیلدهای `national_licenses` (بدون PII متقاضی محلی) |
| `read:lookups` | استان/شهر/سازمان | همه |
| `read:sync:status` | وضعیت اتصال طلایی | آمار چرخهٔ sync |
| `write:sync:run` | اجرای دستی sync | — |
| `write:sync:decide` | تأیید/رد اپراتور | — |

### ۲۸.۳. Middleware احراز هویت API

**فایل:** `app/Modules/Api/Middleware/AuthenticateApiKey.php`.

```php
public function handle(Request $request, array $requiredScopes): ApiKey
{
    $key = $request->header('X-API-Key');
    if ($key === null) {
        throw new ApiException(401, 'missing_api_key');
    }
    $hash = hash('sha256', $key);
    $apiKey = $this->repo->findByHash($hash);
    if ($apiKey === null || $apiKey->isExpired()) {
        throw new ApiException(401, 'invalid_or_expired_api_key');
    }
    if (!$apiKey->hasScopes($requiredScopes)) {
        throw new ApiException(403, 'insufficient_scope', ['required' => $requiredScopes]);
    }
    // rate limit
    if (!$this->rateLimiter->allow('api:'.$apiKey->id, $apiKey->rateLimitPerMin, 60)) {
        throw new ApiException(429, 'rate_limit_exceeded');
    }
    // audit
    $this->accessLogger->log($apiKey, $request, $requiredScopes);
    // update last_used
    $this->repo->touchLastUsed($apiKey->id, $request->ip());
    return $apiKey;
}
```

### ۲۸.۴. مدیریت API key در پنل admin

- `/admin/api-keys` — لیست، ایجاد، ابطال.
- هنگام ایجاد، key یک‌بار نمایش داده می‌شود (مثل رمز admin در install). ذخیرهٔ `key_hash` فقط.
- هر key می‌تواند scope‌های متعدد داشته باشد.
- ابطال فوری با `expires_at = NOW()`.

---

## 29. Rollout یک‌طرفه اول، دوطرفه بعد

### ۲۹.۱. استراتژی rollout

به‌جای فعال‌سازی کامل اتصال طلایی از روز اول، rollout به دو مرحله تقسیم می‌شود:

**مرحلهٔ ۱ (v3.0.0): همگام‌سازی یک‌طرفه ورودی (upstream → v3، فقط خواندنی از upstream)**
- scraper از درگاه ملی می‌خواند.
- `PatternMatcher` مجوز ملی را به متقاضی محلی وصل می‌کند.
- فقط در حالت `AutoConfirmed` (confidence ≥ ۹۰) به‌صورت خودکار وضعیت تغییر می‌کند.
- در حالت `OperatorFlag`، اپراتور manually تأیید می‌کند.
- **هیچ نوشتهای به upstream ارسال نمی‌شود.** این ریسک drift دوطرفه را حذف می‌کند.

**مرحلهٔ ۲ (v3.1.0، پس از تثبیت مرحلهٔ ۱): همگام‌سازی دوطرفه با مکانیزم‌های حل تعارض**
- اگر upstream در آینده endpoint نوشتنی اضافه کرد (مثلاً webhook از ملی به v3)، فعال می‌شود.
- مکانیزم حل تعارض: last-write-wins با `updated_at` timestamp + source-of-truth برای هر فیلد.
- audit کامل در `integration_log` با `direction='inbound'` و `'outbound'`.

> **✅ فاز ۸ (v1.1.0، 2026-11-17):** زیرساخت مرحلهٔ ۲ پیاده‌سازی شد:
> - جدول `integration_log` با ۱۶ ستون + ۶ ایندکس + FK.
> - `OutboundSyncService` — pushStatusChange + pushDocument با idempotency + conflict detection (last-write-wins) + kill-switch خودکار (۳ 5xx متوالی → revert به Stage 1).
> - `Stage2ReadinessChecker` — ۴ شرط §29.2 (آمادگی را گزارش می‌دهد، هرگز auto-enable نمی‌کند).
> - Contract tests در `tests/Contract/` (۲۱ assertion).
> - `NationalSyncOrchestrator` Stage 2 path: وقتی `sync_two_way_enabled=1` + `sync_direction='two_way'`، بعد از inbound matching، status change‌های متقاضیان match‌شده به upstream push می‌شود.
>
> **نکته:** `OutboundSyncService::pushToUpstream()` فعلاً stub است چون upstream هنوز endpoint نوشتنی ندارد (§19.8). وقتی اضافه کرد، فقط همان یک متد باید ویرایش شود.

### ۲۹.۲. آستانهٔ گذار از مرحلهٔ ۱ به ۲

پیش از فعال‌سازی مرحلهٔ ۲، چهار شرط باید برقرار باشد:
۱. مرحلهٔ ۱ حداقل ۳ ماه در تولید بدون فاجعه اجرا شده باشد.
۲. نرخ false positive در `AutoConfirmed` زیر ۰.۱٪ باشد (از `sync_audit_log` با `action='operator_rejected'` پس از `auto_confirmed`).
۳. نرخ circuit breaker open در ۳۰ روز اخیر زیر ۵٪ باشد.
۴. contract test برای endpoint‌های دوطرفه کامل باشد.

### ۲۹.۳. fallback به مرحلهٔ ۱

در صورت بروز فاجعه در مرحلهٔ ۲، با یک flag در `settings` (`sync_two_way_enabled = false`) به مرحلهٔ ۱ بازگردانی می‌شود — بدون نیاز به deploy جدید.

### ۲۹.۴. به‌روزرسانی نقشهٔ راه

نقشهٔ راه ۷ فازی نسخهٔ 1.0 (بخش ۱۹) به‌روزرسانی می‌شود:

| فاز | محتوای فاز در نسخهٔ 1.0 |
|---|---|
| فاز ۱ | هسته و معماری: `ModuleInterface` + `ModuleLoader` + `MigrationRunner` + `StructuredLogger` + منبع حقیقت واحد نسخه و schema |
| فاز ۲ | دامنهٔ v2: `version`, `deleted_at`, `consent_external_share` ستون‌ها + Repository interfaces |
| فاز ۳ | ماژول درگاه ملی: `CircuitBreaker` + `ApplicantSyncAdapter` (ACL) |
| فاز ۴ | زیرساخت async و اتصال طلایی: `integration_log` + `idempotency_key` + optimistic concurrency |
| فاز ۵ | مهاجرت داده از v2 و gateway به v3 |
| فاز ۶ | امنیت، تست، استقرار: Contract Test + CI/CD با GitHub Actions + scope-based API key |
| فاز ۷ | نهایی‌سازی: Rollout مرحلهٔ ۱ (یک‌طرفه ورودی) |
| **فاز ۸** | Rollout مرحلهٔ ۲ (دوطرفه) پس از تثبیت ۳ ماههٔ مرحلهٔ ۱ |

---

## 30. راهنمای نهایی فایل‌ها برای توسعه‌دهندگان و ایجنت‌های هوش مصنوعی

### ۳۰.۱. چهار فایل ورودی و نقش هر یک

با احتساب چهار فایل موجود، جدول زیر تعیین می‌کند که در نسخهٔ نهایی mojavez-v3 کدام فایل‌ها در اختیار توسعه‌دهندگان و ایجنت‌های هوش مصنوعی قرار گیرد:

| # | فایل | نقش | در اختیار | اولویت | مرحله |
|---|---|---|---|---|---|
| ۱ | **`00-MASTER.md`** (این سند) | **سند اصلی معماری و طراحی v3** — مرجع اول برای ساخت mojavez-v3 از صفر، شامل همهٔ بهبودها | توسعه‌دهندگان اصلی + ایجنت‌های هوش مصنوعی | **الزامی** | از روز اول |
| ۲ | **`01-v2-reference.md`** (سند اول) | **مرجع دادهٔ v2** — ۲۸ جدول، گردش‌کار ۱۸ وضعیتی، امنیت، استقرار، نرخ‌نامه، ویزارد ثبت‌نام | توسعه‌دهندگان ماژول Core + ایجنت‌ها | **الزامی** (پیوست مرجع) | هنگام پیاده‌سازی ماژول Core (فاز ۲) |
| ۳ | **`02-gateway-reference.md`** (سند دوم) | **مرجع scraper** — `MojavecClient`, `TrackScraper`, RSC parser، الگوریتم تطبیق الگویی، endpointهای upstream، مشکلات امنیتی gateway | توسعه‌دهندگان ماژول NationalGateway + ایجنت‌ها | **الزامی** (پیوست مرجع) | هنگام پیاده‌سازی ماژول NationalGateway (فاز ۳) |
| ۴ | **`03-strategy-and-risk.md`** (سند چهارم) | **مرجع استراتژی و ریسک** — جوانب پنهان، ACL، circuit breaker، idempotency، ماتریس مالکیت، contract test، ModuleInterface، rollout تدریجی | توسعه‌دهندگان اصلی + ایجنت‌ها | **الزامی** (پیوست استراتژی) | هنگام طراحی معماری و نقشهٔ راه (فاز ۱) |
| ۵ | **`04-comparison-and-improvements.md`** (سند مقایسه) | **سند تحلیلی** — مقایسهٔ دو دیدگاه، استدلال چرایی بهبودها | توسعه‌دهندگان اصلی + ایجنت‌ها | توصیه‌شده | هنگام درک چرایی تصمیمات |

### ۳۰.۲. توصیهٔ نهایی: تک سند اصلی + پیوست‌های مرجع

**توصیهٔ صریح:** در نسخهٔ نهایی mojavez-v3، **یک سند اصلی واحد** (همین سند) در اختیار توسعه‌دهندگان و ایجنت‌ها قرار گیرد که تمام بهبودها را در خود دارد. سه سند دیگر به‌عنوان **پیوست‌های مرجع تاریخی/فنی** نگه‌داری شوند و در سند اصلی به آن‌ها ارجاع داده شود.

**ساختار پیشنهادی مستندات نهایی v3 در مخزن:**

```
docs/
├── 00-MASTER.md                          # این سند (v3 نسخهٔ 1.0) — در اختیار همه از روز اول
├── 01-v2-reference.md                    # مرجع دادهٔ v2 — پیوست مرجع برای ماژول Core
├── 02-gateway-reference.md               # مرجع scraper — پیواست مرجع برای ماژول NationalGateway
├── 03-strategy-and-risk.md               # مرجع استراتژی و ریسک — پیوست مرجع
├── 04-comparison-and-improvements.md     # سند تحلیلی مقایسه
├── SCHEMA_OWNERSHIP.md                   # ماتریس مالکیت schema (از بخش ۲۶)
├── CODING_CONVENTIONS.md                 # قراردادهای کدنویسی
├── integration-contract.md               # قرارداد یکپارچه‌سازی نسخه‌دار (upstream + API داخلی)
└── DEBT_REVIEW.md                        # بازبینی debt در پایان هر فاز
```

### ۳۰.۳. دستورالعمل استفاده برای ایجنت‌های هوش مصنوعی

هنگام واگذاری پیاده‌سازی mojavez-v3 به ایجنت‌های هوش مصنوعی، دستورالعمل زیر به آن‌ها داده شود:

۱. **ابتدا `00-MASTER.md` (این سند) را به‌طور کامل بخوان.** این سند اصلی است و همهٔ تصمیمات طراحی را در خود دارد.
۲. **هنگام پیاده‌سازی ماژول `Core`** (فاز ۲ نقشهٔ راه): `01-v2-reference.md` را به‌عنوان مرجع داده بخوان.
۳. **هنگام پیاده‌سازی ماژول `NationalGateway`** (فاز ۳ نقشهٔ راه): `02-gateway-reference.md` را به‌عنوان مرجع scraper بخوان.
۴. **هنگام طراحی معماری و تصمیمات راهبردی** (فاز ۱): `03-strategy-and-risk.md` را برای آگاهی از جوانب پنهان بخوان.
۵. **در صورت سؤال دربارهٔ چرایی یک تصمیم**: `04-comparison-and-improvements.md` را برای تحلیل بخوان.
۶. **هنگام نوشتن migration**: ماتریس مالکیت در `SCHEMA_OWNERSHIP.md` را رعایت کن.
۷. **هنگام نوشتن endpoint API**: contract را در `integration-contract.md` رعایت کن و contract test در `tests/contract/` اضافه کن.
۸. **هنگام پایان هر فاز**: `DEBT_REVIEW.md` را به‌روزرسانی کن.

### ۳۰.۴. آنچه هر سند «نمی‌تواند» جایگزین کند

| سند | نمی‌تواند جایگزین |
|---|---|
| `00-MASTER.md` (این سند) | هیچ‌کدام — این سند اصلی است |
| `01-v2-reference.md` | سند اصلی — فقط مرجع دادهٔ v2 است |
| `02-gateway-reference.md` | سند اصلی — فقط مرجع scraper است |
| `03-strategy-and-risk.md` | سند اصلی — فقط استراتژی و ریسک است (به‌دلیل عدم دسترسی به gateway در زمان نگارش) |
| `04-comparison-and-improvements.md` | سند اصلی — فقط تحلیل مقایسه است |

### ۳۰.۵. توصیه‌های نهایی

۱. **سند اصلی v3 (این سند، نسخهٔ 1.0) را به‌عنوان `00-MASTER.md` در `docs/` قرار دهید.** این تنها سندی است که توسعه‌دهندگان و ایجنت‌ها از روز اول باید به‌طور کامل بخوانند.

۲. **سه سند دیگر را به‌عنوان پیوست مرجع در `docs/` نگه‌داری کنید.** هرگز حذف نشوند — آن‌ها دانش تاریخی و فنی دارند که در سند اصلی خلاصه شده ولی گاهی نیاز به مرجع کامل است.

۳. **برای ایجنت‌های هوش مصنوعی، دستورالعمل بخش ۳۰.۳ را به‌صورت صریح در prompt واگذاری قرار دهید.** این تضمین می‌کند که ایجنت‌ها به‌ترتیب درست و با اولویت درست اسناد را می‌خوانند.

۴. **قرارداد یکپارچه‌سازی نسخه‌دار (`integration-contract.md`) را قبل از شروع فاز ۳ بسازید.** این قرارداد مرجع رسمی بین scraper و upstream است.

۵. **rollout را تدریجی نگه‌دارید** (بخش ۲۹): مرحلهٔ ۱ (یک‌طرفه ورودی) حداقل ۳ ماه در تولید بدون فاجعه اجرا شود، سپس مرحلهٔ ۲ (دوطرفه) فعال شود.

۶. **ماتریس مالکیت schema را از روز اول اعمال کنید** (بخش ۲۶): حتی در یک پروژهٔ واحد با یک توسعه‌دهنده، این جلوگیری از drift schema در آینده است.

---

**پایان مستند.**

> این مستند بر اساس تحلیل کامل چهار سند ورودی تدوین شده:
> - `Mojavez-v2-Integration-Document.md` (نسخهٔ v2: 0.6.3، ۱۲۳ کامیت) — مرجع دادهٔ v2.
> - `mojavez-gateway.md` (نسخهٔ gateway: 0.1.0-unreleased، ۴۹ کامیت، ۲۱ مکالمه) — مرجع scraper.
> - `سند_توسعه_mojavez_v2.md` (سند استراتژی-ریسک، نسخهٔ 1.0، ۱۶ نوامبر ۲۰۲۶) — مرجع استراتژی و ریسک.
> - `Mojavez-v3-Comparison-And-Improvements.md` (سند مقایسه، نسخهٔ 1.0) — تحلیل مقایسه و استخراج بهبودها.
>
> این سند طراحی بتنی v3 را بر اساس دانش کامل v2 و gateway ارائه می‌دهد و با افزودن بخش‌های ۲۳ تا ۳۰، تدابیر پیشگیرانهٔ مستخرج از سند استراتژی-ریسک (جوانب پنهان، ACL، Circuit Breaker، idempotency، ماتریس مالکیت، ModuleInterface، Contract Test، CI/CD، Structured Logging، Scope-based API، Rollout تدریجی) را ادغام می‌کند. این دو دیدگاه مکمل هستند، نه متعارض: سند v3 با دانش کامل، طراحی بتنی ارائه داد؛ سند استراتژی-ریسک با تمرکز بر جوانب پنهان، تدابیر پیشگیرانه را با عمق تحلیلی ارائه داد. این سند این-gap را پر کرد.
>
> در صورت تغییر نسخهٔ پایهٔ هر یک از پروژه‌های پیشین، یا در صورت دریافت سند پنجم، این مستند باید به‌روزرسانی شود. تاریخچهٔ کامل تغییرات در `CHANGELOG.md` نگه‌داری می‌شود.
