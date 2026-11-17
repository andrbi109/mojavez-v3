# مدل دامنهٔ mojavez-v3

> این سند موجودیت‌ها، Value Object‌ها، روابط و enum‌های مدل دامنه را بر اساس فایل‌های واقعی `app/Modules/Core/Domain/` و `app/Modules/NationalGateway/Domain/` تشریح می‌کند. مرجع راهبردی `docs/00-MASTER.md §6` است.

---

## ۱. موجودیت‌های Core

تمام کلاس‌های دامنهٔ Core در `app/Modules/Core/Domain/` قرار دارند و با صفت `final readonly` ساخته شده‌اند (immortal value objects مطابق `CODING_CONVENTIONS §2.3`). هفت موجودیت اصلی:

- `Applicant.php` — قلب سامانه (پرونده). کلاس `App\Modules\Core\Domain\Applicant` با ۲۷ فیلد از جمله: `id`, `personId`, `trackingCode` (HG-XXXXXXXX با CSPRNG)، `nationalCode` (CHAR(10))، `currentStatus` (ApplicantStatus)، `licenseState`، `licenseValidUntil`، و ستون‌های جدید v3: `nationalLicenseRequestNumber` (لنگر اتصال طلایی)، `matchConfidence`، `matchedAt`، `version` (optimistic concurrency)، `deletedAt` (soft-delete)، `consentExternalShare` (پرچم اشتراک PII با سیستم خارجی). متدهای کمکی: `isMatchedToNational()`, `isAutoSyncEligible()`, `hasValidLicense()`, `nationalCodeMaskPattern()`.
- `Person.php` — لنگر هویت (از v2 به ارث). شامل `nationalCode`, `firstName`, `lastName`, `fatherName`, `mobile`, `mobile2`, `mobile3`, `postalCode`, `personType` (Real/Legal)، و برای اشخاص حقوقی `companyName`, `companyNationalId`, `regNumber`, `regDate`.
- `PropertyDetail.php` — جزئیات محل کسب‌وکار (۱:۱ با Applicant): `province`, `county` (township)، `addressStreet`, `addressAlley`, `addressPlaque`, `postalCode`.
- `JobDetail.php` — جزئیات شغل (۱:۱ با Applicant): `jobTitle`, `isicCode`, `supervisorName`, `employeeCount`, `monthlyIncome`.
- `Payment.php` — رکورد پرداخت (۱:N با Applicant): `amount`, `gateway`, `trackingNumber`, `status` (pending/paid/failed), `paidAt`.
- `Document.php` — فایل آپلود‌شده (۱:N با Applicant): `documentTypeId`, `originalName`, `storedPath`, `mime`, `sizeBytes`.
- `LicenseFile.php` — PDF مجوز صادرشده (۱:N با Applicant): `licenseNumber`, `filePath`, `issuedAt`, `expiresAt`.

ساختار نمونه از `app/Modules/Core/Domain/Applicant.php`:

```php
final readonly class Applicant
{
    public function __construct(
        public int $id,
        public ?int $personId,
        public string $trackingCode,
        public string $nationalCode,
        public ApplicantStatus $currentStatus,
        public LicenseState $licenseState,
        public ?string $licenseValidUntil,
        // ... 20 other fields
        public ?string $nationalLicenseRequestNumber = null,
        public ?int $matchConfidence = null,
        public ?string $matchedAt = null,
        public int $version = 1,
        public ?string $deletedAt = null,
        public bool $consentExternalShare = false,
    ) {}

    public function isAutoSyncEligible(): bool
    {
        return !$this->isMatchedToNational()
            && $this->currentStatus->isAutoSyncTransitionableFrom();
    }
}
```

---

## ۲. موجودیت‌های NationalGateway

موجودیت‌های ماژول درگاه ملی در `app/Modules/NationalGateway/Domain/` قرار دارند:

- `License.php` — یک رکورد از `qr.mojavez.ir/api/v1/licenses/search`. شامل `requestNumber`, `applicantName`, `userImage`, `licenseTitle`, `organizationTitle`, `provinceTitle`, `townshipTitle`, `respondedAt`, `status` (LicenseStatus). فیلدهای anchor اتصال طلایی (`maskedNationalCode`, `fatherName`, `businessAddress`, `postalCodeMasked`, `isicCode`, `expiresAt`) از صفحهٔ `/track/{rn}` توسط `TrackScraper` پر می‌شوند. سه factory متد دارد: `fromUpstream()` (از JSON API)، `fromArray()` (از cache)، `fromTrackPage()` (از صفحهٔ track).
- `Filter.php` — payload جستجوی upstream (صفحه، pageSize، province, township, organization, nationalCode masked).
- `LicenseCollection.php` — collection صفحه‌بندی‌شده (items, page, pageSize, total).

---

## ۳. روابط موجودیت‌ها

روابط اصلی مطابق `§6.1` و ماتریس SCHEMA_OWNERSHIP:

