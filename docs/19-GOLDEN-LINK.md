# اتصال طلایی در mojavez-v3

> این سند چرخهٔ کامل «اتصال طلایی» (golden link) را بر اساس کد واقعی `PatternMatcher`, `ApplicantSyncAdapter`, `NationalSyncOrchestrator` و `sync_audit_log` تشریح می‌کند. مرجع راهبردی `docs/00-MASTER.md §9` است.

---

## ۱. PatternMatcher quorum (۹ فیلد)

کلاس `app/Modules/NationalGateway/Services/PatternMatcher.php` هستهٔ الگوریتم است (`§9.2`, `§9.3`). بینش کلیدی (§9.1): فیلدهای ماسک‌شده از upstream (مثلاً `072****107`) دادهٔ از دست رفته نیستند — آنها confirmation anchor هستند. رقم‌های آشکار باید با مقدار کامل محلی تراز شوند و ستاره‌ها wildcard هستند که مقدار محلی آنها را پر می‌کند. نه فیلد با وزن مجموعه می‌شوند (`PatternMatcher::WEIGHTS`):

```php
private const WEIGHTS = [
    'national_code'        => 10,  // strongest — masked pattern
    'full_name'            => 5,  // exact text
    'father_name'          => 3,  // exact text
    'business_province'    => 2,  // exact text
    'business_township'    => 2,  // exact text
    'license_title'        => 2,  // fuzzy text (contains)
    'postal_code'          => 3,  // masked pattern
    'business_address'     => 4,  // masked text pattern
    'request_number'       => 1,  // auxiliary signal
];
```

حداکثر امتیاز = ۳۲. سه الگوریتم match: `matchNumeric` (digit-by-digit با `*` به‌عنوان wildcard تک‌کاراکتری)، `matchText` (glob-style با `*****` به‌عنوان suffix wildcard)، `matchExactText` (مقایسهٔ برابر پس از normalizer)، `matchFuzzyText` (contains برای license_title).

طبق `§9.6` قاعدهٔ ایمنی ۱: هیچ فیلد منفردی کافی نیست. `national_code` باید match شود + حداقل ۲ فیلد دیگر برای AutoConfirmed. این از false positive در صورت تصادفی بودن یک فیلد جلوگیری می‌کند.

---

## ۲. MatchConfidence (AutoConfirmed ≥۹۰، OperatorFlag ۵۰-۸۹، AutoRejected ۰)

enum در `app/Modules/NationalGateway/Domain/Enums/MatchConfidence.php` با type `int`:

- `AutoConfirmed = 90` — score ≥ آستانهٔ auto (پیش‌فرض ۹۰، قابل تنظیم با `SYNC_AUTO_CONFIRM_THRESHOLD`). نیاز به national code match + ≥۲ فیلد دیگر. `mayAutoAct()` فقط برای این برقرار است.
- `OperatorFlag = 50` — score بین ۵۰ و ۸۹. در پنل مدیر flag می‌شود، بدون تغییر status خودکار.
- `AutoRejected = 0` — anchor mismatch روی کد ملی ماسک‌شده (رقم‌های آشکار با مقدار محلی تراز نمی‌شوند).

متد `fromScore(score, autoThreshold=90, operatorThreshold=50)` تصمیم می‌گیرد. آستانه‌ها از طریق متغیرهای محیطی قابل تنظیم‌اند که در صورت نیاز به سختگیری یا انعطاف در محیط‌های مختلف قابل تنظیم باشد.

---

## ۳. ApplicantSyncAdapter (match + act + audit)

کلاس `app/Modules/NationalGateway/Services/ApplicantSyncAdapter.php` پل ACL بین NationalGateway و Core است. این کلاس orchestrator نیست — فقط یک match + act انجام می‌دهد. متد اصلی `matchAndAct(License, Applicant): string`:

```php
// §9.5 step "match" — build Person + property from applicant
$personVo = new Person(
    id: $applicant->personId ?? 0,
    nationalCode: $applicant->nationalCode,
    firstName: $applicant->firstName,
    lastName: $applicant->lastName,
    mobile: $applicant->mobile,
    personType: PersonType::Real,
    fatherName: $fatherName,   // fetched via personRepo if applicant has person_id
);

// D-4-2 fix: property_details now wired (was empty array before)
$propertyDetail = $this->propertyRepo->findPropertyDetailByApplicantId($applicant->id);
$property = [
    'province' => $propertyDetail->province,
    'township' => $propertyDetail->county,
    'business_address' => implode('، ', $addressParts),
    'postal_code' => $applicant->postalCode,
];

$result = $this->matcher->matchQuorum($license, $personVo, $property);

// Audit log regardless of outcome (§9.6 rule 5)
$this->auditRepo->log(
    applicantId: $applicant->id,
    nationalRequestNumber: $license->requestNumber,
    action: 'match_attempted',
    outcome: $this->outcomeForConfidence($result->confidence),
    confidence: $result->totalScore,
    context: ['fields_matched' => $result->fieldsMatched, ...]
);

return $this->act($license, $applicant, $result);
```

متد خصوصی `act()` بر اساس confidence تصمیم می‌گیرد:

- `AutoConfirmed` → `actAutoConfirmed()` (transaction اتمی per-applicant): `markMatchedToNational()`، `changeStatus('صدور مجوز')` با validateStatusTransition، `updateLicenseState(Issued)`، `setMatch` روی license، audit log، dispatch `ApplicantMatched` رویداد.
- `OperatorFlag` → فقط audit log برای مرور دستی.
- `AutoRejected` → audit log، بدون تغییر status.

