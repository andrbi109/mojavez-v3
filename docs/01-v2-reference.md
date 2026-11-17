# مستند جامع یکپارچه‌سازی پروژهٔ Mojavez-v2

> **هدف این مستند:** شناخت کامل پروژهٔ **Mojavez-v2** (سامانهٔ مدیریت ثبت‌نام و پیگیری صدور مجوز صنایع دستی و مشاغل خانگی) به‌منظور ادغام آن در یک پروژهٔ همسو که **لایه‌ی واسط اختصاصی، فیلترشده و فقط‌خواندنی روی درگاه ملی مجوزهای کشور `qr.mojavez.ir`** است. این مستند از منبع کد (کامیت پایهٔ `b78adfd`، نسخهٔ `0.6.3`) و تاریخچهٔ کامل ۱۲۳ کامیت استخراج شده و تمام آنچه پروژهٔ یکپارچه‌شونده باید بداند را پوشش می‌دهد.

---

## فهرست

1. [خلاصهٔ اجرایی و تصمیم‌های کلیدی برای یکپارچه‌سازی](#1-خلاصهٔ-اجرایی-و-تصمیمهای-کلیدی-برای-یکپارچهسازی)
2. [مشخصات کلی پروژه](#2-مشخصات-کلیدی-پروژه)
3. [معماری و ساختار پوشه‌ها](#3-معماری-و-ساختار-پوشهها)
4. [پیش‌نیازها، وابستگی‌ها و الزامات سیستم](#4-پیشنیازها-وابستگیها-و-الزامات-سیستم)
5. [پیکربندی: تمام متغیرهای محیطی و ثابت‌ها](#5-پیکربندی-تمام-متغیرهای-محیطی-و-ثابتها)
6. [پایگاه داده: جداول، روابط، انواع داده](#6-پایگاه-داده-جداول-روابط-انواع-داده)
7. [مدل‌های داده (۲۷ کلاس)](#7-مدلهای-داده-27-کلاس)
8. [وضعیت‌های پرونده و گردش کار](#8-وضعیتهای-پرونده-و-گردش-کار)
9. [کد رهگیری: ساختار، تولید، نقش در یکپارچه‌سازی](#9-کد-رهگیری-ساختار-تولید-نقش-در-یکپارچهسازی)
10. [مسیرها (Routes) و APIها — مرجع کامل](#10-مسیرها-routes-و-apiها--مرجع-کامل)
11. [احراز هویت و نقش‌ها](#11-احراز-هویت-و-نقشها)
12. [امنیت: لایه‌به‌لایه](#12-امنیت-لایهبهلایه)
13. [سرویس‌های کلیدی کسب‌وکار](#13-سرویسهای-کلیدی-کسبوکار)
14. [بارگذاری و سرو فایل‌ها (مدارک و تصویر مجوز)](#14-بارگذاری-و-سرو-فایلها-مدارک-و-تصویر-مجوز)
15. [استقرار در محیط‌های مختلف](#15-استقرار-در-محیطهای-مختلف)
16. [سابقهٔ خطاها و رفع‌ها (git log + CHANGELOG)](#16-سابقهٔ-خطاها-و-رفعها-git-log--changelog)
17. [ممیزی امنیتی و رفع‌های بحرانی](#17-ممیزی-امنیتی-و-رفعهای-بحرانی)
18. [نقشهٔ راه و فازهای باقی‌مانده](#18-نقشهٔ-راه-و-فازهای-باقیمانده)
19. [تحلیل استاتیک و تست‌ها](#19-تحلیل-استاتیک-و-تستها)
20. [نقاط یکپارچه‌سازی برای لایهٔ واسط روی درگاه ملی](#20-نقاط-یکپارچهسازی-برای-لایهٔ-واسط-روی-درگاه-ملی)
21. [خطاهای شناخته‌شده، محدودیت‌ها و تله‌ها](#21-خطاهای-شناختهشده-محدودیتها-و-تلهها)
22. [چک‌لیست یکپارچه‌سازی (پیش از ادغام)](#22-چکلیست-یکپارچهسازی-پیش-از-ادغام)
23. [مرجع سریع فایل‌ها و ثابت‌ها](#23-مرجع-سریع-فایلها-و-ثابتها)

---

## 1. خلاصهٔ اجرایی و تصمیم‌های کلیدی برای یکپارچه‌سازی

**Mojavez-v2** یک سامانهٔ مدیریت کامل فرآیند درخواست مجوز (صنایع دستی، مشاغل خانگی مستقل، پشتیبان، گردشگری، هنری) است که با **PHP 8 خالص (بدون فریم‌ورک، بدون Composer)**، معماری MVC دست‌نویس و MySQL/MariaDB پیاده‌سازی شده. این سامانه **خصوصی** است (غیر قابل ایندکس توسط موتورهای جستجو و خزنده‌های هوش مصنوعی) و یک دامنهٔ کامل از ثبت‌نام متقاضی تا صدور/تحویل مجوز را پوشش می‌دهد.

### ۱.۱. آنچه برای لایهٔ واسط `qr.mojavez.ir` اهمیت راهبردی دارد

لایهٔ واسط فیلترشده و فقط‌خواندنی، باید بداند که این سامانه **منبع حقیقت محلی** برای داده‌های مجوز است و درگاه ملی `qr.mojavez.ir` منبع مرجع کشوری. نقاط اتصال کلیدی:

| موضوع | نکتهٔ یکپارچه‌سازی |
|---|---|
| **کلید اصلی اتصال** | `applicants.tracking_code` با قالب `HG-XXXXXXXX` (۱۱ کاراکتر، یکتا، تولیدشده با CSPRNG). این کد باید به کد رهگیری ملی در `qr.mojavez.ir` نگاشت شود. |
| **وضعیت مجوز** | فیلد `applicants.license_state` با ENUM چهار مقداری: `جاری`, `صادر شده`, `باطل شده`, `منقضی`. این دقیقاً معادل حالت‌های درگاه ملی است. |
| **تاریخ انقضای مجوز** | `applicants.license_valid_until` (DATE، شمسی/میلیادی طبق `config/jdf.php`). برای فیلتر «فقط مجوزهای معتبر» در لایهٔ واسط استفاده می‌شود. |
| **کد ملی متقاضی** | `applicants.national_code` (CHAR(10)، با رقم کنترل اعتبارسنجی‌شده توسط `Validator::isValidNationalCode`). لنگر هویتی برای تطبیق با پایگاه ملی. |
| **شناسهٔ ملی حقوقی** | `persons.company_national_id` (CHAR(11)، با الگوریتم رقم کنترل). |
| **رشتهٔ مجوز** | `job_details.license_title` مرتبط با `license_titles.title` که به `service_type_id` اختصاصی شده. لایهٔ واسط باید این نگاشت را با رشته‌های ملی هماهنگ کند. |
| **وضعیت ۱۸گانهٔ پرونده** | `applicants.current_status` — فقط وضعیت‌های `terminal` (`تحویل مجوز`، `تأیید نهایی دریافت کد رهگیری`) به‌معنای مجوز صادرشدهٔ نهایی هستند. |
| **تصویر مجوز** | `license_files` + `license_access_requests` (توکن ۷۲ ساعته، یک‌بار دریافت). تصویر فیزیکی نباید مستقیماً در لایهٔ واسط سرو شود؛ فقط حضور/غیاب و متادیتا. |
| **پایان‌پذیری (idempotency)** | لایهٔ واسط فقط‌خواندنی باید از `updated_at` و `status_log.created_at` برای کش‌باستینگ و polling افزایي استفاده کند. |
| **سلامت سامانه** | `GET /health` بدون احراز هویت وضعیت DB + نسخه را برمی‌گرداند — برای مانیتورینگ لایهٔ واسط ایده‌آل است. |
| **نرخ درخواست** | endpointهای عمومی محدود به `PUBLIC_RATE_LIMIT_PER_MIN=30` درخواست/دقیقه/IP هستند. لایهٔ واسط باید با backoff و caching کار کند. |

### ۱.۲. تصمیمات راهبردی پیشنهادی برای ادغام

1. **لایهٔ واسط مستقل از کد این پروژه باشد.** این پروژه PHP خالص است؛ لایهٔ واسط می‌تواند با هر تکنولوژی باشد، اما **نباید** کد PHP را import کند. فقط از طریق (الف) خواندن مستقیم دیتابیس (read-replica) یا (ب) فراخوانی endpointهای عمومی/JSON موجود یا (ج) ساخت یک endpoint خواندنی اختصاصی در این پروژه که لایهٔ واسط صدا بزند، متصل شود.
2. **هیچ نوشتاری در دیتابیس Mojavez از سوی لایهٔ واسط انجام ندهید.** لایهٔ واسط فقط‌خواندنی است؛ تمام FKها و triggerهای `ON DELETE CASCADE` ممکن است با نوشتن مستقیم آسیب ببینند. در صورت نیاز به sync، از جدول جداگانه در schema جدا استفاده کنید.
3. **نقشهٔ فیلدها:** جدول نگاشت (`mapping table`) بین `tracking_code` محلی و `national_tracking_code` درگاه ملی `qr.mojavez.ir` باید در لایهٔ واسط نگه‌داری شود، نه در دیتابیس Mojavez (تا از تغییر schema منبع پرهیز شود).
4. **احترام به حریم خصوصی:** این سامانه با `X-Robots-Tag: noindex, nofollow, noarchive` و مسدودسازی بات‌ها پیکربندی شده. لایهٔ واسط باید همین سیاست را روی داده‌های فیلترشده اعمال کند و هرگز کل مشخصات هویتی را بدون احراز هویت افشا نکند.
5. **کد ملی و شماره موبایل داده‌های حساس هستند.** لایهٔ واسط نباید آن‌ها را در URL یا logهای عمومی قرار دهد. در صورت نیاز به جستجو با کد ملی، باید از احراز هویت شش‌عاملی (که قبلاً در این پروژه پیاده و سپس به مدل نشست‌محور تغییر یافت) الهام بگیرد.

---

## 2. مشخصات کلیدی پروژه

| مشخصه | مقدار |
|---|---|
| **نام مخزن** | `andrbi109/Mojavez-v2` |
| **نسخهٔ فعلی** | `0.6.3` (طبق `VERSION` و `config/config.php::APP_VERSION`) |
| **نام نسخه** | «سخت‌سازی امنیتی و حریم خصوصی — مسدودسازی موتورهای جستجو و خزنده‌های هوش مصنوعی» |
| **طراح/مالک** | امین دروگر بزدی (`Derogaramin@` در ایتا/روبیکا/بله) |
| **زبان پیاده‌سازی** | PHP 8.0+ خالص (بدون فریم‌ورک، بدون Composer) |
| **پایگاه داده** | MySQL 5.7+ / MariaDB 10.3+ با `utf8mb4_unicode_ci` و InnoDB |
| **نام دیتابیس پیش‌فرض** | `handicraft_license` |
| **معماری** | MVC دست‌نویس (AutoLoader + Router + Controller base + Models + Views) |
| **تعداد کل فایل‌ها** | ۴۱۶ فایل (شامل assets استاتیک PDF.js) |
| **تعداد فایل‌های کد منبع** | ~۱۲۰ فایل PHP/SQL/htaccess |
| **تعداد کنترلرها** | ۲۶ کنترلر |
| **تعداد مدل‌ها** | ۲۷ مدل (+ یک مرجع ثابت `DocTypes`) |
| **تعداد جداول دیتابیس** | ۲۸ جدول |
| **تعداد مسیرها (Routes)** | ~۱۰۹ مسیر (GET + POST) |
| **تعداد وضعیت‌های پرونده** | ۱۸ وضعیت (۱۴ مشترک + ۴ اختصاصی مشاغل خانگی) |
| **تعداد نقش‌ها** | ۳ نقش مدیر + ۱ نقش کاربر متقاضی |
| **تعداد تست‌های smoke** | ۱۷۱ (همگی سبز) |
| **تعداد کامیت‌ها** | ۱۲۳ |
| **تاریخ اولین نسخهٔ رسمی** | ۰.۱.۰ (۱۴۰۴/۰۸/۱۷ ≈ ۲۰۲۶-۱۱-۰۷) |
| **نوع سیستم** | خصوصی (Private) — غیر قابل ایندکس |

### ۲.۱. فناوری‌ها و ابزارها

- **Backend:** PHP 8.0 تا 8.5+ (تست‌شده روی 8.2.34 و 8.5.12)، بدون Composer، بدون وابستگی خارجی PHP.
- **پایگاه داده:** MySQL 5.7+ / MariaDB 10.3+ با PDO + Prepared Statements واقعی (`ATTR_EMULATE_PREPARES=false`).
- **Frontend:** Bootstrap 5 RTL (محلی یا CDN با `USE_CDN`)، فونت وزیرمتن + لاله‌زار، SVGهای درون‌خطی، تم روز/شب.
- **PDF viewer:** PDF.js (محلی، در `public/assets/js/`) برای رندر PDF روی canvas.
- **تقویم:** `config/jdf.php` (تبدیل میلادی↔جلالی، بدون وابستگی).
- **استقرار:** Apache با `mod_rewrite` + `mod_headers`، یا Nginx reverse proxy، یا Docker (PHP 8.2-apache + MariaDB 10.11 + Nginx 1.27)، یا PHP built-in server برای توسعه، یا هاست اشتراکی (DirectAdmin/Nginx+Apache).
- **بدون:** Composer، npm، Webpack، Vite، فریم‌ورک PHP (Laravel/Symfony/...).

### ۲.۲. لایسنس و مالکیت

طراحی، توسعه و مدیریت: **امین دروگر بزدی**. مخزن فایل `LICENSE` صریح ندارد؛ قبل از هرگونه ادغام/نشر، مجوز کاری از مالک گرفته شود.

---

## 3. معماری و ساختار پوشه‌ها

```
Mojavez-v2/
├── app/
│   ├── Controllers/     # ۲۶ کنترلر (Auth, Admin, Registration, Tracking, License, Payment, ...)
│   ├── Models/          # ۲۷ مدل (Applicant, Person, Payment, LicenseFile, User, UserAccount, ...)
│   ├── Services/        # سرویس‌ها (AutoCancelService, FileStorage, FeeCalculatorService,
│   │                    #   ApplicantRegistrationService, PaymentReceiptService)
│   └── Views/           # نماها (layout, admin_layout, layout_print, registration/, tracking/, admin/, user/, auth/)
├── config/              # config.php, env.php, helper.php, jdf.php (تقویم جلالی), workflow.php, statuses.php
├── core/                # هستهٔ MVC: AutoLoader, Router, Database (Singleton), Session, Validator, CsvExporter, PdfGenerator
├── database/            # schema.sql (مرجع), install.sql (نصب idempotent), seed-admin.sql, migrations/ (۶ فایل)
├── deploy/
│   ├── apache/          # mojavez.conf (vhost)
│   ├── nginx/           # mojavez.conf (server block)
│   ├── docker/          # Dockerfile, docker-compose.yml, entrypoint.sh, init-db.sql, nginx-proxy.conf, .env.example
│   ├── linux/           # install.sh, install-service.sh (systemd), README.md
│   ├── shared-hosting/  # install.php (نصب‌کننده وب), check.php (بررسی پیش‌نیازها), .htaccess, root-htaccess.htaccess, README.md
│   ├── windows/         # README.md
│   └── migrate-documents.php  # مهاجرت فیزیکی فایل‌ها به ساختار کد رهگیری
├── docs/                # ۱۰ سند فارسی (blueprint, معماری, امنیت, راهنما, DB, استقرار, تست‌ها, نقشه‌راه, واکنش‌گرایی, عملکردی)
├── public/              # document root (index.php, .htaccess, router.php, assets/, robots.txt)
├── tests/               # smoke-test.sh, TestCase.php, ۷ فایل تست PHP, run.php, گزارش‌های JSON
├── tools/               # static-analysis.py, responsive-test.py (Python)
├── uploads/             # فایل‌های بارگذاری‌شده (خارج از public + .htaccess محافظ)
├── .env.example         # نمونهٔ متغیرهای محیطی
├── .htaccess            # هدایت به public/ + مسدودسازی پوشه‌های حساس
├── .dockerignore, .gitignore, .gitattributes
├── CHANGELOG.md         # ۹۳۸ خط تاریخچهٔ نسخه‌ها
├── REFACTOR_PLAN.md     # طرح بازنویسی v2 (۱۳ فاز)
├── VERSION              # "0.6.3"
├── README.md            # راهنمای اصلی
└── pg.html              # صفحهٔ کمک‌هزینه
```

### ۳.۱. جریان درخواست (Request Flow)

```
HTTP Request
  → public/.htaccess (RewriteRule → index.php?url=...)
  → public/index.php
       ├─ require config/config.php → load_env() (config/env.php) → define constants
       ├─ error_reporting + ini_set display_errors (طبق APP_DEBUG)
       ├─ set_exception_handler (JSON برای AJAX، HTML برای سایر) + set_error_handler
       ├─ هدرهای امنیتی سراسری (X-Frame-Options, CSP via .htaccess, COOP, CORP, Cache-Control, X-Robots-Tag, ...)
       ├─ Session::start() (cookie flags, strict mode, regenerate هر ۳۰ دقیقه)
       ├─ بررسی IP_BLOCKLIST (early exit 403)
       ├─ Router(BASE_URL) → ثبت ~۱۰۹ مسیر
       ├─ مسیر /health (بدون احراز هویت)
       └─ Router::dispatch(method, uri)
              → Controller@action
                   ├─ requireLogin()/requireUserLogin()/requireRole([...]) (guards)
                   ├─ ADMIN_IP_ALLOWLIST بررسی (در requireLogin)
                   ├─ verifyCsrf() (برای POST)
                   ├─ validateStatusTransition() (برای تغییر وضعیت)
                   ├─ Model→Database::getInstance() (Singleton PDO)
                   ├─ View render (layout + content)
                   └─ Response (HTML/JSON/file stream)
```

### ۳.۲. AutoLoader (`core/AutoLoader.php`)

PSR-4-like ساده: namespace `app\Controllers\X` → `app/Controllers/X.php`. بدون Composer. تمام کلاس‌ها به‌صورت lazy load بارگذاری می‌شوند.

### ۳.۳. Singleton Database (`core/Database.php`)

```php
class Database {
    private static ?Database $instance = null;
    private PDO $pdo;
    private function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,  // prepared statements واقعی
        ]);
        $this->pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->pdo->exec("SET time_zone = '+00:00'");
    }
    public static function getInstance(): self { ... }
    public function getConnection(): PDO { return $this->pdo; }
}
```

**نکتهٔ یکپارچه‌سازی:** `ATTR_EMULATE_PREPARES=false` به این معناست که همهٔ کوئری‌ها prepared statements واقعی سرور هستند. اگر لایهٔ واسط بخواهد مستقیم به همان DB وصل شود، باید همین تنظیم را رعایت کند و از تکرار پارامترهای نام‌دار (مثل `:v` دو بار) پرهیز کند (MariaDB با Emulate‌ها مشکل دارد — رفع در `Setting::set` با `:v` و `:v2` مجزا).

---

## 4. پیش‌نیازها، وابستگی‌ها و الزامات سیستم

### ۴.۱. الزامات PHP

| الزام | نسخه/مقدار | توضیح |
|---|---|---|
| **PHP** | 8.0+ (تست‌شده 8.2.34 و 8.5.12) | بدون Composer؛ فقط `require_once`. |
| **افزونهٔ `pdo_mysql`** | اجباری | اتصال به MySQL/MariaDB. |
| **افزونهٔ `mbstring`** | اجباری | پردازش رشته‌های چندبایتی فارسی. |
| **افزونهٔ `fileinfo`** | اجباری | تشخیص MIME واقعی فایل‌های آپلودی (`finfo`). |
| **افزونهٔ `gd`** | اختیاری اما توصیه‌شده | تغییر اندازه تصاویر و بندانگشتی (در Docker نصب می‌شود). |
| **افزونهٔ `openssl`** | اجباری | `random_bytes()` برای CSPRNG (توکن‌ها، کد رهگیری، CSRF). |
| **تابع `hash_equals`** | اجباری (PHP 5.6+) | مقایسهٔ زمان‌ثابت CSRF و CRON_SECRET. |
| **`session.use_strict_mode`** | فعال | در `Session::start()` تنظیم می‌شود. |

### ۴.۲. الزامات دیتابیس

| الزام | نسخه | توضیح |
|---|---|---|
| **MySQL** | 5.7+ | پشتیبانی JSON و توابع پنجره‌ای. |
| **MariaDB** | 10.3+ | تست‌شده با 10.11 (Docker) و 10.11.18 (محیط تست). |
| **کاراکترست** | `utf8mb4` + `utf8mb4_unicode_ci` | برای پشتیبانی کامل از فارسی و ZWNJ. |
| **موتور ذخیره‌سازی** | InnoDB | همهٔ جداول (پشتیبانی FK و تراکنش). |
| **TimeZone نشست** | `+00:00` | در `Database.php` با `SET time_zone` تنظیم می‌شود (UTC برای TIMESTAMPهای یکپارچه). |

### ۴.۳. الزامات وب‌سرور

| محیط | الزامات |
|---|---|
| **Apache** | `mod_rewrite`, `mod_headers`, `mod_deflate`, `mod_expires`, `mod_ssl` (اختیاری برای HSTS). DocumentRoot باید روی `public/` تنظیم شود. |
| **Nginx** | به‌عنوان reverse proxy در جلوی Apache (Docker) یا به‌عنوان سرور اصلی با `try_files` به `index.php`. |
| **PHP built-in** | `php -S localhost:8000 -t public public/router.php` (توسعه). |
| **Docker** | Docker Engine + Docker Compose v2. تصاویر: `php:8.2-apache`, `mariadb:10.11`, `nginx:1.27-alpine`. |

### ۴.۴. وابستگی‌های خارجی (هیچ‌کدام از طریق Composer)

| وابستگی | نحوهٔ ارائه | توضیح |
|---|---|---|
| **Bootstrap 5 RTL** | محلی در `public/assets/bootstrap.rtl.min.css` (یا CDN با `USE_CDN=true`) | بدون نیاز به دانلود. |
| **فونت وزیرمتن** | محلی در `public/assets/` | برای متن. |
| **فونت لاله‌زار** | محلی | برای تیترها/لوگو. |
| **PDF.js** | محلی در `public/assets/js/pdf.min.js` + `pdf.worker.min.js` + `cmaps/` + `standard_fonts/` | برای رندر PDF در مرورگر (رفع CSP با CDN). |
| **GD** (سیستم) | نصب سیستم | برای تغییر اندازهٔ تصاویر. |

### ۴.۵. محدودیت‌های دیگر

- **`post_max_size` و `upload_max_filesize`** در `php.ini` باید ≥ `2MB` (مدارک) و ≥ `5MB` (CSV ایمپورت) باشند. در غیر این صورت `AdminController::updateApplication` به‌صورت تدافعی `$_POST` خالی را تشخیص می‌دهد.
- **`memory_limit`** ≥ 128MB توصیه می‌شود (به‌خاطر PDF.js و GD).
- **`max_execution_time`** ≥ 30 ثانیه (ایمپورت CSV بزرگ ممکن است طول بکشد).
- **دسترسی نوشتن:** پوشهٔ `uploads/` باید قابل نوشتن توسط کاربر PHP-FPM/Apache باشد (chmod 0775 در Docker، 0755 در shared hosting).
- **HTTPS:** به‌دلیل `session.cookie_secure=auto` و HSTS، در تولید باید HTTPS فعال باشد.

---

## 5. پیکربندی: تمام متغیرهای محیطی و ثابت‌ها

### ۵.۱. متغیرهای فایل `.env` (مرجع: `.env.example` + `config/config.php`)

| متغیر | پیش‌فرض | نوع | توضیح |
|---|---|---|---|
| `APP_DEBUG` | `false` | bool | حالت اشکال‌زدایی. در تولید **حتماً false**. فقط از `.env` خوانده می‌شود (رفع H-11: auto-debug حذف شد). |
| `DB_HOST` | `localhost` | string | هاست دیتابیس (در Docker: `db`). |
| `DB_NAME` | `handicraft_license` | string | نام دیتابیس. |
| `DB_USER` | `root` | string | کاربر دیتابیس. |
| `DB_PASS` | (خالی) | string | رمز دیتابیس. |
| `BASE_URL` | `/` | string | مسیر پایه. مثال‌ها: `/`, `/public/`, `/mojavez/`, `/mojavez/public/`. **نکتهٔ هاست اشتراکی:** اگر `public/` سرو می‌شود، روی `/public/` تنظیم شود (رفع v0.6.3). |
| `USE_CDN` | `false` | bool | Bootstrap از CDN بارگذاری شود یا محلی. |
| `CRON_SECRET` | (خالی) | string | رمز محافظ `/check-cancel`. ۶۴ کاراکتر hex با `openssl rand -hex 32`. اگر خالی باشد، endpoint غیرفعال (403). |
| `SESSION_LIFETIME` | `86400` | int | طول عمر نشست به ثانیه (۲۴ ساعت). |
| `ERROR_LOG_PATH` | (خالی) | string | مسیر لاگ خطا. توصیه: خارج از DocumentRoot. |
| `PUBLIC_RATE_LIMIT_PER_MIN` | `30` | int | حداکثر درخواست/دقیقه برای endpointهای عمومی بدون login. |
| `TRACKING_REQUIRE_MOBILE` | `false` | bool | اگر true، کد رهگیری به‌تنهایی کافی نیست؛ موبایل هم لازم است. (در فاز بعدی به `true` تغییر کند.) |
| `ADMIN_IP_ALLOWLIST` | (خالی) | string | لیست IPهای مجاز برای پنل مدیریت (کاما جدا). خالی = همه مجاز. |
| `IP_BLOCKLIST` | (خالی) | string | لیست IPهای مسدودشده (کاما جدا). |
| `APP_ENV` | `production` | string | `production` یا `development`. کنترل سطح لاگ. |

### ۵.۲. ثابت‌های PHP تعریف‌شده در `config/config.php`

| ثابت | مقدار | توضیح |
|---|---|---|
| `APP_VERSION` | `'0.6.3'` | نسخه فعلی (در `/health` و فوتر بازگردانده می‌شود). |
| `APP_VERSION_NAME` | `'سخت‌سازی امنیتی و حریم خصوصی...'` | نام نسخه. |
| `APP_DEBUG` | `(bool)env('APP_DEBUG', false)` | — |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | از `.env` | — |
| `BASE_URL` | از `.env` | — |
| `ROOT_PATH` | `dirname(__DIR__)` | ریشهٔ پروژه. |
| `APP_PATH`, `CORE_PATH`, `VIEW_PATH`, `CONFIG_PATH` | زیرمسیرها | — |
| `MAX_UPLOAD_SIZE` | `2 * 1024 * 1024` (2 MiB) | حداکثر حجم مدارک. |
| `MAX_CSV_SIZE` | `5 * 1024 * 1024` (5 MiB) | حداکثر حجم CSV ایمپورت. |
| `ALLOWED_EXTENSIONS` | `['jpg','jpeg','png','pdf']` | پسوندهای مجاز آپلود. |
| `CSV_EXTENSION` | `'csv'` | — |
| `UPLOAD_DIR` | `ROOT_PATH . '/uploads'` | مسیر ذخیره فایل‌ها (خارج از `public/`). |
| `USE_CDN` | از `.env` | — |
| `SESSION_LIFETIME` | `86400` | — |
| `AUTO_CANCEL_DAYS` | `14` | لغو خودکار پس از ۱۴ روز از «ثبت درخواست صدور». |
| `PRIORITY_DAYS` | `12` | اولویت‌بندی برای ثبت‌نام بیش از ۱۲ روز. |
| `CRON_SECRET` | از `.env` | — |
| `TRACKING_REQUIRE_MOBILE` | از `.env` | — |
| `PUBLIC_RATE_LIMIT_PER_MIN` | `30` | — |
| `APP_ENV` | از `.env` | — |
| `ERROR_LOG_PATH` | از `.env` | — |
| `ADMIN_IP_ALLOWLIST` | از `.env` | — |
| `IP_BLOCKLIST` | از `.env` | — |

### ۵.۳. ثابت‌های سرویس‌ها و مدل‌ها

| ثابت | مقدار | محل | توضیح |
|---|---|---|---|
| `LicenseAccessRequest::TOKEN_HOURS` | `72` | مدل | اعتبار توکن دسترسی به تصویر مجوز (۳ روز). |
| `FileStorage::MAX_IMAGE_DIMENSION` | `1920` | سرویس | حداکثر عرض/ارتفاع تصویر برای تغییر اندازه. |
| `FileStorage::JPEG_QUALITY` | `85` | سرویس | کیفیت فشرده‌سازی JPEG. |
| `FileStorage::PNG_COMPRESSION` | `9` | سرویس | سطح فشرده‌سازی PNG. |
| `FormField::TYPES` | array | مدل | انواع فیلد فرم‌ساز پویا. |
| `FormField::TOGGLABLE_SYSTEM_KEYS` | array | مدل | فیلدهای سیستمی قابل حذف از فرم. |
| `PaymentChannel::TYPES` | array | مدل | انواع کانال پرداخت. |
| `DocTypes::ALL`, `USER_UPLOAD`, `ADMIN_ADD` | array | مرجع | فهرست مرجع انواع مدرک. |
| `Setting::editableKeys()` | array | مدل | کلیدهای قابل ویرایش از پنل. |

---

## 6. پایگاه داده: جداول، روابط، انواع داده

### ۶.۱. مشخصات کلی

- **تعداد جداول:** ۲۸ جدول (نکته: `database/README.md` ادعای ۲۹ می‌کند — اختلاف جزئی مستندات).
- **کاراکترست:** `utf8mb4` / `utf8mb4_unicode_ci` / InnoDB.
- **دو فایل نصب:**
  - `database/schema.sql` — مرجع توسعه‌دهنده، همهٔ ستون‌ها inline در `CREATE TABLE`، بدون `ALTER TABLE` (بازنویسی کامل در کامیت `394ab37`).
  - `database/install.sql` — نسخهٔ production با رویکرد idempotent: `CREATE TABLE IF NOT EXISTS` + `ALTER TABLE`های تدافعی + `INSERT ... SELECT ... FROM DUAL WHERE NOT EXISTS` (سازگار با phpMyAdmin/MariaDB). ۱۲۷۹ خط.
- **بذر مدیر:** `database/seed-admin.sql` — حساب `admin` / `Mojavez@2026` (bcrypt cost=10).

### ۶.۲. لیست کامل ۲۸ جدول (خلاصه)

> جزئیات کامل ستون‌ها در فایل `database/schema.sql` موجود است. در اینجا نقش هر جدول و ستون‌های کلیدی برای یکپارچه‌سازی آورده می‌شود.

| # | جدول | نقش | ستون‌های کلیدی برای لایهٔ واسط |
|---|---|---|---|
| ۱ | `users` | حساب‌های مدیر/کارکنان | `id`, `username`, `role` (`admin`/`expert`/`document_uploader`), `created_at` |
| ۲ | `persons` | **لنگر هویت** (کد ملی یکتا) | `id`, `national_code` (UNIQUE), `first_name`, `last_name`, `mobile`, `person_type` (`حقیقی`/`حقوقی`), `company_national_id`, `created_at` |
| ۳ | `service_types` | انواع خدمت (۵ سرویس) | `id`, `title`, `authority`, `is_active` |
| ۴ | **`applicants`** | **قلب سامانه — پرونده** | `id`, `person_id`, `tracking_code` (UNIQUE), `national_code`, `current_status` (ENUM ۱۸ مقدار), `license_state` (ENUM ۴ مقدار), `license_valid_until`, `service_type_id`, `parent_application_id` (self-ref), `priority`, `registration_started_at`, `registration_completed_at`, `created_at`, `updated_at` |
| ۵ | `job_details` | اطلاعات شغلی (۱:۱ با applicants) | `applicant_id`, `license_title`, `experience_months`, `training_method` |
| ۶ | `property_details` | اطلاعات ملک (۱:۱) | `applicant_id`, `ownership_status`, `province`, `county`, `city`, `village`, `electricity_bill_id` |
| ۷ | `documents` | مدارک (۱:N) | `id`, `applicant_id`, `doc_type`, `file_path` (`/{tracking_code}/{doc_type}_{rand6}.ext`), `original_name`, `uploaded_at` |
| ۸ | `status_log` | تاریخچهٔ وضعیت‌ها | `applicant_id`, `status_name`, `description`, `created_at` |
| ۹ | `postal_code_changes` | درخواست تغییر کد پستی | `applicant_id`, `old_postal_code`, `new_postal_code`, `status`, `reviewed_by` |
| ۱۰ | `settings` | تنظیمات کلید/مقدار | `setting_key` (PK), `setting_value`, `updated_at` |
| ۱۱ | `wizard_steps` | مراحل ویزارد ثبت‌نام | `id`, `view_key`, `title`, `service_type_id`, `is_system`, `is_active`, `sort_order` |
| ۱۲ | `form_fields` | فیلدهای فرم پویا | `id`, `wizard_step_id`, `service_type_id`, `field_key` (UNIQUE), `field_type`, `is_required`, `is_system`, `is_active` |
| ۱۳ | `applicant_field_values` | مقادیر فیلدهای پویا | `applicant_id`, `field_id`, `field_value` |
| ۱۴ | `license_titles` | رشته‌های مجوز (اختصاصی هر سرویس) | `id`, `service_type_id`, `category`, `title`, `is_active` (UNIQUE: `(service_type_id, title)`) |
| ۱۵ | `payment_channels` | کانال‌های پرداخت | `id`, `title`, `channel_type`, `is_active` |
| ۱۶ | `license_access_requests` | درخواست دسترسی به تصویر مجوز | `applicant_id`, `access_token` (CHAR(64) UNIQUE), `expires_at` (۷۲h), `status`, `requires_payment`, `amount` |
| ۱۷ | `payments` | پرداخت‌های متقاضی | `applicant_id`, `purpose`, `amount`, `status`, `reference_code`, `paid_at`, `fee_group_id` |
| ۱۸ | `license_files` | تصویر مجوز صادرشده (۱:۱) | `applicant_id` (UNIQUE), `document_id`, `download_count`, `issued_at` |
| ۱۹ | `sms_templates` | الگوهای پیامک | `title`, `sample_text`, `target_status` |
| ۲۰ | `user_sms_entries` | پیامک‌های ثبت‌شده | `applicant_id`, `sms_template_id`, `status`, `applied_status` |
| ۲۱ | `applicant_revisions` | درخواست بازبینی پرونده | `applicant_id`, `payload` (**JSON**), `status` |
| ۲۲ | `identity_checks` | لاگ تلاش‌های احراز هویت | `national_code`, `ip`, `success`, `created_at` |
| ۲۳ | `fee_groups` | گروه‌های قیمتی نرخ‌نامه | `code` (الف/ب/ج/د), `decile_min`, `decile_max`, `total_amount` |
| ۲۴ | `fee_group_items` | آیتم‌های هر گروه | `fee_group_id`, `item_key`, `amount` |
| ۲۵ | `payment_service_ticks` | تیک خدمات هر پرداخت | `payment_id`, `item_key`, `is_done` |
| ۲۶ | `payment_audit_log` | ممیزی تغییرات پرداخت | `payment_id`, `user_id`, `action`, `old_value`, `new_value` |
| ۲۷ | `user_accounts` | حساب کاربری متقاضی | `person_id` (UNIQUE), `mobile` (UNIQUE), `password`, `failed_login_count`, `locked_until` |
| ۲۸ | `user_notifications` | اعلان‌های کاربر | `user_account_id`, `type`, `title`, `body`, `is_read` |

### ۶.۳. ENUM مهم: `applicants.license_state` (نقش راهبردی در یکپارچه‌سازی)

```sql
license_state ENUM('جاری','صادر شده','باطل شده','منقضی') NOT NULL DEFAULT 'جاری'
```

این دقیقاً معادل حالت‌های درگاه ملی `qr.mojavez.ir` است. لایهٔ واسط باید این چهار مقدار را به‌عنوان filter اصلی «فقط مجوزهای معتبر» (`صادر شده` با `license_valid_until >= CURDATE()`) استفاده کند.

### ۶.۴. ENUM مهم: `applicants.current_status` (۱۸ مقدار)

۱۴ وضعیت مشترک + ۴ اختصاصی مشاغل خانمی مستقل (در بخش ۸ تفصیل داده شده).

### ۶.۵. ستون JSON تنها: `applicant_revisions.payload`

تنها ستون `JSON NOT NULL` واقعی. شامل snapshot کامل فرم اطلاعات (مراحل ۱–۳) برای مقایسه side-by-side توسط مدیر.

### ۶.۶. الگوی `ON DELETE`

- **CASCADE:** جداول فرعی وابسته (`job_details`, `property_details`, `documents`, `status_log`, `payments`, `license_files`, ...).
- **SET NULL:** ارجاعات به `persons`, `service_types`, `users`, `payment_channels`, `documents`.
- **Self-ref SET NULL:** `applicants.parent_application_id` (پروندهٔ والد در چندمجوزی).

### ۶.۷. ایندکس‌های مهم برای کوئری‌های لایهٔ واسط

| ایندکس | جدول | کاربرد |
|---|---|---|
| `idx_tracking_code` (UNIQUE) | `applicants.tracking_code` | lookup با کد رهگیری. |
| `idx_national_code` | `applicants.national_code` | lookup با کد ملی. |
| `idx_status` | `applicants.current_status` | فیلتر بر اساس وضعیت. |
| `idx_license_state` | `applicants.license_state` | **فیلتر اصلی لایهٔ واسط** برای مجوزهای صادرشده. |
| `idx_completed_priority` | `(registration_completed_at, priority)` | گزارش اولویت. |
| `idx_person_mobile` | `persons.mobile` | lookup با موبایل. |

### ۶.۸. Migrationها (۶ فایل در `database/migrations/`)

| فایل | تاریخ | کار |
|---|---|---|
| `20251109_v2_upgrade.sql` | ۲۰۲۵-۱۱-۰۹ | افزودن `mobile_2`/`mobile_3` به `persons`. |
| `20251110_household_independent_steps.sql` | ۲۰۲۵-۱۱-۱۰ | ۳ مرحلهٔ اختصاصی مشاغل خانگی + ~۳۰ فیلد `dyn_*`. |
| `20251111_household_status_enum.sql` | ۲۰۲۵-۱۱-۱۱ | افزودن ۴ وضعیت به ENUM `current_status`. |
| `20251112_license_titles_update.sql` | ۲۰۲۵-۱۱-۱۲ | ~۴۰ رشتهٔ مجوز جدید. |
| `20251113_license_titles_service_specific.sql` | ۲۰۲۵-۱۱-۱۳ | **بزرگ‌ترین:** افزودن `service_type_id` به `license_titles` + ~۹۰ رشتهٔ اختصاصی سرویس. |
| `20251114_migrate_documents_to_tracking_code_folders.sql` | ۲۰۲۵-۱۱-۱۴ | مهاجرت فیزیکی فایل‌ها به `uploads/{tracking_code}/`. |

> اگر از `install.sql` نصب تازه انجام شود، **نیازی به اجرای migrationها نیست**.

---

## 7. مدل‌های داده (۲۷ کلاس)

همهٔ مدل‌ها در `app/Models/` با الگوی یکسان: سازنده `Database::getInstance()->getConnection()` را در `$this->db` ذخیره می‌کند.

### ۷.۱. مدل‌های کلیدی برای لایهٔ واسط (فقط‌خواندنی)

| مدل | جدول | متدهای مفید برای خواندن |
|---|---|---|
| **`Applicant`** | `applicants` | `findByTrackingCode($code)`, `findById($id)`, `getAll($filters,$limit,$offset)` (JOIN با `job_details`+`property_details`), `countAll($filters)`, `findExpiredApplications($days)`, **`generateTrackingCode()`** (static — تولید کد رهگیری). |
| **`Person`** | `persons` | `findByNationalCode($nc)`, `applications($personId)` (همهٔ پرونده‌های شخص — برای چندمجوزی). |
| **`LicenseFile`** | `license_files` | `findByApplicantId($aid)` — بررسی وجود تصویر مجوز. |
| **`LicenseAccessRequest`** | `license_access_requests` | `findValidToken($applicantId)` — توکن معتبر جاری, `expireStale()` (static). |
| **`LicenseTitle`** | `license_titles` | `groupedActiveByService($serviceTypeId)` — رشته‌های مجوز گروه‌بندی‌شده. |
| **`ServiceType`** | `service_types` | `getActive()` — لیست سرویس‌های فعال. |
| **`StatusLog`** | `status_log` | `findByApplicantId($aid)`, `getStatusDate($applicantId, $statusName)`. |
| **`Document`** | `documents` | `findByApplicantId($aid)` — لیست مدارک (بدون سرو فیزیکی). |
| **`JobDetail`** | `job_details` | `findByApplicantId($aid)`. |
| **`PropertyDetail`** | `property_details` | `findByApplicantId($aid)`. |

### ۷.۲. سایر مدل‌ها (مدیریت داخلی)

`User`, `UserAccount`, `Payment`, `PaymentChannel`, `PaymentServiceTick`, `PaymentAuditLog`, `FeeGroup`, `FeeGroupItem`, `SmsTemplate`, `UserSmsEntry`, `UserNotification`, `PostalCodeChange`, `FormField`, `WizardStep`, `ApplicantFieldValue`, `ApplicantRevision`, `Setting`, `DocTypes` (مرجع ثابت).

> **هشدار یکپارچه‌سازی:** مدل‌ها به‌صورت singleton به یک اتصال PDO وصل هستند. اگر لایهٔ واسط از بیرون به همان DB وصل شود، باید از یک connection read-only با کاربر محدود استفاده کند (بهترین روش: یک MySQL user با `SELECT` فقط روی جداول لازم).

---

## 8. وضعیت‌های پرونده و گردش کار

### ۸.۱. منبع حقیقت: `config/statuses.php`

مرجع واحد (Single Source of Truth) برای ۱۸ وضعیت. هر وضعیت دارای: `order`, `color`, `manual`, `pre_issue`, `terminal`, و برای وضعیت‌های اختصاصی: `services` (آرایهٔ `service_type_id`).

### ۸.۲. ۱۴ وضعیت مشترک (همهٔ سرویس‌ها)

| # | وضعیت | order | terminal? | pre_issue? |
|---|---|---|---|---|
| ۱ | پیش‌نویس | ۱ | ✗ | ✓ |
| ۲ | تکمیل اطلاعات | ۲ | ✗ | ✓ |
| ۳ | احراز نشانی | ۳ | ✗ | ✓ |
| ۴ | بررسی کارشناسی رشته | ۴ | ✗ | ✓ |
| ۵ | **ثبت درخواست صدور** | ۵ | ✗ | ✓ | ← نقطهٔ شروع شمارش ۱۴ روز لغو خودکار |
| ۶ | بارگذاری مدارک به سازمان | ۶ | ✗ | ✓ |
| ۷ | درخواست اصلاح مدارک | ۷ | ✗ | ✓ |
| ۸ | تأیید اولیه | ۸ | ✗ | ✓ |
| ۹ | تأیید نهایی | ۹ | ✗ | ✓ |
| ۱۰ | **صدور مجوز** | ۱۰ | ✗ | ✗ |
| ۱۱ | **تحویل مجوز** | ۱۱ | **✓ terminal** | ✗ | ← پایان صنایع دستی |
| ۱۲ | مشاهده و چاپ | ۱۲ | ✗ | ✗ |
| ۱۳ | **رد درخواست** | ۹۸ | **✓ terminal** | ✗ |
| ۱۴ | **لغو** | ۹۹ | **✓ terminal** | ✗ |

### ۸.۳. ۴ وضعیت اختصاصی مشاغل خانگی مستقل (`service_type_id = 2`)

| # | وضعیت | order | services |
|---|---|---|---|
| ۱۵ | تطبیق آدرس با ثبت احوال | ۱۵ | [2] |
| ۱۶ | دریافت تصویر مجوز | ۲۵ | [2] |
| ۱۷ | تأیید اولیه در سامانه مشاغل خانگی | ۳۵ | [2] |
| ۱۸ | **تأیید نهایی دریافت کد رهگیری** | ۳۶ | [2] — **terminal** | ← پایان مشاغل خانمی (منبع: `smkh.mcls.gov.ir`) |

### ۸.۴. قواعد گردش کار (`config/workflow.php`)

| نقش | from (وضعیت‌های ورودی) | to (مقصد مجاز) |
|---|---|---|
| `expert` | تکمیل اطلاعات، احراز نشانی، بررسی کارشناسی، درخواست اصلاح، تأیید اولیه | احراز نشانی، بررسی کارشناسی، ثبت درخواست صدور، درخواست اصلاح، تأیید اولیه، تأیید نهایی، رد، لغو |
| `document_uploader` | ثبت درخواست صدور، بارگذاری مدارک | بارگذاری مدارک، درخواست اصلاح، تأیید اولیه، صدور مجوز، لغو |
| `admin` | (خالی — همه) | (خالی — همه) |

**قواعد متمرکز در `Controller::validateStatusTransition()`:**
1. `admin` همهٔ انتقال‌ها مجاز است.
2. مسیر یکطرفه: `$newOrder <= $currentOrder` رد می‌شود (مگر `لغو`/`رد`).
3. انتقال از `terminal` فقط به `لغو`/`رد` مجاز است.
4. `مشاهده و چاپ` هرگز به‌صورت دستی set نمی‌شود (رویداد خودکار سمت کاربر).

### ۸.۵. `applicants.license_state` (مستقل از `current_status`)

چهار مقدار مجزا: `جاری`, `صادر شده`, `باطل شده`, `منقضی`. توسط `Person::updateLicenseState($applicantId, $state, $validUntil)` به‌روزرسانی می‌شود. این فیلد **وضعیت نهایی مجوز** را نشان می‌دهد و مستقل از وضعیت پرونده است.

---

## 9. کد رهگیری: ساختار، تولید، نقش در یکپارچه‌سازی

### ۹.۱. الگو

- **پیشوند:** `HG` (ثابت در کد — مخفف "Handicraft License" یا "Mojavez").
- **جداساز:** `-`.
- **بدنه:** ۸ کاراکتر hex uppercase.
- **قالب نهایی:** `HG-XXXXXXXX` (مثلاً `HG-A3F7B2C9`).
- **طول کل:** ۱۱ کاراکتر.
- **ذخیره:** `applicants.tracking_code VARCHAR(20) NOT NULL UNIQUE`.

### ۹.۲. تولید (`Applicant::generateTrackingCode()` — static)

```php
$prefix = 'HG';
$unique = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$code = $prefix . '-' . $unique;
// سپس بررسی یکتایی با SELECT COUNT(*) — در صورت تصادم، فراخوانی بازگشتی
```

- **منبع آنتروپی:** `random_bytes(4)` — CSPRNG (رمزنگارانه امن).
- **فضای کلید:** ۸ hex = ۴ بایت = ~۴.۳ میلیارد ترکیب.
- **تضمین یکتایی:** قید `UNIQUE` در DB + INDEX `idx_tracking_code` + بررسی PHP + فراخوانی بازگشتی.

### ۹.۳. کاربردهای داخلی کد رهگیری

1. **lookup عمومی:** `Applicant::findByTrackingCode($code)` — پایهٔ `/tracking`.
2. **نام پوشهٔ مدارک:** `uploads/{tracking_code}/{doc_type}_{rand6}.ext` (مهاجرت در `20251114`).
3. **پیام اطلاع‌رسانی:** `build_notify_message($fullName, $trackingCode)`.
4. **URL عمومی:** `/tracking/status/{code}`, `/tracking/print/{code}`, `/tracking/revision/{code}`.

### ۹.۴. نقش در یکپارچه‌سازی با درگاه ملی `qr.mojavez.ir`

درگاه ملی `qr.mojavez.ir` کد رهگیری کشوری خود را دارد (احتمالاً با الگوی متفاوت). لایهٔ واسط باید:

- **جدول نگاشت جداگانه** بسازد: `(local_tracking_code, national_tracking_code, synced_at)`.
- **هیچ کد رهگیری محلی را به درگاه ملی نفرستد** — بلکه داده‌های فیلترشدهٔ محلی را با کد ملی به‌عنوان کلید همگام کند.
- **در صورت دریافت کد ملی از درگاه ملی**، `Person::findByNationalCode($nc)` و سپس `Person::applications($personId)` برای یافتن همهٔ پرونده‌های آن شخص فراخوانی کند.
- **کد رهگیری محلی نباید در URL عمومی لایهٔ واسط افشا شود** (مگر با احراز هویت)، زیرا به‌تنهایی (با `TRACKING_REQUIRE_MOBILE=false`) امکان مشاهدهٔ وضعیت را می‌دهد.

### ۹.۵. امنیت عمومی کد رهگیری

- `TRACKING_REQUIRE_MOBILE` (پیش‌فرض `false`): در حال حاضر کد رهگیری به‌تنهایی کافی است. **توصیهٔ یکپارچه‌سازی:** در لایهٔ واسط، این را به `true` فرض کنید و همیشه کد ملی + موبایل را الزامی کنید.
- `PUBLIC_RATE_LIMIT_PER_MIN = 30`: محدودیت نرخ.

---

## 10. مسیرها (Routes) و APIها — مرجع کامل

تمام مسیرها در `public/index.php` ثبت شده‌اند. الگوی مسیریاب: `Router::get/post($path, [Controller, method])` با پشتیبانی از پارامتر `{id}` و `{trackingCode}`.

### ۱۰.۱. مسیرهای عمومی (بدون احراز هویت) — مهم برای لایهٔ واسط

| متد | مسیر | کنترلر@متد | توضیح | Rate Limit |
|---|---|---|---|---|
| GET | `/` | `HomeController::index` | صفحهٔ اصلی | — |
| GET | `/guide` | `HomeController::guide` | راهنما | — |
| GET | `/about` | `HomeController::about` | دربارهٔ ما (محتوا از تنظیمات) | — |
| GET | `/services` | `ServiceController::index` | لیست خدمات (کارت‌های محصول) | — |
| GET | `/signup` | `AuthController::signupForm` | فرم ثبت‌نام کاربر | — |
| POST | `/signup` | `AuthController::signup` | ثبت‌نام کاربر (CSRF) | — |
| GET | `/login` | `AuthController::loginForm` | فرم ورود کاربر | — |
| POST | `/login` | `AuthController::login` | ورود کاربر (CSRF) | **5/5min (IP)** |
| GET | `/logout` | `AuthController::logout` | خروج کاربر | — |
| GET | `/register` | `RegistrationController::index` | ورود ویزارد ثبت‌نام | — |
| GET | `/register/service/{id}` | `RegistrationController::selectService` | انتخاب سرویس | — |
| GET | `/register/step/{n}` | `RegistrationController::stepByPosition` | مرحلهٔ n (موقعیت‌محور) | — |
| POST | `/register/step/{n}` | `RegistrationController::processByPosition` | پردازش مرحله n (CSRF) | — |
| GET | `/register/step1`...`step4` | `RegistrationController::stepN` | مراحل ثابت ویزارد | — |
| POST | `/register/step1`...`step4` | `RegistrationController::processStepN` | پردازش مراحل (CSRF) | — |
| GET | `/register/success/{trackingCode}` | `RegistrationController::success` | نمایش کد رهگیری | — |
| GET | `/tracking` | `TrackingController::search` | فرم جستجوی کد رهگیری | — |
| POST | `/tracking/search` | `TrackingController::find` | جستجو (CSRF) | **10/60s (session)** |
| GET | `/tracking/status/{trackingCode}` | `TrackingController::status` | وضعیت + تایم‌لاین | **30/min (IP)** |
| POST | `/tracking/change-postal-code` | `TrackingController::changePostalCode` | درخواست تغییر کد پستی (CSRF) | **3/3600s** |
| GET | `/tracking/print/{trackingCode}` | `TrackingController::printView` | نسخهٔ چاپی پرونده | **10/60s (IP)** |
| POST | `/tracking/payments/submit` | `TrackingController::submitPayment` | ثبت رسید پرداخت (CSRF) | — |
| POST | `/tracking/sms/submit` | `TrackingController::submitSms` | ثبت پیامک وضعیت (CSRF) | — |
| POST | `/tracking/license/first-access` | `LicenseController::firstAccess` | دریافت اولیهٔ تصویر مجوز (CSRF) | — |
| POST | `/tracking/license/request-access` | `LicenseController::requestAccess` | درخواست مجدد تصویر مجوز (CSRF) | **2/week** |
| POST | `/tracking/license/pay` | `LicenseController::submitReissuePayment` | پرداخت هزینهٔ مجدد (CSRF) | — |
| GET | `/license/view/{token}` | `LicenseController::viewByToken` | مشاهدهٔ مجوز با توکن ۷۲ ساعته | **20/60s (IP)** |
| GET | `/tracking/revision/{trackingCode}` | `RevisionController::form` | فرم بازبینی پرونده | — |
| POST | `/tracking/revision/submit` | `RevisionController::submit` | ثبت درخواست بازبینی (CSRF) | — |
| GET | `/my-records` | `RecordsController::form` | دسترسی به سوابق (نیازمند نشست) | — |
| POST | `/my-records/verify` | `RecordsController::verify` | shim (CSRF) | — |
| **GET** | **`/health`** | (closure) | **بررسی سلامت: `{"status":"healthy","checks":{"db":"ok","version":"0.6.3","time":"..."}}`** | **بدون احراز هویت** |
| **GET** | **`/check-cancel`** | (closure) | اجرای لغو خودکار (نیازمند `CRON_SECRET`) | **محافظت‌شده با `hash_equals`** |

### ۱۰.۲. مسیرهای پنل کاربری (نیازمند نشست کاربر)

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/panel` | داشبورد کاربر |
| GET/POST | `/panel/profile` | پروفایل |
| POST | `/panel/avatar` | آپلود آواتار |
| GET | `/panel/requests` | درخواست‌های کاربر |
| GET | `/panel/payments` | پرداخت‌های کاربر |
| GET | `/panel/licenses` | مجوزهای کاربر |
| GET | `/panel/notifications` | اعلان‌ها |
| POST | `/panel/notifications/read` | علامت‌گذاری همه به‌عنوان خوانده |
| GET | `/panel/tracking` | پیگیری |
| GET | `/dashboard` | هدایت قدیمی به `/panel` |
| POST | `/services/{id}/purchase` | خریداری سرویس |

### ۱۰.۳. مسیرهای پنل مدیریت (نیازمند نشست مدیر)

| متد | مسیر | کنترلر | نقش |
|---|---|---|---|
| GET/POST | `/admin/login`, `/admin/logout` | `AdminController` | عمومی/نشست |
| GET | `/admin/dashboard` | `AdminController::dashboard` | همهٔ نقش‌ها |
| GET | `/admin/applications` | `AdminController::applications` | admin |
| GET | `/admin/view/{id}` | `AdminController::viewApplication` | ورود |
| GET | `/admin/edit/{id}` | `AdminController::editApplication` | admin |
| POST | `/admin/update` | `AdminController::updateApplication` | admin |
| POST | `/admin/change-status` | `AdminController::changeStatus` | نقش+workflow |
| POST | `/admin/add-document` | `AdminController::addDocument` | admin/expert/document_uploader |
| POST | `/admin/delete-document` | `AdminController::deleteDocument` | admin/expert/document_uploader |
| GET | `/admin/my-tasks` | `AdminController::myTasks` | expert/document_uploader |
| GET/POST | `/admin/register` | `AdminController::registerForm/registerProcess` | admin |
| GET | `/admin/postal-requests` | `AdminController::postalRequests` | admin/expert |
| POST | `/admin/postal-requests/approve\|reject` | `AdminController::approvePostalRequest/rejectPostalRequest` | admin/expert |
| GET | `/admin/users` | `UserController::index` | admin |
| GET/POST | `/admin/users/create`, `/admin/users/store` | `UserController` | admin |
| GET/POST | `/admin/users/edit/{id}`, `/admin/users/update/{id}` | `UserController` | admin |
| POST | `/admin/users/delete` | `UserController::delete` | admin |
| GET | `/admin/form-fields` | `FormFieldController::index` | admin |
| GET/POST | `/admin/form-fields/create\|edit\|save\|toggle\|delete\|move` | `FormFieldController` | admin |
| GET/POST | `/admin/service-types` | `ServiceTypeController` | admin |
| GET/POST | `/admin/wizard-steps` | `WizardStepController` | admin |
| GET/POST | `/admin/settings` | `SettingController` | admin |
| GET/POST | `/admin/payments` | `PaymentController` | admin |
| GET/POST | `/admin/bulk-import/*` | `BulkImportController` | admin |
| GET/POST | `/admin/fee-tariff` | `FeeTariffController` | admin |
| POST | `/admin/request-payment` | `AdminController::requestPayment` | admin |
| POST | `/admin/license-state` | `AdminController::updateLicenseState` | admin |
| POST | `/admin/license/upload` | `LicenseController::upload` | admin |
| GET | `/admin/license-requests` | `LicenseController::requests` | admin |
| POST | `/admin/license-requests/review` | `LicenseController::reviewRequest` | admin |
| GET | `/admin/sms` | `SmsController::index` | admin |
| GET | `/admin/revisions` | `RevisionController::index` | admin |
| POST | `/admin/revisions/approve\|reject/{id}` | `RevisionController` | admin |
| GET | `/admin/license-titles` | `LicenseTitleController::index` | admin |
| POST | `/admin/license-titles/*` | `LicenseTitleController` | admin |
| GET | `/admin/license-titles/by-service/{id}` | `LicenseTitleController::byService` | admin (AJAX) |
| GET | `/admin/reports/by-province\|by-city\|by-village\|by-license\|by-date` | `ReportController` | admin |
| GET | `/admin/output/general\|single/{code}\|grouped` | `ReportController` | admin |
| GET | `/admin/report/csv` | `ReportController::csvReport` | admin (CSV with BOM) |
| GET | `/admin/report/pdf` | `ReportController::pdfReport` | admin (چاپ مرورگر) |

### ۱۰.۴. مسیرهای سرو فایل (DocumentController)

| متد | مسیر | توضیح | محافظت |
|---|---|---|---|
| POST | `/document/view` | مشاهدهٔ مدرک در مودال | `requireLogin()` + CSRF |
| GET | `/document/avatar` | سرو آواتار | `requireAnyLogin()` + ownership |
| GET | `/document/thumb/{id}` | بندانگشتی مدرک (GD، max 200×200) | `requireLogin()` |
| GET | `/document/inline/{id}` | نمایش مستقیم برای iframe | `requireLogin()` |
| GET | `/document/pdf-viewer` | نمایش PDF با PDF.js | `requireLogin()` |

> **نکته:** مسیر `/document/raw/{id}` و `streamRaw()` در کامیت `b78adfd` حذف شدند (PDF مستقیماً به‌صورت base64 در HTML embed می‌شود).

### ۱۰.۵. هیچ API REST/JSON عمومی برای خواندن داده‌های پرونده وجود ندارد

**هشدار مهم برای لایهٔ واسط:** این سامانه **API REST برای خواندن داده‌های پرونده ندارد**. تمام پاسخ‌ها HTML هستند (نماهای PHP). تنها خروجی‌های JSON:
- `/health` (سلامت)
- `/check-cancel` (نتایج لغو)
- خطاهای AJAX (CSRF 419, خطای 500)
- `/admin/license-titles/by-service/{id}` (AJAX، نیازمند نشست admin)

بنابراین برای یکپارچه‌سازی فقط‌خواندنی، گزینه‌ها:
1. **خواندن مستقیم از دیتابیس** (با کاربر SELECT-only) — **توصیه‌شده**.
2. **استخراج داده از HTML** `/tracking/status/{code}` (fragile، نه توصیه‌شده).
3. **افزودن یک endpoint JSON اختصاصی** به این پروژه (مثلاً `/api/v1/license/{code}` با احراز هویت API key) — بهترین راه در صورت امکان تغییر کد منبع.

---

## 11. احراز هویت و نقش‌ها

### ۱۱.۱. دو قلمرو مجزا (Two Realms)

| قلمرو | نشست | کلیدهای session | ورود |
|---|---|---|---|
| **مدیر** (admin-side) | `admin_*` | `admin_logged_in`, `admin_id`, `admin_role`, `admin_username`, `admin_display_name` | `/admin/login` |
| **کاربر** (user-side) | `user_*` | `user_logged_in`, `user_id`, `user_mobile`, `user_name`, `user_national_code` | `/login` |

دو قلمرو می‌توانند در یک مرورگر همزمان فعال باشند (کلیدهای session مجزا).

### ۱۱.۲. نقش‌های مدیر (در `users.role`)

| نقش | دسترسی |
|---|---|
| `admin` | همهٔ انتقال‌ها، ثبت‌نام دستی، ویرایش، حذف، گزارش‌ها، مدیریت کاربران، تنظیمات، نرخ‌نامه، ایمپورت، فرم‌ساز، انواع خدمات، مراحل ویزارد. |
| `expert` | داشبورد + «وظایف من» (فیلتر به وضعیت‌های `workflow.expert.from`) + انتقال‌های محدود + تأیید/رد کد پستی + افزودن/حذف مدارک. |
| `document_uploader` | داشبورد + «وظایف من» (وضعیت‌های `ثبت درخواست صدور`, `بارگذاری مدارک`) + انتقال‌های محدود + افزودن/حذف مدارک. |

### ۱۱.۳. Guards در `app/Controllers/Controller.php`

```php
requireLogin()        // بررسی admin_logged_in + ADMIN_IP_ALLOWLIST
requireUserLogin()    // بررسی user_logged_in + حفظ redirect_after_login
requireAnyLogin()     // قبول admin یا user
requireRole(array)    // requireLogin + بررسی admin_role
isSuperAdmin()        // admin_role === 'admin'
validateStatusTransition($current, $new)  // متمرکز در Controller
```

### ۱۱.۴. هش رمز عبور

`PASSWORD_BCRYPT` با cost پیش‌فرض ۱۰. در `User::create/update` و `UserAccount::create/updatePassword`.

### ۱۱.۵. مقایسهٔ زمان‌ثابت

- رمز: `password_verify()` (PHP built-in، constant-time).
- CSRF: `hash_equals($valid, $token)`.
- CRON_SECRET: `hash_equals(CRON_SECRET, $provided)`.
- ورود مدیر: حتی وقتی `$user` برابر null است، `password_verify` صدا زده می‌شود (anti-timing).

### ۱۱.۶. بازتولید نشست (Session Regeneration)

- بلافاصله پس از ورود موفق (هر دو قلمرو): `Session::regenerate()` → `session_regenerate_id(true)`.
- **خودکار هر ۳۰ دقیقه** در `Session::start()` با حفظ داده.

### ۱۱.۷. حساب مدیر پیش‌فرض

- **فعلی** (در `database/seed-admin.sql`): username `admin`, password `Mojavez@2026` (bcrypt cost=10).
- **تاریخچه:** در `schema.sql` قبلاً `admin/admin123` inline بود — در ممیزی C-2 حذف و به فایل جدا منتقل شد.

### ۱۱.۸. قفل ورود کاربر (Account Lockout)

```sql
-- UserAccount::recordFailedLogin
SET failed_login_count = failed_login_count + 1,
    locked_until = CASE
        WHEN failed_login_count + 1 >= 5 THEN DATE_ADD(NOW(), INTERVAL 5 MINUTE)
        ELSE locked_until END
```

پس از ۵ تلاش ناموفق، حساب ۵ دقیقه قفل می‌شود.

---

## 12. امنیت: لایه‌به‌لایه

### ۱۲.۱. هدرهای امنیتی (در `public/index.php` و `.htaccess`)

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
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net data:; img-src 'self' data: blob:; connect-src 'self'; object-src 'self'; frame-src 'self'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'
```

> **نکتهٔ CSP:** `'unsafe-inline'` و `'unsafe-eval'` به‌خاطر Bootstrap و PDF.js لازم هستند. لایهٔ واسط باید سیاست سخت‌گیرانه‌تری اعمال کند.

### ۱۲.۲. CSRF Protection

- توکن: `bin2hex(random_bytes(32))` = ۶۴ hex، در `$_SESSION['_csrf_token']`.
- فیلد: `csrfField()` → `<input type="hidden" name="_csrf" value="...">`.
- اعتبارسنجی: `hash_equals()` در `Controller::verifyCsrf()`.
- AJAX: توکن از `X-CSRF-TOKEN` هدر هم خوانده می‌شود.
- خطا: HTTP **419** + JSON برای AJAX / HTML برای سایر.
- **در همهٔ ۵۹ فرم POST** اعمال می‌شود.

### ۱۲.۳. نشست (Session)

| تنظیم | مقدار |
|---|---|
| `session.use_strict_mode` | `1` |
| `session.use_only_cookies` | `1` |
| `session.sid_bits_per_character` | `5` |
| `session.sid_length` | `40` |
| `session.cookie_httponly` | `1` |
| `session.cookie_samesite` | `Lax` |
| `session.cookie_secure` | auto (HTTPS) |
| `session.name` | `MOJAVEZSESSID` (سفارشی) |
| طول عمر | `SESSION_LIFETIME` = ۸۶۴۰۰s |

### ۱۲.۴. امنیت آپلود فایل (۷ لایه)

1. `UPLOAD_ERR_OK` الزامی.
2. نرمال‌سازی نام فایل (`sanitize_upload_filename`): حذف BOM، کاراکترهای کنترلی، `\`، basename.
3. استخراج پسوند با `safe_file_extension()` (پشتیبانی از نام فارسی، چندنقطه‌ای).
4. **fallback با MIME**: اگر پسوند نامعتبر، `detect_extension_from_mime()` با `finfo`.
5. بررسی حجم: `≤ MAX_UPLOAD_SIZE` (2 MiB).
6. **MIME واقعی** با `finfo(FILEINFO_MIME_TYPE)` روی `tmp_name`.
7. **یکپارچگی پسوند↔MIME**: مثلاً `shell.php` به `x.jpg` تغییر نام یافته → رد (`ext_mime_mismatch`).

ذخیره: `uploads/{tracking_code}/{doc_type}_{rand6}.ext`, خارج از `public/`, `chmod 0640`, تصاویر با GD به max 1920px re-encode (defeats polyglot).

### ۱۲.۵. محافظت Path Traversal

در `FileStorage` و `DocumentController`:
```php
$cleanPath = str_replace(['..', "\0"], '', $relativePath);
$filePath = realpath(UPLOAD_DIR . $cleanPath);
$base = realpath(UPLOAD_DIR);
if ($filePath === false || !str_starts_with($filePath, $base)) { deny; }
```

### ۱۲.۶. Rate Limiting (دو مکانیزم)

**۱. مبتنی بر نشست** (`Session::hit/isLimited`):
| عملیات | حد | پنجره |
|---|---|---|
| ورود مدیر | ۵ | ۳۰۰s |
| جستجوی tracking | ۱۰ | ۶۰s |
| درخواست تغییر کد پستی | ۳ | ۳۶۰۰s |
| درخواست مجدد تصویر مجوز | ۲ | ۶۰۴۸۰۰s (هفته) |

**۲. مبتنی بر IP** (`rate_limit_ip()` در `config/helper.php`):
| endpoint | حد | پنجره |
|---|---|---|
| ورود کاربر | ۵ | ۳۰۰s |
| `/tracking/status/{code}` | `PUBLIC_RATE_LIMIT_PER_MIN=30` | ۶۰s |
| `/license/view/{token}` | ۲۰ | ۶۰s |
| `/tracking/print/{code}` | ۱۰ | ۶۰s |

> **هشدار:** rate limit مبتنی بر نشست با drop کردن cookie قابل دور زدن است. توصیهٔ production: `fail2ban` / `mod_evasive` / WAF.

### ۱۲.۷. CSV Formula Injection Protection

`CsvExporter::sanitizeCell($value)`:
```php
if ($str !== '' && in_array($str[0], ['=','+','-','@',"\t","\r","\n"], true)) {
    if (!is_numeric($str)) { $str = "'" . $str; }
}
```
+ UTF-8 BOM (`\xEF\xBB\xBF`) برای فارسی در Excel.

### ۱۲.۸. توکن تصویر مجوز (۷۲ ساعته، یک‌بار دریافت)

- `TOKEN_HOURS = 72`.
- توکن: `bin2hex(random_bytes(32))` = ۶۴ hex / ۲۵۶ بیت آنتروپی.
- قاعدهٔ «یک‌بار دریافت»: `LicenseFile.download_count` پس از اولین دانلود > 0 → دریافت مجدد نیازمند درخواست + (احتمالاً) پرداخت.
- انقضوی تنبل: `LicenseAccessRequest::expireStale()` در هر فراخوانی `viewByToken`.

### ۱۲.۹. IP Blocklist / Allowlist

- `IP_BLOCKLIST` (global): بررسی در ابتدای `index.php` قبل از routing.
- `ADMIN_IP_ALLOWLIST`: بررسی در `Controller::requireLogin()` (به‌جز `/admin/login`).
- **اعتماد به `X-Forwarded-For`:** هر دو از first hop معتبر استفاده می‌کنند. **هشدار:** بدون reverse proxy قابل اعتماد، قابل جعل است.

### ۱۲.۱۰. `/check-cancel` Protection

```php
$provided = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_SECRET'] ?? '');
if (CRON_SECRET === '' || !hash_equals(CRON_SECRET, (string)$provided)) {
    http_response_code(403); echo json_encode(['error' => 'forbidden']);
}
```

### ۱۲.۱۱. مسدودسازی موتورهای جستجو و خزنده‌های هوش مصنوعی

در `public/.htaccess` با `RewriteCond %{HTTP_USER_AGENT}` و `[R=410,L]`:
- **لایهٔ ۱:** Googlebot, Bingbot, Slurp, DuckDuckBot, Baiduspider, YandexBot, Sogou, Exabot, facebot, ia_archiver, Applebot, Twitterbot, LinkedInBot, TelegramBot, Discordbot.
- **لایهٔ ۲ (AI):** GPTBot, ChatGPT-User, CCBot, anthropic-ai, Claude-Web, cohere-ai, Google-Extended, PerplexityBot, Amazonbot, Bytespider, Diffbot, Meta-ExternalAgent, OAI-SearchBot, Applebot-Extended.
- **لایهٔ ۳ (SEO):** SemrushBot, AhrefsBot, MJ12bot, DotBot, BLEXBot, SeznamBot, PetalBot.

### ۱۲.۱۲. `.htaccess` سه‌لایه

- **ریشه:** هدایت به `public/` + مسدودسازی `app/core/config/database/tests/tools/docs/uploads`.
- **`public/`:** rewrite rules + security headers + CSP + bot blocking + cache + ErrorDocument.
- **`uploads/`:** `Require all denied` + `php_flag engine off` + deny اسکریپت‌ها.

---

## 13. سرویس‌های کلیدی کسب‌وکار

### ۱۳.۱. `AutoCancelService` (لغو خودکار، بدون cron)

```php
AutoCancelService::cancelExpired()    // لغو پرونده‌های با «ثبت درخواست صدور» قدیمی‌تر از ۱۴ روز
AutoCancelService::updatePriorities() // پرچم priority برای ثبت‌نام بیش از ۱۲ روز
```

**فراخوانی:** در هر بازدید از داشبورد مدیر + `/tracking/status/{code}` + `GET /check-cancel` (با CRON_SECRET).

### ۱۳.۲. `FileStorage` (ذخیرهٔ مدارک)

- مسیر: `uploads/{tracking_code}/{doc_type}_{rand6}.ext`.
- تصاویر با GD به max 1920px، JPEG quality 85، PNG compression 9.
- PDF بدون تغییر.
- `chmod 0640`.

### ۱۳.۳. `ApplicantRegistrationService` (۴۷۸ خط)

مدیریت ۳ سطح ثبت‌نام (Person → Applicant → JobDetail/PropertyDetail/Document) به‌صورت atomic. استخراج‌شده در فاز ۴ بازنویسی v2.

### ۱۳.۴. `PaymentReceiptService` (۲۳۶ خط)

اعتبارسنجی رسید پرداخت (مبلغ، فایل، حداقل مبلغ). استخراج‌شده در فاز ۵.

### ۱۳.۵. `FeeCalculatorService`

محاسبهٔ هزینه بر اساس دهک درآمدی + معافیت روستایی.

### ۱۳.۶. `PaymentReceiptService::validateReceipt($data, $file, $minAmount)`

برای تأیید رسید پرداخت مجدد تصویر مجوز استفاده می‌شود.

---

## 14. بارگذاری و سرو فایل‌ها (مدارک و تصویر مجوز)

### ۱۴.۱. ساختار پوشه‌بندی (پس از مهاجرت `20251114`)

```
uploads/
├── HG-ABC12345/                    ← پوشهٔ کد رهگیری متقاضی
│   ├── national_card_front_a1b2c3.jpg
│   ├── national_card_back_d4e5f6.jpg
│   ├── birth_cert_7890ab.pdf
│   └── license_image_e1f2g3h4.jpg   ← تصویر مجوز (در صورت صدور)
└── HG-DEF67890/
    └── ...
```

### ۱۴.۲. انواع مدرک (`DocTypes`)

ثابت مرجع در `app/Models/DocTypes.php` (استخراج‌شده در فاز ۶ بازنویسی). شامل `ALL`, `USER_UPLOAD`, `ADMIN_ADD`. متدهای `label($dt)`, `isAllowed($dt, $adminContext)`, `allowedForUserUpload()`, `allowedForAdminAdd()`.

### ۱۴.۳. سرو فایل‌ها (همیشه از طریق کنترلر)

- **هیچ فایلی مستقیماً از web root سرو نمی‌شود.** `uploads/.htaccess` همه را deny می‌کند.
- همهٔ سرو از طریق `DocumentController` با `requireLogin()` / `requireAnyLogin()` + ownership check.
- MIME فایل روی دیسک دوباره اعتبارسنجی می‌شود.
- هدرها: `Content-Type`, `Content-Length`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline` یا `attachment`, `Cache-Control`.

### ۱۴.۴. تصویر مجوز (License Image)

- در `license_files` ثبت می‌شود (۱:۱ با `applicants`).
- اولین دریافت: خودکار با توکن ۷۲ ساعته (رایگان).
- دریافت‌های بعدی: نیازمند درخواست + (احتمالاً) پرداخت.
- توکن: `bin2hex(random_bytes(32))` = ۶۴ hex.

### ۱۴.۵. بندانگشتی (Thumbnail)

`DocumentController::thumb()` با GD به max 200×200، کاهش حجم ۹۸٪ (کامیت `b78adfd`).

---

## 15. استقرار در محیط‌های مختلف

### ۱۵.۱. PHP Built-in Server (توسعه)

```bash
php -S localhost:8000 -t public public/router.php
```

### ۱۵.۲. Docker (تولید/استقرار کامل)

```bash
cd deploy/docker
cp .env.example .env
docker compose up -d --build
```

**سه سرویس:**
- `db`: MariaDB 10.11 (volume `mojavez-db-data`، healthcheck).
- `app`: PHP 8.2-apache (build از Dockerfile، volume `mojavez-uploads`).
- `web`: Nginx 1.27-alpine (reverse proxy، پورت `${MOJAVEZ_PORT:-8080}`).

**Dockerfile ویژگی‌ها:**
- نصب `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `curl`, `default-mysql-client`.
- `a2enmod rewrite headers`.
- DocumentRoot → `/var/www/html/public`.
- `chmod 0775` روی `uploads/`.
- `HEALTHCHECK` با `curl http://localhost/`.
- `entrypoint.sh`: انتظار برای MySQL (۶۰ تلاش × ۲s) + اجرای `schema.sql` با `--force` (در صورت `INIT_DB=1`).

### ۱۵.۳. Linux (Apache + MariaDB + systemd)

```bash
sudo bash deploy/linux/install.sh
sudo bash deploy/linux/install-service.sh  # systemd
```

### ۱۵.۴. Windows (XAMPP/Laragon)

```cmd
deploy\windows\install-and-run.bat
```

### ۱۵.۵. هاست اشتراکی (DirectAdmin/Nginx+Apache — نت افراز)

```bash
# آپلود فایل‌ها → بازدید check.php → اجرای install.php
https://example.com/check.php     # بررسی پیش‌نیازها
https://example.com/install.php   # نصب‌کنندهٔ وب دو مرحله‌ای + تولید .env و CRON_SECRET
```

**نکات کلیدی هاست اشتراکی:**
- `BASE_URL=/public/` در `.env` (رفع v0.6.3).
- `root-htaccess.htaccess` در `public_html/` برای ری‌دایرکت ۳۰۱ به `/public/`.
- `.htaccess` سازگار: بدون `<LimitExcept>`, `ServerTokens`, `mod_php`. همهٔ قواعد با `<IfModule>`.
- `install.php` خودکار `CRON_SECRET` تولید می‌کند.

### ۱۵.۶. Apache vhost (`deploy/apache/mojavez.conf`)

DocumentRoot روی `public/`، `AllowOverride All` برای `.htaccess`.

### ۱۵.۷. Nginx server block (`deploy/nginx/mojavez.conf`)

`try_files $uri $uri/ /index.php?$query_string;` + fastcgi به PHP-FPM.

---

## 16. سابقهٔ خطاها و رفع‌ها (git log + CHANGELOG)

### ۱۶.۱. آمار کلی

- **۱۲۳ کامیت** کل.
- **۱۲ نسخهٔ مستند در CHANGELOG** (0.1.0، 0.1.1، 0.1.2، 0.2.0، 0.2.1، 0.2.2، 0.2.3، 0.5.6، 0.6.0، 0.6.1، 0.6.2، 0.6.3).
- سری‌های 0.3.x، 0.4.x، 0.5.0–0.5.5، 0.7.x فقط در `git log` (به‌ارث‌رسیده از مخزن اصلی `Mojaze`).

### ۱۶.۲. دسته‌بندی خطاها و رفع‌ها

#### ۱۶.۲.۱. رندر PDF (۱۱+ کامیت — بزرگ‌ترین خوشه)

| کامیت | علت | راه‌حل |
|---|---|---|
| `0f87f7a` | `embed` دانلود اجباری | `iframe` |
| `f442f7c` | `fetch POST` به blob دانلود trigger می‌کرد | حذف fetch POST، GET مستقیم |
| `dfd5409` | `filename` در `Content-Disposition: inline` + CSP | حذف filename + رفع CSP |
| `a711a8d` | مرورگر با inline هم دانلود می‌کرد + هدرهای Apache بازنویسی می‌کردند | معرفی PDF.js (canvas) |
| `0ddad50` | مسیر اشتباه به `pdf-viewer.php` حذف‌شده | هدایت به `document/pdf-viewer?id=` |
| `86e33e6` | PDF.js در «در حال بارگذاری» توقف | data URL به‌جای fetch |
| `330984c` | data URL برای PDF بزرگ نامعتبر (`Unexpected server response (0)`) | `Uint8Array` |
| `4d8d112` | CSP با CDN PDF.js | بارگذاری محلی |
| `36392ae` | فونت فارسی و favicon 404 | رفع 404 |
| `6d7f287` | فونت فارسی ناخوانا | `useSystemFonts: true` + `font.css` |
| `b78adfd` (نهایی) | CSP `Header always set` حذف نمی‌شد + بندانگشتی ۴۹۲KB | **embed base64 در HTML** + حذف `/document/raw/{id}` + بندانگشتی GD (۲۰۰×۲۰۰، ۹۸٪ کاهش). Lighthouse: Performance 99/100. |

#### ۱۶.۲.۲. خطاهای دیتابیس/SQL

| کامیت | علت | راه‌حل |
|---|---|---|
| `d6f607e` | `Unknown column suspicion_flags` (schema مفرد، کد جمع) | تغییر schema به `suspicion_flags` |
| `08bf515` | `There is already an active transaction` در ایمپورت | پرچم `ownsTransaction` + بررسی `inTransaction()` |
| `394ab37` | `Unknown column user_account_id` از ALTER متعدد | **بازنویسی کامل schema.sql بدون ALTER** |
| `9efac09` | خطای 1064 در MariaDB/phpMyAdmin | تبدیل INSERT به `INSERT ... SELECT ... FROM DUAL WHERE NOT EXISTS` |
| `ae6288c` | ستون‌های `license_state`/`license_valid_until` در DB قدیم نبودند | `try/catch` + لاگ تشخیصی |

#### ۱۶.۲.۳. عدم ذخیرهٔ تنظیمات (۳ کامیت)

| کامیت | علت | راه‌حل |
|---|---|---|
| `fbe2b3b` | خطاها در `Setting::set` سرکوب می‌شد | `try/catch` + `error_log` |
| `fe4f641` | **nested form** (فرم آپلود داخل فرم تنظیمات) | دو فرم مستقل + JS کپی مقادیر |

#### ۱۶.۲.۴. باگ‌های گردش کار وضعیت

| کامیت | علت | راه‌حل |
|---|---|---|
| `81faf07` | «صدور مجوز» در `terminal` بود (برای مشاغل خانمی میانی است) | حذف از `terminal` |
| `93449e0` | `terminal` ثابت بود، نوع خدمت را در نظر نمی‌گرفت | `terminal` فقط ۴ وضعیت واقعاً پایانی |
| `aabd169`, `7f8475c`, `653d306` | منوی کشویی همهٔ وضعیت‌ها | فیلتر بر اساس نوع خدمت |

#### ۱۶.۲.۵. خطای 404 فایل‌های استاتیک روی هاست اشتراکی (v0.6.3)

`BASE_URL=/public/` + `root-htaccess.htaccess` با ری‌دایرکت ۳۰۱.

#### ۱۶.۲.۶. خطای ۵۰۰ در Apache/XAMPP/Windows (PHP 8.5.12)

دستورات نامعتبر `.htaccess` (`ServerTokens`, `SetHandler none`, `mod_php.c`) + `sid_bits_per_character=6` و `sid_length=48` هشدار. رفع: حذف موارد نامعتبر + کاهش به ۵ و ۴۰ + `@session_start`.

#### ۱۶.۲.۷. شکست تست‌ها (۱۱ رفع + ۲ زیرساخت)

| کامیت | علت | راه‌حل |
|---|---|---|
| `d423955` | `TestCase not found` + خطاهای schema در محیط تست | `run.php` require TestCase اول + split SQL هوشمند |
| `64a5b94` | `must implement run()` | حذف متد abstract |
| `c5780f9` | ۱۱ شکست (`assertContains` با آرایه، SemVer، کد ملی) | پذیرش string\|array + کد ملی `6890213246` |

#### ۱۶.۲.۸. باگ‌های ویزارد ثبت‌نام

| کامیت | علت | راه‌حل |
|---|---|---|
| `5e8aaac` | خطای 302 validation — `marital_status` از جدول اشتباه خوانده می‌شد | خواندن از `applicants` به‌جای `persons` |
| `fc7d436` | کاربر واردشده باید همه را دوباره وارد کند | پیش‌فرض اطلاعات هویتی از Person |

---

## 17. ممیزی امنیتی و رفع‌های بحرانی

### ۱۷.۱. کامیت یکپارچهٔ ممیزی: `5fd96a5` (merge: `9f20f86`)

«fix(audit): رفع ۴ بحرانی + ۵ HIGH + ۲ MEDIUM از گزارش تحلیل»

#### ۴ مورد CRITICAL

| شناسه | مشکل | رفع |
|---|---|---|
| **C-1** | `DocumentController::avatar` فقط مدیر مجاز بود | `requireAnyLogin()` — کاربران هم آواتار ببینند |
| **C-2** | `admin/admin123` در `schema.sql` hardcoded | حذف از schema + `seed-admin.sql` با `Mojavez@2026` |
| **C-3** | `public/phpinfo.php` افشای کامل سرور | حذف کامل فایل |
| **C-4** | `debugWrite` همیشه در production | گیت با `if (APP_DEBUG)` |

#### ۵ مورد HIGH

| شناسه | مشکل | رفع |
|---|---|---|
| **H-1** | `die()` در `Database.php` | `throw new RuntimeException` + global handler |
| **H-2** | ورود کاربر بدون rate limit IP | `rate_limit_ip('user_login', 5, 300)` |
| **H-10** | پیام خام استثنا در `FeeTariffController::toggleTick` | `error_log` به‌جای client |
| **H-11** | auto-debug detection (نامطمئن) | `APP_DEBUG` فقط از `.env`، پیش‌فرض `false` |
| **(فاز ۱ REFACTOR)** | S-1/S-2/S-10 (`APP_DEBUG`/DB creds → `.env`), S-4 (rate limit print), S-5 (`/check-cancel` + CRON_SECRET), S-6 (rate limit license view), S-9 (`Person::mobileBelongsToOtherPerson`) | کامیت `fc21287` |

#### ۲ مورد MEDIUM

| شناسه | مشکل | رفع |
|---|---|---|
| **M-1** | `redirect_after_login` مصرف نمی‌شد | مصرف در `AuthController::login` |
| **M-5** | `RecordsController::verify` بدون CSRF | افزودن CSRF |

### ۱۷.۲. حذف احراز هویت شش‌عاملی (کامیت `ec74d06`)

جریان قبلی شش‌عاملی (کد ملی + نام + نام خانوادگی + نام پدر + موبایل + تاریخ تولد) با مدل نشست‌محور جایگزین شد. جدول `identity_checks` برای audit حفظ شده. **دلیل:** UX ساده‌تر + امنیت بالاتر (نشست bearer credential است، نه داده‌های قابل حدس).

---

## 18. نقشهٔ راه و فازهای باقی‌مانده

### ۱۸.۱. نقشهٔ ۱۰ فازی (طبق `docs/07-نقشه-راه.md`)

«✅ هر ۱۰ فاز پیاده‌سازی و آزمون شده‌اند»:

| فاز | عنوان | وضعیت |
|---|---|---|
| ۱ | ویزارد پویا با مراحل سفارشی | ✅ |
| ۲ | چرخهٔ بازبینی پرونده | ✅ |
| ۳ | گردش پرداخت هزینهٔ ثبت‌نام | ✅ (رسید-محور) |
| ۴ | بخش «درآمد» در پنل مدیریت | ✅ |
| ۵ | پیامک‌های وضعیت | ✅ (الگو-محور) |
| ۶ | تصویر مجوز + قاعدهٔ «یک‌بار دریافت» | ✅ |
| ۷ | چندمجوزی و سلسله‌مراتب | ✅ |
| ۸ | احراز هویت شش‌عاملی → نشست‌محور | ✅ (تغییر یافت) |
| ۹ | انواع خدمات | ✅ (۳ سرویس) |
| ۱۰ | اشخاص حقوقی | ✅ |

### ۱۸.۲. فازهای باقی‌مانده (صریحاً ذکر شده)

1. **اتصال واقعی به درگاه پرداخت آنلاین** (زرین‌پال/PSP ایرانی) — فعلاً رسید-محور.
2. **سرویس واقعی ارسال/دریافت SMS** — فعلاً الگو-محور.
3. **اتصال API به درگاه ملی مجوزها (`mojavez.gov.ir` / `qr.mojavez.ir`)** — **این دقیقاً هدف لایهٔ واسط است!**
4. مجوزهای گردشگری و هنری/فرهنگی (فاز ۹ فقط ۳ سرویس پیاده شد).
5. تبدیل PDF باینری واقعی (بدون کتابخانه).
6. بندانگشتی واقعی با GD (تا حدودی در `b78adfd` حل شد).
7. Rate Limiting سطح IP کامل (با fail2ban).
8. نرمال‌سازی ZWNJ در جستجوی LIKE.

### ۱۸.۳. REFACTOR_PLAN v2 (۱۳ فاز — همگی ✅)

| فاز | خلاصه |
|---|---|
| ۱ | رفع ۵ حفرهٔ HIGH |
| ۲ | `Person::mobileBelongsToOtherPerson` |
| ۳ | جریان وضعیت در ایمپورت و Sms |
| ۴ | استخراج `ApplicantRegistrationService` (۴۷۸ خط) |
| ۵ | استخراج `PaymentReceiptService` (۲۳۶ خط) |
| ۶ | مرکزی‌سازی `DocTypes` |
| ۷ | `RevisionController::approve` از مدل |
| ۸ | یکسان‌سازی نقش‌ها در `addDocument`/`deleteDocument` |
| ۹ | حذف کد مرده |
| ۱۰ | Schema idempotency + `APP_VERSION` به ۲.۰.۰ |
| ۱۱+۱۲ | MEDIUM/LOW متفرقه |
| ۱۳ | تست و تأیید نهایی |

**آمار:** حذف ~۸۵۰ خط کد تکراری. فایل‌های جدید: `config/env.php`, `.env.example`, دو سرویس, `DocTypes.php`, migration v2.

---

## 19. تحلیل استاتیک و تست‌ها

### ۱۹.۱. تحلیل استاتیک (`tests/static-analysis-report.json`)

**۱۶ PASS، ۲ WARN، ۰ FAIL.**

| # | تست | وضعیت |
|---|---|---|
| ۱ | syntax PHP | ✅ |
| ۲ | ارجاعات require/include | ✅ |
| ۳ | routeها و کنترلرها | ✅ (۱۰۹ مسیر) |
| ۴ | CSRF در فرم‌های POST | ✅ (۵۹ فرم) |
| ۵ | htmlspecialchars | ⚠️ (false positive — `nl2br(htmlspecialchars(...))` درست) |
| ۶ | Prepared Statements | ✅ (۹۸۷ کوئری) |
| ۷ | AutoCancel و Priority | ✅ |
| ۸ | helperها و ثابت‌ها | ✅ |
| ۹ | جدول‌ها و مدل‌ها | ✅ |
| ۱۰ | فرم‌های ثبت‌نام | ✅ |
| ۱۱ | دکمه‌ها و اکشن‌ها | ✅ |
| ۱۲ | آپلود فایل و امنیت | ✅ |
| ۱۳ | احراز هویت و نقش‌ها | ✅ |
| ۱۴ | خروجی CSV با BOM | ✅ |
| ۱۵ | نسخهٔ چاپی A4 | ✅ |
| ۱۶ | CSV Injection | ⚠️ (در `docs/09` اصلاح شد با `sanitizeCell`) |
| ۱۷ | هدرهای امنیتی | ✅ |
| ۱۸ | `APP_VERSION` | ✅ |

### ۱۹.۲. runtime-errors-scan.json

**critical: 0, warn: 12, info: 9.** همه‌اش `@` suppress عمدی در `chmod`/`unlink` + دسترسی مستقیم به `$_GET` در viewها (با `htmlspecialchars`).

### ۱۹.۳. تست‌های smoke (۱۷۱)

`tests/smoke-test.sh` با curl + bash. **۱۷۱ موفق / ۰ ناموفق.** پوشش: صفحات عمومی، ویزارد، پیگیری، پنل مدیریت، گزارش‌ها، سرو مدارک، هدرها، فرم‌ساز، تنظیمات، رشته‌ها، پرداخت، تصویر مجوز، پیامک، بازبینی، چندمجوزی، مراحل ویزارد، سرویس‌ها، حقوقی.

### ۱۹.۴. تست‌های واحد PHP

`ConfigTest`, `FunctionalTest`, `LicenseTitleTest`, `SecurityTest`, `ServiceScopeTest`, `StatusWorkflowTest`, `WizardFormTest` + `TestCase` + `bootstrap` + `run.php`. اجرا: `php tests/run.php`.

### ۱۹.۵. تست واکنش‌گرایی (Playwright)

۸ دستگاه موبایل/تبلت/دسکتاپ. همگی سبز.

### ۱۹.۶. محیط تست

Debian 12، PHP 8.2.34، MariaDB 10.11.18.

---

## 20. نقاط یکپارچه‌سازی برای لایهٔ واسط روی درگاه ملی

این بخش **راهنمای اصلی** برای پروژهٔ یکپارچه‌شونده (لایهٔ واسط فیلترشده و فقط‌خواندنی روی `qr.mojavez.ir`) است.

### ۲۰.۱. سناریوهای اتصال پیشنهادی (به ترتیب توصیه)

#### سناریو الف: خواندن مستقیم از دیتابیس با کاربر SELECT-only (توصیه‌شده)

```sql
-- ایجاد کاربر read-only در MySQL
CREATE USER 'mojavez_ro'@'gateway-layer-ip' IDENTIFIED BY '<strong-password>';
GRANT SELECT ON handicraft_license.applicants TO 'mojavez_ro'@'gateway-layer-ip';
GRANT SELECT ON handicraft_license.persons TO 'mojavez_ro'@'gateway-layer-ip';
GRANT SELECT ON handicraft_license.job_details TO 'mojavez_ro'@'gateway-layer-ip';
GRANT SELECT ON handicraft_license.property_details TO 'mojavez_ro'@'gateway-layer-ip';
GRANT SELECT ON handicraft_license.license_files TO 'mojavez_ro'@'gateway-layer-ip';
GRANT SELECT ON handicraft_license.license_titles TO 'mojavez_ro'@'gateway-layer-ip';
GRANT SELECT ON handicraft_license.service_types TO 'mojavez_ro'@'gateway-layer-ip';
GRANT SELECT ON handicraft_license.status_log TO 'mojavez_ro'@'gateway-layer-ip';
-- نه documents (فایل‌های حساس)، نه user_accounts، نه users، نه payments
FLUSH PRIVILEGES;
```

**کوئری اصلی لایهٔ واسط (مجوزهای صادرشدهٔ معتبر):**

```sql
SELECT
    a.tracking_code AS local_tracking_code,
    a.national_code,
    a.first_name,
    a.last_name,
    a.mobile,
    a.current_status,
    a.license_state,
    a.license_valid_until,
    a.service_type_id,
    st.title AS service_title,
    jd.license_title,
    pd.province, pd.city, pd.village,
    a.created_at, a.updated_at
FROM applicants a
JOIN service_types st ON a.service_type_id = st.id
LEFT JOIN job_details jd ON jd.applicant_id = a.id
LEFT JOIN property_details pd ON pd.applicant_id = a.id
WHERE a.license_state = 'صادر شده'
  AND a.license_valid_until IS NOT NULL
  AND a.license_valid_until >= CURDATE()
  AND a.current_status IN ('تحویل مجوز', 'تأیید نهایی دریافت کد رهگیری')
ORDER BY a.updated_at DESC;
```

#### سناریو ب: افزودن endpoint JSON اختصاصی به این پروژه

اگر امکان تغییر کد منبع وجود دارد، یک endpoint خواندنی با API key اضافه کنید:

```php
// در public/index.php، قبل از router dispatch
$router->get('/api/v1/license/{trackingCode}', function ($trackingCode) {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals(GATEWAY_API_KEY, $apiKey)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        return;
    }
    $app = (new Applicant())->findByTrackingCode($trackingCode);
    if (!$app || $app['license_state'] !== 'صادر شده') {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        return;
    }
    // فیلتر کردن فقط فیلدهای مجاز برای درگاه ملی
    echo json_encode([
        'tracking_code' => $app['tracking_code'],
        'national_code' => $app['national_code'],
        'license_state' => $app['license_state'],
        'license_valid_until' => $app['license_valid_until'],
        'service_title' => ...,
        'license_title' => ...,
        // بدون مشخصات هویتی کامل، بدون فایل، بدون پرداخت
    ], JSON_UNESCAPED_UNICODE);
});
```

#### سناریو ج: scrape از HTML (نه توصیه‌شده)

صفحهٔ `/tracking/status/{trackingCode}` HTML برمی‌گرداند. قابل parse اما fragile و با rate limit ۳۰/min.

### ۲۰.۲. فیلدهای قابل افشا به درگاه ملی (whitelist)

| فیلد | منبع | افشا |
|---|---|---|
| `tracking_code` محلی | `applicants.tracking_code` | ✓ (به‌عنوان شناسهٔ محلی، نه کد ملی) |
| `national_code` | `applicants.national_code` | ✓ (به‌عنوان کلید تطبیق) |
| `license_state` | `applicants.license_state` | ✓ |
| `license_valid_until` | `applicants.license_valid_until` | ✓ |
| `service_title` | `service_types.title` | ✓ |
| `license_title` | `job_details.license_title` | ✓ |
| `province`, `city` | `property_details` | ✓ (برای گزارش منطقه‌ای) |
| `current_status` | `applicants.current_status` | ✓ (فقط در صورت terminal) |
| `updated_at` | `applicants.updated_at` | ✓ (برای sync) |
| `first_name`, `last_name` | `applicants` | ⚠️ (فقط در صورت نیاز و با احراز هویت) |
| `mobile` | `applicants.mobile` | ✗ (دادهٔ حساس) |
| `file_path`, `documents` | `documents` | ✗ (هرگز) |
| `password`, `user_accounts` | — | ✗ (هرگز) |
| `payments`, `amount` | `payments` | ✗ (مالی خصوصی) |

### ۲۰.۳. نگاشت وضعیت محلی → وضعیت ملی

| `applicants.license_state` | معادل درگاه ملی `qr.mojavez.ir` (پیشنهادی) |
|---|---|
| `صادر شده` + `license_valid_until >= CURDATE()` | معتبر / Valid |
| `صادر شده` + `license_valid_until < CURDATE()` | منقضی / Expired |
| `جاری` | در حال بررسی / Pending |
| `باطل شده` | ابطال شده / Revoked |
| `منقضی` | منقضی / Expired (سیستمی) |

### ۲۰.۴. جریان همگام‌سازی پیشنهادی

```
[درگاه ملی qr.mojavez.ir]
        ↑
        | (کد ملی + کد رهگیری ملی)
        |
[لایهٔ واسط (لایهٔ شما)]
        | - جدول نگاشت (local_tracking_code ↔ national_tracking_code)
        | - کش با TTL بر اساس updated_at
        | - فیلتر فقط مجوزهای `صادر شده` + معتبر
        | - rate limit داخلی + backoff
        | - احراز هویت API key برای کلاینت‌ها
        ↓
[دیتابیس Mojavez (read-only replica یا direct SELECT-only)]
        |
[Mojavez-v2 PHP app — منبع حقیقت محلی]
```

### ۲۰.۵. بهترین‌های یکپارچه‌سازی

1. **read replica:** به‌جای اتصال مستقیم به DB اصلی، از یک replica فقط‌خواندنی استفاده کنید تا بار کوئری روی سامانهٔ تولید نباشد.
2. **caching با `updated_at`:** لایهٔ واسط `MAX(updated_at)` آخرین sync را نگه دارد و فقط رکوردهای `updated_at > last_sync` را بخواند (incremental sync).
3. **webhook (آینده):** اگر در آینده Mojavez یک endpoint `/api/v1/webhook/license-issued` اضافه کند، لایهٔ واسط می‌تواند push-based همگام شود.
4. **manیتورینگ `/health`:** لایهٔ واسط هر ۶۰ ثانیه `GET /health` را چک کند و در صورت `degraded` به حالت fallback (کش قدیمی) برود.
5. **احترام به rate limit:** هرگز بیش از ۳۰ درخواست/min/IP به `/tracking/status/{code}` نفرستید.
6. **عدم افشای کد رهگیری در URL عمومی:** از کد ملی به‌عنوان کلید جستجوی عمومی استفاده کنید، نه کد رهگیری.

---

## 21. خطاهای شناخته‌شده، محدودیت‌ها و تله‌ها

### ۲۱.۱. تله‌های هاست اشتراکی

- **404 فایل‌های استاتیک:** `BASE_URL=/public/` + `root-htaccess.htaccess` الزامی است.
- **`.htaccess` محدود:** بدون `<LimitExcept>`, `ServerTokens`, `mod_php`. همهٔ قواعد با `<IfModule>`.
- **`ErrorDocument` با متن فارسی → mojibake** بدون `<meta charset='UTF-8'>`.

### ۲۱.۲. حساسیت به نسخهٔ PHP

- **PHP 8.5+:** `PDO::MYSQL_ATTR_INIT_COMMAND` منسوخ. `str_getcsv` منسوخ. `sid_bits_per_character=6` هشدار.
- **PHP 8.4+:** `str_getcsv` منسوخ.
- **توصیه:** PHP 8.2.x پایدارترین است.

### ۲۱.۳. تله‌های PDF.js (۱۱+ کامیت رفع)

- مرورگرها با `inline` هم دانلود می‌کنند → PDF.js روی canvas.
- هدرهای Apache با `Header always set` هدرهای PHP را بازنویسی می‌کنند → embed base64.
- CDN با CSP مشکل دارد → بارگذاری محلی.

### ۲۱.۴. تفاوت‌های MariaDB vs MySQL

- `SET ... = LAST_INSERT_ID() WHERE` نامعتبر در MariaDB.
- `INSERT ... ON DUPLICATE KEY UPDATE` با UNIQUE ممکن است خطا دهد.
- راه‌حل: `INSERT ... SELECT ... FROM DUAL WHERE NOT EXISTS`.
- `EMULATE_PREPARES=false` با پارامترهای نام‌دار تکراری مشکل دارد.

### ۲۱.۵. تله‌های امنیتی باقی‌مانده

- **rate limit مبتنی بر نشست قابل دور زدن** با drop cookie. توصیه: fail2ban.
- **ورود مدیر فقط session-based** (نه IP-based) — برخلاف ورود کاربر.
- **اعتماد به `X-Forwarded-For`** بدون reverse proxy قابل اعتماد، خطرناک است.
- **`TRACKING_REQUIRE_MOBILE=false`** — کد رهگیری به‌تنهایی کافی است.
- **`/health` بدون احراز هویت** — نسخه و وضعیت DB را افشا می‌کند (ریسک پایین).
- **CSP `'unsafe-inline'` و `'unsafe-eval'`** — برای Bootstrap و PDF.js لازم.
- **رمز پیش‌فرض مدیر `Mojavez@2026`** در `seed-admin.sql` — باید تغییر یابد.

### ۲۱.۶. محدودیت‌های ساختاری

- **هیچ API REST/JSON عمومی** برای خواندن داده‌های پرونده (همه HTML).
- **هیچ view/trigger/stored procedure دائمی** در schema.
- **هیچ تراکنش صریح در مدل‌ها** (همه atomic در سطح یک statement؛ کنترلرها برای عملیات چندمرحله‌ای تراکنش دارند).
- **هیچ ORM** — همهٔ کوئری‌ها دست‌نویس با PDO prepared statements.
- **CHANGELOG کامل نیست** — سری‌های 0.3.x، 0.4.x، 0.5.0–0.5.5، 0.7.x فقط در git log.
- **بازاستفادهٔ شمارهٔ نسخه `0.6.0`** — یک‌بار به‌ارث‌رسیده، یک‌بار جدید.

### ۲۱.۷. محدودیت‌های داده

- **`applicants.tracking_code` و `national_code` غیرقابل‌ویرایش** (طبق REFACTOR_PLAN، حفظ یکپارچگی داده).
- **`license_titles` به `service_type_id` اختصاصی شده** — نگاشت به رشته‌های ملی باید این ساختار را در نظر بگیرد.
- **وضعیت‌های اختصاصی مشاغل خانمی** (`service_type_id=2`) با `smkh.mcls.gov.ir` هماهنگ است، نه با `qr.mojavez.ir`.

---

## 22. چک‌لیست یکپارچه‌سازی (پیش از ادغام)

### ۲۲.۱. پیش‌نیازهای سمت Mojavez

- [ ] نسخهٔ `0.6.3` یا بالاتر مستقر شود.
- [ ] `APP_DEBUG=false` و `APP_ENV=production` در `.env`.
- [ ] رمز مدیر پیش‌فرض `Mojavez@2026` تغییر یابد (≥۱۲ کاراکتر).
- [ ] `CRON_SECRET` با `openssl rand -hex 32` تولید شود.
- [ ] `ADMIN_IP_ALLOWLIST` با IPهای مجاز پنل مدیریت تنظیم شود.
- [ ] `TRACKING_REQUIRE_MOBILE=true` (در صورت امکان) برای امنیت بیشتر.
- [ ] HTTPS فعال باشد (برای `session.cookie_secure` و HSTS).
- [ ] `ERROR_LOG_PATH` خارج از DocumentRoot.
- [ ] بک‌اپ روزانه از `handicraft_license` و `uploads/`.

### ۲۲.۲. پیش‌نیازهای سمت لایهٔ واسط

- [ ] کاربر MySQL `mojavez_ro` با `SELECT` فقط روی جداول whitelist (بخش ۲۰.۱).
- [ ] جدول نگاشت `(local_tracking_code, national_tracking_code, synced_at)` در schema جدا.
- [ ] کش با TTL بر اساس `updated_at`.
- [ ] rate limit داخلی (≤۳۰/min/IP برای endpointهای Mojavez).
- [ ] backoff نمایی در صورت خطای 429/500.
- [ ] manیتورینگ `GET /health` هر ۶۰ ثانیه.
- [ ] fallback به کش قدیمی در صورت `degraded`.
- [ ] API key احراز هویت برای کلاینت‌های لایهٔ واسط.
- [ ] فیلتر فقط `license_state = 'صادر شده'` + `license_valid_until >= CURDATE()`.
- [ ] عدم افشای `mobile`, `password`, `file_path`, `payments`.
- [ ] `X-Robots-Tag: noindex, nofollow` روی پاسخ‌های لایهٔ واسط.
- [ ] CSP سخت‌گیرانه (بدون `unsafe-eval`).

### ۲۲.۳. تست‌های لازم پیش از rollout

- [ ] تست اتصال read-only به DB از IP لایهٔ واسط.
- [ ] تست کوئری اصلی (بخش ۲۰.۱) با dataset واقعی.
- [ ] تست rate limit: ۳۱امین درخواست در دقیقه باید ۴۲۹ بگیرد.
- [ ] تست `/health` در حالت DB down (باید `degraded` برگرداند).
- [ ] تست عدم دسترسی به جداول حساس (`user_accounts`, `payments`) با کاربر `mojavez_ro`.
- [ ] تست احراز هویت API key لایهٔ واسط.
- [ ] تست fallback کش در صورت قطعی Mojavez.

### ۲۲.۴. مستندسازی لازم

- [ ] جدول کامل نگاشت `license_titles.title` محلی ↔ رشتهٔ ملی درگاه `qr.mojavez.ir`.
- [ ] جدول نگاشت `service_types.title` محلی ↔ نوع خدمت ملی.
- [ ] جدول نگاشت `license_state` محلی ↔ وضعیت ملی.
- [ ] نمودار جریان همگام‌سازی با timing.
- [ ] طرح بازیابی در صورت ناهماهنگی داده.

---

## 23. مرجع سریع فایل‌ها و ثابت‌ها

### ۲۳.۱. فایل‌های کلیدی

| فایل | نقش |
|---|---|
| `public/index.php` | نقطهٔ ورود + routing + security headers + IP blocklist + `/health` + `/check-cancel` |
| `config/config.php` | تمام ثابت‌های پیکربندی |
| `config/env.php` | لودر `.env` بدون وابستگی |
| `config/helper.php` | `validate_uploaded_file`, `rate_limit_ip`, `client_ip`, `setting`, `get_upload_path`, `normalize_national_code`, `normalize_mobile`, `build_notify_message` |
| `config/statuses.php` | مرجع ۱۸ وضعیت (Single Source of Truth) |
| `config/workflow.php` | ماتریس انتقال نقش‌ها |
| `config/jdf.php` | تبدیل میلادی↔جلالی |
| `core/Database.php` | Singleton PDO |
| `core/Router.php` | مسیریاب ساده |
| `core/Session.php` | نشست + CSRF + rate limit session-based |
| `core/Validator.php` | اعتبارسنجی (کد ملی، موبایل، ...) |
| `core/CsvExporter.php` | خروجی CSV با Formula Injection protection |
| `core/AutoLoader.php` | PSR-4-like lazy loader |
| `app/Controllers/Controller.php` | guards (`requireLogin`, `requireRole`, `validateStatusTransition`) |
| `app/Models/Applicant.php` | قلب سامانه + `generateTrackingCode()` |
| `app/Models/Person.php` | لنگر هویت |
| `app/Models/LicenseAccessRequest.php` | توکن ۷۲ ساعته |
| `app/Models/LicenseFile.php` | تصویر مجوز |
| `app/Services/AutoCancelService.php` | لغو خودکار |
| `app/Services/FileStorage.php` | ذخیرهٔ مدارک |
| `database/schema.sql` | مرجع توسعه (بدون ALTER) |
| `database/install.sql` | نصب idempotent (production) |
| `database/seed-admin.sql` | حساب مدیر پیش‌فرض |
| `deploy/docker/` | Docker stack کامل |
| `deploy/shared-hosting/install.php` | نصب‌کنندهٔ وب |
| `deploy/shared-hosting/check.php` | بررسی پیش‌نیازها |
| `.htaccess` + `public/.htaccess` + `uploads/.htaccess` | سه‌لایهٔ امنیت Apache |
| `CHANGELOG.md` | تاریخچهٔ نسخه‌ها (۹۳۸ خط) |
| `REFACTOR_PLAN.md` | طرح بازنویسی v2 |
| `docs/` | ۱۰ سند فارسی کامل |

### ۲۳.۲. ثابت‌های مهم برای لایهٔ واسط

| ثابت | مقدار | کاربرد |
|---|---|---|
| `APP_VERSION` | `'0.6.3'` | در `/health` بازگردانده می‌شود |
| `AUTO_CANCEL_DAYS` | `14` | لغو خودکار |
| `PRIORITY_DAYS` | `12` | اولویت‌بندی |
| `PUBLIC_RATE_LIMIT_PER_MIN` | `30` | rate limit endpointهای عمومی |
| `TRACKING_REQUIRE_MOBILE` | `false` (پیش‌فرض) | امنیت کد رهگیری |
| `LicenseAccessRequest::TOKEN_HOURS` | `72` | اعتبار توکن تصویر مجوز |
| `MAX_UPLOAD_SIZE` | `2 MiB` | حد آپلود |
| `ALLOWED_EXTENSIONS` | `['jpg','jpeg','png','pdf']` | پسوندهای مجاز |

### ۲۳.۳. دستورات مفید

```bash
# کلون
git clone https://github.com/andrbi109/Mojavez-v2.git  # دسترسی با توکن ارائه‌شده توسط تیم توسعه

# اجرای محلی
php -S localhost:8000 -t public public/router.php

# نصب دیتابیس
mysql -u root -p < database/install.sql
mysql -u root -p handicraft_license < database/seed-admin.sql

# اجرای تست‌ها
./tests/smoke-test.sh
php tests/run.php
python3 tools/static-analysis.py
python3 tools/responsive-test.py

# Docker
cd deploy/docker && cp .env.example .env && docker compose up -d --build

# بررسی سلامت
curl http://localhost:8000/health

# لغو خودکار دستی
curl -H "X-Cron-Secret: $CRON_SECRET" http://localhost:8000/check-cancel
```

### ۲۳.۴. ورود پیش‌فرض

- **پنل مدیریت:** `/admin/login` — `admin` / `Mojavez@2026` (تغییر الزامی)
- **پنل کاربری:** `/login` — (با موبایل)

---

## پیوست: نمودار جریان یکپارچه‌سازی پیشنهادی

```
┌─────────────────────────────────────────────────────────────────┐
│                    درگاه ملی مجوزها qr.mojavez.ir                │
│              (منبع مرجع کشوری — فقط خواندن از سوی لایه واسط)     │
└────────────────────────────┬────────────────────────────────────┘
                             │ کد ملی + کد رهگیری ملی
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│            لایهٔ واسط اختصاصی، فیلترشده، فقط‌خواندنی              │
│  ┌────────────┐  ┌──────────────┐  ┌──────────────────────────┐ │
│  │ جدول نگاشت │  │ کش با TTL    │  │ API key احراز هویت       │ │
│  │ local↔nat  │  │ بر اساس      │  │ برای کلاینت‌ها           │ │
│  │            │  │ updated_at   │  │                          │ │
│  └────────────┘  └──────────────┘  └──────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ فیلتر: license_state='صادر شده' AND valid_until>=CURDATE()│ │
│  │ Rate limit داخلی + backoff + fallback کش                  │ │
│  │ CSP سخت‌گیرانه + X-Robots-Tag noindex                      │ │
│  └────────────────────────────────────────────────────────────┘ │
└────────────────────────────┬────────────────────────────────────┘
                             │ SELECT-only (mojavez_ro user)
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                Mojavez-v2 (منبع حقیقت محلی)                     │
│  PHP 8 خالص + MySQL/MariaDB + ۲۸ جدول + ۲۷ مدل                  │
│  applicants.tracking_code (HG-XXXXXXXX) ← کلید اصلی محلی        │
│  applicants.license_state (۴ مقدار) ← فیلتر اصلی                │
│  applicants.license_valid_until ← معیار اعتبار                  │
│  /health (مانیتورینگ) + /tracking/status/{code} (HTML)          │
│  CRON_SECRET محافظ /check-cancel                                │
└─────────────────────────────────────────────────────────────────┘
```

---

**پایان مستند.**

> این مستند از تحلیل کامل کد منبع (کامیت `b78adfd`، نسخهٔ `0.6.3`)، تاریخچهٔ ۱۲۳ کامیت، ۹۳۸ خط CHANGELOG، ۱۰ سند فارسی داخلی، و گزارش‌های تحلیل استاتیک/runtime/test استخراج شده. در صورت تغییر نسخهٔ Mojavez، این مستند باید به‌روزرسانی شود. تاریخچهٔ کامل تغییرات در `CHANGELOG.md` و `git log` موجود است.
