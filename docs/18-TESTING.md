# تست و تضمین کیفیت در mojavez-v3

> این سند لایه‌های تست سامانه را بر اساس فایل‌های واقعی `tests/`, `tools/`, `scripts/` و `.github/workflows/` تشریح می‌کند. مرجع راهبردی `docs/00-MASTER.md §18` است.

---

## ۱. tests/run.php (۱۷ تست PHP، ۳۶۱ assertion)

فایل `tests/run.php` runner بومی است که تمام فایل‌های `tests/Unit/*Test.php` را به ترتیب حروفی بارگذاری، instantiate و اجرا می‌کند. هر test فایلی است که یک آبجکت برمی‌گرداند (شامل متدهای `run()`, `assertions()`, `failures()`, `failureMessages()`، اختیاری `setUp()`/`tearDown()`/`isSkipped()`). ۱۷ فایل تست موجود:

`ContainerTest`, `ContainerWiringTest`, `EventBusTest`, `GoldenLinkIntegrationTest`, `HashTest`, `IpRateLimiterTest`, `JobQueueTest`, `MigrateFromGatewayTest`, `MigrateFromV2Test`, `NationalLicenseRepositoryTest`, `NormalizerTest`, `NotificationServiceTest`, `PatternMatcherTest`, `RouterTest`, `StructuredLoggerTest`, `TrackScraperTest`, `ValidatorTest`.

هر test از `tests/TestCase.php` مشتق می‌شود که assert helper‌ها را فراهم می‌کند (`assertEquals`, `assertTrue`, `assertNotNull`, `assertContains`, …). خروجی runner شامل تعداد assertions و failures هر تست است:

```
PatternMatcherTest                        48 assertions    0 failures  PASS
GoldenLinkIntegrationTest                 32 assertions    0 failures  PASS
...
Total: 361 assertions, 0 failures
```

خروجی ۰ یعنی همهٔ تست‌ها سبز. smoke test این را به‌عنوان assert اول بررسی می‌کند: `php tests/run.php 2>&1 | tail -1 | grep -qi "0 failures"`.

---

## ۲. smoke-test.sh (۴۱ PASS، ۶ SKIPPED در محیط بدون server)

فایل `tests/smoke-test.sh` دو حالت دارد:

- `--no-http` (پیش‌فرض وقتی سرور قابل راه‌اندازی نیست): تست‌های PHP واحد، static analysis، lint همهٔ فایل‌های PHP، migration script‌ها با `--help`، موجودیت فایل‌های کلیدی، تنظیمات امنیتی (invariant‌ها)، ماژول‌ها و migrationها، و test‌های data migration.
- حالت پیش‌فرض (با `BASE_URL` یا سرور قابل شروع): موارد بالا + HTTP endpoint check‌ها، هدرهای امنیتی، CSRF protection، rate limiting، admin guards، صفحات پیگیری و path traversal.

در محیط sandbox بیرون از Iran و بدون PHP قابل اجرا، smoke test بخش‌های PHP را در صورتی که PHP یافت شود اجرا می‌کند؛ در غیر این صورت ۶ بخش HTTP را skip می‌کند (۶ SKIPPED). ۴۱ assert در بخش‌های بدون HTTP اجرا می‌شوند. هدف تولید: ۱۸۵+ PASS و ۰ FAIL (`§18.3`). نمونه assert از smoke-test.sh:

```bash
assert "CSP بدون unsafe-eval" \
  'grep "public const CSP" -A2 app/Http/Response.php | grep -v "^.*\*\|^.*//" | grep -qv "unsafe-eval"'
assert "session.name = MOJAVEZSESSID" 'grep -q "MOJAVEZSESSID" app/Container.php'
assert "rate_limit_buckets جدول موجود است" \
  'find app/Modules -name "*rate_limit_buckets*.sql" | grep -q .'
```

این invariant‌ها از regress در فازهای بعدی جلوگیری می‌کنند.

---

## ۳. test_track_scraper.py (fixture-based)

