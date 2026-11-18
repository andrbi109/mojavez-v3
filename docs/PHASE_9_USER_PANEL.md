# Phase 9 — User Panel (`/panel/*`)

> **تاریخ:** 2026-11-17
> **شاخه:** `phase/9-user-panel` (merge به `main`)
> **هدف:** برطرف‌سازی خطای 404 که پس از ورود کاربر به `/panel` رخ می‌داد، با
> الهام از سطح امکانات v2 (`mojavez.zip`) اما بازنویسی‌شده بر معماری ماژولی v3.

## ۱) گزارش مشکل

کاربر پس از ثبت‌نام و ورود موفق، به مسیر `http://localhost:8000/panel`
هدایت شد و با **404 - صفحه یافت نشد** مواجه گردید.

### ریشه

در `AuthController::login()` (فاز ۶) خط ۱۲۴ نوشته شده بود:

```php
$redirect = (string)($this->session->get('redirect_after_login') ?? '/panel');
return $this->redirect($redirect);
```

یعنی پس از ورود، کاربر به `/panel` هدایت می‌شد — اما **هیچ مسیر `/panel` و
هیچ کنترلری برای سرویس‌دادن آن در v3 تعریف نشده بود**. این یک ناسازگاری
بین فاز ۲ (auth) و قابلیت کامل storefront بود که از v2 ارث مانده بود.

### چرا تا حالا کشف نشد؟

تا پیش از fix ISSUE-001 (که boot HTTP را فعال می‌کرد)، کل سرور توسعه‌ی PHP
با Fatal Error بالا نمی‌آمد، پس مسیر `/panel` اصلاً قابل تست نبود. پس از
fix boot، کاربر محلی سرور را بالا آورد و 404 را دید — اولین تست واقعی مسیر
post-login.

## ۲) تطبیق با v2

کاربر `mojavez.zip` (v2 مستقر روی هاست اشتراکی) را برای **تطبیق و الهام**
قرار داد. زیرمجموعه‌ی مسیرهای `/panel/*` در v2 (از `index.php` v2):

| مسیر v2 | متد | کنترلر v2 | پیاده‌سازی شد در v3؟ |
|---|---|---|---|
| `/panel` | GET | `UserPanelController::index` | ✅ |
| `/panel/profile` | GET | `::profile` | ✅ (read-only) |
| `/panel/profile` | POST | `::updateProfile` | ✅ stub (501) — D-9-1 |
| `/panel/avatar` | POST | `::uploadAvatar` | ✅ stub (501) — D-9-1 |
| `/panel/requests` | GET | `::requests` | ✅ |
| `/panel/payments` | GET | `::payments` | ✅ |
| `/panel/licenses` | GET | `::licenses` | ✅ |
| `/panel/notifications` | GET | `::notifications` | ✅ |
| `/panel/notifications/read` | POST | `::markAllRead` | ✅ |
| `/panel/tracking` | GET | `::tracking` | ✅ |

**نکته:** مسیر `/dashboard` قدیمی v2 (که به `/panel` alias شده بود) در v3
پیاده نشد چون v3 از ابتدا فقط `/panel` را redirect-target قرار داده بود و
alias قدیمی نیازی نبود.

### آنچه عمداً به v1.3 موکول شد (D-9-1)

- **`POST /panel/profile` (updateProfile)** — نیاز به سرویس اعتبارسنجی فرم
  (نام، موبایل، ایمیل) دارد که در فاز ۹ خارج از scope بستن 404 بود.
  stub با 501 + پیام واضح برگردانده می‌شود.
- **`POST /panel/avatar` (uploadAvatar)** — نیاز به `AvatarService` با
  resize/cache/مبارزه با path-traversal دارد. stub برگردانده می‌شود.

این دو مسیر در گزارش ثبت شده‌اند تا بعداً تکمیل شوند.

## ۳) معماری پیاده‌سازی

### ۳-۱) Contract جدید

`app/Shared/Contracts/UserNotificationRepositoryInterface.php` — سه متد:

```php
public function findByUser(int $accountId, ?int $limit = null): array;
public function unreadCount(int $accountId): int;
public function markAllRead(int $accountId): int;
```

چون `UserAccountRepositoryInterface` از پیش `getApplications(accountId)` و
`getPayments(accountId)` داشت (فاز ۶)، تنها قسمت گمشده نوتیفیکیشن بود.

### ۳-۲) پیاده‌سازی PDO

