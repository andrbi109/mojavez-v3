# API یکپارچهٔ mojavez-v3

> این سند API داخلی v3 را بر اساس کد و قراردادهای پروژه تشریح می‌کند. مرجع رسمی `docs/integration-contract.md §3` و `docs/00-MASTER.md §10.2` و `§28` است.

---

## ۱. قلمرو احراز هویت: API key مبتنی بر scope

احراز هویت API با هدر `X-API-Key: <key>` انجام می‌شود. کلیدها در جدول `api_keys` (مالک: `Modules/Api`) با hash ذخیره می‌شوند و شامل فیلد `scopes` (JSON list) و `rate_limit_per_min` هستند. هر endpoint نیازمند scope‌های خاص است (`§28.1`، `§28.2`):

- `read:applicants:basic` — کد رهگیری + national_code ماسک‌شده + license_state + service_title.
- `read:applicants:full` — دسترسی کامل به first_name, last_name, mobile (نیازمند `consent_external_share=true` روی رکورد متقاضی).
- `read:applicants:status` — فقط tracking_code + current_status + updated_at.
- `read:licenses` — جستجوی مجوزهای ملی.
- `read:lookups` — استخراج استان‌ها/شهرستان‌ها/سازمان‌ها.
- `read:sync:status` — وضعیت circuit breaker و چرخهٔ sync.
- `write:sync:run` — اجرای دستی sync (admin-only).

قاعدهٔ حریم خصوصی (§28.3): اگر `consent_external_share=false` و scope درخواست‌شده `full` باشد، پاسخ با سطح `basic` معادل می‌شود و هشدار در `meta.warnings` قرار می‌گیرد.

---

## ۲. Endpointهای `/api/v1/*`

### ۲.۱. استریم رویدادها

```
GET /api/v1/stream
Accept: text/event-stream
```

این endpoint توسط `App\Modules\Core\Controllers\SseController::stream` کنترل می‌شود و در `Core/Module.php` ثبت شده است. در production، رویدادها از جدول `events` poll می‌شوند (`EventBus::eventsSince(lastId)`). این endpoint احراز هویت نشست‌محور دارد (admin)، نه API key، چون consumer مرورگر مدیر است.

### ۲.۲. متقاضیان محلی (REST)

```
GET /api/v1/applicants/{tracking_code}
X-API-Key: <key with read:applicants:basic OR read:applicants:full>
```

پاسخ موفق (طبق `integration-contract.md §3.4.1`):

```json
{
  "success": true,
  "data": {
    "tracking_code": "HG-ABC12345",
    "national_code": "072059****",
    "license_state": "صادر شده",
    "license_valid_until": "2027-05-15",
    "service_title": "فرش دستباف",
    "updated_at": "2026-11-16T10:30:00+03:30"
  },
  "meta": { "contract_version": "1.0.0", "request_id": "req_1", "latency_ms": 12 }
}
```

این endpoint در `RateLimitConfig` با key `api_applicants` و سقف ۶۰ req/min per IP ثبت شده است (`app/Http/RateLimitConfig.php`).

### ۲.۳. جستجوی مجوز ملی

```
GET /api/v1/licenses/search?national_code=<10-digit>&province=<code>
X-API-Key: <key with read:licenses>
```

پاسخ لیست مجوزهای upstream به همراه `matched_applicant_tracking_code` (اگر با متقاضی محلی match شده باشد). rate limit: `api_licenses_search` با ۱۰ req/min per session.

### ۲.۴. lookups و sync status

```
GET /api/v1/lookups/provinces
GET /api/v1/lookups/townships?province=<code>
GET /api/v1/lookups/organizations
GET /api/v1/sync/status
X-API-Key: <key with read:lookups OR read:sync:status>
```

پاسخ `sync/status` شامل: `circuit_breaker_state` (closed/open/half-open)، `last_upstream_success_at`، `last_cycle_at`، `pending_jobs`، `failed_jobs_24h`، `auto_confirmed_24h`، `operator_flagged_24h`.

---

## ۳. Rate limit روی API و CSRF روی mutating

Rate limit در `public/index.php` بخش ۱۳ قبل از CSRF بررسی می‌شود (طبق کد زیر):

```php
$rlRule = \App\Http\RateLimitConfig::match($request->method(), $request->path());
if ($rlRule !== null) {
    $rateLimiter = $container->make(\App\Http\IpRateLimiter::class);
    $allowed = $rateLimiter->hit($rlRule['key'], $rlRule['max'], $rlRule['window'], $rlRule['type']);
    if (!$allowed) {
        $response = Response::json(['success' => false, 'error' => ['code' => 'rate_limited', ...]], 429);
        // ...
        $response->send();
        exit(0);
    }
}
```

مسیرهای mutating (`POST /api/v1/sync/run`, `POST /api/v1/applicants/{id}/status`) هم در معرض CSRF قرار می‌گیرند (`$request->isMutating()` true است). برای consumer‌های غیر مرورگر، توکن CSRF را از `GET /api/v1/csrf-token` بگیرید و در هدر `X-CSRF-TOKEN` بفرستید.

---

## ۴. ساختار پاسخ و کدهای HTTP

پاسخ موفق (`integration-contract.md §3.2`):

```json
{
  "success": true,
  "data": { ... },
  "meta": { "contract_version": "1.0.0", "request_id": "req_abc123", "latency_ms": 42 }
}
```

پاسخ خطا:

```json
{
  "success": false,
  "error": { "code": "not_found", "message": "Applicant not found", "details": { "tracking_code": "HG-XYZ12345" } },
  "meta": { "contract_version": "1.0.0", "request_id": "req_abc123" }
}
```

کدهای HTTP (`§3.3`): 200 موفق، 201 ساخته شد، 204 بدون محتوا، 400 درخواست نامعتبر، 401 کلید نامعتبر، 403 scope ناکافی، 404 موجودیت یافت نشد، 409 تعارض، 422 خطای اعتبارسنجی فیلد، 429 rate limit exceeded، 503 سرویس در دسترس نیست (upstream circuit open).

---

## ۵. مدیریت API key و توکن تصویر

پنل admin در `/admin/api-keys` کلیدها را مدیریت می‌کند: ایجاد (با انتخاب scope‌ها و rate limit)، revocation، و مشاهدهٔ `api_access_log` (هر درخواست API یک رکورد با IP, scope‌های استفاده‌شده, latency, status code ثبت می‌کند). توکن تصویر مجوز ۷۲ ساعته است و فقط یک‌بار قابل دریافت (`§12.8`)؛ این توکن از کلید API جداست و از طریق `license_access_requests` ردگیری می‌شود. برای تغییرات breaking در API، نسخهٔ قرارداد (در URL `/api/v1/`) با SemVer افزایش می‌یابد (`integration-contract.md §1`)؛ در حال حاضر نسخه ۱.۰.0 است.