فایل `scripts/test_track_scraper.py` یک regression test برای `TrackScraper` است که از fixture‌های واقعی HAR استفاده می‌کند. fixtureها در `tests/fixtures/har/` قرار دارند (capture واقعی از `qr.mojavez.ir`). تست RSC payload parsing را تأیید می‌کند: هر label (`کدملی`, `نام پدر`, `کدپستی`, `نشانی کسب و کار`, `استان`, `شهرستان`) به مقدار درست استخراج شود. این مهم است چون upstream می‌تواند ساختار صفحه را تغییر دهد و scraper بدون این تست در سکوت می‌شکند. اجرا در CI یک گام جداگانه است (طبق `.github/workflows/ci.yml` می‌تواند اضافه شود).

---

## ۴. pattern_matcher.py (۳ test case)

فایل `scripts/pattern_matcher.py` نسخهٔ پایتون الگوریتم `PatternMatcher` PHP است که به‌عنوان reference implementation استفاده می‌شود. سه test case کلیدی:

۱. `matchNumeric('0720593107', '072****107')` → match (anchorهای ۰۷۲ و ۱۰۷ تراز می‌شوند، ۴ ستاره wildcard).
۲. `matchNumeric('0720593107', '072****269')` → mismatch (anchor ۱۰۷ ≠ ۲۶۹ — رد).
۳. `matchNumeric('12345', '*****')` → inconclusive (هیچ anchor موجود نیست — تصمیم‌گیری ممکن نیست).

این نسخه پایتون به توسعه‌دهنده اجازه می‌دهد الگوریتم را مستقل از PHP و MariaDB تست کند. اجرای آن در CI یک گام جداگانه است (`.github/workflows/ci.yml`):
```
- name: Pattern matcher test
  run: python3 scripts/pattern_matcher.py
```

---

## ۵. static_analysis.py (۱۸۵ فایل، ۰ FAIL)

فایل `tools/static_analysis.py` تحلیل استاتیک PHP است (`§18.1`). بررسی‌ها:

- هر فایل `.php` از basic syntax عبور کند (php_lint_simple).
- هر Controller زیر `app/Modules/<Module>/Controllers/` باشد (thin controller rule).
- هر public method در کلاس `*Service` PHPDoc summary داشته باشد.
- هر SQL query از prepared statement استفاده کند (هیچ string-concat با `$var`).
- هر echo/print از محتوای پویا از `htmlspecialchars` استفاده کند (جلوگیری از XSS).
- ثابت‌های `MAX_UPLOAD_SIZE`, `APP_VERSION` و غیره re-define نشوند.

حدود ۱۸۵ فایل PHP آنالیز می‌شوند. CI این را اجرا می‌کند و اگر FAIL پیدا شود، fail می‌شود:

```yaml
- name: Static analysis (0 FAIL required)
  run: |
    python3 tools/static_analysis.py
    if python3 tools/static_analysis.py 2>&1 | grep -qi "fail"; then
      echo "::error::static analysis reported a FAIL"
      exit 1
    fi
```

همچنین `tools/check_schema_ownership.py` ماتریس `SCHEMA_OWNERSHIP.md` را اعمال می‌کند و هر migration که به جدول خارج از ماژول دست می‌زند را fail می‌کند.

---

## ۶. CI workflow (MariaDB service container، ۲ job)

فایل `.github/workflows/ci.yml` روی هر push و PR اجرا می‌شود (`§27.2`). دو job:

- **`test`** (همیشه اجرا): MariaDB 10.11 service container با healthcheck، Setup PHP 8.2 با extension‌های `pdo_mysql, mbstring, fileinfo, gd, curl, zip`، Setup Python 3.11، wait-for-MariaDB loop، ساخت `.env`، اعمال migrationها (`php scripts/migrate.php --all`)، setup جداول منبع v2 (`php tests/setup_v2_source.php`)، lint همهٔ فایل‌های PHP، check schema ownership، static analysis، unit tests، pattern matcher، smoke test `--no-http`.
- **`smoke`** (فقط روی main): بعد از `test` موفق، سرور PHP built-in را شروع می‌کند و smoke test کامل با HTTP را اجرا می‌کند.

این دوjob به CI اجازه می‌دهد در PR سریع بگذرد (فقط `test`) و در main یکبار کامل. اهداف پوشش (`§18.2`): حداقل ۸۰٪ برای `app/Modules/Core/Domain/`، ۹۰٪ برای `ApplicantSyncAdapter.php` (ACL)، ۱۰۰٪ برای `Normalizer.php`. PHPStan پیشنهادی برای آینده است (`§18.8`).
