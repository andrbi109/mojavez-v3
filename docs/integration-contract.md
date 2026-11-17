# قرارداد یکپارچه‌سازی نسخه‌دار — mojavez-v3

> **نسخهٔ قرارداد:** 1.0 · **تاریخ:** ۱۴۰۵/۰۸/۲۶ (۲۰۲۶-۱۱-۱۶)
> **هدف:** تعریف رسمی و نسخه‌دار همهٔ قراردادهای یکپارچه‌سازی در mojavez-v3:
> - قرارداد scraper با درگاه ملی (upstream).
> - قرارداد API داخلی (`/api/v1/*`) برای مصرف‌کنندگان خارجی.
> - قرارداد ACL بین ماژول NationalGateway و دامنهٔ Core.
> هر تغییر breaking با افزایش نسخه-major همراه است. contract test‌ها در `tests/Contract/` این قرارداد را به‌صورت اجرایی اعمال می‌کنند.

---

## فهرست

۱. [قواعد نسخه‌بندی](#1-قواعد-نسخه‌بندی)
۲. [قرارداد A: scraper ↔ درگاه ملی (upstream)](#2-قرارداد-a-scraper--درگاه-ملی-upstream)
۳. [قرارداد B: API داخلی v3 (`/api/v1/*`)](#3-قرارداد-b-api-داخلی-v3-apiv1)
۴. [قرارداد C: ACL بین NationalGateway و Core](#4-قرارداد-c-acl-بین-nationalgateway-و-core)
۵. [قرارداد D: رویدادها (Event Bus)](#5-قرارداد-d-رویدادها-event-bus)
۶. [قرارداد E: JobQueue](#6-قرارداد-e-jobqueue)
۷. [مدیریت تغییر قرارداد](#7-مدیریت-تغییر-قرارداد)

---

## 1. قواعد نسخه‌بندی

### ۱.۱. SemVer

همهٔ قراردادها از SemVer پیروی می‌کنند: `MAJOR.MINOR.PATCH`.

- **MAJOR:** تغییر breaking (حذف endpoint، تغییر نوع فیلد، تغییر معنای status code).
- **MINOR:** افزودن backward-compatible (endpoint جدید، فیلد اختیاری جدید).
- **PATCH:** رفع bug بدون تغییر معنایی.

### ۱.۲. هدر نسخه

هر درخواست و پاسخ شامل:

- **API داخلی:** URL prefix `/api/v{MAJOR}/` (مثلاً `/api/v1/applicants`).
- **Scraper upstream:** هدر `X-Scraper-Version: 1.0.0` در هر request.
- **Response:** هدر `X-Contract-Version: 1.0.0`.

### ۱.۳. جدول نسخه‌های فعلی

| قرارداد | نسخهٔ فعلی | توضیح |
|---|---|---|
| A — scraper upstream | 1.0.0 | خواندن از درگاه ملی |
| B — API داخلی v3 | 1.0.0 | endpoint‌های `/api/v1/*` |
| C — ACL | 1.0.0 | ApplicantSyncAdapter |
| D — Event Bus | 1.0.0 | رویدادهای داخلی |
| E — JobQueue | 1.0.0 | jobs table + worker |

---

## 2. قرارداد A: scraper ↔ درگاه ملی (upstream)

### ۲.۱. شناسهٔ upstream

- **URL پایه:** `https://q.mojavez.ir` (یا همان که در `.env` به‌عنوان `UPSTREAM_BASE_URL` تنظیم شده).
- **روش دسترسی:** HTTP از طریق `MojavecClient` (cURL بومی).
- **احراز هویت:** بدون credential صریح. upstream با session cookie پس از ورود به `/human/login` کار می‌کند. session توسط scraper مدیریت می‌شود.

### ۲.۲. endpoint‌های مصرفی

#### A.2.1. جستجوی مجوز با کد ملی ماسک‌شده

```
POST /search/license
Content-Type: application/x-www-form-urlencoded
X-Scraper-Version: 1.0.0
User-Agent: <fixed UA from .env>
Cookie: <session>

national_code=<masked>&pro_code=<province>&town_code=<township>&org_code=<organization>
```

**پاسخ موفق (HTTP 200):** HTML حاوی جدول نتایج با ردیف‌های `<tr>` برای هر مجوز.

**پاسخ بدون نتیجه (HTTP 200):** HTML با جدول خالی یا پیام «مجوزی یافت نشد».

**پاسخ rate-limited (HTTP 429):** صفحهٔ بازگشت به `/human/login`. scraper این را به‌عنوان rate-limit تفسیر می‌کند و backoff می‌زند.

#### A.2.2. جزئیات مجوز

```
GET /license/<request_number>
Cookie: <session>
X-Scraper-Version: 1.0.0
```

**پاسخ موفق (HTTP 200):** HTML شامل:
- فیلدهای `<td>` با لیبل و مقدار.
- بخش RSC (React Server Components) با دادهٔ ساختاریافته.
- لینک‌های `<a>` برای فایل‌های ضمیمه (مثلاً `/file/<file_id>`).

#### A.2.3. دانلود فایل ضمیمه

```
GET /file/<file_id>
Cookie: <session>
X-Scraper-Version: 1.0.0
```

**پاسخ:** binary با `Content-Type` مناسب (`application/pdf`, `image/jpeg`, ...).

### ۲.۳. قواعد اتیکت

۱. **هدرهای ثابت:** `User-Agent`, `Accept-Language`, `Accept` در همهٔ درخواست‌ها یکسان (از `.env`).
۲. **jitter:** تأخیر Gaussian بین درخواست‌ها (میانگین ۱.۵s، انحراف ۰.۵s، حداقل ۰.۸s).
۳. **rate limit واقعی:** حداکثر ۲۰ درخواست در دقیقه. اگر HTTP 429، backoff نمایی تا ۶۰s.
۴. **no spoofing:** هیچ هدر `Referer` جعلی، هیچ IP spoofing، هیچ تغییر fingerprint browser.
۵. **احترام robots.txt:** اگر upstream `robots.txt` مسیری را ممنوع کرد، scraper از آن مسیر نمی‌رود.

### ۲.۴. دادهٔ استخراج‌شده

از HTML توسط `TrackScraper::parseTrack()` استخراج می‌شود:

| فیلد | منبع در HTML | نوع | nullable |
|---|---|---|---|
| `request_number` | `/license/{rn}` URL یا `<td>` با لیبل «شماره درخواست» | string(20) | ❌ |
| `national_code_masked` | `<td>` با لیبل «کد ملی» | string(10) با `*` | ❌ |
| `national_code_full` | اگر در صفحه موجود باشد (گاهی در جزئیات) | string(10) | ✓ |
| `license_state` | `<td>` با لیبل «وضعیت» | enum string | ❌ |
| `license_valid_until` | `<td>` با لیبل «تاریخ انقضا» | string فارسی (مثلاً «۱۴۰۷/۰۵/۱۵») | ✓ |
| `province_code` | از فرم جستجو | int | ❌ |
| `township_code` | از فرم جستجو | int | ❌ |
| `organization_code` | از فرم جستجو | int | ❌ |
| `service_title` | `<td>` با لیبل «نوع فعالیت» | string | ✓ |
| `applicant_name` | `<td>` با لیبل «نام متقاضی» | string | ✓ |

### ۲.۵. تغییرات breaking احتمالی upstream

این تغییرات در upstream به‌عنوان **MAJOR** در نظر گرفته می‌شوند و scraper باید به‌روزرسانی شود:

- تغییر URL پایه (مثلاً `q.mojavez.ir` → `new.mojavez.ir`).
- تغییر ساختار `/license/<rn>` (مثلاً انتقال به `/api/v2/license/<rn>` با JSON).
- حذف فیلد `request_number` یا تغییر نام آن.
- افزودن نیاز به احراز هویت (مثلاً OAuth2).

مانیتورینگ: اگر نرخ موفقیت scrape زیر ۹۰٪ در یک ساعت، هشدار + بررسی دستی.

---

## 3. قرارداد B: API داخلی v3 (`/api/v1/*`)

### ۳.۱. قواعد عمومی

- **URL prefix:** `/api/v1/`.
- **احراز هویت:** هدر `X-API-Key: <key>`. key در `api_keys` جدول با hash ذخیره می‌شود.
- **scope-based:** هر endpoint نیازمند scope‌های خاص است.
- **rate limit:** ۶۰ req/min برای هر key (قابل تنظیم در `api_keys.rate_limit_per_min`).
- **Content-Type:** درخواست و پاسخ `application/json; charset=utf-8`.
- **Timestamp:** همیشه ISO 8601 با timezone `Asia/Tehran`.

### ۳.۲. ساختار پاسخ استاندارد

#### موفق:

```json
{
  "success": true,
  "data": { ... },
  "meta": {
    "contract_version": "1.0.0",
    "request_id": "req_abc123",
    "latency_ms": 42
  }
}
```

#### خطا:

```json
{
  "success": false,
  "error": {
    "code": "not_found",
    "message": "Applicant not found",
    "details": { "tracking_code": "HG-XYZ12345" }
  },
  "meta": {
    "contract_version": "1.0.0",
    "request_id": "req_abc123"
  }
}
```

### ۳.۳. کد وضعیت HTTP

| کد | معنا |
|---|---|
| 200 | موفق |
| 201 | ساخته شد |
| 204 | بدون محتوا (مثلاً DELETE موفق) |
| 400 | درخواست نامعتبر (validation error) |
| 401 | احراز هویت نامعتبر (missing/invalid API key) |
| 403 | scope ناکافی |
| 404 | موجودیت یافت نشد |
| 409 | تعارض (مثلاً duplicate) |
| 422 | خطای اعتبارسنجی فیلد |
| 429 | rate limit exceeded |
| 500 | خطای داخلی سرور |
| 503 | سرویس در دسترس نیست (upstream circuit open) |

### ۳.۴. endpoint‌ها

#### B.3.4.1. جستجوی متقاضی با کد رهگیری

```
GET /api/v1/applicants/{tracking_code}
X-API-Key: <key with scope: read:applicants:basic OR read:applicants:full>
```

**Response 200:**

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

**فیلدهای قابل افشا بر اساس scope:**

| فیلد | `read:applicants:basic` | `read:applicants:full` (نیازمند `consent_external_share=true`) | `read:applicants:status` |
|---|---|---|---|
| `tracking_code` | ✓ | ✓ | ✓ |
| `national_code` (ماسک) | ✓ | ✓ | ✗ |
| `first_name`, `last_name` | ✗ | ✓ | ✗ |
| `mobile` | ✗ | ✓ | ✗ |
| `license_state` | ✓ | ✓ | ✓ |
| `current_status` | ✗ | ✓ | ✓ |
| `license_valid_until` | ✓ | ✓ | ✗ |
| `service_title` | ✓ | ✓ | ✗ |
| `updated_at` | ✓ | ✓ | ✓ |

اگر `consent_external_share=false` و scope `full` درخواست شده، پاسخ با `read:applicants:basic` معادل است + هشدار در `meta.warnings`.

#### B.3.4.2. جستجوی مجوز ملی

```
GET /api/v1/licenses/search?national_code=<10-digit>&province=<code>
X-API-Key: <key with scope: read:licenses>
```

**Response 200:**

```json
{
  "success": true,
  "data": [
    {
      "request_number": "Q-12345678",
      "national_code_masked": "072059****",
      "license_state": "صادر شده",
      "license_valid_until": "2027-05-15",
      "service_title": "فرش دستباف",
      "matched_applicant_tracking_code": "HG-ABC12345",
      "last_seen_at": "2026-11-16T10:30:00+03:30"
    }
  ],
  "meta": { "contract_version": "1.0.0", "request_id": "req_2", "latency_ms": 35, "count": 1 }
}
```

#### B.3.4.3. صفحات lookup

```
GET /api/v1/lookups/provinces
GET /api/v1/lookups/townships?province=<code>
GET /api/v1/lookups/organizations
X-API-Key: <key with scope: read:lookups>
```

#### B.3.4.4. وضعیت sync

```
GET /api/v1/sync/status
X-API-Key: <key with scope: read:sync:status>
```

**Response 200:**

```json
{
  "success": true,
  "data": {
    "circuit_breaker_state": "closed",
    "last_upstream_success_at": "2026-11-16T10:25:00+03:30",
    "last_cycle_at": "2026-11-16T10:30:00+03:30",
    "pending_jobs": 0,
    "failed_jobs_24h": 2,
    "auto_confirmed_24h": 18,
    "operator_flagged_24h": 3
  },
  "meta": { "contract_version": "1.0.0", "request_id": "req_3", "latency_ms": 8 }
}
```

#### B.3.4.5. اجرای دستی sync (admin)

```
POST /api/v1/sync/run
X-API-Key: <key with scope: write:sync:run>
Content-Type: application/json

{ "national_code": "0720593107", "province": 7 }
```

**Response 202:**

```json
{
  "success": true,
  "data": { "job_id": "job_xyz789", "status": "queued" },
  "meta": { "contract_version": "1.0.0", "request_id": "req_4" }
}
```

#### B.3.4.6. تصمیم اپراتور

```
POST /api/v1/sync/{audit_id}/decide
X-API-Key: <key with scope: write:sync:decide>
Content-Type: application/json

{ "decision": "confirmed", "applicant_id": 42, "note": "تأیید شد" }
```

`decision`: `confirmed` | `rejected`.

### ۳.۵. pagination

endpoint‌های لیست از query param‌های زیر پیروی می‌کنند:

- `page` (default 1)
- `per_page` (default 20، max 100)
- `sort` (مثلاً `-updated_at` برای نزولی)

`meta` شامل:

```json
"pagination": {
  "page": 1, "per_page": 20, "total": 145, "total_pages": 8
}
```

---

## 4. قرارداد C: ACL بین NationalGateway و Core

### ۴.۱. قرارداد `ApplicantSyncAdapter`

```php
interface ApplicantSyncAdapterInterface
{
    /**
     * ترجمهٔ License ملی به ApplicantDelta (تغییرات قابل اعمال روی متقاضی).
     */
    public function translateUpstreamToDomain(License $national): ApplicantDelta;

    /**
     * بررسی رضایت اشتراک داده با خارج.
     */
    public function validateConsent(Applicant $applicant): bool;

    /**
     * بررسی تعارض با قاعدهٔ source-of-truth.
     */
    public function checkConflict(Applicant $applicant, ApplicantDelta $delta): ConflictResolution;

    /**
     * اعمال delta با idempotency key.
     */
    public function applyDelta(
        Applicant $applicant,
        ApplicantDelta $delta,
        string $idempotencyKey
    ): ApplyResult;
}
```

### ۴.۲. `ApplicantDelta`

```php
final readonly class ApplicantDelta
{
    public function __construct(
        public ?string $licenseState = null,
        public ?string $licenseValidUntil = null,
        public ?string $currentStatus = null,  // only in AutoConfirmed mode
        public ?string $serviceTitle = null,
        public ?int $matchedLicenseId = null,
    ) {}
}
```

### ۴.۳. `ConflictResolution`

```php
enum ConflictResolution: string {
    case ApplyAsIs = 'apply_as_is';        // upstream newer, apply
    case SkipLocalNewer = 'skip_local_newer'; // local newer, skip
    case ForceLocal = 'force_local';       // source-of-truth is local, ignore upstream
    case RequiresOperator = 'requires_operator'; // ambiguous, flag for review
}
```

### ۴.۴. `ApplyResult`

```php
final readonly class ApplyResult
{
    public function __construct(
        public bool $applied,
        public string $action,  // 'updated', 'skipped', 'conflict_skipped', 'forgotten'
        public int $applicantId,
        public int $versionBefore,
        public int $versionAfter,
        public ?string $conflictReason = null,
    ) {}
}
```

---

## 5. قرارداد D: رویدادها (Event Bus)

### ۵.۱. رویدادهای تعریف‌شده

| نام رویداد | payload | ناشر | مشترک‌های احتمالی |
|---|---|---|---|
| `ApplicantRegistered` | `{applicant_id, tracking_code, national_code_masked}` | `Core:RegisterApplicant` | `Queue:SendSms`, `Queue:SendEmail`, `NationalGateway:ScheduleSync` |
| `ApplicantStatusChanged` | `{applicant_id, from, to, by_user_id, triggered_by}` | `Core:ChangeStatus` | `Queue:Notify`, `Reporting:Track`, `NationalGateway:ScheduleSync` |
| `LicenseIssued` | `{applicant_id, license_id, issued_at}` | `Core:IssueLicense` | `Queue:SendSms`, `Queue:SendEmail` |
| `PaymentConfirmed` | `{applicant_id, payment_id, amount}` | `Core:ConfirmPayment` | `Core:AdvanceStatus`, `Queue:Notify` |
| `NationalLicenseMatched` | `{applicant_id, national_license_id, confidence, mode}` | `NationalGateway:PatternMatcher` | `NationalGateway:ApplyAutoConfirmed` (if mode=auto), `Queue:NotifyOperator` (if mode=operator_flag) |
| `SyncConflictSkipped` | `{applicant_id, local_updated_at, upstream_seen_at, reason}` | `NationalGateway:ApplicantSyncAdapter` | `Queue:NotifyAdmin`, `Reporting:Track` |
| `CircuitBreakerOpened` | `{system, failure_count, opened_at}` | `NationalGateway:CircuitBreaker` | `Queue:NotifyAdmin` |
| `CircuitBreakerClosed` | `{system, closed_at, duration_open}` | `NationalGateway:CircuitBreaker` | `Queue:NotifyAdmin` |

### ۵.۲. ساختار payload

همیشه JSON با:

```json
{
  "event": "ApplicantRegistered",
  "occurred_at": "2026-11-16T10:30:00+03:30",
  "payload": { ... },
  "correlation_id": "corr_abc123"
}
```

`correlation_id` برای ردیابی زنجیرهٔ رویدادها (مثلاً از `ApplicantRegistered` تا `LicenseIssued`).

### ۵.۳. idempotency

هر subscriber باید idempotent باشد. رویداد ممکن است بیش از یک‌بار منتشر شود (در صورت retry). مشترک با `event_id` (UUID) از تکرار جلوگیری می‌کند.

---

## 6. قرارداد E: JobQueue

### ۶.۱. جدول `jobs`

```sql
CREATE TABLE jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(64) NOT NULL,            -- 'default', 'sync', 'notifications', 'reports'
    payload JSON NOT NULL,                 -- {class: '...', method: '...', args: [...]}
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    available_at DATETIME NOT NULL,
    reserved_at DATETIME NULL,
    completed_at DATETIME NULL,
    failed_at DATETIME NULL,
    error TEXT NULL,
    idempotency_key VARCHAR(64),
    UNIQUE KEY uq_jobs_idem (idempotency_key),
    INDEX idx_jobs_queue_available (queue, reserved_at, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### ۶.۲. worker CLI

```
php scripts/worker.php --queue=default --max-jobs=100 --max-time=300
```

- هر job با `SELECT ... FOR UPDATE SKIP LOCKED` برداشت می‌شود.
- `attempts` افزایش می‌یابد.
- اگر `attempts >= max_attempts`، `failed_at` تنظیم و `error` ذخیره.
- retry با backoff نمایی: `available_at = now + 2^attempts * 10s`.

### ۶.۳. idempotency

اگر `idempotency_key` موجود و قبلاً کامل شده، job جدید skip می‌شود.

---

## 7. مدیریت تغییر قرارداد

### ۷.۱. فرآیند تغییر

۱. PR با:
   - تغییر در این فایل.
   - bump نسخه (MAJOR/MINOR/PATCH).
   - به‌روزرسانی یا افزودن contract test در `tests/Contract/`.
   - به‌روزرسانی fixture اگر رفتار تغییر کرده.

۲. تأیید maintainer اصلی + maintainer هر ماژول درگیر.

۳. ثبت در `CHANGELOG.md`.

۴. اگر breaking (MAJOR)، اعلام در channel توسعه حداقل ۲ هفته پیش از merge.

### ۷.۲. عقب‌گرد

اگر قرارداد جدید در تولید فاجعه ساخت:

- revert PR.
- اگر داده با قرارداد جدید نوشته شده، migration بازگشت با نسخهٔ قدیمی.
- ثبت در `DEBT_REVIEW.md`.

### ۷.۳. deprecation

برای حذف endpoint در نسخهٔ بعدی:

- در نسخهٔ فعلی: هدر `Deprecation: true` + `Sunset: <date>` در response.
- در `CHANGELOG.md` اعلام.
- حداقل ۶ ماه پیش از حذف.

---

*پایان قرارداد یکپارچه‌سازی.*