`app/Modules/Core/Persistence/PdoUserNotificationRepository.php` — از
helper های rename‌شده‌ی ISSUE-001 (`fetchAll`/`fetchScalar`/`execute`)
استفاده می‌کند. کوئری‌ها ساده‌اند: `WHERE user_account_id = ? ORDER BY created_at DESC`.

### ۳-۳) کنترلر

`app/Modules/Core/Controllers/UserPanelController.php` — ۱۰ اکشن:

| متد | مسیر | شرح |
|---|---|---|
| `index` | `GET /panel` | پیشخوان: آمار + آخرین درخواست + timeline + آخرین پرداخت‌ها + اعلان‌ها |
| `profile` | `GET /panel/profile` | نمایش read-only حساب |
| `updateProfile` | `POST /panel/profile` | stub 501 (D-9-1) |
| `uploadAvatar` | `POST /panel/avatar` | stub 501 (D-9-1) |
| `requests` | `GET /panel/requests` | لیست درخواست‌های کاربر |
| `payments` | `GET /panel/payments` | لیست پرداخت‌ها |
| `licenses` | `GET /panel/licenses` | مجوزهای صادرشده + لینک دانلود |
| `notifications` | `GET /panel/notifications` | لیست کامل اعلان‌ها |
| `markAllRead` | `POST /panel/notifications/read` | علامت‌گذاری همه به‌عنوان خوانده‌شده |
| `tracking` | `GET /panel/tracking` | فرم پیگیری سریع (پست به `/tracking/search` موجود) |

هر اکشن با `requireUserLogin()` شروع می‌شود که در نبود session،
`redirect_after_login` را ذخیره و به `/login` هدایت می‌کند.

DI به‌صورت autowire است (Container از طریق Reflection تمام وابستگی‌های
constructor را resolve می‌کند) — تمام interface های مورد نیاز از فاز ۶
موجود بودند، فقط `UserNotificationRepositoryInterface` جدید اضافه شد.

### ۳-۴) Layout و Views

`app/Views/user_layout.php` — قالب RTL با سایدبار راست (Bootstrap 5
self-hosted + Vazirmatn + app.css)، badge اعلان‌های خوانده‌نشده، و nav
هفت‌آیتمی. **هیچ کد v2 کپی نشده** — از روی `assets/vendor/...` و الگوی
`admin_layout.php` v3 بازنویسی شده.

هفت ویو زیر `app/Views/user/`:
- `overview.php` — پیشخوان با سه کارت آمار + آخرین درخواست + timeline + اعلان‌ها
- `profile.php` — اطلاعات حساب (read-only)
- `requests.php` — جدول درخواست‌ها با badge وضعیت
- `payments.php` — جدول پرداخت‌ها با badge وضعیت
- `licenses.php` — کارت‌های مجوز با لینک دانلود
- `notifications.php` — لیست با دکمه «خواندن همه»
- `tracking.php` — فرم ساده پست به `/tracking/search`

### ۳-۵) ثبت در Container و Router

در `app/Modules/Core/Module.php`:
- اتصال `UserNotificationRepositoryInterface` → `PdoUserNotificationRepository`
  (singleton از طریق Connection).
- ۱۰ مسیر `/panel/*` در بلوک «Phase 9 user panel routes».

## ۴) آنچه از v3 موجود استفاده شد (بدون schema جدید)

| منبع داده | متد موجود |
|---|---|
| `UserAccountRepositoryInterface::getApplications` | فاز ۶ |
| `UserAccountRepositoryInterface::getPayments` | فاز ۶ |
| `UserAccountRepositoryInterface::findById` | فاز ۶ |
| `PaymentRepositoryInterface` | فاز ۶ |
| `LicenseFileRepositoryInterface::findByApplicantId` | فاز ۶ |
| `ServiceTypeRepositoryInterface::getAll` | فاز ۶ |
| `StatusLogRepositoryInterface::findByApplicantId` | فاز ۶ |
| جدول `user_notifications` | فاز ۶ (migration ۰۰۶) |

هیچ migration جدیدی نیاز نبود — schema از فاز ۶ کامل بود، فقط سطح
کاربردی (controller + views + routes) پیاده نشده بود.

## ۵) تست

### ۵-۱) تست واحد جدید

`tests/Unit/UserNotificationRepositoryTest.php` — ۵ سناریو، ۹ assertion:

