# امنیت در mojavez-v3

> این سند لایه‌های امنیتی سامانه را بر اساس کد واقعی تشریح می‌کند. مرجع راهبردی `docs/00-MASTER.md §12` است (رفع تمام مشکلات شناخته‌شده از v2 و gateway).

---

## ۱. CSRF متمرکز در front controller

CSRF همیشه فعال است (رفع ST1 از gateway). کلاس `app/Http/Csrf.php` توکن ۶۴ hex char (۲۵۶ بیت entropy با `bin2hex(random_bytes(32))`) را در نشست تولید و پایدار می‌کند. بررسی با `hash_equals` (مقایسهٔ زمان‌ثابت) انجام می‌شود؛ اگر توکن موجود نباشد، یک مقایسهٔ ساختگی اجرا می‌شود تا timing uniform بماند. AJAX می‌تواند توکن را از هدر `X-CSRF-TOKEN` بفرستد یا از فیلد مخفی `_csrf`.

اعمال CSRF در `public/index.php` متمرکز است، نه در هر کنترلر:

```php
// public/index.php — بخش ۱۳
if ($request->isMutating()) {
    $csrf = $container->make(Csrf::class);
    $provided = $csrf->readProvided(
        $request->body(),
        $request->header('X-CSRF-TOKEN')
    );
    if (!$csrf->verify($provided)) {
        throw new CsrfException();
    }
}
```

`CsrfException` توسط try-catch بالا به HTTP 419 تبدیل می‌شود (JSON برای AJAX، HTML برای بقیه). هر فرم mutating باید `<?= Csrf::field() ?>` را شامل شود (`CODING_CONVENTIONS §6.2`).

---

## ۲. هدرهای امنیتی سراسری

کلاس `app/Http/Response.php` دو ثابت دارد که در هر response اعمال می‌شود:

```php
public const SECURITY_HEADERS = [
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options'        => 'SAMEORIGIN',
    'Referrer-Policy'        => 'strict-origin-when-cross-origin',
    'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=(), interest-cohort=()',
    'X-Robots-Tag'           => 'noindex, nofollow, noarchive, nosnippet, noimageindex',
    'Cross-Origin-Opener-Policy'   => 'same-origin',
    'Cross-Origin-Resource-Policy' => 'same-site',
    'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
    'Pragma'                 => 'no-cache',
    'Expires'                => '0',
];

public const CSP = "default-src 'self'; script-src 'self' 'unsafe-inline'; "
    . "style-src 'self' 'unsafe-inline'; font-src 'self' data:; "
    . "img-src 'self' data: blob:; connect-src 'self'; object-src 'self'; "
    . "frame-src 'self'; base-uri 'self'; frame-ancestors 'self'; "
    . "form-action 'self'";
```

نکتهٔ مهم: CSP شامل `'unsafe-eval'` نیست (رفع مشکل v2). همچنین هیچ منبع خارجی (CDN) مجاز نیست؛ این تضمین می‌کند Bootstrap، Vazirmatn و PDF.js همه self-hosted باشند. هدرها هم در `index.php` (بخش ۶) برای همهٔ response‌ها شامل خطاها emit می‌شوند و هم در `Response::send()` با strip CR/LF برای جلوگیری از header injection. HSTS (`Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`) فقط روی HTTPS فعال می‌شود. Smoke test این invariant‌ها را در بخش ۶ خود بررسی می‌کند (۱۲ assert).

---

## ۳. Session config

کلاس `app/Http/Session.php` تنظیمات سختگیرانه‌ای دارد (§12.3):

- نام کوکی `MOJAVEZSESSID` (متمایز از gateway).
- `use_strict_mode=1` برای جلوگیری از session adoption.
- `sid_bits_per_character=5` و `sid_length=40` (رفع هشدار PHP 8.5 در v2 — `§20.3`).
- پرچم‌های کوکی: `HttpOnly=true`, `SameSite=Lax`, `Secure=auto` (بر اساس HTTPS).
- `use_only_cookies=1`.
- save path سفارشی به `storage/sessions` (رفع مشکل Windows/XAMPP).
- بازتولید ID هر ۱۸۰۰ ثانیه (۳۰ دقیقه) با حفظ داده‌ها (`session_regenerate_id(true)`).
- دو قلمرو جدا (admin/user) با پیشوند کلید متفاوت، فعال همزمان در یک مرورگر.

---

## ۴. IpRateLimiter (دو مکانیزم)

کلاس `app/Http/IpRateLimiter.php` دو نوع bucket ارائه می‌دهد:

