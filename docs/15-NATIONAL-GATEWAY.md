# ماژول درگاه ملی در mojavez-v3

> این سند اجزای ماژول NationalGateway را بر اساس کد واقعی در `app/Modules/NationalGateway/` تشریح می‌کند. مرجع راهبردی `docs/00-MASTER.md §6` تا `§9` است.

---

## ۱. TrackScraper (parse HTML track page)

کلاس `app/Modules/NationalGateway/Infrastructure/TrackScraper.php` صفحهٔ `/track/{request_number}` از `qr.mojavez.ir` را fetch و parse می‌کند. این سایت Next.js App Router است و داده را به‌صورت inline به‌عنوان React Server Components (RSC) "flight" payload منتشر می‌کند: سری `self.__next_f.push([1, "<json-encoded string>"])` در تگ‌های `<script>`. TrackScraper این payload را parse می‌کند و فیلدهای ماسک‌شدهٔ زیر را استخراج می‌کند (از `app/Modules/NationalGateway/Infrastructure/TrackScraper.php`):

```php
private const APPLICANT_LABELS = [
    'father_name'  => 'نام پدر',
    'national_id'  => 'کدملی',     // no space — exact label on live site
    'mobile'       => 'شماره همراه کسب و کاری',
];
private const LOCATION_LABELS = [
    'business_province' => 'استان',
    'business_township'  => 'شهرستان',
    'postal_code'        => 'کدپستی',     // no space
    'business_address'   => 'نشانی کسب و کار',
];
```

نکتهٔ مهم: مقدار mobile در صفحهٔ public همیشه خالی است — سایت آن را به‌صورت عمومی نمایش نمی‌دهد. این فیلدهای ماسک‌شده به‌عنوان confirmation anchor در `national_licenses` ذخیره می‌شوند و PatternMatcher آنها را با مقادیر کامل محلی مقایسه می‌کند (`§9.1`).

---

## ۲. MojavecClient (HTTP + retry + rate limit)

کلاس `app/Modules/NationalGateway/Infrastructure/MojavecClient.php` client رسمی API upstream است. endpointهای استفاده‌شده:

- `GET /api/v1/lookups/provinces`
- `GET /api/v1/lookups/organizations`
- `POST /api/v1/licenses/search` (با body از `Filter::toUpstreamPayload()`)
- `POST /api/v1/licenses/count` (برای total درست وقتی صفحه پر است)

MojavecClient با `HttpClientInterface` (که `AntiDetectionMiddleware` را wrap می‌کند) کار می‌کند. منطق retry با backoff نمایی: اگر HTTP 429 یا timeout، تلاش مجدد با exponential backoff تا حداکثر retry (پیکربندی‌شده). نمونه از کد:

```php
$response = $this->doWithRetry(
    fn () => $this->http->request('POST',
        $this->upstreamBase . '/api/v1/licenses/search',
        ['Origin' => $this->upstreamBase],
        $body,
        'application/json'
    ),
    'POST /api/v1/licenses/search',
    $rateLimiter    // callable که قبل از هر درخواست wait() می‌زند
);
```

نکتهٔ کلیدی: upstream در پاسخ search تعداد کل (`total`) را برنمی‌گرداند (از HAR تأیید شده). MojavecClient اکتشافی دارد: اگر صفحه پر است (هماند `pageSize`)، یک درخواست `/count` اضافی می‌زند تا total دقیق را بگیرد. این رفع bug قبلی است که `total_pages` غلط تولید می‌کرد (`page 2 of 1.5`).

---

## ۳. LicenseService (fetchAndCacheByTrackNumber، refreshLookups)

کلاس `app/Modules/NationalGateway/Services/LicenseService.php` orchestrator اصلی است و در `Module.php` با ۹ وابستگی تزریق می‌شود: `MojavecClient`, `TrackScraper`, `CacheService`, `NationalLicenseRepositoryInterface`, `NationalLookupRepositoryInterface`, `EventBus`, `StructuredLogger`, `CircuitBreaker`, `RateLimiterService`. دو متد اصلی:

