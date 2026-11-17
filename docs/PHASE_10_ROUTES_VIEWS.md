# Phase 10 — Route/View 404/500 prevention

> **تاریخ:** 2026-11-17
> **شاخه:** `phase/10-routes-views-404-prevention` (merge به `main`)
> **هدف:** بازبینی گستردهٔ تمام مسیرها و ویوها برای برطرف‌سازی هر ۴۰۴ و
> هر ۵۰۰ پنهان که در time اجرا ظاهر می‌شود. این فاز پس از فاز ۹
> (برطرف‌سازی ۴۰۴ `/panel/*`) و در پاس به درخواست کاربر برای یک بازبینی
> گسترده‌تر انجام شد.

## ۱) زمینه

کاربر فایل‌های متعددی با فهرست کامل مسیرها و ویوهای v3 (و v2 به‌عنوان الهام)
بارگذاری کرد. این فایل‌ها نشان می‌داد که پروژهٔ هدف v3 بسیار گسترده‌تر از
آنچه در مخزن پیاده شده است — شامل ده‌ها مسیر admin (form-fields, fee-tariff,
license-titles, bulk-import, sms, revisions, reports, output, ...) و
ویوهای مرتبط.

با این حال، در مخزن فعلی v3 (پس از فاز ۹)، بیشتر آن مسیرها هنوز پیاده
نشده‌اند و به‌صورت آگاهانه به v1.2 موکول شده‌اند (DEBT_REVIEW D-7-1، D-2-2،
D-2-3). هدف این فاز **پیاده‌سازی کامل** آن مسیرها نبود — بلکه برطرف‌سازی
**خطاهای زمان اجرا** (۴۰۴ و ۵۰۰) بود که در مسیرها و ویوهای فعلی پنهان بودند.

## ۲) خطاهای برطرف‌شده

### ۲-۱) خطاهای ۵۰۰ پنهان (constructor signature mismatch)

**`UsersController` و `SettingsController`** (فاز ۷) هر دو کنترلر
`parent::__construct()` را با فقط ۴ آرگومان فراخوانی می‌کردند:

```php
parent::__construct($container, $session, $csrf, $environment);
```

در حالی که base `Controller` ۶ پارامتر الزامی دارد:

```php
public function __construct(
    protected readonly Container $container,
    protected readonly Session $session,
    protected readonly Csrf $csrf,
    protected readonly Environment $environment,
    protected readonly Hash $hash,
    protected readonly StructuredLogger $logger
) {}
```

این یعنی هر بار که `Container::make(UsersController::class)` فراخوانی می‌شد
(یعنی هر بار که کاربر به `/admin/users`، `/admin/users/new`،
`/admin/users/{id}/edit`، `POST /admin/users`، `POST /admin/users/{id}`،
`POST /admin/users/{id}/delete`، یا هر مسیر `/admin/settings` می‌رفت)،
PHP با fatal error `Too few arguments to Controller::__construct()` fail
می‌شد.

این باگ از فاز ۷ موجود بود اما چون هیچ تستی به‌عنوان ادمین لاگین نمی‌کرد و
این مسیرها را exercise نمی‌کرد، پنهان ماند.

**برطرف شد:** هر دو dependency (`Hash`، `StructuredLogger`) به constructor
هر دو کنترلر اضافه شد و به `parent::__construct()` پاس داده شد.

### ۲-۲) رندر بدون layout (render third-arg misuse)

همان دو کنترلر، `render()` را با یک رشتهٔ فارسی به‌عنوان آرگومان سوم فراخوانی
می‌کردند:

```php
return $this->render('admin/users/index', ['users' => $users], 'مدیریت کاربران');
```

اما signature واقعی `render()` این است:

```php
protected function render(string $view, array $data = [], string $layout = 'layout'): Response
```

آرگومان سوم `$layout` است نه `$title`. پس layout resolver به دنبال
`app/Views/مدیریت کاربران.php` می‌گشت، آن را پیدا نمی‌کرد، و به fallback
«no layout» برمی‌گشت. نتیجه: صفحات admin بدون `admin_layout.php` رندر می‌شدند
— بدون سایدبار، بدون هدر صحیح، بدون Bootstrap CSS (چون CSS فقط از طریق
layout لود می‌شود).