- `Person` 1:N `Applicant` (یک شخص می‌تواند چند پروندهٔ جداگانه داشته باشد، مثلاً برای چند نوع فعالیت).
- `Applicant` 1:1 `JobDetail` (یک شغل فعال در زمان ثبت‌نام).
- `Applicant` 1:1 `PropertyDetail` (یک محل کسب‌وکار).
- `Applicant` 1:N `Document` (چند فایل آپلودی در طول پرونده).
- `Applicant` 1:N `Payment` (چند تراکنش پرداخت، فقط آخرین paid معتبر).
- `Applicant` 1:N `LicenseFile` (مجوزهای صادرشده و بازصادرشده).
- `Applicant` 1:N `StatusLogEntry` (لاگ کامل انتقال‌ها).
- `Applicant` 1:1 `NationalLicense` (از طریق `applicants.national_license_request_number` ← `national_licenses.request_number`، پس از AutoConfirmed اتصال طلایی).
- `Applicant` 0..1 `Applicant` (self-reference از طریق `parent_application_id` برای multi-license).

همهٔ جداول فرعی از `applicants(id)` با `ON DELETE CASCADE` حذف می‌شوند (`§7.5`).

---

## ۴. Enum‌ها

سه enum اصلی در `app/Modules/Core/Domain/Enums/` و دو enum در `app/Modules/NationalGateway/Domain/Enums/`:

### ۴.۱. `ApplicantStatus` (۱۸ وضعیت فارسی — وارث v2)

در `app/Modules/Core/Domain/Enums/ApplicantStatus.php`، با type `string` (Persian slug) و ذخیره به‌صورت `VARCHAR(32)`. ۱۴ وضعیت مشترک + ۴ وضعیت مختص کسب‌وکار خانگی (service_type_id=2). متدهای کلیدی: `order()` (ممنوعیت بازگشت به عقب)، `isTerminal()`, `isPreIssue()` (مشمول auto-cancel ۱۴ روزه از `ثبت درخواست صدور`)، `isAutoSyncTransitionableFrom()` (اجازهٔ انتقال توسط نقش `auto_sync`). نمونه:

```php
enum ApplicantStatus: string
{
    case Draft = 'پیش‌نویس';
    case IssueRequest = 'ثبت درخواست صدور';
    case LicenseIssuance = 'صدور مجوز';
    case LicenseDelivery = 'تحویل مجوز';        // terminal
    case Rejected = 'رد درخواست';                  // terminal
    case Cancelled = 'لغو';                       // terminal
    case FinalApprovalTrackingCode = 'تأیید نهایی دریافت کد رهگیری'; // terminal
    // ... 11 more
    public function isAutoSyncTransitionableFrom(): bool
    {
        return in_array($this, [
            self::IssueRequest, self::DocUploadToOrg,
            self::InitialApproval, self::FinalApproval,
        ], true);
    }
}
```

### ۴.۲. `LicenseState` (۴ مقدار — وارث v2)

در `app/Modules/Core/Domain/Enums/LicenseState.php`: `Current='جاری'`, `Issued='صادر شده'`, `Revoked='باطل شده'`, `Expired='منقضی'`. متد `fromNationalStatus(string)` نگاشت‌کنندهٔ وضعیت upstream (`active`, `pending`, `revoked`, `expired`) به حالت داخلی است.

### ۴.۳. `MatchConfidence` (جدید v3)

در `app/Modules/NationalGateway/Domain/Enums/MatchConfidence.php` با type `int`: `AutoConfirmed=90`, `OperatorFlag=50`, `AutoRejected=0`. متد `fromScore(score, autoThreshold=90, operatorThreshold=50)` تصمیم می‌گیرد. `mayAutoAct()` فقط برای `AutoConfirmed` برقرار است (الزام اتصال طلایی به تأیید بالا). آستانه‌ها از طریق `SYNC_AUTO_CONFIRM_THRESHOLD` و `SYNC_OPERATOR_FLAG_THRESHOLD` قابل تنظیم.

### ۴.۴. سایر enum‌ها

- `PersonType` (`Real`/`Legal`) در `app/Modules/Core/Domain/Enums/PersonType.php`.
- `LicenseStatus` در `app/Modules/NationalGateway/Domain/Enums/LicenseStatus.php` (وضعیت در درگاه ملی).
- `ConflictResolution` در `app/Modules/NationalGateway/Domain/Enums/ConflictResolution.php` (LocalWins/UpstreamWins/ManualReview).

---

## ۵. Collections و متدهای کمکی

دامنه از collection‌های ساده استفاده می‌کند (`LicenseCollection`, آرایه‌های typed با PHPDoc `@return Applicant[]`) به‌جای generic collection library. متدهای کمکی روی `Applicant` رفتار کسب‌وکار را کپسوله می‌کنند: `fullName()` برای نمایش، `isIssuedLicense()` برای صفحهٔ پیگیری عمومی، `isPreIssue()` برای منطق ۱۴ روزه auto-cancel، و `nationalCodeMaskPattern()` که الگوی ماسک‌شده را برای PatternMatcher می‌سازد. این متدها بر اساس همان قانون طلایی لایه‌بندی (`§4.4`) در خود موجودیت قرار دارند تا controller نیازی به منطق کسب‌وکار نداشته باشد.
