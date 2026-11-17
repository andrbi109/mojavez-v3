# Issues — mojavez-v3

این فایل لیست issues شناخته‌شده در پروژه است (چون در sandbox `gh` CLI در دسترس
نیست و چاپ/ذخیره credentials ممنوع است، issues به‌صورت فایل در مخزن ثبت
می‌شوند). هر Issue شمارهٔ `ISSUE-N` دارد و در commit message ها به آن ارجاع
داده می‌شود. پس از رفع، وضعیت به `RESOLVED` تغییر می‌کند و commit hash رفع
ذکر می‌شود.

---

## ISSUE-001 — RESOLVED

**عنوان:** `PdoSettingRepository::all()` با `PdoRepository::all()` سازگار
نیست → PHP Fatal Error هنگام boot HTTP سرور.

**تاریخ ثبت:** 2026-11-17
**تاریخ رفع:** 2026-11-17
**شدت:** critical (سرور HTTP اصلاً boot نمی‌شود)
**نسخه‌های تحت تأثیر:** v1.0.0 و v1.1.0 (از فاز ۶ به بعد)
**Commit رفع:** (پس از merge در این فایل به‌روزرسانی می‌شود)

### شرح خطا

هنگام اجرای development server:

```
C:\xampp\php\php.exe -S localhost:8000 -t public public\router.php
```

خطای مهلک زیر رخ می‌دهد (PHP 8.5.12):

```
PHP Fatal error: Declaration of
App\Modules\Core\Persistence\PdoSettingRepository::all(): array
must be compatible with
App\Modules\Core\Persistence\PdoRepository::all(string $sql, array $params = []): array
in D:\mojavez-v3\app\Modules\Core\Persistence\PdoSettingRepository.php on line 19
```

### ریشه

در `app/Modules/Core/Persistence/PdoRepository.php` (کلاس پایه) یک متد
`protected all(string $sql, array $params = []): array` وجود دارد که یک
**helper** برای اجرای SELECT و بازگرداندن همهٔ سطرهاست.

در `app/Modules/Core/Persistence/PdoSettingRepository.php` (کلاس مشتق‌شده)
متد `public all(): array` تعریف شده که متد دامنه‌ای (از
`SettingRepositoryInterface`) است و **همهٔ تنظیمات** را به‌صورت
`[key => value]` بازمی‌گرداند.

PHP 8.5+ سازگاری امضای override را به‌سختی اعمال می‌کند: متد فرزند باید
همان signature والد را داشته باشد یا LSP-compliant باشد. اینجا والد پارامتر
`string $sql` دارد ولی فرزند هیچ پارامتری ندارد → تضاد.

### چرا در CI/تست‌ها گرفتار نشد؟

تست‌های واحد (`tests/run.php`) و contract tests (`tests/contract.php`) فقط
کلاس‌های خاص را `require` می‌کنند و از طریق autoloader از
`App\Container::make()` عبور نمی‌کنند که مسیر کامل DI را فعال کند. اما در
`public/index.php` (مسیر HTTP)، `Container::make(PdoSettingRepository::class)`
فراخوانی می‌شود که کلاس را بارگذاری می‌کند و PHP فوراً تضاد امضا را تشخیص
می‌دهد.

به‌علاوه، `smoke-test.sh --no-http` مسیر HTTP را فعال نمی‌کرد، پس خطا پنهان
ماند تا کاربر محلی سرور را اجرا کرد.

### راهکار

نام متدهای کمکی پایه را به نام‌های بدون تضاد تغییر می‌دهیم تا با متدهای
دامنه‌ای هم‌نام تداخل نکنند:

| قدیم (PdoRepository) | جدید |
|---|---|
| `protected all(string $sql, array $params = []): array` | `protected fetchAll(string $sql, array $params = []): array` |
| `protected one(string $sql, array $params = []): ?array` | `protected fetchOne(string $sql, array $params = []): ?array` |
| `protected scalar(string $sql, array $params = []): mixed` | `protected fetchScalar(string $sql, array $params = []): mixed` |

`execute`، `insert`، `transactional`، `escapeLike` تغییر نام نمی‌کنند چون با
هیچ متد دامنه‌ای تضاد ندارند.

سپس تمام فراخوانی‌های `$this->all(` → `$this->fetchAll(`، `$this->one(` →
`$this->fetchOne(`، `$this->scalar(` → `$this->fetchScalar(` در تمام کلاس‌های
مشتق‌شده به‌روزرسانی می‌شوند.

این تغییر:
- حجم مکانیکی دارد (۵۷ فراخوانی در ۱۲ فایل) ولی بدون تغییر منطقی است.
- آینده‌نگر است: هر متد دامنه‌ای جدید به نام `all()`/`one()`/`scalar()` در
  repository های دیگر نیز جلوی regression را می‌گیرد.
- نام‌های جدید با قرارداد PDO همخوان‌اند (`fetchAll`/`fetchOne` نام‌های
  استاندارد PHP PDO هستند).

### آزمون رفع

- سرور HTTP باید بدون Fatal Error boot شود.
- `tests/run.php` همچنان ۳۸۲ assertion / 0 failures.
- `tests/contract.php` همچنان ۲۱ assertion / 0 failures.
- `smoke-test.sh` **بدون** `--no-http` هم باید PASS شود (تست HTTP boot به
  smoke-test اضافه می‌شود تا جلوی regression گرفته شود).

### تمهیدات پیش‌گیری از تکرار

1. `smoke-test.sh` یک تست HTTP boot اضافه می‌کند: یک request به `/` می‌زند و
   تأیید می‌کند پاسخ HTTP 200 (یا حداقل بدون Fatal Error در log) است.
2. این تست در CI نیز اجرا می‌شود (`.github/workflows/ci.yml`).

---

<!-- Issues بعدی اینجا اضافه شوند -->