**برطرف شد:** title حالا به‌عنوان کلید `'title'` در `$data` پاس داده می‌شود
(که `admin_layout.php` آن را extract می‌کند و در `<h1>` و `<title>` استفاده
می‌کند) و `render()` با layout پیش‌فرض (`'layout'`) فراخوانی می‌شود. چون view
با `admin/` شروع می‌شود، `render()` به‌صورت خودکار آن را به `admin_layout`
تبدیل می‌کند.

### ۲-۳) HTML nested (layout wrapping برای full-HTML views)

دو ویو در پروژه به‌صورت HTML document کامل نوشته شده بودند:

- `app/Views/admin/login.php` — یک صفحهٔ ورود مستقل با `<html>/<head>/<body>`
  و CSS اختصاصی برای center کردن کارت ورود.
- `app/Views/print.php` — نسخهٔ چاپی A4 با `<html>/<head>/<body>` و CSS
  چاپ-محور (`@media print`).

اما `render()` همیشه view را در یک layout می‌پیچید:
- `admin/login.php` → به‌صورت خودکار در `admin_layout.php` پیچیده می‌شد
  (چون view با `admin/` شروع می‌شود).
- `print.php` → در `layout.php` (پیش‌فرض) پیچیده می‌شد.

نتیجه: `<html>` داخل `<body>`، `<head>` داخل `<body>`، دو بار Bootstrap
CSS load می‌شد، و صفحهٔ چاپی سایت nav/footer را نمایش می‌داد که برای چاپ
A4 نادرست است.

**برطرف شد:** یک sentinel جدید `'none'` به `Controller::render()` اضافه شد
که layout wrapping را کاملاً skip می‌کند:

```php
protected function render(string $view, array $data = [], string $layout = 'layout'): Response
{
    ...
    if ($layout === 'none') {
        return Response::html($content);
    }
    ...
}
```

و در `AuthController::adminLoginForm` و `TrackingController::printView` از
این sentinel استفاده می‌شود.

### ۲-۴) ۵۰۰ پنهان در Container autowiring

`Container` خودش را به‌عنوان singleton در `singletons[]` ثبت نمی‌کرد. وقتی
یک کنترلر با autowire ساخته می‌شد و `Container` به‌عنوان dependency
constructor می‌خواست، `Container::make(Container::class)` یک **Container جدید
خالی** می‌ساخت (بدون module bindings).

سپس وقتی کنترلر متدی مانند `$this->container->make(Connection::class)` را
فراخوانی می‌کرد (مثلاً `AdminController::connection()` که در
`AdminController::dashboard()` استفاده می‌شود)، Container خالی factory
نداشت، سعی می‌کرد `Connection` را autowire کند، و چون constructor `Connection`
۸ پارامتر با built-in types (string, int) دارد، با `RuntimeException: Cannot
autowire Connection: parameter $host has no type` fail می‌شد.

این یعنی اولین کلیک admin روی `/admin/dashboard` (پس از لاگین موفق) با ۵۰۰
fail می‌شد.

**برطرف شد:** یک line به constructor `Container` اضافه شد:

```php
$this->singletons[self::class] = $this;
```

حالا autowired controllers به همان Container اصلی (با تمام module bindings)
ارجاع می‌شوند.

### ۲-۵) مسیرهای ۴۰۴ در سایدبار ادمین

`admin_layout.php` یک سایدبار با لینک‌های زیر داشت:

- `/admin/dashboard` ✓
- `/admin/applications` ✓
- `/admin/users` ✓ (اما ۵۰۰ — بخش ۲-۱)
- `/admin/form-fields` ❌ (مسیر وجود نداشت → ۴۰۴)
- `/admin/fee-tariff` ❌ (۴۰۴)
- `/admin/license-titles` ❌ (۴۰۴)
- `/admin/settings` ✓ (اما ۵۰۰ — بخش ۲-۱)
- `/admin/sync/status` ✓ (اما بدون auth — بخش ۲-۶)
- `/admin/sync/pending` ✓ (اما بدون auth)
- `/admin/logout` ✓