1. `findByUser` جدیدترین‌اول برمی‌گرداند.
2. `findByUser` به `LIMIT` احترام می‌گذارد.
3. `unreadCount` فقط خوانده‌نشده‌ها را می‌شمارد.
4. `markAllRead` تعداد ردیف‌های update‌شده را برمی‌گرداند (نه ردیف‌های از قبل خوانده‌شده).
5. `findByUser` به `user_account_id` scope می‌شود (نشت بین کاربران ندارد).

نتیجه: **391 assertions, 0 failures** (۳۸۲ قبلی + ۹ جدید).

### ۵-۲) تست HTTP واقعی (مسیر دقیق کاربر)

بوت سرور توسعه و اجرای جریان کامل:

| مرحله | نتیجه |
|---|---|
| `GET /panel` بدون session | HTTP 302 → `/login` ✅ (نه 404) |
| `GET /login` | HTTP 200 (فرم + CSRF) ✅ |
| `POST /login` با موبایل/پسورد صحیح + CSRF | HTTP 302 → `/panel` ✅ |
| `GET /panel` با session | HTTP 200, 8640 bytes ✅ |
| محتوای `/panel` | شامل «پنل کاربری», «کل درخواست‌ها», «در حال بررسی», «صادرشده», «آخرین درخواست» ✅ |
| `GET /panel/requests` | HTTP 200 ✅ |
| `GET /panel/payments` | HTTP 200 ✅ |
| `GET /panel/licenses` | HTTP 200 ✅ |
| `GET /panel/notifications` | HTTP 200 ✅ |
| `GET /panel/tracking` | HTTP 200 ✅ |
| `GET /panel/profile` | HTTP 200 ✅ |
| Fatal error در لاگ سرور | هیچ ✅ |

### ۵-۳) smoke-test

`PASS: 66, FAIL: 8` — همان وضعیت فاز ۸ (۸ FAIL همگی D-8-5 هستند،
pre-existing HTTP-behavior sandbox تفاوت‌ها). **هیچ regression جدیدی وارد
نشده**.

## ۶) Debt جدید (ثبت‌شده)

| شناسه | شرح | شدت | راهکار |
|---|---|---|---|
| D-9-1 | `POST /panel/profile` (updateProfile) و `POST /panel/avatar` (uploadAvatar) به‌صورت stub با 501 برگردانده می‌شوند. پیاده‌سازی کامل نیاز به `AvatarService` (resize/cache/path-traversal defense) و فرم اعتبارسنجی دارد. | medium | v1.3 |

## ۷) فایل‌های اضافه/تغییر یافته

| فایل | عمل |
|---|---|
| `app/Shared/Contracts/UserNotificationRepositoryInterface.php` | جدید |
| `app/Modules/Core/Persistence/PdoUserNotificationRepository.php` | جدید |
| `app/Modules/Core/Controllers/UserPanelController.php` | جدید (۱۰ اکشن) |
| `app/Views/user_layout.php` | جدید |
| `app/Views/user/overview.php` | جدید |
| `app/Views/user/requests.php` | جدید |
| `app/Views/user/payments.php` | جدید |
| `app/Views/user/licenses.php` | جدید |
| `app/Views/user/notifications.php` | جدید |
| `app/Views/user/tracking.php` | جدید |
| `app/Views/user/profile.php` | جدید |
| `app/Modules/Core/Module.php` | +۱۰ route, +۱ container binding |
| `tests/Unit/UserNotificationRepositoryTest.php` | جدید (۹ assertion) |
| `docs/PHASE_9_USER_PANEL.md` | جدید (این فایل) |
| `docs/DEBT_REVIEW.md` | +D-9-1 |
| `CHANGELOG.md` | بخش Phase 9 |

## ۸) نتیجه

خطای 404 پس از ورود برطرف شد. کاربر اکنون پس از login به داشبورد پنل کاربری
کامل هدایت می‌شود: آمار، آخرین درخواست، timeline، پرداخت‌ها، اعلان‌ها،
و دسترسی به شش صفحهٔ فرعی (requests/payments/licenses/notifications/tracking/profile).

پیاده‌سازی بر معماری ماژولی v3 استوار است (نه کپی از v2) و تمام داده‌ها از
schema موجود فاز ۶ تغذیه می‌شوند. تنها debt ثبت‌شده (D-9-1) به‌صورت آگاهانه به
v1.3 موکول شد چون پیاده‌سازی کامل آن نیاز به سرویس‌های جانبی داشت که خارج از
scope «بستن 404» بود.
