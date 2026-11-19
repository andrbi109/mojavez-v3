# معماری کلی سامانهٔ mojavez-v3

> این سند معماری اجرایی سامانهٔ mojavez-v3 را بر اساس کد واقعی پروژه تشریح می‌کند. مرجع راهبردی سند `docs/00-MASTER.md` (بخش ۴) است؛ اینجا بر پیاده‌سازی تمرکز می‌کنیم.

---

## ۱. لایه‌بندی و ماژول‌بندی

mojavez-v3 به‌صورت یک Monorepo با پنج ماژول مستقل سازماندهی شده است که هر کدام `Module.php` خود را پیاده‌سازی می‌کنند (`app/Modules/ModuleInterface.php`). ماژول‌ها بر اساس وابستگی بارگذاری می‌شوند: ابتدا `register()` (declare bindings)، سپس `boot()` (cross-module wiring)، سپس `routes()` و `migrations()` به Router و MigrationRunner متصل می‌شوند. ماژول‌های فعال:

- `Modules/Core` (نسخه ۱.۰.۰) — صاحب ۲۳ جدول وارث از v2 (applicants, persons, payments, documents, license_files, settings, …)؛ مقر احراز هویت، پنل مدیریت و ویزارد ثبت‌نام.
- `Modules/NationalGateway` (نسخه ۱.۰.۰) — صاحب ۵ جدول (`national_licenses`, `national_lookups`, `sync_audit_log`, `national_filters`, `national_batches`)؛ scraper درگاه ملی + اتصال طلایی.
- `Modules/Api` — endpoint‌های `/api/v1/*` با احراز هویت API key مبتنی بر scope.
- `Modules/Queue` — جداول meta (`jobs`, `batches`, `events`, `worker_state`) و کلاس `Worker` برای اجرای batch.
- `Modules/Reporting` — جدول `report_runs` و سرویس‌های گزارش‌گیری.

قرارداد رسمی هر ماژول در `app/Modules/ModuleInterface.php` تعریف شده است: `name()`, `version()`, `register()`, `boot()`, `routes()`, `migrations()`, `subscribers()`, `ownedTables()`. ماتریس `ownedTables` توسط `tools/check_schema_ownership.py` پیش از هر merge بررسی می‌شود (`docs/SCHEMA_OWNERSHIP.md`).

---

## ۲. Container + Dependency Injection

`app/Container.php` یک Container سبک با `bind(interface, closure)` و `make(interface)` است. هنگام boot، Core تمام repository interface‌ها را به پیاده‌سازی PDO وصل می‌کند (مثال از `app/Modules/Core/Module.php`):

```php
$container->bind(
    \App\Shared\Contracts\ApplicantRepositoryInterface::class,
    fn () => new \App\Modules\Core\Persistence\PdoApplicantRepository(
        $container->make(\App\Shared\Persistence\Connection::class)
    )
);
```

NationalGateway نیز با همین الگو سرویس‌های زنجیره‌ای خود را می‌سازد: `AntiDetectionMiddleware` → `HttpClient` → `MojavecClient` → `LicenseService` → `ApplicantSyncAdapter` → `NationalSyncOrchestrator`. تمام کلوزرها به‌صورت lazy ساخته می‌شوند و در اولین فراخوانی `make()` cache می‌شوند (singleton در عمل).

---

## ۳. Front Controller — `public/index.php`

تنها نقطه ورود هر درخواست HTTP است و در ۱۳ گام انجام می‌شود (`public/index.php`):