۱. **Session-based** (`type='session'`) — ردیابی تلاش‌ها در `$_SESSION['_rate_limit'][$key]`. بدون نوشتن DB، با منقضی شدن نشست پاک می‌شود. مناسب پنل مدیر (مرز اعتماد داخلی، فقط ضد brute-force).
۲. **IP-based** (`type='ip'`) — پایدار در جدول `rate_limit_buckets` ( Survives logout, restart, و session reset). برای endpoint‌های عمومی.

```php
private function hitIp(string $key, int $max, int $window): bool
{
    $ip = $this->clientIp();
    $row = $this->conn->one(
        'SELECT * FROM rate_limit_buckets WHERE bucket_key = ? AND ip = ? LIMIT 1',
        [$key, $ip]
    );
    $hits = $this->pruneOld($row, $window);
    if (count($hits) >= $max) { return false; }
    $hits[] = time();
    // INSERT or UPDATE hits_json (JSON encoded list of timestamps)
    // Lazy prune: 1/50 probability drops stale buckets older than 2× window.
}
```

Bucket ها sliding-window هستند: هر hit یک timestamp اضافه می‌کند، timestamp‌های قدیمی در خواندن prune می‌شوند. `pruneStale` به‌صورت best-effort (با احتمال ۱/۵۰) اجرا می‌شود تا نیاز به cron مجزا نباشد. اعتماد به `X-Forwarded-For` فقط با پرچم `TRUST_PROXY=1` فعال می‌شود.

---

## ۵. RateLimitConfig (۷ rule)

فایل `app/Http/RateLimitConfig.php` قواعد اعلانی هر route pattern را تعریف می‌کند (اولین match برنده):

| Pattern | Key | Max | Window (s) | Type |
|---|---|---|---|---|
| `POST #^/admin/login$#` | `admin_login` | 5 | 300 | session |
| `POST #^/login$#` | `user_login` | 5 | 300 | ip |
| `GET #^/tracking/status/#` | `tracking_status` | 30 | 60 | ip |
| `GET #^/license/view/#` | `license_view` | 20 | 60 | ip |
| `GET #^/tracking/print/#` | `tracking_print` | 10 | 60 | ip |
| `GET #^/api/v1/licenses/search#` | `api_licenses_search` | 10 | 60 | session |
| `GET #^/api/v1/applicants/#` | `api_applicants` | 60 | 60 | ip |

اعمال در `index.php` قبل از CSRF: یک فلاد از توکن‌های CSRF بد نیز throttle می‌شود. هدرهای `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` در پاسخ ۴۲۹ اضافه می‌شوند. این مکانیزم نباید با `RateLimiterService` (anti-detection برای upstream scraper) اشتباه شود — آن یکی مکانیزم متفاوت است (تأخیر Gaussian بین درخواست‌های upstream).

---

## ۶. امنیت آپلود (۷ لایه)

طبق `§12.4` (تداوم از v2)، آپلود فایل از هفت لایه عبور می‌کند: (۱) بررسی `Content-Type` از طریق `fileinfo` PECL نه trusted header، (۲) بررسی `magic bytes` واقعی فایل، (۳) محدودیت extension whitelist (pdf, jpg, png, jpeg)، (۴) محدودیت سایز در `php.ini` و در سطح app، (۵) ذخیره خارج از `public/` با نام بازنویسی‌شده (CSPRNG)، (۶) non-executable directory (`.htaccess` با `php_flag engine off` در `uploads/`)، (۷) اسکن بدافزار اختیاری در صورت موجود بودن `clamav`.

---

## ۷. Path traversal protection و Guards

طبق `§12.5`، تمام مسیرهای فایل کاربر از `realpath()` عبور می‌کنند و بررسی می‌شود که با `ROOT_PATH` شروع شوند. smoke test موارد `/../../../etc/passwd` را امتحان می‌کند و انتظار ۴۰۴/۴۰۰/۴۰۳ دارد.

Guards احراز هویت در کنترلرها (`§11.3`): `requireLogin()` و `requireRole('admin'|'operator')` در پایهٔ `Controller.php`. مسیرهای `/admin/*` بدون نشست مدیر به `/admin/login` redirect می‌شوند (۳۰۲). مسیرهای `/admin/sync/*` هم شامل نقش اضافی `auto_sync` هستند که فقط برای golden-link orchestrator است و هیچ کاربر انسانی آن را ندارد (§25 ACL). CSV Formula Injection Protection با پیشوند `'` قبل از رشته‌های شروع‌شده با `=`، `+`، `-`، `@` اعمال می‌شود (`§12.7`). توکن تصویر مجوز ۷۲ ساعته و یک‌بار دریافت است (`§12.8`).
