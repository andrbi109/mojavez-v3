# Phase 12 — AvatarService + ProfileUpdateValidator (v1.3.0)

> **تاریخ:** 2026-11-17
> **شاخه:** `phase/12-avatar-service-v1.3.0` (merge به `main` با `--no-ff`)
> **نسخهٔ منتشرشده:** v1.3.0
> **هدف:** پرداخت D-9-1 (آخرین debt medium) — پیاده‌سازی واقعی
> `POST /panel/profile` (updateProfile) و `POST /panel/avatar`
> (uploadAvatar) به‌جای stubهای 501 فاز ۹.

## ۱) زمینه

پس از فاز ۹ که مسیرهای `/panel/*` را برای بستن 404 پس از ورود متصل
کرد، دو مسیر mutating (`POST /panel/profile` و `POST /panel/avatar`)
به‌صورت stub با 501 برگردانده شدند چون scope فاز ۹ فقط بستن 404 بود و
پیاده‌سازی کامل نیاز به service-layer مستقل داشت. فاز ۱۲ این debt را
با ساخت `AvatarService` + `ProfileUpdateValidator` پرداخت می‌کند.

## ۲) چه ساخته شد

### ۲-۱) `AvatarService` — بارگذاری + resize تصویر آواتار

| Method | Signature | هدف |
|---|---|---|
| `upload` | `(int $accountId, array $file): array` | اعتبارسنجی + resize + ذخیره + درج documents row |
| `getPath` | `(int $documentId): ?string` | resolve documents.id به filesystem path |

مراحل `upload()`:

1. **GD check**: `function_exists('imagecreatetruecolor')` — اگر نه،
   exception فارسی.
2. **Upload error**: باید `UPLOAD_ERR_OK` باشد. در غیر این صورت، پیام
   فارسی متناسب با کد (UPLOAD_ERR_INI_SIZE، UPLOAD_ERR_NO_FILE و...).
3. **MIME whitelist**: فقط `image/jpeg`, `image/png`, `image/webp`.
4. **Size cap**: ≤ ۲ MiB (`2 * 1024 * 1024` bytes).
5. **Real-image check**: `getimagesize()` روی `tmp_name` — هم تأیید
   real-image و هم re-validation MIME.
6. **WEBP support check**: اگر `imagecreatefromwebp` وجود ندارد، refusal
   با راهنمایی به JPEG/PNG.
7. **Path-traversal defense**:
   - نام روی دیسک همیشه server-constructed: `storage/uploads/avatars/account-{id}.jpg`.
   - نام کاربر فقط در `documents.original_name` ذخیره می‌شود پس از
     `basename()` + strip non-ASCII + strip dots + truncate به 250 نویسه.
8. **Resize**: `imagecreatetruecolor(256, 256)` + `imagecopyresampled` با
   crop-center (largest square از مرکز). transparency PNG/WEBP به
   white flatten می‌شود (چون خروجی JPEG است).
9. **Save**: `imagejpeg($resized, $absPath, 85)` + `chmod 0640`.
10. **DB write**:
    - `documents.applicant_id` NOT NULL است — اولین applicant کاربر
      (با `applicants.user_account_id`) به‌عنوان carrier استفاده می‌شود.
      اگر کاربر هیچ applicant‌ای ندارد، آواتار refusal می‌شود (با
      Persian message: «برای بارگذاری تصویر پروفایل ابتدا یک درخواست
      ثبت کنید»).
    - درج `documents` row با `doc_type='avatar'`, `file_name='account-{id}.jpg'`,
      `original_name=<sanitized>`, `file_path='storage/uploads/avatars/account-{id}.jpg'`.
    - `UPDATE user_accounts SET avatar_document_id = ?` به documents.id جدید.
11. **Best-effort cleanup**: فایل + row آواتار قبلی حذف می‌شود (در
    try/catch، خطاها log می‌شوند ولی upload success می‌ماند).

خروجی: `['success' => true, 'document_id' => int, 'file_path' => string]`.
در صورت شکست: `InvalidArgumentException` با پیام فارسی.

### ۲-۲) `ProfileUpdateValidator` — اعتبارسنجی فرم پروفایل

Static class (الگوی `App\Shared\Support\Validator`):

