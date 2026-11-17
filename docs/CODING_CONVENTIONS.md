# قراردادهای کدنویسی — mojavez-v3

> **نسخه:** 1.0 · **تاریخ:** ۱۴۰۵/۰۸/۲۶ (۲۰۲۶-۱۱-۱۶)
> **هدف:** تعریف قراردادهای مشترک کدنویسی برای همهٔ ماژول‌های mojavez-v3، برای کاهش drift فرهنگی در توسعهٔ موازی (رفع جنبهٔ پنهان ۵.۱۱ از سند استراتژی-ریسک).
> **مخاطب:** توسعه‌دهندگان اصلی، ایجنت‌های هوش مصنوعی، و code reviewerها.

---

## ۱. فلسفهٔ پایه

mojavez-v3 با فلسفهٔ «PHP 8.2 خالص، بدون Composer، بدون فریم‌ورک» ساخته می‌شود. این انتخاب آگاهانه است و دلایل آن:

۱. **استقرار ساده:** هیچ وابستگی خارجی برای نصب نیست. کپی فایل + اجرای `schema.sql` = نصب کامل.
۲. **کنترل کامل:** هیچ به‌روزرسانی وابستگی第三方 دیسی را دیکته نمی‌کند.
۳. **پایداری درازمدت:** کد ده سال دیگر همان‌طور کار می‌کند، بدون نگرانی دربارهٔ end-of-life یک کتابخانه.
۴. **امنیت:** سطح حملهٔ کوچک‌تر؛ هیچ کد third-party در vendor directory نیست.

این فلسفه به‌معنای **سخت‌گیری در کدنویسی** است: هر الگو که نیاز به کتابخانه دارد باید به‌صورت دستی و با کیفیت بالا پیاده‌سازی شود.

---

## ۲. قواعد PHP

### ۲.۱. تنظیمات پایه

همهٔ فایل‌های PHP:

```php
<?php
declare(strict_types=1);

namespace App\<Module>\<Layer>;

// کد
```

- `declare(strict_types=1)` در هر فایل — بدون استثنا.
- `namespace` مطابق ساختار فیزیکی (`app/Modules/Core/Services/Foo.php` → `App\Modules\Core\Services\Foo`).
- `<?php` در ابتدای فایل، بدون BOM، بدون خط خالی قبل.
- پایان فایل: خط خالی، بدون `?>`.

### ۲.۲. type declarations

- همهٔ متدها: نوع بازگشتی + نوع هر پارامتر.
- никогда `mixed` یا `array` بدون کلید — همیشه typed array با PHPDoc `@return Foo[]`.
- nullable: `?Type` نه `Type|null`.
- union types فقط وقتی واقعاً ضروری است.

```php
public function findById(int $id): ?Applicant { ... }
public function all(): array { ... }  // bad
/** @return Applicant[] */ public function all(): array { ... }  // good
```

### ۲.۳. readonly و immutability

- همهٔ value objects `readonly`.
- همهٔ DTOها `readonly`.
- setter injection فقط برای حل وابستگی‌های چرخه‌ای (با کامنت توضیح).
- در غیر این صورت، constructor injection.

### ۲.۴. enum‌ها

به‌جای constant‌های عددی یا string‌های literal:

```php
enum LicenseState: string {
    case Pending = 'pending';
    case AutoConfirmed = 'auto_confirmed';
    case OperatorFlag = 'operator_flag';
    case Confirmed = 'confirmed';
    case NotFound = 'not_found';
}
```

ذخیره در DB به‌صورت `VARCHAR(32)` با value (نه INT). این خوانایی را بالا می‌برد و تغییر را امن می‌کند.

---

## ۳. ساختار فایل و namespace

```
app/
├── Modules/
│   └── <Name>/
│       ├── Controllers/        # Http layer (thin controllers)
│       ├── Services/           # Use cases (orchestration)
│       ├── Domain/             # Entities, Value Objects
│       ├── Infrastructure/     # Implementations (PDO, HTTP client, ...)
│       ├── Persistence/        # Repository implementations
│       ├── migrations/
│       ├── Module.php          # implements ModuleInterface
│       └── README.md
├── Shared/
│   ├── Contracts/              # Interfaces
│   ├── Support/                # Cross-cutting (Logger, Config, ...)
│   ├── Domain/                 # Shared Value Objects
│   └── Persistence/            # Connection, MigrationRunner
├── Container.php
└── Http/                       # Router, Session, Csrf, Request, Response
```

### قواعد:

۱. **控制器 نازک:** کنترلر فقط request parse + call service + response build. هیچ logic کسب‌وکار.
۲. **سرویس‌های use case:** هر متد سرویس یک use case است (مثلاً `RegisterApplicant`, `IssueLicense`, `SyncFromNationalGateway`).
۳. **Domain ایزوله:** Entity و Value Object هیچ وابستگی به PDO، HTTP، یا چارچوب ندارند.
۴. **Infrastructure قابل جایگزینی:** همهٔ پیاده‌سازی‌ها به Interface وابسته‌اند.

---

## ۴. نام‌گذاری

### ۴.۱. کلاس‌ها