سه مسیر `/admin/form-fields`، `/admin/fee-tariff`، `/admin/license-titles`
هیچ route ثبت‌شده‌ای نداشتند. کلیک روی هر کدام → ۴۰۴.

**برطرف شد:** سه stub action به `AdminController` اضافه شد:

```php
public function formFields(Request $request, array $params = []): Response
{
    $this->requireLogin();
    return $this->render('admin/stub_coming_soon', [
        'title'       => 'فیلدهای فرم',
        'icon'        => 'bi-input-cursor-text',
        'description' => 'مدیریت فیلدهای پویای فرم ثبت‌نام ... در نسخهٔ ۱.۲ فعال خواهد شد.',
    ]);
}
// و feeTariff، licenseTitles مشابه
```

و سه route در `Core/Module.php` ثبت شد:

```php
['method' => 'GET', 'path' => '/admin/form-fields',   'handler' => AdminController::class, 'action' => 'formFields'],
['method' => 'GET', 'path' => '/admin/fee-tariff',    'handler' => AdminController::class, 'action' => 'feeTariff'],
['method' => 'GET', 'path' => '/admin/license-titles', 'handler' => AdminController::class, 'action' => 'licenseTitles'],
```

این stubها HTTP 200 برمی‌گردانند (نه ۴۰۴، نه ۵۰۱) تا کاربر بتواند بخش
پلن‌شده را بدون hitting a dead end کشف کند. توضیح «فعال‌سازی در v1.2» به
DEBT_REVIEW D-7-1 + D-10-1 ارجاع می‌دهد.

### ۲-۶) باگ امنیتی: `/admin/sync/*` بدون auth

`SyncAdminController` (فاز ۸) از base `Controller` ارث نمی‌برد:

```php
final class SyncAdminController
{
    public function __construct(
        private readonly NationalSyncOrchestrator $orchestrator,
        private readonly Worker $worker,
    ) {}
    ...
}
```

Docblock ادعا می‌کرد: «All endpoints require requireRole(['admin','sync_operator'])
— enforced by the Controller base class guard». اما کلاس `Controller` را
extend نمی‌کرد، پس هیچ auth check‌ای انجام نمی‌شد.

نتیجه: `/admin/sync/status`، `/admin/sync/pending`، `/admin/sync/run` برای
کاربران غیراحراز هویت باز بود. `/admin/sync/run` حتی می‌توانست یک sync cycle
کامل را trigger کند.

این یک باگ امنیتی است (نه ۴۰۴)، اما در این فاز برطرف شد چون در همان
audit پیدا شد.

**برطرف شد:** `SyncAdminController` حالا از `Controller` ارث می‌برد و در هر
action:

```php
public function status(Request $request, array $params = []): Response
{
    $this->requireRole(['admin', 'sync_operator']);
    ...
}
```

کاربران غیراحراز هویت حالا 302 redirect به `/admin/login` دریافت می‌کنند
(به‌جای JSON خام).

### ۲-۷) صفحهٔ ۴۰۴ bare HTML

صفحهٔ ۴۰۴ فقط این بود:

```php
$response = Response::html(
    '<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>صفحه یافت نشد.</p>',
    404
);
```

بدون layout، بدون nav، بدون هیچ راهی برای بازگشت به صفحات اصلی سایت.

**برطرف شد:** view جدید `app/Views/errors/404.php` (content-only) با
layout.php اصلی سایت، با دکمه‌های «بازگشت به خانه»، «پیگیری پرونده»،
«راهنما». Front controller (`public/index.php`) این view را در catch
`RouteNotFoundException` رندر می‌کند، با try/catch fallback به bare HTML اگر
view خودش fail شود — تا صفحهٔ ۴۰۴ هرگز ۵۰۰ ندهد.

## ۳) فایل‌های اضافه/تغییر یافته