```php
public static function validate(
    array $body,
    UserAccountRepositoryInterface $repo,
    int $accountId
): array  // {valid: bool, errors: string[], clean: array}
```

قوانین:

| فیلد | قوانین | normalize |
|---|---|---|
| `username` | ۳-۵۰ نویسه، `[a-z0-9_.]+`، یکتا (با `findByUsername`) | lower-case |
| `mobile` | `09\d{9}`، یکتا (با `findByMobile`) | as-is |
| `email` | `filter_var(FILTER_VALIDATE_EMAIL)`، ≤ ۲۵۴ نویسه | as-is |
| `display_name` | ۱-۱۰۰ نویسه (Persian OK) | as-is |

تمام فیلدها optional هستند. اگر فیلدی در `$body` نباشد، اعتبارسنجی
نمی‌شود. اگر فیلدی empty string باشد، رفتار فیلد-specific است: `username`
و `email` و `display_name` به `null` تبدیل می‌شوند (clear) ولی
`mobile` خطا می‌دهد (چون در schema NOT NULL است).

در صورت شکست: `valid=false`, `errors` آرایه‌ای از پیام‌های فارسی،
`clean` شامل فقط فیلدهای موفق.

### ۲-۳) `UserPanelController` — wiring

| Action | پیش از فاز ۱۲ | پس از فاز ۱۲ |
|---|---|---|
| `GET /panel/profile` | read-only view با 501 notice | فرم قابل‌ویرایش + فرم آواتار + AJAX |
| `POST /panel/profile` | 501 stub | validate → update() → JSON 200/422 |
| `POST /panel/avatar` | 501 stub | AvatarService::upload() → JSON 200/422/500 |

ساختار constructor:
- ۶ arg به parent (همان الگوی Phase 10).
- ۱ arg اضافی: `AvatarService $avatars` (autowire از Container).

### ۲-۴) Repository additions

| Method | Interface | Implementation |
|---|---|---|
| `findByUsername(string $username): ?array` | `UserAccountRepositoryInterface` | `PdoUserAccountRepository` |
| `update(int $id, array $fields): void` | همان | همان (whitelist-enforced، dynamic SET clause) |

`update()` whitelist فقط `username` و `mobile` را قبول می‌کند (چون
این تنها ستون‌های قابل‌ویرایش کاربر روی `user_accounts` هستند). سایر
کلیدها به‌صورت silent drop می‌شوند.

### ۲-۵) Container binding

`Core/Module.php::register()` یک binding اضافه می‌کند (الگوی
`SmsService`):

```php
$container->bind(
    \App\Modules\Core\Services\AvatarService::class,
    fn () => new \App\Modules\Core\Services\AvatarService(
        $container->make(\App\Shared\Persistence\Connection::class),
        $container->logger
    )
);
```

`ProfileUpdateValidator` static class است — binding لازم نیست.

### ۲-۶) View rewrite

`app/Views/user/profile.php` از read-only dl به فرم قابل‌ویرایش + فرم
آواتار + AJAX + `<div id="profile-feedback">` بازنویسی شد:

- دو فرم (profile + avatar) با CSRF.
- Avatar form با `enctype="multipart/form-data"`.
- `<script>` در پایین که با `fetch()` به دو endpoint POST می‌زند و
  feedback inline نمایش می‌دهد. CSRF token از `<meta name="csrf-token">`
  خوانده می‌شود و در header `X-CSRF-TOKEN` ارسال می‌شود.
- فیلدهای `email` و `display_name` با badge «به‌زودی» نمایش داده
  می‌شوند و توضیح داده می‌شود که در v1.3 ذخیره نمی‌شوند (D-12-1).

### ۲-۷) Seed migration

`app/Modules/Core/migrations/20261117_007_seed_avatar_document_type.sql`:

```sql
INSERT IGNORE INTO document_types
    (doc_type_key, label, category, is_active, sort_order)
VALUES
    ('avatar', 'تصویر پروفایل', 'user_upload', 1, 100);
```

این فقط forward-compat است — `documents.doc_type` یک VARCHAR آزاد
است (نه FK به `document_types.id`). در v1.4+ اگر FK tightening شد،
این seed به‌صورت idempotent ردیف را تضمین می‌کند.

## ۳) فایل‌های اضافه/تغییر یافته