- `PascalCase`: `ApplicantRegistrationService`.
- Suffix بر اساس لایه:
  - `*Service` — use case.
  - `*Controller` — HTTP.
  - `*Repository` — پیاده‌سازی داده.
  - `*RepositoryInterface` — قرارداد داده.
  - `*Adapter` — ACL/translation.
  - `*Validator` — اعتبارسنجی.
  - `*Event` — رویداد.
  - `*Listener` — مشترک رویداد.
  - `*Job` — کار async.
  - `*Command` — دستور CLI.
  - `*Middleware` — میان‌افزار HTTP.
  - `*Dto` — DTO (در PHPDoc فقط).
  - `*Exception` — مستثنی.

### ۴.۲. متدها

- `camelCase`: `findById`, `applyDelta`, `translateUpstreamToDomain`.
- نام متد عمل را توصیف می‌کند: `get*` (без side effect), `set*` (mutate), `is*`/`has*` (boolean), `apply*`/`run*`/`execute*` (action).

### ۴.۳. متغیرها

- `camelCase`: `$applicantId`, `$nationalLicense`.
- نام‌های تک‌حرفی فقط برای شمارندهٔ حلقه (`$i`, `$j`) یا مختصات (`$x`, `$y`).
- همیشه نام‌گذاری معنایی: `$a` بد، `$applicant` خوب.

### ۴.۴. ثابت‌ها

- `UPPER_SNAKE_CASE`: `MAX_RETRY_COUNT`, `DEFAULT_TIMEOUT_SECONDS`.
- در `const` کلاس یا `enum`.

---

## ۵. SQL و پایگاه داده

### ۵.۱. نام‌گذاری

- جدول: `snake_case` جمع: `applicants`, `national_licenses`, `sync_audit_log`.
- ستون: `snake_case`: `tracking_code`, `license_state`, `created_at`.
- کلید خارجی: `<referenced_table_singular>_id`: `applicant_id`, `user_id`.
- کلید اصلی: همیشه `id INT AUTO_INCREMENT PRIMARY KEY`.
- timestampها: `created_at`, `updated_at`, `deleted_at` (همیشه `DATETIME`).

### ۵.۲. type‌ها

- شناسه‌ها: `INT UNSIGNED`.
- متن کوتاه: `VARCHAR(n)` با طول واقعی.
- متن بلند: `TEXT` (یا `MEDIUMTEXT` اگر > ۶۴KB).
- تاریخ: `DATETIME` (نه `TIMESTAMP` — جلوگیری از 2038 problem).
- boolean: `TINYINT(1)` با `0`/`1`.
- status: `VARCHAR(32)` با value از enum (نه `ENUM` MySQL — پرهیز از ALTER پرهزینه).
- JSON: `JSON` (MariaDB 10.2+).

### ۵.۳. ایندکس‌ها

- هر ستون در `WHERE`/`JOIN`/`ORDER BY` باید ایندکس داشته باشد.
- composite index برای کوئری‌های چندستونی: `INDEX idx_a_b (a, b)`.
- unique constraint: `UNIQUE KEY uq_<table>_<cols>`.
- نام‌گذاری: `idx_<table>_<purpose>` برای index، `uq_<table>_<cols>` برای unique.

### ۵.۴. کلید خارجی

- همیشه `FOREIGN KEY` صریح: `FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE`.
- `ON DELETE`:
  - `CASCADE` برای جداول فرعی وابسته.
  - `SET NULL` برای ارجاعات اختیاری.
  - `RESTRICT` برای ارجاعات اجباری (مثلاً `users` نمی‌تواند حذف شود اگر `applicants` به آن ارجاع دارد).
- soft-delete (`deleted_at`) پیش از CASCADE در سطح app.

---

## ۶. امنیت

### ۶.۱. Prepared Statements

هیچ‌گاه concatenation SQL:

```php
// BAD
$sql = "SELECT * FROM applicants WHERE id = $id";

// GOOD
$stmt = $pdo->prepare('SELECT * FROM applicants WHERE id = ?');
$stmt->execute([$id]);
```

### ۶.۲. CSRF

هر فرم mutating باید CSRF token داشته باشد:

```php
<input type="hidden" name="_csrf" value="<?= Csrf::token() ?>">
```

و در کنترلر:

```php
if (!Csrf::verify($_POST['_csrf'] ?? '')) {
    throw new CsrfException();
}
```

### ۶.۳. خروجی HTML

همیشه escape:

```php
<?= htmlspecialchars($userInput, ENT_QUOTES, 'UTF-8') ?>
```

یا با template helper: `<?= e($userInput) ?>`.

### ۶.۴. احراز هویت

- session برای پنل مدیریت.
- API key با scope برای API (`api_keys` جدول).
- هرگز hardcoded credential در کد. همه در `.env`.

### ۶.۵. ورودی کاربر

همیشه validate و sanitize:

```php
$input = Validator::make($_POST, [
    'national_code' => 'required|pattern:/^\d{10}$/',
    'mobile' => 'required|pattern:/^09\d{9}$/',
])->validate();
```

---

## ۷. لاگ‌گذاری