| فایل | عمل |
|---|---|
| `app/Container.php` | +1 line: self-register as singleton in constructor |
| `app/Modules/Core/Controllers/Controller.php` | +`'none'` layout sentinel in `render()` |
| `app/Modules/Core/Controllers/AuthController.php` | `adminLoginForm` از `render('admin/login', [], 'none')` استفاده می‌کند |
| `app/Modules/Core/Controllers/TrackingController.php` | `printView` از `render('print', ..., 'none')` استفاده می‌کند |
| `app/Modules/Core/Controllers/UsersController.php` | اصلاح constructor + اصلاح render() calls |
| `app/Modules/Core/Controllers/SettingsController.php` | همان اصلاح |
| `app/Modules/Core/Controllers/AdminController.php` | +3 stub actions: formFields/feeTariff/licenseTitles |
| `app/Modules/NationalGateway/Controllers/SyncAdminController.php` | حالا extends Controller + requireRole |
| `app/Modules/Core/Module.php` | +3 stub routes |
| `public/index.php` | 404 handler از errors/404.php view استفاده می‌کند |
| `app/Views/admin/stub_coming_soon.php` | جدید |
| `app/Views/errors/404.php` | جدید |
| `docs/DEBT_REVIEW.md` | بخش فاز ۱۰ + به‌روزرسانی خلاصهٔ live debt |
| `docs/PHASE_10_ROUTES_VIEWS.md` | جدید (این فایل) |
| `CHANGELOG.md` | بخش [Unreleased] با جزئیات فاز ۱۰ |

## ۴) Debt

### پرداخت‌شده

| ID | شرح |
|---|---|
| D-7-1 (partial) | سه مسیر سایدبار ادمین که ۴۰۴ می‌دادند با stub متصل شدند |

### جدید

| ID | شرح | شدت | مهلت |
|---|---|---|---|
| D-10-1 | سه stub admin CRUD کامل ندارند (فقط «coming soon») | medium | v1.2 (با D-7-1 ادغام) |

### باگ‌های برطرف‌شده (نه debt، چون bug-fix بود)

7 باگ زمان اجرا که در بخش ۲ فهرست شد. این‌ها از فاز ۷ موجود بودند و در
این فاز برطرف شدند — در CHANGELOG و این فایل مستند شدند تا تاریخچه حفظ
شود.

## ۵) تست

- تمام unit tests (391 assertion): بدون regression
- تمام contract tests (21 assertion): بدون regression
- HTTP smoke test (66 pass / 8 pre-existing D-8-5): بدون regression
- `static_analysis.py` و `php_lint_simple.py`: همان warningهای pre-existing
  (false positive‌ها)، بدون افزایش.

تست‌های جدید برای این فاز اضافه نشد چون:
1. بیشتر اصلاحات در لایهٔ HTTP/controller است که توسط unit tests قابل
   پوشش نیست (نیاز به MariaDB + session + HTTP stack دارد).
2. smoke-test فعلی `/admin/dashboard` و `/admin/sync/status` را بدون session
   آزمون می‌کند (redirect ۳۰۲ انتظار می‌رود) — این از قبل covered است.
3. تست‌های actual logged-in admin در v1.2 با Phase 11 (CRUD کامل) اضافه
   خواهند شد، که در آن زمان هر سه stub به کنترلرهای کامل تبدیل می‌شوند و
   تست پوشش قابل اعتمادی می‌طلبد.

## ۶) نتیجه

تمام ۴۰۴ و ۵۰۰ پنهان که در مسیرها و ویوهای فعلی v3 وجود داشت برطرف شد.
کاربر حالا می‌تواند:

- به `/admin/users` و `/admin/settings` برود بدون ۵۰۰
- صفحهٔ `/admin/login` را ببیند بدون HTML nested
- صفحهٔ `/tracking/print/{code}` را چاپ کند بدون HTML nested و بدون nav/footer سایت
- روی لینک‌های `/admin/form-fields`، `/admin/fee-tariff`، `/admin/license-titles`
  در سایدبار کلیک کند و صفحهٔ «coming soon» ببیند به‌جای ۴۰۴
- به `/admin/sync/*` به‌صورت غیراحراز هویت نرود (redirect به `/admin/login`)
- هر مسیر ناشناخته را ببیند و یک صفحهٔ ۴۰۴ مناسب با layout سایت و دکمه‌های
  بازگشت دریافت کند

کنترلرهای کامل CRUD برای سه stub admin و سایر کنترلرهای موکول‌شده (D-7-1،
D-2-2، D-2-3، D-8-4) در فاز ۱۱ (v1.2.0) پیاده خواهند شد.