### فایل‌های جدید

| فایل | شرح |
|---|---|
| `app/Modules/Core/Services/AvatarService.php` | upload + resize + DB write |
| `app/Modules/Core/Support/ProfileUpdateValidator.php` | static validator |
| `app/Modules/Core/migrations/20261117_007_seed_avatar_document_type.sql` | idempotent seed |
| `tests/Unit/AvatarServiceTest.php` | validation paths |
| `tests/Unit/ProfileUpdateValidatorTest.php` | 4 cases |
| `docs/PHASE_12_AVATAR_PROFILE.md` | این فایل |

### فایل‌های تغییر یافته

| فایل | تغییر |
|---|---|
| `app/Modules/Core/Controllers/UserPanelController.php` | replace 2 stubs + profile() GET editable form + AvatarService dep + avatarUrlFor() helper |
| `app/Shared/Contracts/UserAccountRepositoryInterface.php` | +2 methods: `findByUsername()`, `update()` |
| `app/Modules/Core/Persistence/PdoUserAccountRepository.php` | +2 methods: `findByUsername()`, `update()` (whitelist + dynamic SET) |
| `app/Modules/Core/Module.php` | +1 binding (AvatarService), +1 migration entry (007) |
| `app/Views/user/profile.php` | rewrite به فرم قابل‌ویرایش + AJAX |
| `database/install.sql` | +1 INSERT IGNORE (mirror migration 007) |
| `VERSION` | `1.2.0` → `1.3.0` |
| `README.md` | ارجاع نسخه در سرصفحه |
| `CHANGELOG.md` | بخش `## [1.3.0]` جدید |
| `docs/DEBT_REVIEW.md` | بخش فاز ۱۲ + به‌روزرسانی خلاصهٔ live debt (medium 2→0, low 28→29) |

## ۴) Debt

### پرداخت‌شده (۱ medium)

| ID | شرح |
|---|---|
| D-9-1 | دو stub `/panel/profile` و `/panel/avatar` (501) |

### جدید (۱ low)

| ID | شرح | مهلت |
|---|---|---|
| D-12-1 | `email` و `display_name` اعتبارسنجی می‌شوند ولی ذخیره نمی‌شوند (schema گپ) | v1.4 |

### Carried forward

- D-2-3 (low, v1.4, partial): ~۱۲ کنترلر ادمین باقی‌مانده
- D-8-5 (low): pre-existing smoke-test variance
- D-11-1, D-11-2 (low): Phase 11 leftovers
- D-12-1 (low, v1.4): email/display_name columns
- ۲۴ مورد pre-existing low از فازهای قبلی

پس از فاز ۱۲: **۰ critical، ۰ high، ۰ medium، ۲۹ low**. پروژه به
وضعیت «بدون debt medium یا بالاتر» می‌رسد.

## ۵) تست

### ۵-۱) تست‌های unit جدید

- `AvatarServiceTest` — بدون write فایل واقعی (mock `$_FILES` با
  error codes مختلف): UPLOAD_ERR_INI_SIZE، UPLOAD_ERR_NO_FILE،
  invalid MIME، oversized. هر بار `InvalidArgumentException` با
  پیام فارسی. اگر GD در محیط تست موجود نباشد، test skip می‌شود.
- `ProfileUpdateValidatorTest` — ۴ case:
  1. همه فیلدها معتبر → valid=true.
  2. duplicate mobile → valid=false، errors شامل «قبلاً ثبت شده».
  3. invalid email → valid=false.
  4. empty body → valid=true، clean=[].

### ۵-۲) regression

- تمام unit tests قبلی بدون regression.
- تمام contract tests (21 assertion): بدون regression.
- HTTP smoke test: بدون regression (مسیرهای `/panel/profile` و
  `/panel/avatar` در smoke test پوشش داده نمی‌شوند چون نیاز به login
  دارند؛ ولی AJAX endpoints از طریق manual testing در فاز ۱۲ تأیید شد).
- `static_analysis.py` و `php_lint_simple.py`: همان warningهای
  pre-existing (false-positive‌ها).
- `check_schema_ownership.py`: PASS — migration 007 فقط INSERT
  به جدول Core-owned است.

## ۶) تصمیم‌های طراحی