۱. تعریف `ROOT_PATH` و بارگذاری `Environment` از `.env`.
۲. تنظیم `error_reporting` و `display_errors` بر اساس `APP_DEBUG`.
۳. نصب `set_exception_handler` (JSON برای AJAX، HTML برای بقیه) و `set_error_handler` (تبدیل به `ErrorException`).
۴. `spl_autoload_register` نگاشت `App\` به `app/` (بدون Composer).
۵. `StructuredLogger` به `storage/logs/app-YYYY-MM-DD.log`.
۶. انتشار هدرهای امنیتی سراسری (CSP, X-Frame-Options, COOP, CORP, X-Robots-Tag, HSTS روی HTTPS).
۷. بررسی `IP_BLOCKLIST` و خروج زودهنگام با ۴۰۳.
۸. ساخت `Container` و `boot(ROOT_PATH)`.
۹. `Session::start` (با `use_strict_mode`، HttpOnly، SameSite=Lax، نام `MOJAVEZSESSID`).
۱۰. ساخت `Request` از globals.
۱۱. ساخت `Router` و اعمال `config/routes.php` (meta routes).
۱۲. کشف ماژول‌ها توسط `ModuleLoader`، `registerAll`، `bootAll`، `collectRoutes`، `collectMigrations`، اجرای `MigrationRunner::run()` (به‌جز `/health`, `/check-cancel`, `/check-sync`).
۱۳. `Router::match(method, path)`، اعمال Rate Limit سپس CSRF روی mutating، resolve handler و `$response->send()`.

استثناها به‌صورت تمیز مدیریت می‌شوند: `RouteNotFoundException → 404`، `MethodNotAllowedException → 405`، `CsrfException → 419`.

---

## ۴. Router، EventBus و MigrationRunner

**Router** (`app/Http/Router.php`) یک first-match regex-based router است. مسیرها به‌صورت `add(method, '/api/licenses/{id}', ClassName::class, 'methodName')` ثبت می‌شوند. placeholder‌ها: `{name}` برابر `[^/]+` و `{name:\d+}` برابر regex سفارشی. ترتیب ثبت اهمیت دارد؛ مسیرهای خاص قبل از catch-all قرار می‌گیرند.

**EventBus** (`app/Events/EventBus.php`) یک pub/sub درون‌پروسه با persist در جدول `events` است. سه subscriber در `boot` ثبت می‌شوند (مرجع `§13.1`):

- `LogSubscriber` (wildcard `*`) — لاگ همهٔ رویدادها.
- `SyncSubscriber` روی `LicenseIssuedOnNational` — enqueues `SyncJob`.
- `NotificationSubscriber` روی `ApplicantMatched` — ارسال SMS + in-app notification.

**MigrationRunner** در `app/Shared/Persistence/MigrationRunner.php` در هر boot فایل‌های `app/Modules/*/migrations/*.sql` را اسکن می‌کند، در جدول `migrations` با `filename` + SHA-256 `checksum` ردگیری می‌کند و فقط migration‌های جدید یا تغییر یافته را اعمال می‌کند. این مکانیزم drift schema را در زمان توسعه موازی محو می‌کند (مرجع `§24.5`).

---

## ۵. شماتیک جریان درخواست

```
 HTTP Request
      │
      ▼
 public/index.php (front controller)
      │
      ├─ Environment::load(.env)
      ├─ security headers (CSP/XFO/COOP/CORP/HSTS)
      ├─ IP_BLOCKLIST → 403
      ├─ Container + boot
      ├─ Session::start (MOJAVEZSESSID, strict_mode)
      ├─ Request::fromGlobals(baseUrl)
      │
      ├─ Router::match(method, path)
      │     │
      │     ├─ RateLimitConfig::match → IpRateLimiter::hit()
      │     │       └─ bucket full → 429 + Retry-After
      │     │
      │     └─ isMutating? → Csrf::verify() → CsrfException → 419
      │
      ├─ ModuleLoader → Controller (container-resolved)
      │     │
      │     ├─ Core\Services\*Service (use case)
      │     ├─ Repository (Pdo* implements Interface)
      │     └─ Connection (PDO singleton)
      │
      ├─ Response::html()/json() → send()
      │
      ▼
 HTTP Response (with security headers)
```

برای مسیرهای async (sync admin panel)، `POST /admin/sync/run` یک رویداد روی EventBus منتشر می‌کند؛ Subscriber آن را در `jobs` قرار می‌دهد و Worker بعدی (با cron یا Poor Man's Cron از طریق بازدید مدیر) آن را اجرا می‌کند. این الگو در `§13.3` و `§16.5` مستند شده است.

---

## ۶. قانون طلایی لایه‌بندی (§4.4)

کنترلر نازک است: فقط parse request + call service + build response. سرویس‌ها use case را orchestrate می‌کنند. Entity‌ها و Value Object‌ها (`App\Modules\Core\Domain\*` و `App\Modules\NationalGateway\Domain\*`) هیچ وابستگی به PDO، HTTP یا چارچوب ندارند. همهٔ پیاده‌سازی‌ها به Interface وابسته‌اند (۱۷ interface در `app/Shared/Contracts/`). ماژول‌های دیگر هرگز مستقیماً به جدولی متعلق به ماژول دیگر `INSERT/UPDATE` نمی‌کنند؛ بلکه یا از Interface می‌خوانند یا از طریق ACL (مانند `ApplicantSyncAdapter`) می‌نویسند (مرجع `SCHEMA_OWNERSHIP.md §3`).
