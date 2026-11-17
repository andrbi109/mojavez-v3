# راهنمای مشارکت در mojavez-v3

> این سند راهنمای عملی برای توسعه‌دهندگان و ایجنت‌های هوش مصنوعی است که به mojavez-v3 مشارکت می‌کنند. مرجع رسمی `docs/CODING_CONVENTIONS.md` و `docs/DEBT_REVIEW.md` و `docs/00-MASTER.md §26` است.

---

## ۱. ساختار ماژولار و ModuleInterface

هر ماژول در `app/Modules/<Name>/` یک فایل `Module.php` دارد که `app/Modules/ModuleInterface.php` را پیاده‌سازی می‌کند. قرارداد شامل هفت متد است:

```php
interface ModuleInterface
{
    public function name(): string;          // snake_case: 'core', 'national_gateway', ...
    public function version(): string;        // SemVer: '1.0.0'
    public function register(ModuleLoader $loader): void;  // bindings into Container
    public function boot(ModuleLoader $loader): void;        // cross-module wiring
    public function routes(): array;          // route definitions for Router
    public function migrations(): array;      // migration filenames
    public function subscribers(): array;     // event => handlers for EventBus
    public function ownedTables(): array;     // tables this module owns (CI enforces)
}
```

ترتیب اجرا توسط `ModuleLoader` در `public/index.php` بخش ۱۲: `discover()` → `registerAll()` → `bootAll()` → `collectRoutes()` → `collectMigrations()`. هر migration توسط `MigrationRunner` با filename + SHA-256 checksum ردگیری می‌شود تا تغییر در فایل migration موجود re-apply شود.

---

## ۲. اضافه کردن ماژول جدید

برای اضافه کردن ماژول جدید (مثلاً `Modules/Billing`):

۱. ساختار پوشه مطابق `CODING_CONVENTIONS §3`:
```
app/Modules/Billing/
├── Controllers/
├── Services/
├── Domain/
├── Infrastructure/
├── Persistence/
├── migrations/
├── Module.php       # implements ModuleInterface
└── README.md
```

۲. پیاده‌سازی `Module.php` با هفت متد. در `register()` تمام repository interface‌ها به PDO وصل شوند. در `ownedTables()` فقط جدول‌هایی که این ماژول صاحب آنهاست ذکر شوند.

۳. افزودن ماتریس ownership به `docs/SCHEMA_OWNERSHIP.md §2` و dict در `tools/check_schema_ownership.py`.

۴. افزودن `Billing` به ترتیب بارگذاری در `ModuleLoader` (اگر وابستگی خاصی به ماژول دیگر دارد).

۵. نوشتن `README.md` ماژول با ساختار `CODING_CONVENTIONS §12.2` (مسئولیت، ساختار، API عمومی، وابستگی‌ها، migrationها، تست).

۶. افزودن حداقل یک test در `tests/Unit/Billing*Test.php`.

---

## ۳. اضافه کردن migration

هر migration در `app/Modules/<Owner>/migrations/YYYYMMDD_NNN_description.sql`. نامگذاری تاریخ‌محور است تا ترتیب واضح باشد. قواعد:

- migration باید idempotent باشد (`CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` برای MariaDB 10.5+).
- فقط به جدول‌هایی دست بزند که ماژول مالک آنهاست (در غیر این صورت، در PR توضیح و تأیید maintainer ماژول مالک لازم است).
- پس از اعمال، `MigrationRunner` آن را در جدول `migrations` با checksum ثبت می‌کند. اگر بعداً فایل را تغییر دهید، checksum متفاوت است و دوباره اعمال می‌شود — پس migration را ویرایش نکنید، migration جدید بسازید.

برای rollback (که v3 به‌صورت خودکار پشتیبانی نمی‌کند)، یک migration جدید بنویسید که `DROP` یا `ALTER` معکوس انجام دهد. تمام migrationها در `app/Modules/Core/Module.php::migrations()` (و مشابه برای سایر ماژول‌ها) به ترتیب آرایه اجرا می‌شوند.

---

## ۴. CODING_CONVENTIONS (no Composer، strict_types، readonly، final)

طبق `docs/CODING_CONVENTIONS.md`:

- **PHP 8.2 خالص، بدون Composer، بدون فریم‌ورک.** سطح حمله کوچک، کنترل کامل، پایداری درازمدت. هر الگو که نیاز به کتابخانه دارد باید دستی و با کیفیت پیاده شود.
- **`declare(strict_types=1)`** در هر فایل بدون استثنا.
- **`readonly`** روی تمام value object‌ها و DTOها. **`final`** روی تمام کلاس‌ها (مگر اینکه صریحاً برای وراثت طراحی شده باشد).
- **Constructor injection** پیش‌فرض؛ setter injection فقط برای حل وابستگی‌های چرخه‌ای با کامنت توضیح.
- **Type declarations** روی هر متد و پارامتر. `mixed` ممنوع؛ `array` بدون کلید ممنوع (همیشه PHPDoc `@return Foo[]`).
- **enum به‌جای constant عددی یا string literal.** ذخیره در DB به‌صورت `VARCHAR(32)` با value (نه MySQL `ENUM`).
- **Prepared statements همیشه.** هیچ string-concat SQL با `$var`. `tools/static_analysis.py` این را بررسی می‌کند.
- **`htmlspecialchars`** روی هر echo از محتوای پویا.
- **وابستگی‌های ممنوع:** Composer، فریم‌ورک، ORM، کتابخانه HTTP، کتابخانه template، کتابخانه logging. استثناها: PECL extensions موجود، Bootstrap 5 و Vazirmatn و PDF.js (همه self-hosted).
- **نام‌گذاری:** کلاس `PascalCase` با suffix لایه (`*Service`, `*Controller`, `*Repository`, `*Adapter`, `*Middleware`, ...). متد `camelCase`. ثابت `UPPER_SNAKE_CASE`.