property_details wiring (debt D-4-2) مهم است: قبل از این fix، فقط national_code + full_name + father_name می‌توانستند match شوند (آرایهٔ property خالی بود). حالا province + township + business_address + postal_code هم در quorum شرکت می‌کنند و AutoConfirmed می‌تواند واقعاً trigger شود.

---

## ۴. NationalSyncOrchestrator (runCycle، matchOne)

کلاس `app/Modules/NationalGateway/Services/NationalSyncOrchestrator.php` چرخهٔ کامل را در `runCycle(int $licenseBatchSize = 50): array` drive می‌کند (`§9.5`):

۱. **trigger** — cron واقعی، poor man's cron (بازدید مدیر `/admin/sync/status`)، یا اجرای دستی از پنل admin.
۲. **scrape** — اینجا انجام نمی‌شود؛ LicenseService قبلاً `national_licenses` را با anchor fields پر کرده است. Orchestrator فقط `findUnmatched($batchSize)` می‌زند (WHERE `matched_applicant_id IS NULL`).
۳. **match** — برای هر license، `ApplicantSyncAdapter::matchLicenseAgainstApplicants()` تمام متقاضیان eligible را تلاش می‌کند.
۴. **act** — adapter تصمیم را به‌صورت اتمی per-applicant اعمال می‌کند.
۵. **notify** — رویداد `ApplicantMatched` در `EventBus` dispatch می‌شود تا `NotificationSubscriber` SMS و in-app notification بفرستد. در پایان `SyncCycleCompleted`.
۶. **report** — خلاصه‌ای شامل `licenses_processed`, `applicants_tried`, `auto_confirmed`, `operator_flagged`, `auto_rejected` برمی‌گردد تا پنل `/admin/sync/status` نمایش دهد.

قواعد ایمنی (`§9.6`): idempotent (licenseهای match‌شده skip می‌شوند)، اتمی per-applicant، جلوگیری از double-action با UNIQUE constraint روی `applicants.national_license_request_number`، و full audit در `sync_audit_log`.

---

## ۵. sync_audit_log (هر تصمیم)

هر تصمیم اتصال طلایی یک رکورد در `sync_audit_log` می‌سازد (table owned by `Modules/NationalGateway`). action‌های ممکن:

- `match_attempted` — همهٔ تلاش‌ها، با outcome `auto_confirmed`/`operator_flag`/`auto_rejected`.
- `auto_confirmed` — تأیید خودکار، با context شامل `fields_matched`, `total_score`, `license_title`, `expires_at`.
- `operator_flagged` — نیاز به مرور، با context شامل reason و fields_matched.
- `auto_rejected` — anchor mismatch، با context شامل reason.
- `migrated` — marker مهاجرت داده با outcome `v2_import` یا `gateway_import`.

```php
// نمونه audit log از ApplicantSyncAdapter::actAutoConfirmed
$this->auditRepo->log(
    applicantId: $applicant->id,
    nationalRequestNumber: $license->requestNumber,
    action: 'auto_confirmed',
    outcome: 'auto_confirmed',
    confidence: $result->confidence->value,  // 90
    context: [
        'fields_matched' => $result->fieldsMatched,
        'total_score' => $result->totalScore,
        'license_title' => $license->licenseTitle,
        'expires_at' => $license->expiresAt,
    ]
);
```

این audit trail به مدیر امکان می‌دهد هر تصمیم خودکار را追溯 کند، در صورت نیاز دستی override کند، و در گزارش‌های فصلی استفاده شود.

---

## ۶. شماتیک جریان: scrape → match → act

```
 cron / admin visit
      │
      ▼
 NationalSyncOrchestrator::runCycle(batchSize=50)
      │
      ├─ licenseRepo.findUnmatched(50)  ← national_licenses WHERE matched_applicant_id IS NULL
      │
      │   ┌──────────────────────────────────────────────────┐
      │   │  برای هر license (با masked_national_code و ...): │
      │   │                                                  │
      │   │  ApplicantSyncAdapter::matchLicenseAgainstApplicants  │
      │   │     │                                            │
      │   │     ├─ applicantRepo.findAutoSyncEligible()      │
      │   │     │   ← applicants در وضعیت pre-issue           │
      │   │     │                                            │
      │   │     │   ┌─────────────────────────────────────┐  │
      │   │     │   │  برای هر applicant:                  │  │
      │   │     │   │    PatternMatcher::matchQuorum       │  │
      │   │     │   │      (9 فیلد، وزن‌دار)                  │  │
      │   │     │   │    → MatchResult (confidence, score) │  │
      │   │     │   │    sync_audit_log.insert              │  │
      │   │     │   │    ↓                                  │  │
      │   │     │   │    AutoConfirmed → actAutoConfirmed  │  │
      │   │     │   │      - applicants.markMatchedToNational│ │
      │   │     │   │      - changeStatus('صدور مجوز')        │  │
      │   │     │   │      - updateLicenseState(Issued)    │  │
      │   │     │   │      - licenseRepo.setMatch           │  │
      │   │     │   │      - dispatch ApplicantMatched      │  │
      │   │     │   │    OperatorFlag → audit log only     │  │
      │   │     │   │    AutoRejected → audit log only     │  │
      │   │     │   └─────────────────────────────────────┘  │
      │   └──────────────────────────────────────────────────┘
      │
      ├─ dispatch SyncCycleCompleted
      │
      ▼
  return summary → /admin/sync/status panel
```

این چرخه تضمین می‌کند که هیچ تصمیم خودکار بدون audit trail ثبت نشود، هیچ متقاضی دو بار transition نشود، و در صورت قطع وسط چرخه (مثلاً timeout)، دوباره اجرا امن باشد.