### ۶-۱) چرا `documents.applicant_id` به‌جای NULL از اولین applicant کاربر استفاده می‌شود؟

`documents.applicant_id` در schema واقعی `NOT NULL` با FK به `applicants`
روی `ON DELETE CASCADE` است. task constraint «don't change schema»
را داریم. بنابراین avatar باید به یک applicant واقعی متصل باشد.

این محدودیت معنی UX ضعیفی دارد: کاربری که ثبت‌نام کرده ولی هنوز
درخواستی ثبت نکرده نمی‌تواند آواتار بارگذاری کند. راه‌حل بهینه
ALTER TABLE برای nullable کردن `applicant_id` بود ولی schema constraint
ما را از این بازداشت. به‌جایش، AvatarService با پیام فارسی refusal
می‌کند (و فایل روی دیسک را برای جلوگیری از orphan حذف می‌کند).

در v1.4+ می‌توان با یک ALTER TABLE کوچک `documents.applicant_id` را
nullable کرد و این محدودیت را رفع کرد (D-12-2 احتمالی).

### ۶-۲) چرا `email` و `display_name` ذخیره نمی‌شوند؟

`user_accounts` این ستون‌ها را ندارد (فقط `users` admin table ستون
`display_name` دارد و `email` در هیچ جدول کاربری نیست). task constraint
«don't change schema» این ستون‌ها را در v1.3 منع می‌کند.

به‌جای حذف این فیلدها از فرم، تصمیم گرفتیم فرم آن‌ها را با badge
«به‌زودی» نمایش دهد و `ProfileUpdateValidator` اعتبارسنجی را انجام
دهد (forward-compat). `update()` whitelist به‌صورت silent آن‌ها را
drop می‌کند. این الگو وقتی در v1.4 ستون‌ها اضافه شوند، به‌سادگی با
گسترش whitelist قابل فعال‌سازی است.

این به‌عنوان D-12-1 (low debt) ثبت شد.

### ۶-۳) چرا static class برای `ProfileUpdateValidator`؟

الگوی `App\Shared\Support\Validator` (که از فاز ۱ وجود دارد و static
است). مزیتی: نیازی به Container binding نیست، testing آسان‌تر است
(یک method call ساده)، و وابستگی‌ها (repo) به‌صورت method arg
عبور می‌کنند.

### ۶-۴) چرا `/panel/avatar` فایل را از طریق `/panel/avatar/file` serve نمی‌کند؟

Task صراحتاً می‌گوید: «`/panel/avatar` برای بارگذاری، نه serve». در
حال حاضر، آواتار ذخیره می‌شود ولی در view هنوز letter avatar نمایش
داده می‌شود (تا زمانی که route serve در v1.4+ اضافه شود). این به‌عنوان
محدودیت شناخته‌شده در docblock `avatarUrlFor()` ذکر شده. در v1.4+
می‌توان `GET /panel/avatar/file` اضافه کرد که با `AvatarService::getPath()`
فایل را serve کند (با `requireUserLogin()` + ownership check).

### ۶-۵) چرا GET `/panel/profile` به‌جای redirect از AJAX استفاده می‌کند؟

برای تجربهٔ کاربری بهتر: user می‌تواند چندین field را ویرایش کند و
بازخورد فوری بگیرد بدون reload کامل صفحه. همچنین redirect بعد از POST
(POST/Redirect/GET pattern) برای فرم‌های non-AJAX مناسب‌تر است که در
این فاز پیاده نشد.

## ۷) نتیجه

دو stub 501 فاز ۹ با پیاده‌سازی واقعی تعویض شدند. `AvatarService` با
GD resize و path-traversal defense ساخته شد. `ProfileUpdateValidator`
به‌صورت static class با ۴ فیلد پیاده شد. `/panel/profile` به فرم
قابل‌ویرایش با AJAX تبدیل شد. ۱ debt medium (آخرین) پرداخت شد.
پروژه به وضعیت «بدون debt medium یا بالاتر» رسید.

مسیرهای بعدی (v1.4+): اتمام ~۱۲ کنترلر ادمین باقی‌مانده (D-2-3)،
افزودن ستون‌های `email`/`display_name` به `user_accounts` (D-12-1)،
افزودن endpoint serve آواتار، endpoint عمومی license-titles (D-11-1).