---

## ۵. تست‌ها (TestCase + assert helpers)

همهٔ test‌های واحد در `tests/Unit/*Test.php` از `tests/TestCase.php` مشتق می‌شوند. هر test فایلی است که یک آبجکت برمی‌گرداند (نه کلاسی که PHPUnit آن را instantiate می‌کند). assert helper‌ها: `assertEquals`, `assertNotEquals`, `assertTrue`, `assertFalse`, `assertNull`, `assertNotNull`, `assertContains`, `assertMatchesRegularExpression`, `expectException`. متدهای چرخهٔ عمر: `setUp()`, `tearDown()`, `isSkipped()`, `skipReason()`.

```php
// tests/Unit/PatternMatcherTest.php (نمونه ساختار)
return new class extends \App\Tests\TestCase {
    public function name(): string { return 'PatternMatcherTest'; }
    public function run(): void {
        $m = new \App\Modules\NationalGateway\Services\PatternMatcher();
        $r = $m->matchNumeric('0720593107', '072****107');
        $this->assertTrue($r->matched, 'national code anchor alignment');
        // ...
    }
};
```

پوشش هدف (`CODING_CONVENTIONS §8.2`): حداقل ۸۰٪ برای `app/Modules/Core/Domain/`، ۹۰٪ برای `ApplicantSyncAdapter.php` (ACL)، ۱۰۰٪ برای `Normalizer.php`. fixture‌های واقعی HAR scraper در `tests/fixtures/har/` قرار دارند. اجرا با `php tests/run.php` یا در CI.

---

## ۶. PR checklist

هر PR باید این چک‌لیست را پاس کند:

- [ ] Lint همهٔ فایل‌های PHP سبز است (`php -l` روی هر فایل در `app/`, `public/`, `scripts/`).
- [ ] `python3 tools/static_analysis.py` بدون FAIL اجرا می‌شود.
- [ ] `python3 tools/check_schema_ownership.py` سبز است (اگر migration اضافه شده، مالکیت در ماتریس و dict به‌روز شده باشد).
- [ ] `php tests/run.php` با ۰ failure اجرا می‌شود.
- [ ] `./tests/smoke-test.sh --no-http` سبز است.
- [ ] اگر feature جدید، حداقل یک test واحد اضافه شده.
- [ ] PHPDoc روی هر متد public و کلاس (`CODING_CONVENTIONS §12.1`).
- [ ] اگر تغییر breaking، افزایش نسخه-major در `VERSION` و یادداشت در `CHANGELOG.md`.
- [ ] commit message طبق قالب `<type>(<scope>): <subject>` (مثلاً `feat(national_gateway): add CircuitBreaker`).

CI در `.github/workflows/ci.yml` این موارد را به‌صورت خودکار اجرا می‌کند؛ PR بدون CI سبز مرج نمی‌شود.

---

## ۷. روال DEBT_REVIEW

طبق `docs/DEBT_REVIEW.md`، در پایان هر فاز از نقشهٔ راه (`§19`، هفت فاز)، بازبینی debt ثبت می‌شود. هر item debt شامل: شناسه (`D-X-N` که X شمارهٔ فاز و N شمارهٔ item است)، توضیح، موقعیت (فایل/خط)، شدت، راهکار پیشنهادی.

چهار سطح شدت:

- `critical` — ریسک امنیتی یا فاجعهٔ تولید، رفع پیش از فاز بعدی.
- `high` — ریسک عملکرد یا maintainability، رفع در فاز بعدی.
- `medium` — نگرانی کیفیت کد، رفع در ۲ فاز بعدی.
- `low` — نگرانی زیبایی‌شناختی، در فرصت مناسب.

قاعده: اگر debt carried forward بیش از ۳ مورد باشد، فاز بعدی باید شامل «پاکسازی debt» باشد — حتی اگر به‌معنای تأخیر در feature باشد. این از انباشت debt و نیاز به refactor مجدد جلوگیری می‌کند (جنبهٔ پنهان ۵.۱۰). نمونه فعلی از DEBT_REVIEW: D-1-1 (EventBus placeholder)، D-1-4 (install.php پیاده‌سازی نشده)، D-1-5 (database/schema.sql ساخته نشده). هر کدام با فاز و راهکار مشخص ردیابی می‌شود.