- `fetchAndCacheByTrackNumber(string $requestNumber, bool $includeAdvancedData = true)` — صفحهٔ track را scrape می‌کند، License را از RSC payload می‌سازد، در `national_licenses` با `INSERT ... ON DUPLICATE KEY UPDATE` ذخیره می‌کند، `first_seen_at` و `last_seen_at` را به‌روزرسانی می‌کند، و اگر status جدید `active` باشد، رویداد `LicenseIssuedOnNational` را روی EventBus publish می‌کند تا SyncSubscriber یک `SyncJob` enqueue کند.
- `refreshLookups(): array{provinces:int, townships:int, organizations:int}` — استان‌ها، شهرستان‌ها و سازمان‌ها را از upstream fetch و در `national_lookups` با TTL ۲۴ ساعت cache می‌کند. این متد توسط `scripts/seed_lookups.php` فراخوانی می‌شود.

CircuitBreaker سه‌حالته (closed/open/half-open) هر call upstream را wrap می‌کند: اگر ۳ شکست در ۶۰ ثانیه، ۵ دقیقه open می‌ماند و درخواست‌ها سریع fail می‌شوند تا فشار از upstream برداشته شود (`§25.2`).

---

## ۴. RateLimiterService (anti-detection jitter)

کلاس `app/Modules/NationalGateway/Services/RateLimiterService.php` یک مکانیزم متفاوت از `IpRateLimiter` است: این ضد شناسایی است، نه ضد abuse. منطق آن در `wait()`:

```php
$minDelay = ($this->config['min_delay_ms'] ?? 2000) / 1000;  // 2s
$maxDelay = ($this->config['max_delay_ms'] ?? 5000) / 1000;  // 5s
$burst = $this->config['burst'] ?? 5;
$cooldown = ($this->config['cooldown_ms'] ?? 30000) / 1000;  // 30s

// 1. Prune timestamps older than 60s.
// 2. If burst threshold reached within 60s → cooldown + jitter.
// 3. Otherwise enforce min delay from last request, with Gaussian jitter.
```

تأخیر Gaussian به‌جای ثابت: `jitter($minDelay, $maxDelay)` یک عدد تصادفی توزیع‌شده با شکل normal بین min و max تولید می‌کند تا الگوی درخواست‌ها انسانی به‌نظر برسد. State در `storage/cache/rate_limiter.json` ذخیره می‌شود (تک‌میزبانی). این مکانیزم هیچ spoofing انجام نمی‌دهد — هیچ هدر `Referer` جعلی، هیچ IP spoofing، هیچ تغییر fingerprint browser. فلسفهٔ «احترام‌آمیز» (`§8.6`).

---

## ۵. AntiDetectionMiddleware (هدرهای ثابت + jitter)

کلاس `app/Modules/NationalGateway/Infrastructure/AntiDetectionMiddleware.php` هدرهای ثابت upstream را تضمین می‌کند (طبق `§8.3`): `User-Agent`, `Accept-Language`, `Accept`, `Origin` در همهٔ درخواست‌ها یکسان (از `.env` از طریق `Config::get('anti_detection', [])` خوانده می‌شوند). تزریق در `Module.php`:

```php
$container->bind(
    AntiDetectionMiddleware::class,
    fn () => new AntiDetectionMiddleware(
        \App\Shared\Support\Config::get('anti_detection', [])
    )
);
```

این middleware به‌جای تغییر fingerprint، fingerprint ثابت واقعی را تضمین می‌کند: upstream همان مرورگر را در هر درخواست می‌بیند (نه چندین متغیر)، و RateLimiterService الگوی زمانی انسانی را حفظ می‌کند. این احترام به upstream است، نه تلاش برای مخفی کردن scraper.

---

## ۶. Geofence ایران

نکتهٔ مهم مستند در `deploy/README.md`: `qr.mojavez.ir` فقط از داخل ایران قابل دسترس است. خارج از ایران scraper با timeout برمی‌گردد. برای محیط‌های sandbox خارج از ایران، `scripts/seed_lookups.php --from-gateway` دادهٔ شهرستان‌ها را از gateway-imported `sub_organization` data می‌سازد (بهترین تلاش برای تست محلی). در production، میزبان باید داخل ایران باشد تا scraping زنده کار کند. خارج از ایران، توسعه‌دهنده می‌تواند از دادهٔ cache‌شده در `national_licenses` (که توسط `migrate_from_gateway.php` از SQLite gateway وارد شده) برای توسعه استفاده کند. این محدودیت در `§16.8` و `§20` توضیح داده شده است.
