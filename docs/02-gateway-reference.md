# مستند جامع فنی پروژه Mojavez Gateway (درگاه اختصاصی مجوزها)

> **نسخه پروژه:** 0.1.0-unreleased
> **تاریخ تدوین:** ۱۴۰۵/۰۸/۲۶ (۲۰۲۶-۱۱-۱۶)
> **مخزن:** https://github.com/andrbi108/mojavez-gateway — دسترسی با توکن ارائه‌شده توسط تیم توسعه
> **سایت هدف (upstream):** https://qr.mojavez.ir
> **هدف مستند:** شناخت کامل پروژه جهت ادغام در یک پروژه همسو (از ثبت درخواست تا صدور، تمامی مراحل)

---

## فهرست مطالب

1. [معرفی و خلاصه اجرایی](#۱-معرفی-و-خلاصه-اجرایی)
2. [وضعیت پروژه و آمار](#۲-وضعیت-پروژه-و-آمار)
3. [معماری](#۳-معماری)
4. [ساختار پروژه (درخت فایل‌ها)](#۴-ساختار-پروژه-درخت-فایلها)
5. [پیش‌نیازها و وابستگی‌ها](#۵-پیش‌نیازها-و-وابستگی‌ها)
6. [پیکربندی (Config و Environment)](#۶-پیکربندی-config-و-environment)
7. [پایگاه داده و Schema](#۷-پایگاه-داده-و-schema)
8. [مدل دامنه (Domain Model)](#۸-مدل-دامنه-domain-model)
9. [API داخلی REST (رابط فرانت‌اند با بک‌اند)](#۹-api-داخلی-rest-رابط-فرانت‌اند-با-بک‌اند)
10. [API درگاه ملی (upstream qr.mojavez.ir)](#۱۰-api-درگاه-ملی-upstream-qrmojavezir)
11. [زیرساخت کلاینت HTTP و لایه ضد شناسایی](#۱۱-زیرساخت-کلاینت-http-و-لایه-ضد-شناسایی)
12. [TrackScraper (استخراج صفحه‌ی track)](#۱۲-trackscraper-استخراج-صفحهی-track)
13. [صف وظایف، رویدادها و SSE](#۱۳-صف-وظایف-رویدادها-و-sse)
14. [سرویس‌ها (Domain Logic)](#۱۴-سرویس‌ها-domain-logic)
15. [جریان داده کامل یک جستجو (صفر تا صد)](#۱۵-جریان-داده-کامل-یک-جستجو-صفر-تا-صد)
16. [نصب و استقرار (Dev / Staging / Production)](#۱۶-نصب-و-استقرار-dev--staging--production)
17. [امنیت و ملاحظات حقوقی](#۱۷-امنیت-و-ملاحظات-حقوقی)
18. [سابقه خطاها و رفع‌ها (۲۱ مکالمه، ۱۶ خطای ثبت‌شده)](#۱۸-سابقه-خطاها-و-رفعها-۲۱-مکالمه-۱۶-خطای-ثبتشده)
19. [مشکلات امنیتی شناخته‌شده (مهم هنگام انتقال)](#۱۹-مشکلات-امنیتی-شناختهشده-مهم-هنگام-انتقال)
20. [نقاط اتصال برای ادغام در پروژه دیگر](#۲۰-نقاط-اتصال-برای-ادغام-در-پروژه-دیگر)
21. [نقشه راه ادغام (۶ گام)](#۲۱-نقشه-راه-ادغام-۶-گام)
22. [ریسک‌ها و راه‌حل‌ها](#۲۲-ریسکها-و-راهحلها)
23. [چک‌لیست انتقال/ادغام](#۲۳-چکلیست-انتقالادغام)
24. [مرجع فایل‌های کلیدی](#۲۴-مرجع-فایلهای-کلیدی)
25. [دستورات مفید](#۲۵-دستورات-مفید)
26. [واژه‌نامه](#۲۶-واژهنامه)

---

## ۱. معرفی و خلاصه اجرایی

**Mojavez Gateway** یک لایه‌ی واسط اختصاصی، فیلترشده و فقط‌خواندنی روی **درگاه ملی مجوزهای کشور (qr.mojavez.ir)** است. این پروژه با زبان **PHP 8.2** و **بدون استفاده از Composer یا هرگونه وابستگی خارجی** توسعه یافته و هدف آن فراهم کردن یک درگاه قابل تنظیم برای جستجو، فیلتر و کش مجوزهای صادرشده در سامانه ملی است تا کاربر بتواند بر اساس استان، شهرستان، دستگاه صادرکننده، بازه زمانی شمسی و سایر محورها، نتایج را به صورت **کارت محصول‌مانند** یا **جدول لیستی** مشاهده کند.

### جایگاه پروژه در زنجیره‌ی مجوز

این پروژه **خواننده (reader) درگاه ملی** است، نه یک سامانه‌ی کامل صدور مجوز. به‌عبارت دیگر:

- **ثبت درخواست، گردش‌کار، پرداخت، صدور و تحویل تصویر مجوز** را **انجام نمی‌دهد**.
- تنها **مجوزهای صادرشده** را از درگاه ملی می‌خواند، فیلتر می‌کند، در پایگاه داده‌ی محلی کش می‌کند و نمایش می‌دهد.
- صفحه‌ی جزئیات مجوز (با QR، تصویر متقاضی و خروجی PDF) را ارائه می‌دهد.

به همین دلیل، ادغام این پروژه با یک سامانه‌ی کامل (که ثبت‌نام، گردش‌کار و صدور را مدیریت می‌کند) ارزش بالایی دارد: سامانه‌ی کامل می‌تواند هر از گاهی درگاه ملی را نگاه کند، مجوز صدورشده‌ی یک متقاضی را پیدا کند و خودکار وضعیت درخواست آن شخص را به «صدور شده» تغییر دهد. این همان **«اتصال طلایی»** است که هیچ‌یک از دو پروژه به‌تنهایی نمی‌توانستند ببندند (مبحث تفصیلی در بخش ۲۰ و ۲۱).

### فلسفه‌ی طراحی

- **بدون Composer و بدون فریم‌ورک** — همه‌چیز با PHP نیتیو و یک DI Container دستی پیاده‌سازی شده است.
- **طراحی برای هاست اشتراکی (cPanel)** — SQLite به جای MySQL، SSE به جای WebSocket، Poor Man's Cron به جای daemon.
- **لایه ضد شناسایی احترام‌آمیز** — User-Agent ثابت شبیه Chrome 120، Rate Limiting با Jitter، Retry با Backoff. هدف احترام به منابع سایت هدف و جلوگیری از مسدود شدن IP است، نه دور زدن امنیت.

---

## ۲. وضعیت پروژه و آمار

| شاخص | مقدار |
|------|-------|
| نسخه | 0.1.0-unreleased |
| زبان | PHP 8.2+ |
| مدیریت بسته | بدون Composer (پرهیز سخت‌گیرانه) |
| پایگاه داده | SQLite (فایل `storage/app.db`) |
| تعداد کامیت‌ها | ۴۹ |
| تعداد فایل‌های PHP | ۷۰ (~۹٬۳۵۸ خط) |
| تعداد فایل‌های JS | ۱۳ (vanilla، بدون فریم‌ورک) |
| تعداد فایل‌های CSS | ۹ |
| تعداد مستندات Markdown | ۲۴ |
| تعداد مکالمات ثبت‌شده | ۲۱ |
| تعداد خطاهای ثبت‌شده | ۱۶ خطای کدگذاری‌شده با شناسه‌ی یکتا |
| مجوز | MIT (Copyright ۲۰۲۶) |
| وضعیت انتشار | منتشرنشده (خصوصی) |

### جداول پایگاه داده (۹ جدول، ۲ migration)

`licenses`، `filters`، `jobs`، `batches`، `lookups`، `settings`، `admin_users`، `events`، `worker_state`

### مسیرها (routes)

- حدود **۴۰ مسیر** تعریف‌شده در `config/routes.php`
- **۷ کنترلر**: Home, License, Search, Batch, Admin, Sse, Proxy

### محدودیت کلیدی

> سایت هدف `qr.mojavez.ir` **فقط از داخل ایران قابل دسترسی است** (geofence). هرگونه تست زنده‌ی crawl باید در محیط داخل ایران انجام شود. در محیط خارج از ایران، `MojavecClient` با خطای `timeout` مواجه می‌شود. برای توسعه‌ی خارج از ایران باید از **داده‌ی seed‌شده / فایل HAR** یا **mock HTTP** استفاده کرد.

---

## ۳. معماری

معماری پروژه **ترکیبی از سه رویکرد** است که هرکدام برای دسته‌ی مشخصی از وظایف به‌کار رفته‌اند:

| الگو | حوزه کاربرد | دلیل انتخاب |
|------|-------------|-------------|
| **MVC** | رابط کاربری وب و کنترلرها | جداسازی presentation از logic؛ ساده و قابل فهم برای هاست اشتراکی |
| **Event-Driven** | زیرساخت crawl، JobQueue، به‌روزرسانی بلادرنگ | ناهمزمانی بدون نیاز به Redis؛ SSE واقعی |
| **REST API** | رابط داخلی فرانت‌اند/بک‌اند و خروجی JSON/CSV | رابط تمیز برای AJAX و خروجی برنامه‌نویسی‌پذیر |

### نمودار لایه‌ها (جریان وابستگی فقط از بالا به پایین)

```
        ┌──────────────────────────────────────────────┐
        │            لایه Presentation                  │
        │   Card View   |   Table View   |   Admin      │
        └───────────────────┬──────────────────────────┘
                            │  (EventSource / SSE)
        ┌───────────────────▼──────────────────────────┐
        │            لایه Controllers (MVC)              │
        │  Home | License | Search | Batch | Admin | Sse│
        │  (+ Proxy)                                   │
        └───────────────────┬──────────────────────────┘
                            │
        ┌───────────────────▼──────────────────────────┐
        │            لایه REST API (داخلی)               │
        │  /api/licenses | /api/search | /api/batch      │
        │  /api/filters  | /api/lookups| /api/proxy-image│
        │  /api/admin/*  | /api/stream (SSE — غیرفعال)  │
        └───────────────────┬──────────────────────────┘
                            │
        ┌───────────────────▼──────────────────────────┐
        │            لایه Services (Domain Logic)        │
        │ License | Filter | CsvBatch | Cache | Export   │
        │              | RateLimiter |                   │
        └───────────────────┬──────────────────────────┘
                            │
        ┌───────────────────▼──────────────────────────┐
        │       لایه Event-Driven Core (Async)           │
        │   EventBus  ->  JobQueue  ->  Worker           │
        │              (SSE push to browser)             │
        └───────────────────┬──────────────────────────┘
                            │
        ┌───────────────────▼──────────────────────────┐
        │     لایه Persistence + Infrastructure          │
        │  Repositories (SQLite/PDO)                    │
        │  MojavecClient (cURL) + AntiDetectionMiddleware│
        │  TrackScraper (RSC payload parser)            │
        │  + Parser/ (Strategy)                         │
        └───────────────────┬──────────────────────────┘
                            │
                  qr.mojavez.ir  (فقط از داخل ایران)
```

### قانون کلیدی لایه‌بندی

جریان وابستگی **فقط از بالا به پایین** است. هیچ لایه‌ای نباید به لایه‌ی بالایی خود وابسته باشد. به‌طور خاص، **لایه‌ی Domain هرگز نباید به Infrastructure وابسته باشد**؛ اگر به داده نیاز دارد، از یک interface در دامنه‌ی خود استفاده می‌کند که در DI Container به پیاده‌سازی SQLite متصل می‌شود. این ویژگی امکان تعویض SQLite به MySQL در آینده را با کمترین تغییر فراهم می‌کند.

### الگوهای GoF به‌کار رفته

| الگو | پیاده‌سازی | کاربرد |
|------|------------|--------|
| **Front Controller** | `public/index.php` | نقطه‌ی ورود واحد؛ autoloader + Container + Router |
| **Strategy** | `Parser/ResponseParserInterface` + `JsonSearchParser` | تعویض parser در صورت تغییر ساختار پاسخ upstream |
| **Observer** | `EventBus` + `Subscribers/LogSubscriber` | جداسازی تولیدکننده و مصرف‌کننده‌ی رویداد |
| **Repository** | `LicenseRepository`, `FilterRepository`, `JobRepository`, `LookupRepository`, `BatchRepository` | جدا کردن Domain از جزئیات ذخیره‌سازی |
| **Adapter** | `MojavecClient` روی `HttpClient` (cURL) | رابط ساده برای جستجو/lookup |
| **Command** | `JobInterface` + `Jobs/SearchJob`, `Jobs/BatchJob` | صف‌بندی، retry، اولویت‌بندی |
| **Factory** | `License::fromUpstream()`, `License::fromTrackPage()`, `License::fromArray()` | متمرکز کردن نگاشت داده‌ی خام به Domain Object |
| **Singleton (محدود)** | `Container`, `Config` | تضمین یک نمونه‌ی واحد در چرخه‌ی حیات درخواست |

### DI Container دستی (بدون Composer)

از آنجا که پروژه به‌طور صریح از Composer پرهیز می‌کند، یک DI Container دستی در `app/Container.php` پیاده‌سازی شده است که:

1. **PSR-4 autoloading سفارشی** با `spl_autoload_register`: namespace `App\` به پوشه‌ی `app/` نگاشت می‌شود (مثلاً `App\Services\LicenseService` → `app/Services/LicenseService.php`).
2. **Lazy initialization**: سرویس فقط زمانی ساخته می‌شود که اولین بار درخواست شود، سپس در یک آرایه‌ی داخلی کش می‌شود.
3. **Autowire با ReflectionClass**: بررسی constructor و تزریق وابستگی‌ها به‌صورت بازگشتی. برای interfaceها یک نگاشت interface→implementation در پیکربندی وجود دارد.
4. **الگوی setter injection برای وابستگی‌های چرخه‌ای**: سرویس‌هایی که به repository وابسته‌اند اما به دلیل ترتیب ساخت نمی‌توانند از constructor دریافت کنند، یک setter method `public function setXRepository(X $repo): void` دارند که در factory بلافاصله پس از ساخت فراخوانی می‌شود (نتیجه‌ی رفع خطای 003-01).

> **نکته‌ی مهم برای ادغام:** Container، Environment و Logger را به‌عنوان singleton ثبت می‌کند تا autowire کار کند (نتیجه‌ی رفع خطای 004-03). همچنین `Connection` به‌عنوان **memoized singleton** ثبت شده است، نه factory — تا فقط یک اتصال PDO در طول عمر Container ایجاد شود (نتیجه‌ی رفع خطای 004-05).

---

## ۴. ساختار پروژه (درخت فایل‌ها)

```
mojavez-gateway/
├── public/                      # document root
│   ├── index.php                # front controller + autoloader + install-check
│   ├── install.php              # نصاب one-time (پس از نصب حذف شود)
│   ├── .htaccess                # Apache rewrite + security headers
│   └── assets/
│       ├── css/                 # app, cards, tables, admin, dashboard, analytics,
│       │                        #   license-detail, static, tokens (۹ فایل)
│       ├── js/                  # app, cards, tables, realtime, batch, admin,
│       │                        #   dashboard, analytics, license-detail, qr-generator,
│       │                        #   + html2canvas-vendor, jspdf-vendor, qrcode-vendor
│       ├── fonts/               # vazirmatn.woff2 (self-hosted) + OFL.txt
│       ├── img/                 # favicon.svg
│       └── templates/           # batch-sample.csv (UTF-8 BOM، نمونه)
├── app/
│   ├── Container.php            # DI container دستی (autowire + singleton + factory)
│   ├── Controllers/             # Home, License, Search, Batch, Admin, Sse, Proxy
│   ├── Services/                # License, Filter, CsvBatch, Cache, Export, RateLimiter
│   ├── Domain/                  # License, LicenseCollection, Filter + Enums/ (LicenseStatus, JobStatus)
│   ├── Infrastructure/          # MojavecClient, HttpClient, HttpResponse,
│   │                            #   AntiDetectionMiddleware, TrackScraper + Parser/
│   ├── Events/                  # EventBus, EventInterface, SubscriberInterface
│   │                            #   + Events/ (۷ event) + Subscribers/LogSubscriber
│   ├── Queue/                   # JobInterface, JobQueue, Worker + Jobs/ (Search, Batch)
│   ├── Persistence/             # Connection (SQLite+WAL), MigrationRunner,
│   │                            #   License/Filter/Job/Lookup/BatchRepository
│   │                            #   + Migrations/ (001_init.sql, 002_add_saved_licenses.sql)
│   ├── Api/                     # ApiController, Responses/JsonResponse
│   ├── Http/                    # Request, Response, Router, Session, Csrf + Exceptions/
│   └── Support/                 # Config, Environment, Logger, Validator, Hash,
│                                #   PersianDate, CsvReader
├── config/                      # app, database, filters, anti-detection, routes (۵ فایل)
├── storage/                     # logs, cache, exports, uploads (هرکدام با .gitkeep)
├── tests/                       # fixtures/har/ (فایل‌های HAR واقعی برای تست scraper)
├── scripts/                     # migrate.php, run_worker.php, seed_lookups.php,
│                                #   test_track_scraper.py (تست رگرسیون پایتونی scraper)
├── docs/                        # ۲۴ فایل Markdown (معماری، API، استقرار، ...)
├── .editorconfig, .gitattributes, .gitignore, .env.example
├── CHANGELOG.md, LICENSE, README.md
```

---

## ۵. پیش‌نیازها و وابستگی‌ها

### پیش‌نیازهای زمان اجرا

| پیش‌نیاز | حداقل نسخه | کاربرد | ضروری؟ |
|----------|------------|--------|--------|
| **PHP** | 8.2.0 | زبان اصلی؛ بهره‌گیری از readonly classes، enum، DNF types | بله |
| **ext-curl** | همراه PHP | ارتباط HTTP با سایت هدف (cURL نیتیو) | بله |
| **ext-pdo_sqlite** | همراه PHP | پایگاه داده‌ی SQLite برای کش و صف | بله |
| **ext-mbstring** | همراه PHP | پردازش رشته‌های فارسی و UTF-8 | بله |
| **ext-json** | همراه PHP | رمزگشایی پاسخ‌های JSON سایت هدف | بله |
| **وب‌سرور** | اختیاری در لوکال | Apache/nginx یا `php -S` built-in | برای production |

> **بررسی خودکار پیش‌نیازها:** اسکریپت `public/install.php` هنگام نصب، نسخه‌ی PHP و extensionهای ضروری را بررسی می‌کند.

### وابستگی‌های خارجی

> **هیچ وابستگی خارجی وجود ندارد.** پروژه به‌طور صریح و سخت‌گیرانه از Composer پرهیز می‌کند. اگر در آینده قابلیت خارجی لازم شد، معادل نیتیو PHP نوشته می‌شود. این یک قاعده‌ی پروژه است (خطای 002-01 و TROUBLESHOOTING تأکید کرده‌اند).

### کتابخانه‌های سمت کلاینت (vendor شده، نه Composer)

این فایل‌ها به‌صورت دستی دانلود و در `public/assets/js/` قرار گرفته‌اند (نه از طریق Composer):

| فایل | نسخه | کاربرد |
|------|------|--------|
| `qrcode-vendor.js` | — | تولید QR در صفحه‌ی جزئیات |
| `html2canvas-vendor.js` | 1.4.1 (~۱۹۹KB) | تبدیل DOM به canvas برای PDF |
| `jspdf-vendor.js` | 2.5.2 (~۳۶۶KB) | تولید PDF سمت کلاینت |

> تولید PDF سمت سرور (mPDF/Dompdf) ممکن نیست چون Composer ممنوع است. PDF تولیدی **تصویری (raster)** است نه متن-انتخاب‌پذیر، اما دقیقاً چیدمان چاپ صفحه را ضبط می‌کند.

### فونت

`vazirmatn.woff2` (نسخه‌ی متغیر v33.003، وزن‌های ۱۰۰ تا ۹۰۰ در یک فایل ~۱۰۹KB) به‌صورت **self-host** در `public/assets/fonts/` قرار دارد. دلیل self-host به‌جای CDN/Google Fonts: کاربران هدف در ایران هستند و Google Fonts در ایران مسدود/ناپایدار است (مکالمه #008).

---

## ۶. پیکربندی (Config و Environment)

پروژه از دو مکانیزم پیکربندی استفاده می‌کند: فایل‌های PHP در `config/` و فایل `.env` در ریشه.

### ۶.۱ متغیرهای محیطی (`.env.example`)

```ini
APP_ENV=local            # local | staging | production
APP_DEBUG=true           # true | false
APP_URL=http://localhost:8000
DB_PATH=/home/z/.../mojavez-gateway/storage/app.db
UPSTREAM_BASE=https://qr.mojavez.ir
ADMIN_USER=admin
ADMIN_PASS_HASH=         # خالی = پیش‌فرض 'admin' (در production الزامی تغییر)
CSRF_KEY=
RATE_LIMIT_PER_MINUTE=30
```

> **هشدار امنیتی:** اگر `ADMIN_PASS_HASH` خالی باشد، رمز پیش‌فرض `admin` است. در production **حتماً** یک hash تولید کنید:
> ```bash
> php -r "echo password_hash('your_password', PASSWORD_DEFAULT);"
> ```

### ۶.۲ `config/app.php`

```php
return [
    'name' => 'Mojavez Gateway',
    'version' => '0.1.0',
    'env' => getenv('APP_ENV') ?: 'production',
    'debug' => getenv('APP_DEBUG') === 'true',
    'timezone' => 'Asia/Tehran',
    'locale' => 'fa_IR',
    'default_page_size' => 20,
    'max_page_size' => 100,
    'cache_ttl_search_seconds' => 1800,   // 30 دقیقه
    'cache_ttl_lookups_seconds' => 86400, // ۲۴ ساعت
    'admin' => [
        'username' => getenv('ADMIN_USER') ?: 'admin',
        'password_hash' => getenv('ADMIN_PASS_HASH') ?: password_hash('admin', PASSWORD_DEFAULT),
    ],
];
```

### ۶.۳ `config/database.php`

```php
return [
    'driver' => 'sqlite',
    'path' => getenv('DB_PATH') ?: dirname(__DIR__) . '/storage/app.db',
    'pragmas' => [
        'journal_mode' => 'WAL',        // Write-Ahead Logging — همزمانی خواندن (SSE) و نوشتن (worker)
        'synchronous' => 'NORMAL',
        'foreign_keys' => 'ON',
        'busy_timeout' => 5000,         // ۵ ثانیه انتظار در lock
        'temp_store' => 'MEMORY',
        'cache_size' => -8000,          // 8MB
    ],
];
```

> **نکته‌ی انتقال:** ساختار به‌گونه‌ای طراحی شده که افزودن `mysql` در آینده با کمترین تغییر ممکن باشد (فقط پیاده‌سازی Repositoryها تغییر می‌کند، نه Domain یا Services).

### ۶.۴ `config/anti-detection.php` (مهم برای انتقال)

این فایل **مرجع اصلی** رفتار کلاینت HTTP است. مقادیر واقعی این فایل با مستندات اولیه تفاوت دارد (بعداً در مکالمه #004 برای رفع ۹۰ ثانیه timeout سخت‌گیرانه‌تر شده‌اند):

```php
return [
    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                  . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'headers' => [
        'Accept' => 'application/json',
        'Accept-Language' => 'fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept-Encoding' => 'gzip, deflate, br',
        'Connection' => 'keep-alive',
        'Sec-Fetch-Dest' => 'empty',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Site' => 'same-origin',
        'sec-ch-ua' => '"Not;A=Brand";v="99", "Google Chrome";v="120", "Chromium";v="120"',
        'sec-ch-ua-mobile' => '?0',
        'sec-ch-ua-platform' => '"Windows"',
        'DNT' => '1',
        'Upgrade-Insecure-Requests' => '1',
    ],
    'rate_limit' => [
        'min_delay_ms' => 2000,     // حداقل ۲ ثانیه بین درخواست‌ها
        'max_delay_ms' => 5000,     // حداکثر ۵ ثانیه (با jitter)
        'max_concurrent' => 1,      // هرگز هم‌زمان
        'burst' => 5,               // حداکثر ۵ درخواست در پنجره‌ی ۶۰ ثانیه
        'cooldown_ms' => 30000,     // ۳۰ ثانیه cooldown پس از burst
    ],
    'retry' => [
        'max_attempts' => 2,        // فقط ۲ تلاش (fail fast — کاربر نانتظار ۹۰ ثانیه نکشد)
        'backoff_base_ms' => 500,
        'backoff_factor' => 2,
        'retry_on_status' => [429, 502, 503, 504],
    ],
    'timeout' => [
        'connect' => 5,             // کاهش از ۱۵ → ۵ (DNS+TCP+TLS <۲ ثانیه معمول)
        'response' => 8,            // کاهش از ۳۰ → ۸ (upstream معمول ۱-۴ ثانیه)
    ],
    'upstream_base' => getenv('UPSTREAM_BASE') ?: 'https://qr.mojavez.ir',
];
```

> **⚠️ هشدار مهم برای انتقال (مشکل ST1):** این مقادیر rate limit در فایل پیکربندی تعریف شده‌اند، اما کد فعلی `RateLimiterService` را به‌طور واقعی در `MojavecClient::doWithRetry` **صدا نمی‌زند**. یعنی مکث بین درخواست‌ها در حال حاضر **کد مرده** است. هنگام انتقال به پروژه‌ی دیگر، **حتماً rate limiter را واقعاً فعال کنید** (این یکی از موارد سخت‌سازی هنگام انتقال است — به بخش ۱۹ مراجعه کنید).

### ۶.۵ `config/filters.php` (فیلتر پیش‌فرض نمونه)

پروژه با یک فیلتر پیش‌فرض از پیش تنظیم شده تحویل می‌دهد که نشان‌دهنده‌ی سناریوی اصلی کاربر است:

| محور | مقدار | شناسه |
|------|-------|--------|
| دستگاه اصلی | وزارت میراث فرهنگی، گردشگری و صنایع دستی | 1001 |
| استان | خراسان رضوی | 13 |
| شهرستان | مشهد | (از طریق lookup استخراج می‌شود) |
| تاریخ صدور اولیه (شروع) | 1400/01/01 (شمسی) | — |
| تاریخ صدور اولیه (پایان) | 1405/12/29 (شمسی) | — |

این فیلتر در `config/filters.php` تعریف شده و در نصب اول به پایگاه داده seed می‌شود.

### ۶.۶ `config/routes.php`

حدود ۴۰ مسیر تعریف شده است. ترتیب مسیرها **اهمیت دارد** چون Router از نوع first-match است. مسیرهای خاص (`/saved`, `/export`, `/search`, `/save-all`, `/{id}/save`, `/{id}/track`) باید **قبل از** مسیر generic `/{id}` بیایند تا بلعیده نشوند (این به‌صراحت در کامنت فایل ذکر شده).

---

## ۷. پایگاه داده و Schema

پایگاه داده **SQLite** (فایل `storage/app.db`) با **۲ migration** است.

### ۷.۱ جداول (۹ جدول)

#### `licenses` (کش مجوزها از upstream)
```sql
CREATE TABLE licenses (
    request_number TEXT PRIMARY KEY,           -- شناسه‌ی یکتای مجوز (مثل I17981871)
    applicant_name TEXT NOT NULL DEFAULT '',
    user_image TEXT,                            -- URL تصویر متقاضی
    license_title TEXT NOT NULL DEFAULT '',
    organization_title TEXT NOT NULL DEFAULT '',
    province_title TEXT NOT NULL DEFAULT '',
    township_title TEXT NOT NULL DEFAULT '',
    responded_at TEXT NOT NULL DEFAULT '',      -- تاریخ پاسخ (Gregorian، مثل 2026-11-11 22:32:22)
    status_id TEXT,
    status_title TEXT,
    status_slug TEXT NOT NULL DEFAULT 'unknown', -- active | expired | unknown
    -- فیلدهای denormalized برای فیلتر سریع محلی
    province_id INTEGER,
    township_id INTEGER,
    main_org_code TEXT,
    sub_org_code TEXT,
    first_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
    last_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
    raw_json TEXT
);
-- ۹ ایندکس: province, township, org_main, org_sub, status, responded, applicant, title, last_seen
```

> **Migration 002** دو ستون اضافه می‌کند:
> ```sql
> ALTER TABLE licenses ADD COLUMN is_saved INTEGER NOT NULL DEFAULT 0;  -- 0=کش گذرا، 1=ذخیره‌شده توسط کاربر
> ALTER TABLE licenses ADD COLUMN saved_at TEXT;
> CREATE INDEX idx_licenses_saved ON licenses(is_saved, saved_at);
> ```

#### `filters` (فیلترهای ذخیره‌شده‌ی کاربر)
```sql
CREATE TABLE filters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    label TEXT NOT NULL,
    description TEXT,
    filter_json TEXT NOT NULL,                  -- پارامترهای فیلتر به صورت JSON
    main_org_code TEXT, province_id INTEGER, township_id INTEGER,
    issue_start_date TEXT, issue_end_date TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    auto_refresh INTEGER NOT NULL DEFAULT 0,
    refresh_interval_minutes INTEGER NOT NULL DEFAULT 0,
    last_run_at TEXT, last_result_count INTEGER,
    created_at TEXT, updated_at TEXT
);
```

#### `jobs` (صف وظایف)
```sql
CREATE TABLE jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,                -- 'search' | 'batch' | 'count'
    payload_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',  -- queued|processing|completed|failed|retrying
    priority INTEGER NOT NULL DEFAULT 100,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    filter_id INTEGER, batch_id INTEGER,
    error TEXT, result_count INTEGER,
    started_at TEXT, completed_at TEXT, created_at TEXT,
    FOREIGN KEY (filter_id) REFERENCES filters(id) ON DELETE SET NULL
);
```

#### `batches` (اجرای دسته‌ای CSV)
```sql
CREATE TABLE batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    original_filename TEXT NOT NULL,
    total_rows INTEGER NOT NULL DEFAULT 0,
    processed_rows INTEGER NOT NULL DEFAULT 0,
    matched_rows INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'queued',  -- queued|processing|completed|failed
    csv_path TEXT, results_json TEXT, error TEXT,
    started_at TEXT, completed_at TEXT, created_at TEXT
);
```

#### `lookups` (کش استان‌ها و دستگاه‌ها)
```sql
CREATE TABLE lookups (
    kind TEXT NOT NULL,    -- 'province' | 'main_org' | 'sub_org'
    code TEXT NOT NULL,    -- شناسه‌ی upstream (رشته)
    title TEXT NOT NULL,
    parent_code TEXT,      -- برای sub_org به main_org اشاره می‌کند
    extra_json TEXT,
    refreshed_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (kind, code)
);
```

#### سایر جداول
- **`settings`**: key-value (کلید PRIMARY KEY)
- **`admin_users`**: id, username (UNIQUE), password_hash, is_active, last_login_at, created_at
- **`events`**: id, type, payload_json, created_at (برای audit + SSE replay؛ ۲ ایندکس)
- **`worker_state`**: single-row table با `CHECK (id = 1)` برای اطمینان از فقط یک ردیف (is_running, last_heartbeat, current_job_id)

### ۷.۲ Migration Runner

- کلاس `MigrationRunner` در `app/Persistence/MigrationRunner.php` فایل‌های SQL را به ترتیب اجرا می‌کند.
- جدول `migrations` پیگیری اجرا را نگه می‌دارد (وجود این جدول در `index.php` به‌عنوان چک نصب بررسی می‌شود).
- اجرا از `public/install.php` یا `php scripts/migrate.php`.

> **نکته‌ی انتقال:** برای انتقال به MySQL، این SQLها باید به MySQL Dialect تبدیل شوند (`INTEGER PRIMARY KEY AUTOINCREMENT` → `INT AUTO_INCREMENT`، `datetime('now')` → `DEFAULT CURRENT_TIMESTAMP` و...). اما چون لایه‌ی Domain با interface کار می‌کند، فقط پیاده‌سازی Repositoryها و migrationها تغییر می‌کنند.

---

## ۸. مدل دامنه (Domain Model)

لایه‌ی Domain خالص‌ترین لایه است و **هیچ وابستگی به زیرساخت ندارد**. همه‌ی کلاس‌های این لایه `readonly` و `immutable` هستند.

### ۸.۱ `License` (Value Object)

یک ردیف از `qr.mojavez.ir /api/v1/licenses/search` را نشان می‌دهد. سه factory متفاوت دارد:

| Factory | منبع داده | کاربرد |
|---------|-----------|--------|
| `License::fromUpstream(array $row)` | پاسخ JSON جستجوی upstream | مسیر جستجوی معمول |
| `License::fromTrackPage(array $t)` | خروجی `TrackScraper` (صفحه‌ی `/track/{rn}`) | مسیر batch (چون upstream از فیلتر `license_id` پشتیبانی نمی‌کند) |
| `License::fromArray(array $row)` | snapshot کش محلی | بازیابی از cache |

**فیلدها:** `requestNumber`, `applicantName`, `userImage`, `licenseTitle`, `organizationTitle`, `provinceTitle`, `townshipTitle`, `respondedAt`, `status` (LicenseStatus enum), `statusId`, `statusTitle`.

> **نکته‌ی مهم:** `License::fromTrackPage()` فقط فیلدهای عمومی صفحه‌ی track را پر می‌کند: `applicantName = ''` و `userImage = null`. علت: صفحه‌ی track عمومی است و نام/عکس متقاضی را نمایش نمی‌دهد. این محدودیت upstream است.

**متدهای کمکی:**
- `avatarUrl($upstreamBase)`: ساخت URL کامل تصویر (اگر `userImage` نسبی باشد، به `upstreamBase` می‌چسبد).
- `initials()`: حرف اول نام برای fallback avatar.

### ۸.۲ `Filter` (Value Object — مهم برای فهم upstream payload)

این کلاس **دقیقاً ساختار payload جستجوی upstream** را بازتاب می‌دهد:

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
    ) {}
}
```

**متدهای کلیدی:**

- **`toUpstreamPayload()`**: ساخت JSON برای ارسال به `/api/v1/licenses/search`. **تبدیل تاریخ شمسی → میلادی** را انجام می‌دهد (مثلاً `"1400/01/01"` → `"2021-03-21"`). اگر تبدیل شکست خورد، تاریخ خالی ارسال می‌شود (upstream خالی را «بدون فیلتر تاریخ» تفسیر می‌کند). این رفع یک باگ بحرانی بود (ارسال تاریخ شمسی به upstream که میلادی انتظار دارد → ۰ نتیجه).

> **⚠️ کشف حیاتی (مکالمه #012):** upstream از فیلتر `license_id` **پشتیبانی نمی‌کند**. جستجو فقط با `license_id` همیشه ۰ برمی‌گرداند، حتی اگر مجوز در `/track/{number}` وجود دارد. برای جستجوی بر اساس شماره مجوز باید از مسیر `/track/{rn}` (از طریق `TrackScraper`) استفاده کرد، نه از `/api/v1/licenses/search`.

- **`toLocalFilterArray()`**: ساخت آرایه‌ی فیلتر برای query کش محلی. شامل `license_id` است (Repository از `request_number` فیلتر می‌کند).
- **`cacheKey()`**: hash SHA-256 پایدار برای کلید کش (page/pageSize/label/savedId را مستثنی می‌کند).
- **`isEmpty()`**: اگر هیچ معیار فیلتری تنظیم نشده باشد، true برمی‌گرداند (جستجوی خالی wasteful است؛ caller باید short-circuit کند).

### ۸.۳ Enums

- **`LicenseStatus`** (`active`, `expired`, `unknown`): متد `fromUpstream($slug, $id)` و `titleFa()` برای نمایش فارسی.
- **`JobStatus`**: وضعیت job در صف.

### ۸.۴ `LicenseCollection`

نگهدارنده‌ی مجموعه‌ای از `License` به همراه متادیتای صفحه‌بندی (`page`, `pageSize`, `total`). متد `totalPages()` و `toArray()` (برای JSON serialization) دارد.

---

## ۹. API داخلی REST (رابط فرانت‌اند با بک‌اند)

این API فقط **داخلی** است (فرانت‌اند با AJAX آن را صدا می‌زند). هیچ احراز هویتی روی `/api/admin/*` وجود ندارد (مشکل S2 — به بخش ۱۹).

### ۹.۱ صفحه‌های وب (HTML)

| متد | مسیر | کنترلر | توضیح |
|-----|------|--------|-------|
| GET | `/` | HomeController::index | داشبورد (SPA) |
| GET | `/dashboard` | HomeController::index | SPA route |
| GET | `/analytics` | HomeController::index | SPA route (نمای تحلیلی) |
| GET | `/about`, `/help` | HomeController::index | صفحات استاتیک |
| GET | `/batch` | HomeController::index | SPA route (جستجوی دسته‌ای) |
| GET | `/admin` | HomeController::index | SPA route (پنل ادمین) |
| GET | `/license/{id}` | HomeController::licenseDetail | صفحه‌ی مستقل جزئیات مجوز |

### ۹.۲ API مجوزها (`/api/licenses`)

> **ترتیب مسیرها مهم است** (first-match router). مسیرهای خاص قبل از `/{id}` آمده‌اند.

| متد | مسیر | متد کنترلر | توضیح |
|-----|------|------------|-------|
| GET | `/api/licenses` | `index` | query کش محلی (پارامتر: page, page_size, name, license_id, province_id, township_id, main_org_code, sub_org_code, status, issue_start_date, issue_end_date, saved_only) |
| GET | `/api/licenses/saved` | `saved` | فقط مجوزهای ذخیره‌شده (`is_saved=1`) |
| GET | `/api/licenses/export` | `export` | خروجی CSV (پیش‌فرض) یا JSON (پارامتر `format`) |
| POST | `/api/licenses/search` | `search` | جستجوی upstream + ذخیره در cache. body = ساختار Filter |
| POST | `/api/licenses/save-all` | `saveAll` | ذخیره‌ی همه‌ی نتایج فیلتر فعلی (همه‌ی صفحات upstream را fetch می‌کند) |
| POST | `/api/licenses/{id}/save` | `save` | علامت‌گذاری یک مجوز به‌عنوان ذخیره‌شده |
| DELETE | `/api/licenses/{id}/save` | `unsave` | برداشتن علامت ذخیره (ردیف حذف نمی‌شود) |
| GET | `/api/licenses/{id}/track` | `track` | جزئیات تفصیلی از صفحه‌ی `/track/{rn}` upstream (scraped) |
| GET | `/api/licenses/{id}` | `show` | یک مجوز از کش محلی |

**ساختار پاسخ صفحه‌بندی‌شده (paginated):**
```json
{
  "items": [ { /* License.toArray() + is_saved: bool */ } ],
  "page": 1,
  "page_size": 20,
  "total": 239,
  "total_pages": 12
}
```

**ساختار پاسخ خطا:**
```json
{
  "error": "...",
  "message": "...",
  "status": 502,
  "suggestion": "...",   // اختیاری
  "found": false         // اختیاری (برای /track)
}
```

### ۹.۳ API Lookups و Filters

| متد | مسیر | متد | توضیح |
|-----|------|-----|-------|
| GET | `/api/lookups` | `lookups` | استان‌ها و دستگاه‌ها (از کش محلی) |
| GET | `/api/lookups/townships/{provinceId}` | `townships` | شهرستان‌های یک استان |
| GET | `/api/lookups/sub-organizations/{orgCode}` | `subOrganizations` | دستگاه‌های تابعه |
| POST | `/api/lookups/refresh` | `refreshLookups` | به‌روزرسانی کش از upstream |
| GET | `/api/filters` | `listFilters` | فهرست فیلترهای ذخیره‌شده |
| POST | `/api/filters` | `createFilter` | ایجاد فیلتر |
| GET | `/api/filters/{id}` | `showFilter` | |
| PUT | `/api/filters/{id}` | `updateFilter` | |
| DELETE | `/api/filters/{id}` | `deleteFilter` | |

### ۹.۴ API Batch (دسته‌ای CSV)

| متد | مسیر | متد | توضیح |
|-----|------|-----|-------|
| POST | `/api/batch/upload` | `upload` | آپلود فایل CSV (ستون‌های `license_id,name`) |
| GET | `/api/batch` | `list` | فهرست اجراهای دسته |
| GET | `/api/batch/{id}/status` | `status` | وضعیت یک اجرا + پیشرفت + نتایج |

> **رفع حلقه‌ی بی‌نهایت (مکالمه #010):** `BatchController::status` پیش از برگرداندن وضعیت، `Worker::run(1)` را صدا می‌زند تا با هر poll یک کار پردازش شود (الگوی **poor man's cron** — سرور تک‌رشته، بدون نیاز به worker جداگانه). این یعنی worker جداگانه نباید اجرا شود.

### ۹.۵ API Admin (⚠️ بدون احراز هویت)

| متد | مسیر | متد | توضیح |
|-----|------|-----|-------|
| GET | `/api/admin/stats` | `stats` | آمار کلی |
| GET | `/api/admin/analytics` | `analytics` | داده‌های تحلیلی (نمودارها) |
| GET | `/api/admin/jobs` | `jobs` | فهرست jobهای صف |
| POST | `/api/admin/jobs/{id}/retry` | `retryJob` | تلاش مجدد یک job |
| GET | `/api/admin/logs` | `logs` | لاگ‌های اخیر |
| POST | `/api/admin/crawl` | `crawl` | اجرای دستی crawl |
| POST | `/api/admin/worker/start` | `workerStart` | شروع worker |
| POST | `/api/admin/worker/stop` | `workerStop` | توقف worker |
| GET | `/api/admin/worker/status` | `workerStatus` | وضعیت worker |

### ۹.۶ API تصویر (proxy)

| متد | مسیر | متد | توضیح |
|-----|------|-----|-------|
| GET | `/api/proxy-image?url=...` | `image` | fetch تصویر متقاضی از upstream و re-serve هم‌مبدا (برای html2canvas/PDF) |

> **⚠️ مشکل SSRF (S4):** این endpoint فعلاً `CURLOPT_SSL_VERIFYPEER = false`، `FOLLOWLOCATION = true` و `Access-Control-Allow-Origin: *` دارد. هنگام انتقال باید TLS روشن شود، redirect محدود شود و CORS محدود گردد.

### ۹.۷ SSE (غیرفعال)

| متد | مسیر | توضیح |
|-----|------|-------|
| GET | `/api/stream` | **در حال حاضر غیرفعال است** — `index.php` به‌جای آن پاسخ `204` می‌فرستد |

> **علت غیرفعالی (مکالمه #010):** PHP built-in server تک‌رشته است؛ اتصال SSE طولانی‌مدت همه‌ی درخواست‌های دیگر را در صف می‌گیرد (علت ۹۰ ثانیه timeout قبلی). SSE فقط در محیط production با Apache/PHP-FPM چند‌پردازشی کار می‌کند. در عمل، به‌روزرسانی پیشرفت از طریق **polling** (با poor man's cron در هر poll) انجام می‌شود.

---

## ۱۰. API درگاه ملی (upstream qr.mojavez.ir)

این endpointها از تحلیل فایل HAR ضبط‌شده توسط کاربر از مرورگر واقعی استخراج شده‌اند. سایت هدف یک **Next.js Single Page Application** با JSON REST API است.

### ۱۰.۱ ساختار پاسخ (JSON Envelope)

تمام پاسخ‌ها از یک پوشش یکسان پیروی می‌کنند:
```json
{ "data": { ... } }
```
کلیدهای درونی **camelCase** هستند (نشان‌دهنده‌ی GraphQL یا یک لایه‌ی data resolver داخلی): `filterLicenses`, `countFilteredLicenses`, `provinceTownship`, `organization`.

در صورت خطا:
```json
{
  "errors": [ { "message": "...", "extensions": { "code": "..." } } ]
}
```
`MojavecClient` هر دو حالت `data` و `errors` را بررسی می‌کند.

### ۱۰.۲ هدرهای مورد نیاز (همه درخواست‌ها)

این هدرها در `AntiDetectionMiddleware` به‌صورت ثابت اعمال می‌شوند (دقیقاً از HAR استخراج شده‌اند):

| هدر | مقدار |
|-----|-------|
| Accept | application/json |
| Accept-Language | fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7 |
| User-Agent | Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 |
| Origin | https://qr.mojavez.ir |
| Referer | https://qr.mojavez.ir/ |
| Sec-Fetch-Dest | empty |
| Sec-Fetch-Mode | cors |
| Sec-Fetch-Site | same-origin |
| sec-ch-ua | "Not;A=Brand";v="99", "Google Chrome";v="120", "Chromium";v="120" |
| sec-ch-ua-mobile | ?0 |
| sec-ch-ua-platform | "Windows" |
| Content-Type | application/json (فقط برای POST) |

> **ترتیب هدرها** باید مشابه مرورگر واقعی باشد تا fingerprint یکسان بودن حفظ شود.

### ۱۰.۳ endpointها

#### (۱) فهرست استان‌ها
```
GET https://qr.mojavez.ir/api/v1/lookups/provinces
```
بدون body، بدون احراز هویت. پاسخ:
```json
{
  "data": { "provinceTownship": { "provinces": [
    { "id": "10", "title": "آذربایجان شرقی" },
    { "id": "13", "title": "خراسان رضوی" },
    { "id": "29", "title": "تهران" }
  ]}}
}
```
> `id` به‌صورت **رشته‌ای** برمی‌گردد. در payload جستجو باید همان نوع رشته ارسال شود. این endpoint باید یک بار در ابتدای نصب صدا زده و در جدول `lookups` کش شود.

#### (۲) فهرست دستگاه‌ها
```
GET https://qr.mojavez.ir/api/v1/lookups/organizations
```
دو دسته: `main` (دستگاه اصلی) و `sub` (دستگاه تابعه با `parent_id`).
```json
{
  "data": { "organization": {
    "main": [ { "id": "1001", "title": "وزارت میراث فرهنگی..." } ],
    "sub":   [ { "id": "...", "title": "...", "parent_id": "1001" } ]
  }}
}
```

#### (۳) شهرستان‌های یک استان
```
GET https://qr.mojavez.ir/api/v1/lookups/provinces/{provinceId}/townships
```

#### (۴) دستگاه‌های تابعه
```
GET https://qr.mojavez.ir/api/v1/lookups/organizations/{mainOrgCode}/subs
```

#### (۵) جستجوی مجوزها ⭐ (اصلی)
```
POST https://qr.mojavez.ir/api/v1/licenses/search
Content-Type: application/json
```
**Request Body:**
```json
{
  "license_id": null,            // ⚠️ upstream از این فیلتر پشتیبانی نمی‌کند (مکالمه #012)
  "name": "",
  "issue_start_date": "2021-03-21",   // میلادی yyyy-mm-dd (شمسی از سمت کاربر، تبدیل می‌شود)
  "issue_end_date": "2027-03-20",
  "last_op_start_date": "",
  "last_op_end_date": "",
  "province_id": "13",           // رشته، نه عدد
  "township_id": null,
  "main_org_code": "1001",
  "sub_org_code": null,
  "page": "1"                    // رشته، نه عدد
}
```

**Response:**
```json
{
  "data": { "filterLicenses": { "license": [
    {
      "request_number": "I17981871",
      "applicant_name": "...",
      "user_image": "https://...",
      "license_title": "...",
      "organization_title": "...",
      "province_title": "...",
      "township_title": "...",
      "responded_at": "2026-11-11 22:32:22",   // میلادی
      "status": { "status_id": "...", "status_title": "...", "status_slug": "active" }
    }
  ]}}
}
```
- هر صفحه حدود **۲۰ مجوز** برمی‌گرداند.
- پاسخ جستجو **`total` ندارد** (در HAR تأیید شده). اگر صفحه‌ی برگشتی پر باشد (== pageSize)، `MojavecClient` یک درخواست `/count` جداگانه می‌فرستد.

#### (۶) شمارش کل مجوزهای منطبق
```
POST https://qr.mojavez.ir/api/v1/licenses/count
```
**Body:** مانند جستجو **بدون فیلد `page`**.
```json
{ "data": { "countFilteredLicenses": { "total": 10428460 } } }
```
> کل سامانه بیش از **۱۰٬۴۲۸٬۴۶۰** مجوز ثبت‌شده دارد. این endpoint نباید مکرر صدا زده شود (هزینه‌بر روی سرور). کش با TTL ۱ ساعت توصیه می‌شود.

#### (۷) وضعیت احراز هویت
```
GET https://qr.mojavez.ir/api/v1/auth/me
```
```json
{ "authenticated": false, "sso_available": true, "local_test_login_available": false }
```
تأیید می‌کند که جستجوی عمومی **نیازی به احراز هویت ندارد**.

#### (۸) صفحه‌ی track و اقلام تخصصی
```
GET https://qr.mojavez.ir/track/{request_number}                      ← صفحه HTML (RSC payload)
GET https://qr.mojavez.ir/api/v1/licenses/{rn}/advanced-data          ← اقلام تخصصی (JSON)
```
این دو endpoint در `docs/API_DISCOVERY.md` به‌طور کامل توضیح داده نشده‌اند اما در `TrackScraper` پیاده‌سازی شده‌اند (به بخش ۱۲ مراجعه کنید).

### ۱۰.۴ نرخ درخواست و رفتار Retry (مقادیر واقعی فایل پیکربندی)

| پارامتر | مقدار واقعی (config) | مقدار اولیه‌ی مستند شده |
|---------|----------------------|--------------------------|
| حداقل فاصله بین درخواست‌ها | ۲۰۰۰ ms | ۲ ثانیه |
| حداکثر درخواست burst | ۵ | ۵ |
| مدت cooldown | ۳۰۰۰۰ ms | ۳۰ ثانیه |
| Timeout اتصال | ۵ ثانیه | ۱۵ ثانیه |
| Timeout پاسخ | ۸ ثانیه | ۱۵-۳۰ ثانیه |
| Retry max_attempts | ۲ | ۳ |
| Retry backoff | ۵۰۰ms, ۲× | ۱،۲،۴ ثانیه |
| Retry روی status | 429, 502, 503, 504 | 429, 5xx |

> **نکته‌ی مهم:** مقادیر timeout و retry بعداً سخت‌گیرانه‌تر شدند تا از ۹۰ ثانیه timeout کاربر جلوگیری شود (مکالمه‌های #004). فلسفه: **fail fast** — کاربر نانتظار ۹۰ ثانیه نکشد.

### ۱۰.۵ رفتار کش

| نوع داده | محل کش | مدت اعتبار | کلید کش |
|----------|--------|------------|---------|
| فهرست استان‌ها | جدول `lookups` | ۲۴ ساعت (`cache_ttl_lookups`) | `provinces` |
| فهرست دستگاه‌ها | جدول `lookups` | ۲۴ ساعت | `organizations` |
| نتایج جستجو | جدول `licenses` | ۳۰ دقیقه (`cache_ttl_search`) | hash فیلتر |
| تعداد کل | جدول `cache` (پیشنهادی) | ۱ ساعت | hash + `count` |

الگوریتم hash فیلتر:
```php
$hash = hash('sha256', implode('|', [$licenseId, $name, $issueStartDate, ...]));
```

---

## ۱۱. زیرساخت کلاینت HTTP و لایه ضد شناسایی

### ۱۱.۱ `HttpClient` (cURL نیتیو)

- کلاس `app/Infrastructure/HttpClient.php` cURL نیتیو PHP را wrap می‌کند (نه Guzzle).
- متد `request($method, $url, $headers, $body, $contentType)`: یک `HttpResponse` برمی‌گرداند.
- تنظیمات: `CURLOPT_RETURNTRANSFER`, `CURLOPT_TIMEOUT`, `CURLOPT_CONNECTTIMEOUT`, `CURLOPT_USERAGENT` (ثابت)، `CURLOPT_COOKIEJAR`/`CURLOPT_COOKIEFILE` (در `storage/cache/cookies.txt`).
- هیچ‌گاه User-Agent پیش‌فرض cURL ارسال نمی‌شود (تنظیم صریح `CURLOPT_USERAGENT`).

### ۱۱.۲ `AntiDetectionMiddleware`

این کلاس مسئول اعمال هدرهای ثابت و (در طراحی) Rate Limiting است. مقادیر در `config/anti-detection.php` تعریف شده‌اند.

**User-Agent ثابت** (هرگز چرخشی نمی‌شود):
```
Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36
```
> علت ثابت بودن: سایت‌های هدف معمولاً یک کاربر را با همان User-Agent اولیه می‌شناسند؛ تغییر مکرر خود نشانه‌ی ربات بودن است.

**Jitter تصادفی Gaussian** (برای جلوگیری از الگوی زمانی ثابت):
```php
// Box-Muller: میانگین 0.5s، انحراف معیار 0.2s، محدود به [0, 2]
$u1 = random_int(1, PHP_INT_MAX) / PHP_INT_MAX;
$u2 = random_int(1, PHP_INT_MAX) / PHP_INT_MAX;
$z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
$jitter = max(0.0, min(2.0, 0.5 + 0.2 * $z));
```

**Cookie Jar**: برای حفظ session cookie سایت هدف بین درخواست‌ها (رفتار طبیعی‌تر و حفظ CSRF token مبتنی بر کوکی).

**عدم ارسال هدرهای SDK**: `X-Requested-With`, `X-Client-Id`, `Guzzle/7.0`, `curl/7.74.0` ارسال نمی‌شوند.

### ۱۱.۳ `MojavecClient` (Adapter روی HttpClient)

این کلاس interface ساده برای جستجو و lookup فراهم می‌کند. متدهای عمومی:

| متد | endpoint upstream | خروجی |
|-----|-------------------|-------|
| `search(Filter $filter, $rateLimiter)` | POST /licenses/search | `LicenseCollection` |
| `count(Filter $filter, $rateLimiter)` | POST /licenses/count | `?int` |
| `provinces($rateLimiter)` | GET /lookups/provinces | `list<{id,title}>` |
| `townships(int $provinceId, $rateLimiter)` | GET /lookups/provinces/{id}/townships | `list<{id,title}>` |
| `organizations($rateLimiter)` | GET /lookups/organizations | `{main:[], sub:[]}` |
| `subOrganizations(string $code, $rateLimiter)` | GET /lookups/organizations/{code}/subs | `list<{id,title}>` |

**منطق `search()` (مهم):**
1. ساخت payload با `Filter::toUpstreamPayload()`.
2. ارسال با `doWithRetry()`.
3. اگر پاسخ ناموفق → لاگ warning و برگرداندن `LicenseCollection` خالی (نه exception).
4. parse با `ResponseParserInterface::parseSearch()`.
5. تبدیل هر ردیف به `License::fromUpstream()`.
6. **محاسبه‌ی total:** پاسخ search شامل total نیست. اگر صفحه‌ی برگشتی پر باشد (== pageSize)، یک درخواست `count()` جداگانه می‌فرستد. در غیر این صورت، total = تعداد آیتم‌های همان صفحه.

**منطق `doWithRetry()` (مهم):**
- مقادیر fallback در خود متد hardcode شده‌اند: `maxAttempts=2`, `backoffBase=500ms`, `backoffFactor=2`, `retryStatuses=[429,502,503,504]`.
- اگر `rateLimiter` (callback) پاس شده باشد، قبل از هر درخواست صدا زده می‌شود.
- در خطای non-retryable → لاگ warning و بازگشت.
- در خطای retryable → `usleep($delay*1000)` و تلاش مجدد.
- پس از اتمام تلاش‌ها → بازگشت `null` یا آخرین response.

> **⚠️ نکته‌ی انتقال:** در کد فعلی، `rateLimiter` به‌ندرت یا هرگز پاس نمی‌شود (مکالمه‌های #004 کشف کردند که rate limiter عملاً کد مرده است). هنگام انتقال باید `$rateLimiter` همیشه پاس شود.

### ۱۱.۴ `Parser/` (الگوی Strategy)

- `ResponseParserInterface`: متدهای `parseSearch`, `parseCount`, `parseProvinces`, `parseTownships`, `parseOrganizations`, `parseSubOrganizations`.
- پیاده‌سازی فعلی: `JsonSearchParser`.
- در صورت تغییر ساختار پاسخ upstream، فقط یک parser جدید اضافه می‌شود.

### ۱۱.۵ تفاوت راهبرد ضد شناسایی با spoofing مخرب

| ویژگی | spoofing مخرب | راهبرد این پروژه |
|--------|---------------|------------------|
| هدف | جعل هویت برای نفوذ | احترام به محدودیت منابع سایت |
| Rate Limit | نادیده گرفته می‌شود | سخت‌گیرانه رعایت می‌شود (طراحی) |
| Jitter | پنهان‌کردن الگوی حمله | طبیعی‌سازی فاصله درخواست‌ها |
| Retry | افزایش فشار | کاهش فشار در خطا |
| حجم درخواست | حداکثر ممکن | حداقل لازم با کش |

> داده‌های qr.mojavez.ir عمومی هستند (بر اساس ماده ۷ قانون اجرای سیاست‌های اصل ۴۴). اما crawl با حجم بالا ممکن است با ToS سایت در تضاد باشد. مسئولیت استفاده بر عهده‌ی کاربر است. اگر سایت درخواست توقف کند، پروژه باید فوراً متوقف شود.

---

## ۱۲. TrackScraper (استخراج صفحه‌ی track)

این کلاس (`app/Infrastructure/TrackScraper.php`) **مهم‌ترین و شکننده‌ترین** جزء پروژه است. صفحه‌ی `https://qr.mojavez.ir/track/{request_number}` را fetch کرده و فیلدهای تفصیلی را از **React Server Components (RSC) flight payload** استخراج می‌کند.

### ۱۲.۱ چرا RSC payload به‌جای DOM parsing؟

سایت یک **Next.js App Router** است و داده را به‌صورت RSC flight payload داخل `self.__next_f.push([1, "..."])` می‌فرستد (نه `__NEXT_DATA__` قدیمی‌تر). استخراج مستقیم از RSC ترجیح داده شد چون:
1. داده‌ی license به‌صورت آبجکت JSON تمیز در payload موجود است.
2. جفت‌های label→value با الگوی ثابت `{"children":"LABEL"}...,{"children":"VALUE"}` قابل استخراج دقیق هستند.
3. این روش از DOM parsing مقاوم‌تر است (وابسته به className یا ساختار DOM نیست).

### ۱۲.۲ ساختار داده‌ی RSC

هر push argument یک JSON-encoded string است؛ concatenation آنها یک stream از فریم‌های `<frameId>:<json-value>` می‌سازد که مقادیر آن درختان React element هستند:
```
["$","div",null,{"className":"...","children":"LABEL"}]
```

**کلیدهای داده‌ی license** (در یک آبجکت JSON با signature کلید `isic_code`):
`license_title`, `organization_title`, `regulatory_organizations`, `isic_code`, `isic_title`, `issue_type`, `responded_at`, `old_license_responded_at`, `expires_at`, `status{status_id,status_title,status_slug}`.

**فیلدهای هویت متقاضی** (به‌صورت label→value در RSC tree):
- `"کدملی"` (بدون فاصله!) → مقدار ماسک‌شده مثل `"073****269"` (به‌صورت آرایه‌ی رشته‌ای `"children":["073****269"," "]`)
- `"نام پدر"` → مثل `"عبدالكریم"`
- `"شماره همراه کسب و کاری"` → معمولاً **خالی** (سایت این فیلد را علناً نمایش نمی‌دهد)
- `"استان"`, `"شهرستان"`, `"کدپستی"` (ماسک‌شده مثل `"9571968***"`), `"نشانی کسب و کار"`

**جدول سوابق** (سطرهای `<td>` با کلیدها): `index`, `license_operation`, `operation_reasons`, `created_at`. مثلاً:
```
{"index":1,"license_operation":"صدور مجوز","operation_reasons":"","created_at":"1405-08-17"}
```
> تاریخ‌ها از upstream به‌صورت **جلالی** می‌آیند (`1405-08-17`)، پس فقط فارسی‌رقم می‌شوند نه تبدیل مجدد.

### ۱۲.۳ متدهای اصلی TrackScraper

| متد | کاربرد |
|-----|--------|
| `fetch($rn, $includeAdvancedData=true)` | متد اصلی: fetch + parse صفحه‌ی track |
| `extractRscPayload($html)` | استخراج concatenation تمام pushهای `self.__next_f` |
| `findLicenseDataObject($blob)` | اسکن آبجکت‌های متعادل JSON برای یافتن آن که کلید `isic_code` دارد |
| `findLabelValue($blob, $label)` | پیدا کردن `"children":"LABEL"` و گرفتن مقدار `children` بعدی |
| `parseHistoryRows($blob)` | استخراج سلول‌های `<td>` و گروه‌بندی در سطر |
| `fetchSpecializedItems($rn)` | GET `/api/v1/licenses/{rn}/advanced-data` → اقلام تخصصی |
| `extractCellValue($raw)` | مدیریت سه قالب: رشته‌ی ساده، عدد، آرایه (رشته‌ای خام یا زیردرخت React) |

### ۱۲.۴ خروجی `fetch()` (ساختار داده)

```php
[
  'found' => true,
  'error' => null,
  'request_number' => 'I17981871',
  'father_name' => 'عبدالكریم',
  'national_id' => '073****269',     // ماسک‌شده، عیناً
  'mobile' => null,                   // خالی در upstream → null → UI «—»
  'issuing_authority' => '...',
  'supervisory_authorities' => '...',
  'tracking_code' => 'I17981871',
  'isic_code' => '131202',
  'isic_title' => '...',
  'issuance_type' => '...',
  'issue_date' => '2026-...',         // responded_at (Gregorian) → UI به جلالی
  'expiry_date' => '2027-...' | 'دائمی',  // 0000-00-00 = دائمی
  'license_title' => '...',
  'business_province' => 'خراسان رضوی',
  'business_township' => 'تربت جام',
  'postal_code' => '9571968***',      // ماسک‌شده
  'business_address' => '<full address>',
  'business_history' => [
    ['index'=>'1','operation'=>'صدور مجوز','reasons'=>'','date'=>'1405-08-17', ...]
  ],
  'status' => ['status_id'=>'...','status_title'=>'معتبر','status_slug'=>'active'],
  'specialized_items' => [ ['label'=>'...','value'=>'...'], ... ],  // از advanced-data
]
```

### ۱۲.۵ تصمیمات کلیدی درباره‌ی ماسک‌شدگی (مهم برای ادغام!)

> **بینش کلیدی برای ادغام (مکالمه #020):** ماسک‌های scraper (`****` در کد ملی، `*****` در نشانی) «از دست‌رفته» نیستند — **لنگر تأیید** هستند. بخش پنهان `{...}` دقیقاً همان چیزی است که پروژه‌ی مقصد هنگام ثبت درخواست ذخیره کرده. پس تطبیق = مقدار کامل پروژه‌ی مقصد باید با الگوی ماسک هم‌راستا باشد (لنگرهای نمایان در جای خود، جایگاه‌های `*` با کاراکترهای مقصد پر شوند).

این موضوع در بخش ۲۰ و ۲۲ (تطبیق الگویی) به‌طور کامل بررسی می‌شود.

### ۱۲.۶ تست رگرسیون

اسکریپت `scripts/test_track_scraper.py` (پورت پایتونی منطق scraper) با **۱۵ assertion قفل‌شده** روی مقادیر fixture واقعی (`tests/fixtures/har/qr.mojavez.ir-track-I17981871.har`) به‌عنوان دروازه‌ی رگرسیون نگه‌داری می‌شود. هر تغییر scraper باید قبل از کامیت با این تست تأیید شود (نتیجه‌ی رفع خطای ۰۰۶-۰۱).

> **⚠️ شکنندگی (R6):** اگر سایت ملی فرمت RSC خود را تغییر دهد، scraper می‌شکند. این شکنندگی بزرگ‌ترین ریسک فنی است و باید با تست رگرسیون و در صورت امکان مانیتورینگ پوشش داده شود.

---

## ۱۳. صف وظایف، رویدادها و SSE

### ۱۳.۱ EventBus (Observer)

`app/Events/EventBus.php` یک EventBus درون‌حافظه‌ای است که رویدادها را در جدول `events` (SQLite) persist می‌کند تا SSE بتواند آنها را replay کند.

**انواع رویداد** (۷ event در `app/Events/Events/`):
- `SearchRequested` — کاربر جستجویی شروع کرد
- `CrawlerProgress` — پیشرفت crawl
- `SearchCompleted` — جستجو کامل شد
- `LicenseSaved` — یک مجوز در کش ذخیره شد
- `JobFailed` — یک job شکست خورد
- `BatchProgress` — پیشرفت دسته
- `CacheHit` — پاسخ از کش

**Subscriberها:** `LogSubscriber` (لاگ همه‌ی رویدادها). در طراحی `SseSubscriber` و `CacheInvalidator` هم پیش‌بینی شده‌اند.

> **نکته‌ی پایداری (خطای 004-04):** متدهای `eventsSince()` و `lastEventId()` در `try-catch` پیچیده شده‌اند تا اگر جدول `events` ناموجود بود (قبل از migration)، به‌جای crash، آرایه‌ی خالی برگردانند.

### ۱۳.۲ JobQueue (Command)

`app/Queue/JobQueue.php` صفی مبتنی بر SQLite است. `JobInterface` یک متد `execute()` دارد. jobهای موجود:
- `Jobs/SearchJob` — اجرای یک جستجوی upstream
- `Jobs/BatchJob` — پردازش یک ردیف از دسته‌ی CSV

**Worker** (`app/Queue/Worker.php`): دوره‌ای `JobRepository::pickNext()` را صدا می‌زند و job را اجرا می‌کند. در طراحی از **Fiber** برای cooperative scheduling استفاده می‌شد، اما چون cURL blocking است، Fiber فایده‌ای نمی‌داد و حذف شد (مکالمه #004).

> **نکته‌ی مهم (مکالمه #010):** در عمل، **Worker جداگانه نباید اجرا شود**. الگوی «poor man's cron» به‌کار گرفته شد: `BatchController::status` قبل از برگرداندن وضعیت، `Worker::run(1)` را صدا می‌زند تا با هر poll یک کار پردازش شود (سرور تک‌رشته). این یعنی worker در همان درخواست status poll اجرا می‌شود.

### ۱۳.۳ Worker::executeBatchJob (رفع حلقه‌ی بی‌نهایت)

پس از رفع خطای #010:
- `incrementProgress` (atomic bump `processed_rows`/`matched_rows` + `status='processing'`)
- `appendResult` (افزودن `{request_number, status, message}` به `results_json`)
- `maybeCompleteBatch` (هنگام `processed>=total`، `status='completed'`)
- در مسیر شکست هم همین‌ها صدا زده می‌شوند تا دسته روی یک ردیف ناموفق قفل نشود.

### ۱۳.۴ SSE (Server-Sent Events)

مسیر `GET /api/stream` → `SseController::stream`. در طراحی یک اتصال `text/event-stream` باز نگه داشته می‌شود و رویدادها به مرورگر push می‌شوند.

> **در عمل غیرفعال است** (مکالمه #010). `index.php` به‌جای آن پاسخ `204` می‌فرستد چون PHP built-in server تک‌رشته است و اتصال طولانی همه‌ی درخواست‌های دیگر را در صف می‌گیرد. SSE فقط در محیط production با Apache/PHP-FPM چندپردازشی کار می‌کند. در عمل، پیشرفت از طریق polling انجام می‌شود.

**چرا SSE نه WebSocket؟** WebSocket نیاز به اتصال full-duplex دارد که در هاست اشتراکی با mod_php به سختی پشتیبانی می‌شود. SSE یک اتصال HTTP ساده و یک‌طرفه است که در Apache به‌خوبی کار می‌کند. ماهیت داده یک‌طرفه است (سرور → کلاینت)، پس WebSocket اضافی است.

---

## ۱۴. سرویس‌ها (Domain Logic)

| سرویس | فایل | مسئولیت |
|-------|------|---------|
| **LicenseService** | `app/Services/LicenseService.php` | جستجوی hybrid (cache + upstream)، ذخیره، `getByRequestNumber`, `saveAllResults`, `fetchAndCacheByTrackNumber` |
| **FilterService** | `app/Services/FilterService.php` | CRUD فیلترهای ذخیره‌شده |
| **CsvBatchService** | `app/Services/CsvBatchService.php` | خواندن CSV (ردیف به ردیف با `CsvReader` که BOM را حذف می‌کند)، ساخت BatchJob |
| **CacheService** | `app/Services/CacheService.php` | بررسی HIT/MISS، ذخیره و بازیابی کش |
| **ExportService** | `app/Services/ExportService.php` | خروجی CSV (با BOM برای Excel) و JSON |
| **RateLimiterService** | `app/Services/RateLimiterService.php` | اعمال min delay + jitter Gaussian + burst/cooldown |

### متدهای کلیدی LicenseService

- **`searchHybrid(Filter $filter)`**: ابتدا کش محلی را بررسی می‌کند؛ اگر HIT باشد، از کش می‌خواند. در غیر این صورت upstream را صدا می‌زند، نتایج را در cache ذخیره می‌کند، سپس صفحه‌ی درخواستی را با `LIMIT/OFFSET` از کش می‌خواند. رفع باگ صفحه‌بندی: صفحه‌ی ≥۲ با کش پرشده از upstream صرف‌نظر می‌کند و فقط از کش می‌خواند (نتیجه‌ی رفع خطای pagination در #004).
- **`saveAllResults(Filter $filter)`**: **همه‌ی صفحات upstream** را برای این فیلتر fetch می‌کند (نه فقط صفحه‌ی کش‌شده)، هر آیتم را persist می‌کند، سپس کل مجموعه را `is_saved=1` علامت می‌زند.
- **`fetchAndCacheByTrackNumber($rn)`** (مهم، مکالمه #012): چون upstream از `license_id` پشتیبانی نمی‌کند، این متد از `TrackScraper.fetch($rn, false)` استفاده می‌کند، یک `License::fromTrackPage` می‌سازد و در cache ذخیره می‌کند. مسیر batch برای ردیف‌های با `license_id` از این متد استفاده می‌کند.
- **`searchLocal(array $filter, $page, $pageSize)`**: فقط کش محلی را query می‌کند (بدون upstream).

> **نکته‌ی وابستگی (خطای 003-01):** `LicenseService` نیاز به `JobRepository` و `TrackScraper` دارد اما به دلیل ترتیب ساخت نمی‌تواند از constructor دریافت کند. این وابستگی‌ها با **setter injection** (`setJobRepository()`, تزریق `TrackScraper` در constructor بعد از رفع #012) حل شده‌اند.

---

## ۱۵. جریان داده کامل یک جستجو (صفر تا صد)

این بخش به‌صورت مرحله‌به‌مرحله جریان داده را برای اجرای یک جستجوی نمونه نشان می‌دهد:

**مرحله ۱:** کاربر در مرورگر فرم جستجو را پر می‌کند (مثلاً خراسان رضوی، وزارت میراث فرهنگی، بازه ۱۴۰۰ تا ۱۴۰۵) و دکمه جستجو را می‌زند. `public/assets/js/app.js` درخواست را به‌صورت AJAX به `POST /api/licenses/search` می‌فرستد.

**مرحله ۲:** `index.php` (Front Controller) درخواست را دریافت می‌کند: بررسی نصب (وجود DB و جدول migrations) → ساخت `Container` و `boot` → ساخت `Request` → بررسی غیرفعال بودن `/api/stream` → ساخت `Router` و بارگذاری `config/routes.php` → dispatch.

**مرحله ۳:** `Router` درخواست را به `LicenseController::search()` هدایت می‌کند (با `LicenseService`, `ExportService`, `LicenseRepository`, `TrackScraper` تزریق‌شده از Container).

**مرحله ۴:** کنترلر `Filter::fromArray($request->allInput())` می‌سازد. اگر `isEmpty()` باشد، بدون upstream پاسخ خالی برمی‌گرداند.

**مرحله ۵:** `LicenseService::searchHybrid($filter)` صدا زده می‌شود:
1. `cacheKey()` محاسبه می‌شود.
2. `CacheService::get(hash)` بررسی می‌شود. اگر HIT → پاسخ از کش (هیچ درخواست upstream).
3. در صورت MISS: `MojavecClient::search($filter, $rateLimiter)`:
   - `Filter::toUpstreamPayload()` تاریخ‌های شمسی را به میلادی تبدیل می‌کند.
   - `doWithRetry()` درخواست POST به `https://qr.mojavez.ir/api/v1/licenses/search` را با هدرهای `AntiDetectionMiddleware` و retry/bounded timeout ارسال می‌کند.
   - پاسخ با `JsonSearchParser::parseSearch()` parse می‌شود.
   - هر ردیف با `License::fromUpstream()` به Domain Object تبدیل می‌شود.
   - اگر صفحه‌ی برگشتی پر باشد (== pageSize)، یک درخواست `count()` جداگانه برای total می‌فرستد.
4. نتایج در `LicenseRepository::saveMany()` در SQLite ذخیره می‌شوند.
5. صفحه‌ی درخواستی با `LIMIT/OFFSET` از کش محلی خوانده می‌شود.

**مرحله ۶:** کنترلر با `enrichWithSavedFlag()` به هر آیتم فیلد `is_saved` را اضافه می‌کند (یک bulk query `IN`، نه N query).

**مرحله ۷:** پاسخ paginated JSON به مرورگر برمی‌گردد. کاربر نتایج را به‌صورت کارت یا جدول می‌بیند.

**مرحله ۸ (صفحه‌بندی):** کاربر روی صفحه‌ی ۲ کلیک می‌کند. `searchHybrid` چون کش محلی پرشده است، **بدون upstream** صفحه‌ی ۲ را از کش می‌خواند (رفتار صحیح پس از رفع باگ صفحه‌بندی #004).

**مرحله ۹ (جزئیات):** کاربر روی یک مجوز کلیک می‌کند → `/license/{request_number}`. `HomeController::licenseDetail` صفحه‌ی مستقل را رندر می‌کند. `license-detail.js`:
1. فیلدهای پایه را از `GET /api/licenses/{rn}` (کش) پر می‌کند.
2. به‌صورت async `GET /api/licenses/{rn}/track` را صدا می‌زند → `TrackScraper::fetch($rn)` صفحه‌ی `https://qr.mojavez.ir/track/{rn}` را fetch و parse می‌کند و فیلدهای تفصیلی (نام پدر، کدملی ماسک‌شده، نشانی، تاریخ صدور/انقضا، سوابق، اقلام تخصصی) را برمی‌گرداند.
3. QR را با `qrcode-vendor.js` تولید می‌کند.
4. تصویر متقاضی را از `GET /api/proxy-image?url=...` (re-serve هم‌مبدا) بارگذاری می‌کند.

**مرحله ۱۰ (PDF):** کاربر روی «دانلود PDF» کلیک می‌کند → `license-detail.js::downloadPdf()`:
1. `Promise.race([Detail.trackPromise, 10s])` را await می‌کند (تضمین پر شدن فیلدهای تفصیلی).
2. کلون `.app-main--detail` می‌سازد، المان‌های پنهان در print را حذف می‌کند.
3. تصویر آواتار را از `/api/proxy-image` fetch → blob → dataURL جایگزین می‌کند (canvas taint نشود).
4. `wrap.classList.add('is-print-light')` تم روشن را اعمال می‌کند (مستقل از تم OS).
5. `html2canvas` (scale:2) → `jsPDF('p','mm','a3')` با fit-to-paper و centered.
6. `pdf.save('license-{rn}.pdf')` (بدون دیالوگ، نام پیش‌فرض).

**مرحله ۱۱ (دسته‌ای):** کاربر یک فایل CSV در `/batch` آپلود می‌کند → `POST /api/batch/upload` → `BatchController::upload` → `CsvBatchService` فایل را ذخیره، یک رکورد `batches` و N رکورد `jobs` می‌سازد. کاربر پیش‌نمایش می‌بیند، تأیید می‌کند (گیت `window.confirm`)، صف شروع می‌شود.

**مرحله ۱۲ (پردازش دسته):** `batch.js` polling می‌کند (`GET /api/batch/{id}/status`). هر poll در `BatchController::status` ابتدا `Worker::run(1)` را صدا می‌زند (poor man's cron). `Worker::executeBatchJob`:
- اگر ردیف `license_id` دارد → `LicenseService::fetchAndCacheByTrackNumber($rn)` (مسیر track، چون upstream از `license_id` پشتیبانی نمی‌کند).
- در غیر این صورت → `searchAndCache` با فیلتر name/province/org.
- `incrementProgress` + `appendResult` + `maybeCompleteBatch`.
- polling با `b.status==='completed'|'failed'` پایان می‌یابد (نه با pct).

---

## ۱۶. نصب و استقرار (Dev / Staging / Production)

### ۱۶.۱ نصب لوکال (Development)

```bash
git clone https://github.com/andrbi108/mojavez-gateway.git  # دسترسی با توکن ارائه‌شده توسط تیم توسعه
cd mojavez-gateway
cp .env.example .env      # ویرایش مقادیر
php -v                    # باید 8.2+
php -m | grep -E 'curl|pdo_sqlite|mbstring|json'
php public/install.php    # بررسی پیش‌نیازها + migration + ساخت admin
php scripts/run_worker.php &   # (اختیاری در عمل — poor man's cron کافی است)
php -S localhost:8000 -t public
# مرورگر: http://localhost:8000
```

### ۱۶.۲ نصب روی هاست اشتراکی cPanel (Production)

1. **بارگذاری فایل‌ها** (به‌جز `tests/`, `scripts/` اختیاری, `.git/`).
2. **تنظیم document root** روی `public/`. اگر ممکن نیست: انتقال محتوای `public/` به `public_html/` و تنظیم `storage_path` به مسیر خارج از document root، یا `.htaccess` در `public_html` برای هدایت.
3. **دسترسی‌ها (Permissions):**

| مسیر | دسترسی |
|------|--------|
| `public/`, `app/`, `config/` | 755 |
| `storage/` و زیرپوشه‌ها | 775 |
| فایل‌های PHP | 644 |
| `storage/app.db` | 660 یا 666 |

4. **تنظیمات environment:** `APP_ENV=production`, `APP_DEBUG=false`. اگر `.env` پشتیبانی نمی‌شود، در `config/app.php` مقادیر را مستقیم تنظیم کنید.
5. **اجرای نصب:** مرورگر به `https://your-domain/install.php`. بررسی پیش‌نیازها + migration + ساخت admin با رمز تصادفی (رمز را ذخیره کنید).
6. **حذف `install.php`** پس از نصب موفق (`rm public/install.php`).
7. **cron یا Poor Man's Cron:**
   - اگر cron در دسترس است:
     ```bash
     * * * * * /usr/local/bin/php /home/username/mojavez-gateway/scripts/run_worker.php --max-jobs=5 >> /home/username/mojavez-gateway/storage/logs/worker.log 2>&1
     ```
   - اگر نه: Poor Man's Cron با هر بازدید صفحه‌ی ادمین یک batch کوچک از صف را اجرا می‌کند.

### ۱۶.۳ به‌روزرسانی

```bash
cp storage/app.db storage/app.db.backup.$(date +%Y%m%d)   # پشتیبان‌گیری
# جایگزینی فایل‌ها (به‌جز config/app.php و storage/)
php scripts/migrate.php    # migration جدید در صورت وجود
rm -rf storage/cache/*     # پاک‌سازی کش در صورت نیاز
```

### ۱۶.۴ monitoring

- لاگ‌ها در `storage/logs/app.log` (با چرخش روزانه).
- صفحه‌ی `/admin/status`: تعداد jobهای در صف، آخرین زمان اجرای Worker، آخرین crawl موفق، تعداد خطاها در ۱/۲۴ ساعت، حجم DB و فضای باقی‌مانده، وضعیت اتصال به upstream.
- هشدار دستی: اگر خطاها در یک ساعت از آستانه (پیش‌فرض ۱۰) بیشتر باشد، در dashboard برجسته نمایش داده می‌شود.

### ۱۶.۵ محیط staging (لوکال با کانفیگ production)

برای کشف خطاهایی که فقط در حالت production ظاهر می‌شوند (خاموش بودن `display_errors`):
```php
// config/app.php
'env' => 'production', 'debug' => false,
```
```ini
display_errors = Off
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
log_errors = On
```

> در staging روی لوکال خارج از ایران، crawl واقعی با timeout مواجه می‌شود (مورد انتظار). باید با mock یا داده‌ی seed شده تست شود.

---

## ۱۷. امنیت و ملاحظات حقوقی

### ۱۷.۱ امنیت داخلی (طراحی‌شده)

| حوزه | پیاده‌سازی |
|------|------------|
| SQL Injection | ۱۰۰٪ prepared statements با PDO |
| XSS | escape تمام خروجی‌های HTML با `htmlspecialchars` |
| CSRF | token در فرم‌ها و اعتبارسنجی در endpointهای POST ⚠️ (اما فعلاً غیرفعال — مشکل S1) |
| Session | `cookie_httponly`, `cookie_secure`, `use_strict_mode` |
| File Upload | اعتبارسنجی MIME، size، extension |
| Input Validation | whitelist validator به‌جای blacklist |
| Error display | خاموش در production، لاگ به فایل |

### ۱۷.۲ ملاحظات حقوقی

داده‌های qr.mojavez.ir **عمومی** هستند (ماده ۷ قانون اجرای سیاست‌های اصل ۴۴). اما crawl با حجم بالا ممکن است با ToS سایت در تضاد باشد. این پروژه برای استفاده‌ی شخصی و تحقیقاتی طراحی شده. در صورت استفاده‌ی تجاری، کاربر باید مجوزهای لازم را اخذ کند. در صورت درخواست سایت هدف برای توقف، کاربر باید فوراً متوقف کند.

> راهبرد ضد شناسایی این پروژه با spoofing مخرب تفاوت اساسی دارد: هدف آن احترام به منابع سایت است، نه استثمار آن (جزئیات در بخش ۱۱.۵).

### ۱۷.۳ توصیه‌ی امنیتی مهم

> **توصیه (مکالمه #021):** توکن GitHub را هرگز در فایل‌های کامیت‌شده یا `.git/config` ذخیره نکنید. اگر قبلاً لو رفته، آن را **rotate** کنید. این پروژه در history خود یک بار توکن را در یک سند مستندات جاسازی کرده بود (در مکالمه #021 شناسایی و پاک شد، اما در git history باقی می‌ماند).

---

## ۱۸. سابقه خطاها و رفع‌ها (۲۱ مکالمه، ۱۶ خطای ثبت‌شده)

این بخش **مهم‌ترین** بخش برای پروژه‌ی مقصد است: خطاهایی که قبلاً رخ داده‌اند، علت ریشه‌ای، راهکار و استاندارد جلوگیری از تکرار. همه‌ی خطاها شناسه‌ی یکتا `MMM-NN` دارند.

### ۱۸.۱ دسته‌بندی خطاها بر اساس علت ریشه‌ای

- **دسته‌ی اول — محدودیت‌های محیط شبکه:** 001-01, 002-02
- **دسته‌ی دوم — محدودیت‌های محیط اجرا:** 002-01
- **دسته‌ی سوم — محدودیت‌های ابزار تحلیلی:** 001-02
- **دسته‌ی چهارم — وابستگی‌های چرخه‌ای معماری:** 003-01, 003-02
- **دسته‌ی پنجم — خطاهای پیاده‌سازی runtime (که php-parser نمی‌بیند):** 004-01 تا 004-07, 006-01
- **دسته‌ی ششم — خطاهای scraper بر اساس حدس به‌جای داده‌ی واقعی:** 005-01, 005-02

### ۱۸.۲ جدول کامل خطاها

| شناسه | مکالمه | عنوان کوتاه | وضعیت | اثر / راهکار |
|-------|--------|-------------|-------|--------------|
| **001-01** | #001 | عدم دسترسی sandbox به qr.mojavez.ir (geofence) | رفع با راهکار | تست زنده crawl به محیط لوکال کاربر داخل ایران موکول شد؛ mock HTTP در sandbox |
| **001-02** | #001 | WebFetch قادر به اجرای JavaScript نبود | رفع با راهکار | استفاده از فایل HAR کاربر به‌عنوان منبع کشف endpoint |
| **002-01** | #002 | نصب نبودن PHP در sandbox | رفع با راهکار | استفاده از بسته‌ی npm `php-parser` برای اعتبارسنجی syntax |
| **002-02** | #002 | محدودیت rate limit در GitHub API بدون توکن | رفع با راهکار | استفاده از توکن دسترسی شخصی کاربر (PAT) |
| **003-01** | #003 | وابستگی‌های چرخه‌ای در DI Container | رفع با راهکار | الگوی **setter injection** برای سرویس‌های دارای چرخه |
| **003-02** | #003 | نقض UNIQUE constraint در تست worker_state | رفع با راهکار | استاندارد تست: SELECT به‌جای INSERT برای verify داده‌های اولیه‌ی migration |
| **004-01** | #004 | فراخوانی متد ناموجود `Environment::isDebug()` | رفع با کد | متد `isDebug()` به Environment اضافه شد |
| **004-02** | #004 | دسترسی به property خصوصی `HttpClient::antiDetection` | رفع با کد | dead code حذف شد |
| **004-03** | #004 | نبود Logger به‌عنوان singleton در Container | رفع با کد | Environment و Logger به‌عنوان singleton ثبت شدند |
| **004-04** | #004 | نبود install check در front controller | رفع با کد | try-catch در EventBus + redirect به install.php در index.php |
| **004-05** | #004 | Connection به‌عنوان factory نه singleton | رفع با کد | Connection به **memoized singleton** تغییر یافت |
| **004-06** | #004 | Router regex نامعتبر برای الگوی ریشه `/` | رفع با کد | `/?` در انتهای همه‌ی regex‌ها |
| **004-07** | #004 | JobQueue دریافت Connection به‌جای JobRepository | رفع با کد | factory اصلاح به `JobRepository::class` |
| **005-01** | #005 | TrackScraper هیچ فیلدی استخراج نمی‌کرد | رفع با کد | بازنویسی بر اساس **RSC payload واقعی** + اسکریپت تست رگرسیون |
| **005-02** | #005 | کدملی خالی با وجود وجود داده | رفع با کد | `extractCellValue()` مدیریت آرایه‌ی رشته‌ای خام را اضافه کرد |
| **006-01** | #006 | `ArgumentCountError` از `preg_match` با ۶ آرگومان | رفع با کد | حذف آرگومان ۶ام + پنجره‌ی ۶۰۰-کاراکتری با `substr` |

### ۱۸.۳ خطاهای کلیدی به‌تفصیل (برای درس‌آموخته‌ها)

#### خطای ۰۰۶-۰۱: ArgumentCountError از preg_match ۶-آرگومانی (قوی‌ترین نمونه‌ی دسته‌ی پنجم)

- **علت:** در `TrackScraper.php` خط ۲۲۴، `preg_match($pattern, $after, $mm, 0, 0, 600)` با ۶ آرگومان فراخوانی می‌شد. آرگومان ششم (۶۰۰) به‌عنوان «حد جستجو» فرض شده بود، اما `preg_match` نهایتاً ۵ آرگومان می‌پذیرد. توسعه‌دهنده گمان کرد `preg_match` پارامتر `limit` دارد.
- **اثبات:** سند رسمی PHP (migration80.incompatible): «Passing the wrong number of arguments to a non-variadic built-in function will throw an ArgumentCountError». پورت پایتونی منطق scraper روی fixture واقعی درست کار می‌کرد (۱۵/۱۵ PASS)، چون پایتون معادل آرگومان اضافی ندارد. اما در PHP 8 فوراً استثنا انداخت و چون در `try/catch(\Throwable)` آرام شد، تنها علامت ظاهری‌اش **خالی ماندن همه‌ی فیلدهای scraper** در تست زنده بود.
- **راهکار:** حذف آرگومان ۶ام و پیاده‌سازی پنجره‌ی ۶۰۰-کاراکتری با `substr($blob, $at + strlen($needle), 600)` سپس `preg_match($pattern, $after, $mm)` با ۳ آرگومان.
- **درس‌آموخته:** **اعتبارسنجی syntax (php-parser) کافی نیست.** نیاز به PHPStan یا اجرای واقعی PHP برای کشف خطاهای شمارش آرگومان/نوع ضروری است. افزودن PHPStan به CI اولویت بالایی دارد.

#### خطای ۰۰۳-۰۱: وابستگی‌های چرخه‌ای در DI Container

- **علت:** `LicenseService` نیاز به `JobRepository` داشت، `CsvBatchService` به `BatchRepository`، و `EventBus` به `Connection` — اما به دلیل ترتیب ساخت نمی‌توانستند از constructor دریافت کنند.
- **راهکار:** سه اصلاح: (۱) ثبت `BatchRepository` در `registerPersistence`. (۲) تزریق `Connection` به `EventBus` در `registerEvents`. (۳) تبدیل factoryهای `LicenseService` و `CsvBatchService` از arrow function به closure که پس از ساخت instance، متدهای `setJobRepository()` و `setBatchRepository()` را فراخوانی می‌کند.
- **استاندارد پروژه:** هر سرویسی که به repository وابسته است اما به دلیل ترتیب ساخت نمی‌تواند از constructor دریافت کند، باید یک setter method `public function setXRepository(X $repo): void` داشته باشد.

#### خطای ۰۰۴-۰۵: Connection به‌عنوان factory (چندین PDO connection)

- **علت:** `Connection` در Container به‌عنوان factory ثبت شده بود. هر بار که یک repository ساخته می‌شد، یک `Connection` جدید با PDO جدید ایجاد می‌شد → هدر منابع، عدم امکان transaction بین repositoryها، تداخل قفل SQLite.
- **راهکار:** factory به **memoized singleton** با `static $instance` در closure تغییر یافت.
- **استاندارد:** هر کلاسی که منبع خارجی نگه می‌دارد (PDO، file handle، اتصال شبکه)، باید singleton در Container باشد.

#### خطای ۰۰۵-۰۱ و ۰۰۵-۰۲: scraper بر اساس حدس به‌جای داده‌ی واقعی

- **علت:** نسخه‌ی قبلی TrackScraper نام کلیدهای فیلد را **حدس** می‌زد (مثلاً `father_name`, `national_id`) بدون دانستن ساختار واقعی صفحه‌ی upstream. هیچ منبع داده‌ی واقعی برای verify وجود نداشت، پس کد اشتباه بدون شکستن چیزی کامپایل می‌شد اما در عمل هیچ‌چیز استخراج نمی‌کرد.
- **راهکار:** بازنویسی کامل با تحلیل HAR واقعی از صفحه‌ی `/track/{rn}`.
- **استاندارد:** هر scraper upstream باید قبل از پیاده‌سازی با یک HAR واقعی از همان صفحه verify شود. اسکریپت تست رگرسیون نگه‌داری شود.

#### خطای ۰۰۴-۰۶: Router regex نامعتبر برای `/`

- **علت:** `explode('/', '/')` آرایه‌ی `['', '']` تولید می‌کرد. سپس `str_ends_with('/', '/')` برقرار بود و `?` به regex اضافه می‌شد → `#^?$#` که در آن `?` یک quantifier روی `^` است (غیرقابل تکرار) → regex در PHP compile نمی‌شد.
- **راهکار:** حذف منطق `str_ends_with + ?`. به‌جای آن، `/?` در انتهای همه‌ی regex‌ها اضافه می‌شود.
- **استاندارد:** هنگام ساخت regex از داده‌های ورودی، edge case‌های خالی (`''`, `'/'`, `'//'`) را جداگانه تست کنید.

### ۱۸.۴ مکالمات ۱۳ تا ۲۱ (UI و نقشه‌ی ادغام)

مکالمات #۰۰۷ تا #۰۱۵ به‌طور عمده **بهبود UI صفحه‌ی جزئیات** هستند (avatar ۱۴۰×۱۶۰، direction LTR برای کدهای ماسک‌شده، روزهای باقی‌مانده‌ی اعتبار، فونت Vazirmatn، دانلود PDF، اقلام تخصصی، ادغام فیلدهای تکراری). مهم‌ترین‌ها:

- **#008:** فعال‌سازی واقعی فونت Vazirmatn (self-host به‌جای CDN، چون Google Fonts در ایران مسدود است).
- **#009:** دانلود PDF با html2canvas+jsPDF (سمت کلاینت، چون Composer ممنوب است). قالب نمونه‌ی CSV و گیت تأیید batch.
- **#010:** رفع **حلقه‌ی بی‌نهایت polling دسته** (الگوی poor man's cron) + PDF تک‌صفحه‌ی A3 با تصویر متقاضی + پروکسی تصویر.
- **#011:** رفع `jalali is not defined` در تاریخچه‌ی دسته + (بعداً اصلاح شد) نرمال‌سازی پیشوند I.
- **#012:** کشف حیاتی: **upstream از `license_id` پشتیبانی نمی‌کند** → مسیر batch به `fetchAndCacheByTrackNumber` تغییر کرد. افزودن اقلام تخصصی.
- **#016/#017/#018/#019/#020/#021:** تحلیل جامع، مقایسه با Mojavez-v2، نقشه‌ی وابستگی کد با graphify، چارچوب کارگران ادغام، حل ریسک تطبیق الگویی، و handoff برای مکالمه‌ی جدید.

---

## ۱۹. مشکلات امنیتی شناخته‌شده (مهم هنگام انتقال)

این مشکلات در پروژه‌ی gateway شناسایی شده‌اند. چون قرار است gateway در نهایت کنار گذاشته شود، لازم نیست خودِ gateway تعمیر شود — فقط کدی که به پروژه‌ی مقصد منتقل می‌شود (یعنی scraper) باید تمیز و امن شود.

| شناسه | مشکل | شدت | راهکار هنگام انتقال |
|-------|------|-----|---------------------|
| **S1** | CSRF کاملاً غیرفعال است — هیچ کنترلی `verifyCsrf` را صدا نمی‌زند | بحرانی | در پروژه‌ی مقصد، CSRF را فعال کنید (v2 این کار را می‌کند) |
| **S2** | هیچ احراز هویتی روی ۹ endpoint `/api/admin/*` نیست | بحرانی | در پروژه‌ی مقصد، احراز هویت و نقش اعمال کنید |
| **S3** | رمز admin پیش‌فرض `admin` است | بالا | در production حتماً hash تولید کنید |
| **S4** | SSRF در `/api/proxy-image`: `CURLOPT_SSL_VERIFYPEER=false`، `FOLLOWLOCATION=true`، `CORS *` | بالا | TLS را روشن کنید، redirect را محدود کنید، CORS را محدود کنید |
| **S5** | `storage/` فقط با `.htaccess` محافظت می‌شود | بالا | document root را روی `public/` تنظیم کنید (نه پوشه‌ی اصلی) |
| **ST1** | Rate limiter کد مرده است — `MojavecClient::doWithRetry` عملاً `$rateLimiter` را پاس نمی‌کند | بالا | هنگام انتقال، rate limiter را **واقعاً فعال** کنید (این مهم‌ترین سخت‌سازی است) |
| **I1** | فیلدهای track در cache پایدار نیستند (ممکن است در re-cache از دست بروند) | متوسط | جدول جداگانه برای track data در پروژه‌ی مقصد |

### تدابیر سخت‌سازی هنگام انتقال (طبق نقشه‌ی راه #017)

1. **مکث بین درخواست‌ها (rate limit) را واقعاً فعال کنید** (الان کدش هست ولی بلااستفاده — ST1).
2. **تأیید گواهی SSL را روشن کنید** و ردیابی redirect را امن کنید (S4).
3. **تست scraper را هم با خودش ببرید** تا نشکند (R6).

---

## ۲۰. نقاط اتصال برای ادغام در پروژه دیگر

این بخش **مرکزی‌ترین** بخش برای هدف کاربر است: آنچه پروژه‌ی مقصد باید بداند برای ادغام.

### ۲۰.۱ چه چیزی ارزش انتقال دارد؟

شش چیز مفید در gateway وجود دارد که پروژه‌ی مقصد (احتمالاً یک سامانه‌ی کامل از ثبت تا صدور) ندارد:

1. **خواننده‌ی سایت ملی (scraper):** `MojavecClient` (جستجوی structured) و `TrackScraper` (جزئیات تفصیلی از صفحه‌ی track).
2. **محافظت در برابر مسدود شدن:** `AntiDetectionMiddleware` (هدرهای ثابت، User-Agent، jitter).
3. **خبررسانی زنده (SSE):** EventBus + JobQueue + Worker (هرچند فعلاً polling جایگزین آن شده).
4. **صف کار:** `JobQueue` و `Worker` مبتنی بر SQLite.
5. **صفحه‌ی جزئیات مجوز:** `/license/{id}` با QR، عکس متقاضی، و خروجی PDF (html2canvas + jsPDF).
6. **جستجوی چندوجهی:** فیلتر بر اساس استان، شهر، سازمان، و تاریخ.

### ۲۰.۲ کلید تطبیق (مهم‌ترین تصمیم فنی برای «اتصال طلایی»)

> **بینش کلیدی (مکالمه #020):** ماسک‌های scraper (`****` در کد ملی، `*****` در نشانی) «از دست‌رفته» نیستند — **لنگر تأیید** هستند. بخش پنهان `{...}` دقیقاً همان چیزی است که پروژه‌ی مقصد هنگام ثبت درخواست ذخیره کرده.

**الگوریتم تطبیق الگویی** (پیاده‌سازی مرجع در `docs/workers/PATTERN_MATCHING.md` و `scripts/pattern_matcher.py`):

دو نوع فیلد:
- **رقمی** (مثل کد ملی `073****269`): هر `*` = یک رقم. تطبیق موقعیت‌به‌موقعیت + بررسی طول. مثلاً `072****107` ← الگوی `072{0593}107` ← مقدار کامل `0720593107`.
- **متنی** (مثل نشانی `... *****`): `*` به‌عنوان glob wildcard، anchors به‌ترتیب.

نرمال‌سازی: ارقام فارسی/عربی به لاتین قبل از مقایسه.

**سطوح اطمینان (آرای چندفیلدی):**
- کد ملی MATCH + ≥۲ فیلد → **تأیید خودکار**
- ۱–۲ فیلد → **پرچم اپراتور** (مرور دستی)
- لنگر mismatch کد ملی (ارقام آشکار هم‌راستا نیستند) → **ردِ خودکار** (شخص متفاوت)

> `request_number` یکی از سیگنال‌هاست، نه تنها سیگنال. آرای چندفیلدی (کد ملی + نام + نام‌خانوادگی + نام‌پدر + استان + شهرستان + شهر + عنوان + نشانی) الزامی است چون جایگاه‌های ماسک‌شده wildcard‌اند و تک‌فیلد سیگنال ضعیف است.

### ۲۰.۳ «اتصال طلایی» (ارزش اصلی کل ادغام)

پس از انتقال scraper به پروژه‌ی مقصد:

1. scraper هر از گاهی سایت ملی را می‌خواند (با rate limit واقعاً فعال).
2. مجوز صدورشده را پیدا می‌کند.
3. با **تطبیق الگویی + آرای چندفیلدی**، آن را به متقاضی پروژه‌ی مقصد وصل می‌کند (کلید تطبیق = ماسک به‌عنوان لنگر).
4. وضعیت درخواست آن متقاضی را خودکار به «صدور شده» تغییر می‌دهد.
5. به متقاضی پیام می‌دهد (SMS/notif).

این حلقه‌ای است که هیچ‌یک از دو پروژه به‌تنهایی نمی‌توانستند ببندند: پروژه‌ی مقصد می‌داند متقاضی درخواست داده ولی نمی‌داند چه زمانی مجوزش صادر شده؛ gateway می‌داند چه مجوزهایی صادر شده ولی هیچ اطلاعی از متقاضی‌های پروژه‌ی مقصد ندارد.

### ۲۰.۴ فایل‌های کلیدی برای انتقال

| فایل | نقش | وابستگی‌های ورودی |
|------|------|-------------------|
| `app/Infrastructure/MojavecClient.php` | کلاینت جستجوی structured | HttpClient, ResponseParserInterface, Filter, License, Logger |
| `app/Infrastructure/TrackScraper.php` | کلاینت صفحه‌ی track (RSC parser) | HttpClient, Logger |
| `app/Infrastructure/HttpClient.php` | cURL wrapper | AntiDetectionMiddleware |
| `app/Infrastructure/AntiDetectionMiddleware.php` | هدرها + rate limit + jitter | Config |
| `app/Infrastructure/Parser/ResponseParserInterface.php` + `JsonSearchParser.php` | Strategy parser | — |
| `app/Domain/License.php` + `Filter.php` + Enums | Value Objects | PersianDate |
| `app/Services/LicenseService.php` | منطق جستجو/کش | MojavecClient, TrackScraper, repositories |
| `app/Services/RateLimiterService.php` | rate limit + jitter | Config |
| `app/Queue/JobQueue.php` + `Worker.php` + `Jobs/` | صف | repositories |
| `app/Events/EventBus.php` + Events/ + Subscribers/ | رویدادها | Connection |
| `app/Support/PersianDate.php` | تبدیل شمسی ↔ میلادی | — |
| `app/Support/Validator.php` | اعتبارسنجی ورودی | — |
| `app/Support/CsvReader.php` | خواندن CSV با حذف BOM | — |
| `scripts/test_track_scraper.py` | **تست رگرسیون scraper** (حتماً منتقل شود) | fixture HAR |

### ۲۰.۵ رابط‌های پایدار (API contract که پروژه‌ی مقصد می‌تواند حفظ کند)

پروژه‌ی مقصد می‌تواند `/api/*` گیت‌وی را به‌عنوان `/api/*` خود نگه دارد. رابط‌های کلیدی:

- `GET /api/licenses?...` → `{items, page, page_size, total, total_pages}` با `License.toArray()` + `is_saved`
- `POST /api/licenses/search` ← body = `Filter` array
- `GET /api/licenses/{rn}/track` → خروجی `TrackScraper::fetch()` (شامل فیلدهای ماسک‌شده)
- `GET /api/licenses/export?format=csv|json`
- `POST /api/batch/upload` (multipart) + `GET /api/batch/{id}/status`

### ۲۰.۶ ناسازگاری‌های احتمالی با پروژه‌ی مقصد

| مورد | gateway | پروژه‌ی مقصد (احتمالاً v2) | راهکار |
|------|---------|------------------------------|--------|
| پایگاه داده | SQLite | MySQL | تبدیل migration به MySQL Dialect؛ فقط Repositoryها تغییر می‌کنند |
| DI | Container دستی autowire | احتمالاً `Database::getInstance()` بدون DI | می‌توان gateway را با DI نگه داشت یا به الگوی مقصد سازگار کرد |
| Frontend | vanilla JS + tokens.css | احتمالاً Bootstrap RTL + VazirMatn | می‌توان فقط منطق بک‌اند را منتقل کرد و UI را با UI مقصد ادغام نمود |
| SSE | طراحی شده ولی فعلاً polling | احتمالاً ندارد | در محیط چندپردازشی می‌توان SSE را فعال کرد |
| زمان‌شماری | شمسی ↔ میلادی با `PersianDate` | احتمالاً دارد | یکتایی به کار بردن `PersianDate` |

---

## ۲۱. نقشه راه ادغام (۶ گام)

این نقشه‌ی راه از `docs/PROJECT_ANALYSIS_AND_ROADMAP.md` (مکالمه‌های #016/#017) است.

### گام ۱ — آماده‌سازی پروژه‌ی مقصد به‌عنوان خانه‌ی نهایی
پروژه‌ی مقصد را روی یک هاست با MySQL راه‌اندازی و مطمئن شوید درست کار می‌کند. این پایه‌ی ادغام است.

### گام ۲ — انتقال خواننده‌ی سایت ملی (scraper) به پروژه‌ی مقصد
کد scraper و محافظت ضد‌شناسایی را از gateway برمی‌داریم و به‌عنوان یک بخش تازه در پروژه‌ی مقصد می‌گذاریم (مثلاً پوشه‌ی `app/Modules/Scraper`). آن را به MySQL وصل می‌کنیم. **مکث بین درخواست‌ها و SSL را درست می‌کنیم** (ST1 و S4). یک جدول تازه در پروژه‌ی مقصد می‌سازیم تا مجوزهای خوانده‌شده را ذخیره کند. تست scraper را هم می‌بریم (R6).

### گام ۳ — انتقال امکانات دیگر gateway به پروژه‌ی مقصد
خبررسانی زنده (SSE)، صف کار، صفحه‌ی جزئیات مجوز، جستجوی چندوجهی، و خروجی CSV را به پروژه‌ی مقصد می‌بریم.

### گام ۴ — مهاجرت داده‌های gateway به پروژه‌ی مقصد
مجوزهایی که gateway تا حالا خوانده و ذخیره کرده را به جدول تازه در پروژه‌ی مقصد منتقل می‌کنیم (طوری که دوباره runnable باشد و چیزی از دست نرود).

### گام ۵ — اتصال طلایی (ارزش اصلی)
حالا که scraper در پروژه‌ی مقصد است: scraper هر از گاهی سایت ملی را می‌خواند، مجوز صدورشده را با **تطبیق الگویی + آرای چندفیلدی** پیدا می‌کند، در پروژه‌ی مقصد وضعیت درخواست آن متقاضی را خودکار به «صدور شده» تغییر می‌دهد، و به او پیام می‌دهد.

### گام ۶ — نهایی‌سازی و کنار گذاشتن gateway
طراحی را یکدست می‌کنیم، تست کامل می‌گیریم، و gateway را آرشیو می‌کنیم (فقط برای مرجع نگه می‌داریم، دیگر توسعه‌اش نمی‌دهیم).

> **نکته:** در مکالمه #021 تصمیم گرفته شد که ادغام در یک مخزن جدید `mojavez-v3` انجام شود (موقعیت مخزن توسط تیم توسعه تعیین می‌شود). ترکیب gateway + v2 در v3.

---

## ۲۲. ریسک‌ها و راه‌حل‌ها

| ریسک | شدت | راه‌حل |
|------|-----|---------|
| **R1: کلید تطبیق `national_code` ماسک‌شده** | بالا (حل‌شده با تطبیق الگویی) | تطبیق الگویی + آرای چندفیلدی طبق `docs/workers/PATTERN_MATCHING.md`. اعتبارسنجی R1 پیش از sync. `request_number` یکی از سیگنال‌هاست، نه تنها سیگنال. |
| **R2: geofence ایران** | بالا | در sandbox از داده‌ی ثبت‌شده‌ی قبلی (HAR) برای تست استفاده می‌کنیم؛ تست زنده در محیط کاربر داخل ایران. |
| **R3: نیاز به MySQL در هاست** | متوسط | قبل از شروع مطمئن شوید هاست MySQL دارد. اگر نه، gateway را به‌عنوان ابزار جدا پشت پروژه‌ی مقصد نگه دارید (نه ادغام کامل). |
| **R4: شکنندگی scraper (R6)** | متوسط | تست scraper را اول و سخت‌گیرانه ببرید تا زود متوجه شکست شوید. fixture HAR و اسکریپت رگرسیون `test_track_scraper.py`. |
| **R5: rate limiter کد مرده (ST1)** | بالا | هنگام انتقال، rate limiter را واقعاً فعال کنید. |
| **R6: شکنندگی TrackScraper RSC** | متوسط | اگر سایت ملی فرمت RSC را عوض کند، scraper می‌شکند. تست رگرسیون + در صورت امکان مانیتورینگ. |

---

## ۲۳. چک‌لیست انتقال/ادغام

### قبل از انتقال
- [ ] پروژه‌ی مقصد روی هاست با MySQL آماده و درست کار می‌کند (گام ۱).
- [ ] هاست داخل ایران است (برای crawl واقعی) یا داده‌ی seed/HAR آماده است.
- [ ] توکن GitHub rotate شده (اگر قبلاً لو رفته).

### هنگام انتقال scraper (گام ۲)
- [ ] `MojavecClient`، `TrackScraper`، `HttpClient`، `AntiDetectionMiddleware`، `Parser/` منتقل شده.
- [ ] `License`، `Filter`، Enums، `PersianDate`، `Validator`، `CsvReader` منتقل شده.
- [ ] migration به MySQL Dialect تبدیل شده (فقط `licenses`, `lookups`, `batches`, `jobs` کافی است).
- [ ] **rate limiter واقعاً فعال شده** (ST1 رفع شده).
- [ ] **SSL verify peer روشن** و redirect محدود (S4 رفع شده).
- [ ] `scripts/test_track_scraper.py` و fixture HAR منتقل شده و PASS.
- [ ] احراز هویت روی endpointهای admin اعمال شده (S2 رفع شده).
- [ ] CSRF فعال (S1 رفع شده).

### هنگام انتقال امکانات (گام ۳)
- [ ] JobQueue + Worker به MySQL منتقل شده.
- [ ] EventBus منتقل شده (یا حذف اگر پروژه‌ی مقصد رویداد دارد).
- [ ] صفحه‌ی جزئیات مجوز + jsPDF + html2canvas + qrcode منتقل شده.
- [ ] `/api/proxy-image` با CORS محدود و TLS روشن.

### اتصال طلایی (گام ۵)
- [ ] کلاس `PatternMatcher` تعریف شده (طبق `docs/workers/PATTERN_MATCHING.md`).
- [ ] تست مثال‌های ماسک‌شده PASS (۴/۴ MATCH).
- [ ] تست کنترل‌های منفی PASS (۴/۴ درست رد).
- [ ] اعتبارسنجی R1 روی داده‌ی واقعی پیش از sync.
- [ ] dispatch رویداد `LicenseSaved` در خطوط ~۱۹۸–۲۰۵ از scraper (طبق worker-F).
- [ ] گردش‌کار «تغییر خودکار وضعیت درخواست به صدور شده» پیاده شده.
- [ ] پیام به متقاضی (SMS/notif) پیاده شده.

### نهایی‌سازی (گام ۶)
- [ ] طراحی یکدست شده (Bootstrap/vanilla + VazirMatn).
- [ ] تست کامل (۱۷۱ تست موجود + تست‌های منتقل‌شده).
- [ ] gateway آرشیو شده.

---

## ۲۴. مرجع فایل‌های کلیدی

| فایل | خطوط | نقش |
|------|------|------|
| `public/index.php` | ~۱۶۷ | Front Controller: autoloader + Container + install-check + Router + error handler |
| `app/Container.php` | — | DI container دستی (autowire + singleton + factory + setter injection) |
| `config/routes.php` | ~۸۰ | تعریف ~۴۰ مسیر |
| `config/anti-detection.php` | ~۵۹ | User-Agent ثابت، هدرها، rate limit، retry، timeout |
| `config/database.php` | ~۲۱ | SQLite + pragmas (WAL, busy_timeout, ...) |
| `config/filters.php` | ~۴۵ | فیلتر پیش‌فرض (میراث فرهنگی + خراسان رضوی + ۱۴۰۰-۱۴۰۵) |
| `app/Domain/License.php` | ~۱۶۷ | Value Object با ۳ factory (fromUpstream, fromTrackPage, fromArray) |
| `app/Domain/Filter.php` | ~۳۰۶ | Value Object + `toUpstreamPayload` (تبدیل شمسی→میلادی) + `cacheKey` |
| `app/Infrastructure/MojavecClient.php` | ~۲۸۰ | کلاینت جستجوی structured + retry + rate-limit hook |
| `app/Infrastructure/TrackScraper.php` | ~۴۸۰ | RSC payload parser (شکننده‌ترین جزء) |
| `app/Infrastructure/AntiDetectionMiddleware.php` | — | هدرهای ثابت + jitter Gaussian |
| `app/Infrastructure/HttpClient.php` | — | cURL نیتیو wrapper + cookie jar |
| `app/Controllers/LicenseController.php` | ~۳۶۲ | API مجوزها (search, save, saveAll, track, show, export) |
| `app/Controllers/BatchController.php` | — | API دسته (upload, status با poor man's cron) |
| `app/Controllers/ProxyController.php` | ~۷۴ | proxy تصویر (SSRF — S4) |
| `app/Services/LicenseService.php` | — | searchHybrid, saveAllResults, fetchAndCacheByTrackNumber |
| `app/Persistence/Migrations/001_init.sql` | ~۱۴۹ | ۹ جدول + ۱۲ ایندکس |
| `app/Persistence/Migrations/002_add_saved_licenses.sql` | ~۱۶ | ستون‌های is_saved + saved_at |
| `scripts/run_worker.php` | — | اجرای Worker (در عمل با poor man's cron جایگزین) |
| `scripts/migrate.php` | — | اجرای migration از CLI |
| `scripts/seed_lookups.php` | — | seed استان‌ها و دستگاه‌ها |
| `scripts/test_track_scraper.py` | — | **تست رگرسیون scraper** (۱۵ assertion) |
| `docs/ARCHITECTURE.md` | — | معماری ترکیبی + الگوهای GoF |
| `docs/API_DISCOVERY.md` | — | مستندات endpointهای upstream از HAR |
| `docs/ANTI_DETECTION.md` | — | راهبرد ضد شناسایی + ملاحظات حقوقی |
| `docs/TROUBLESHOOTING.md` | — | خطاهای رایج و راهکار |
| `docs/DEPLOYMENT.md` | — | استقرار در Dev/Staging/Production |
| `docs/CONVERSATIONS_LOG.md` | ~۹۶۵ | سابقه‌ی ۲۱ مکالمه و ۱۶ خطا |
| `docs/PROJECT_ANALYSIS_AND_ROADMAP.md` | — | نقشه‌ی ادغام با v2 |
| `docs/CODE_DEPENDENCY_MAP.md` | ~۱۰۵۷ | نقشه‌ی وابستگی کد (با graphify) |
| `docs/workers/PATTERN_MATCHING.md` | — | الگوریتم تطبیق الگویی + آرای چندفیلدی |
| `docs/workers/WORKER_FRAMEWORK.md` | — | چارچوب ۷ کارگر برای ادغام |

---

## ۲۵. دستورات مفید

### نصب و اجرا
```bash
php public/install.php              # نصب: بررسی پیش‌نیاز + migration + ساخت admin
php -S localhost:8000 -t public     # وب‌سرور داخلی
php scripts/run_worker.php          # اجرای Worker (در عمل با poor man's cron جایگزین)
php scripts/migrate.php             # اجرای migration از CLI
php scripts/seed_lookups.php        # seed استان‌ها و دستگاه‌ها
```

### بررسی وضعیت و عیب‌یابی
```bash
php -v                              # باید 8.2+
php -m | grep -E 'curl|pdo_sqlite|mbstring|json'   # extensionها
php scripts/run_worker.php --status # وضعیت صف و Worker
php scripts/run_worker.php --verbose# اجرای verbose
ps aux | grep run_worker            # بررسی اجرای Worker
```

### تست
```bash
python3 scripts/test_track_scraper.py    # تست رگرسیون scraper (۱۵ assertion)
node scripts/validate_php.js              # اعتبارسنجی syntax PHP (با php-parser) — sandbox
php tests/run.php                         # test runner سفارشی (بدون PHPUnit)
```

### پایگاه داده
```bash
sqlite3 storage/app.db "SELECT COUNT(*) FROM licenses;"
sqlite3 storage/app.db "DELETE FROM cache;"     # پاک‌سازی کش
sqlite3 storage/app.db "PRAGMA journal_mode=WAL;"
```

### اتصال به upstream (فقط از داخل ایران)
```bash
curl -v -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' \
  -H 'Accept: application/json' \
  https://qr.mojavez.ir/api/v1/auth/me
```

### backup
```bash
cp storage/app.db storage/app.db.backup.$(date +%Y%m%d)
```

---

## ۲۶. واژه‌نامه

| اصطلاح | معنی |
|--------|-------|
| **upstream** | سایت هدف `qr.mojavez.ir` (درگاه ملی مجوزها) |
| **scraper** | کدی که صفحه‌ی upstream را می‌خواند و داده استخراج می‌کند (`MojavecClient` + `TrackScraper`) |
| **RSC payload** | React Server Components flight payload — فرمت داده‌ی inline در صفحات Next.js App Router |
| **geofence** | محدودیت جغرافیایی دسترسی (upstream فقط از داخل ایران) |
| **poor man's cron** | اجرای کار در همان درخواست HTTP (به‌جای cron جداگانه) — الگوی `BatchController::status` → `Worker::run(1)` |
| **langer تأیید (anchor)** | بخش آشکار داده‌ی ماسک‌شده (مثل `073` و `269` در `073****269`) که برای تطبیق الگویی استفاده می‌شود |
| **آرای چندفیلدی (quorum)** | تأیید هویت بر اساس تطبیق هم‌زمان چند فیلد (نه فقط یک فیلد) |
| **WAL** | Write-Ahead Logging — مکانیزم SQLite برای همزمانی خواندن و نوشتن |
| **setter injection** | تزریق وابستگی با متد setter به‌جای constructor (برای وابستگی‌های چرخه‌ای) |
| **memoized singleton** | factory که با `static $instance` فقط یک نمونه می‌سازد |
| **fail fast** | فلسفه‌ی timeout/retry کوتاه تا کاربر نانتظار طولانی نکشد |
| **اتصال طلایی** | اتصال خودکار scraper به گردش‌کار پروژه‌ی مقصد (تشخیص صدور → تغییر وضعیت → پیام) |
| **ST1 / S1-S5 / R1-R6 / I1** | شناسه‌های مشکلات امنیتی، ریسک‌ها و نقاط بهبود در نقشه‌ی راه |
| **MMM-NN** | شناسه‌ی یکتای خطا در `CONVERSATIONS_LOG.md` (MMM = شماره‌ی مکالمه، NN = شماره‌ی ترتیبی خطا) |

---

*پایان مستند. این مستند بر اساس کلون مخزن `mojavez-gateway` در تاریخ ۱۴۰۵/۰۸/۲۶ (۲۰۲۶-۱۱-۱۶) و مطالعه‌ی کامل کد، مستندات، تاریخچه‌ی ۴۹ کامیت و ۲۱ مکالمه‌ی ثبت‌شده تدوین شده است.*