از `StructuredLogger` استفاده شود (نه `error_log` مستقیم):

```php
$this->logger->info('Applicant registered', [
    '_module' => 'core',
    'applicant_id' => $applicant->id,
    'tracking_code' => $applicant->trackingCode,
]);

$this->logger->warning('Sync conflict skipped', [
    '_module' => 'national_gateway',
    'applicant_id' => $applicant->id,
    'local_updated_at' => $applicant->updatedAt->format('c'),
    'upstream_seen_at' => $license->seenAt->format('c'),
]);
```

همیشه context شامل `_module` برای فیلتر در grep/ELK.

---

## ۸. تست

### ۸.۱. نام‌گذاری

- تست واحد: `<ClassName>Test.php` در `tests/Unit/`.
- تست یکپارچه‌سازی: `<Feature>Test.php` در `tests/Integration/`.
- Contract test: fixture JSON در `tests/Contract/<Area>/<scenario>.json`.
- Smoke test: `tests/smoke-test.sh`.

### ۸.۲. پوشش

- حداقل ۸۰٪ برای `app/Modules/Core/Domain/`.
- حداقل ۹۰٪ برای `app/Modules/NationalGateway/Services/ApplicantSyncAdapter.php` (ACL).
- ۱۰۰٪ برای `app/Shared/Support/Normalizer.php` (نرمال‌سازی کد ملی/موبایل).

### ۸.۳. fixture‌ها

- HAR واقعی upstream در `tests/fixtures/har/` برای رگرسیون scraper.
- snapshot داده در `tests/fixtures/snapshots/` برای مقایسه schema.

---

## ۹. Git و commit

### ۹.۱. پیام commit

```
<type>(<scope>): <subject>

<body>

<footer>
```

- `type`: `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `perf`, `security`.
- `scope`: نام ماژول (`core`, `national_gateway`, `api`, `queue`, ...).
- `subject`: فعل امر حال، ≤ ۷۲ کاراکتر.

مثال:

```
feat(national_gateway): add CircuitBreaker to MojavecClient

CircuitBreaker with three states (closed/open/half-open) wraps every
upstream call. Threshold: 3 failures in 60s. Cooldown: 5 minutes.

Closes #42
```

### ۹.۲. branch

- `main` — همیشه deploy-ready.
- `feat/<scope>-<short-desc>` — feature branch.
- `fix/<issue>-<short-desc>` — bugfix branch.

### ۹.۳. PR

- حداقل یک reviewer.
- CI سبز.
- اگر migration دارد: بررسی `SCHEMA_OWNERSHIP.md`.

---

## ۱۰. وابستگی‌های ممنوع

- ❌ `composer.json` یا هر composer package.
- ❌ فریم‌ورک (Laravel, Symfony, ...).
- ❌ ORM (Doctrine, Eloquent, ...).
- ❌ کتابخانه HTTP (Guzzle, ...). از `cURL` بومی استفاده شود.
- ❌ کتابخانه template (Twig, Blade, ...). از PHP بومی استفاده شود.
- ❌ کتابخانه logging (Monolog, ...). از `StructuredLogger` سفارشی استفاده شود.

### استثناها (با تأیید maintainer):

- ✅ PECL extensions که در runtime موجودند (`pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `curl`, `openssl`).
- ✅ Frontend: Bootstrap 5 (self-hosted), Vazirmatn font (self-hosted), PDF.js (self-hosted).

---

## ۱۱. فارسی و RTL

- تمام UI و پیام‌ها به فارسی.
- کد و identifierها به انگلیسی (مثلاً `license_state` نه `وضعیت_مجوز`).
- تاریخ در DB به `DATETIME` میلادی؛ نمایش به شمسی با `PersianDate` helper.
- collation همیشه `utf8mb4_unicode_ci`.

---

## ۱۲. مستندسازی

### ۱۲.۱. PHPDoc

هر کلاس، متد public، و property باید PHPDoc داشته باشد:

```php
/**
 * ثبت متقاضی جدید و تولید کد رهگیری.
 *
 * @param ApplicantInput $input دادهٔ ورودی از فرم
 * @return Applicant متقاضی ثبت‌شده
 * @throws ValidationException اگر ورودی نامعتبر باشد
 * @throws DuplicateApplicantException اگر کد ملی تکرار باشد
 */
public function register(ApplicantInput $input): Applicant { ... }
```

### ۱۲.۲. README ماژول

هر ماژول `README.md` دارد با:

```markdown
# <Module Name>

## مسئولیت
...

## ساختار
...

## API عمومی (پارادوکس)
...

## وابستگی‌ها
- Modules/Core (read via Interface)
- Modules/Queue (publish events)

## migration‌ها
...

## تست
...
```

---

## ۱۳. تغییر قرارداد

اگر نیاز به تغییر این قراردادها بود:

۱. PR با توضیح چرایی + مثال.
۲. تأیید maintainer اصلی.
۳. به‌روزرسانی این فایل + bump نسخه.
۴. ثبت در `CHANGELOG.md`.
۵. اگر breaking، اعلام در channel توسعه.

---

*پایان قراردادهای کدنویسی.*
