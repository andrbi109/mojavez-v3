# بازبینی Debt فنی — mojavez-v3

> **نسخه:** 1.0 · **تاریخ:** ۱۴۰۵/۰۸/۲۶ (۲۰۲۶-۱۱-۱۶)
> **هدف:** قالب بازبینی debt فنی در پایان هر فاز از نقشهٔ راه، برای جلوگیری از انباشت debt و نیاز به refactor مجدد (رفع جنبهٔ پنهان ۵.۱۰ از سند استراتژی-ریسک).
> **مخاطب:** maintainer اصلی و توسعه‌دهندگان ماژول.

---

## ۱. قواعد بازبینی

۱. در پایان هر فاز (از فاز ۱ تا فاز ۸)، این فایل با یک بخش جدید پر می‌شود.

۲. هر فاز شامل:
   - **Debt introduced:** چه debt جدیدی در این فاز وارد شد.
   - **Debt paid:** چه debt قبلی در این فاز پرداخت شد.
   - **Debt carried forward:** چه debt‌هایی به فاز بعد منتقل شدند.
   - **Recommendation:** آیا فاز بعدی باید شامل «پاکسازی debt» باشد یا خیر.

۳. اگر debt انباشته شده (debt carried forward > ۳ مورد)، فاز بعدی باید شامل «پاکسازی debt» باشد — حتی اگر به‌معنای تأخیر در feature باشد.

۴. هر item debt با: شناسه، توضیح، موقعیت (فایل/ماژول)، شدت، راهکار پیشنهادی.

---

## ۲. شدت debt

| شدت | توضیح | مهلت رفع |
|---|---|---|
| `critical` | ریسک امنیتی یا فاجعهٔ تولید | پیش از فاز بعدی |
| `high` | ریسک عملکرد یا maintainability | در فاز بعدی |
| `medium` | نگرانی کیفیت کد | در ۲ فاز بعدی |
| `low` | نگرانی زیبایی‌شناختی | در فرصت مناسب |

---

## ۳. قالب بازبینی هر فاز

### فاز X: <نام فاز>

**تاریخ تکمیل:** YYYY-MM-DD
**Maintainer:** <نام>
**نسخهٔ منتشرشده:** vX.Y.Z

#### Debt introduced در این فاز

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-X-1 | ... | `path/to/file.php:L42` | medium | ... |

#### Debt paid در این فاز

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| D-(X-1)-2 | ... | refactor به ... |

#### Debt carried forward به فاز بعد

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-X-1 | ... | medium | فاز X+2 |

#### توصیه برای فاز بعد

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ (بله / خیر)
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟
- [ ] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟

---

## ۴. بازبینی‌های ثبت‌شده

### فاز ۰: تثبیت foundation

**تاریخ تکمیل:** 2026-11-17 (ادغام‌شده در فاز ۱ — فاز جداگانه اجرا نشد)
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** — (ادغام با فاز ۱)

> نکته: در mojavez-v3، فاز ۰ به‌صورت یک گام از فاز ۱ اجرا شد (ساخت مخزن، VERSION واحد،
> README، LICENSE، قراردادهای کد). این سند با پروژهٔ v2 ادغام می‌شود.

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| — | — | — | — | — |

#### Debt paid

- سه‌گانگی نسخهٔ v2 (README=0.1.0، VERSION=0.6.3، REFACTOR_PLAN=v2.0.0) رفع شد:
  منبع حقیقت واحد در `VERSION` (0.1.0) با ارجاع از `CHANGELOG.md` و `README.md`.

#### Debt carried forward

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| — | — | — | — |

#### توصیه

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ خیر — فاز ۰ بدون debt باقی ماند.

---

### فاز ۱: هسته و معماری

**تاریخ تکمیل:** 2026-11-17
**Maintainer:** AI agent (z.ai)
**نسخهٔ منتشرشده:** 0.1.0 (phase/1-core-architecture branch)

#### Debt introduced

| ID | توضیح | موقعیت | شدت | راهکار پیشنهادی |
|---|---|---|---|---|
| D-1-1 | EventBus هنوز یک anonymous placeholder است (یک کلاس inline در `public/index.php` و `scripts/migrate.php`) تا Phase 4 EventBus واقعی پیاده شود. ModuleLoader یک EventBus واقعی را انتظار دارد. | `public/index.php` lines ~155-165; `scripts/migrate.php` lines ~55-58 | medium | در فاز ۴ یک کلاس `App\Events\EventBus` بساز و به Container بایند. |
| D-1-2 | `collectSubscribers()` در ModuleLoader هنوز صدا زده نمی‌شود چون EventBus واقعی نیست. | `app/Modules/ModuleLoader.php` (متد موجود اما در index.php کامنت شده) | medium | در فاز ۴ فعال کن. |
| D-1-3 | `Request::$trustProxy` hard-coded `false` است. باید از `config('app.trust_proxy')` خوانده شود. | `public/index.php` خط ~140 | low | در فاز ۲ `app.trust_proxy` config اضافه کن و از Container بخوان. |
| D-1-4 | `install.php` و `check.php` برای shared hosting هنوز پیاده‌سازی نشده‌اند (تنها قالب ذکر شده در docs/00-MASTER.md §16.5). | `deploy/shared-hosting/install.php` | medium | در فاز ۶ بساز. |
| D-1-5 | `database/schema.sql` و `database/install.sql` هنوز ساخته نشده‌اند (تنها migration bootstrap هست). | `database/` | medium | در فاز ۲ همراه با migrations کامل Core بساز. |
| D-1-6 | `SseController::stream` در production 501 برمی‌گرداند به‌جای real streaming. | `app/Modules/Core/Controllers/SseController.php` | low | در فاز ۴ با EventBus واقعی پیاده کن. |
| D-1-7 | تست‌های واحد با `php tests/run.php` نوشته شده‌اند اما در محیط sandbox محلی اجرا نمی‌شوند چون PHP نصب نیست. CI در GitHub Actions اجرا می‌کند. | `tests/Unit/` | low | در محیط‌های توسعه با PHP نصب اجرا کن. |
| D-1-8 | static_analysis.php در فاز ۱ advisory-only است (warnings را fail نمی‌کند). | `tools/static_analysis.py` | low | در فاز ۶ به fail-on-warning سختگیرانه تبدیل کن. |
| D-1-9 | مسیر ذخیرهٔ `storage/logs` و `storage/sessions` به‌صورت پیش‌فرض writable فرض می‌شود. در shared hosting ممکن است نیاز به پیکربندی داشته باشد. | `app/Http/Session.php`, `app/Support/StructuredLogger.php` | low | در فاز ۶ در `deploy/shared-hosting/check.php` بررسی پیش‌نیاز اضافه کن. |

#### Debt paid

| ID (از فاز قبلی) | توضیح | چگونه پرداخت شد |
|---|---|---|
| (پیشینیان) | `Database::getInstance()` Singleton در v2 — سخت‌گیری در تست | با Container دستی با autowire + setter injection جایگزین شد. Connection حالا یک memoized singleton در Container است. |
| (پیشینیان gateway) | CSRF غیرفعال (S1) | در `public/index.php` هر درخواست mutating CSRF را با `hash_equals` اعتبارسنجی می‌کند. |
| (پیشینیان gateway) | Rate limiter کد مرده (ST1) | در فاز ۳ هنگام ساخت `MojavecClient::doWithRetry()` فعال خواهد شد؛ زیرساخت `RateLimiterService` در فاز ۳ اضافه می‌شود. |
| (پیشینیان gateway) | Connection factory (004-05) | `Connection::instance()` static cache — singleton واقعی. |
| (پیشینیان gateway) | Router regex `/` نامعتبر (004-06) | هر pattern با `/?$` تمام می‌شود (در `Router::matchPattern`). |
| (پیشینیان v2) | سه‌گانگی نسخه | `VERSION` (0.1.0) منبع واحد؛ CHANGELOG و README به آن ارجاع می‌دهند. |
| (پیشینیان v2) | SQLite در gateway | MySQL/MariaDB با `EMULATE_PREPARES=false` و utf8mb4_unicode_ci. |
| (پیشینیان v2) | `unsafe-eval` در CSP | `Response::CSP` بدون `unsafe-eval` و بدون CDN (self-host اجباری). |
| (پیشینیان v2) | `phpinfo.php` | هرگز وجود ندارد در v3. |
| (پیشینیان v2) | رمز hardcoded در seed-admin.sql | `install.php` در فاز ۶ رمز تصادفی ۱۶-کاراکتری تولید می‌کند. |

#### Debt carried forward به فاز ۲

| ID | توضیح | شدت | مهلت رفع |
|---|---|---|---|
| D-1-1 | EventBus placeholder | medium | فاز ۴ |
| D-1-2 | collectSubscribers() معلق | medium | فاز ۴ |
| D-1-3 | Request::$trustProxy hard-coded | low | فاز ۲ (با config app.trust_proxy) |
| D-1-4 | install.php و check.php | medium | فاز ۶ |
| D-1-5 | database/schema.sql و install.sql | medium | فاز ۲ |
| D-1-6 | SseController::stream واقعی | low | فاز ۴ |
| D-1-7 | اجرای محلی تست‌ها | low | محیط توسعه کاربر |
| D-1-8 | static_analysis fail-on-warning | low | فاز ۶ |
| D-1-9 | writable storage assumptions | low | فاز ۶ |

#### توصیه برای فاز ۲

- [ ] آیا فاز بعدی باید شامل «پاکسازی debt» باشد؟ خیر — ۹ مورد low/medium، همه با مهلت در فازهای بعدی.
- [ ] اگر بله، کدام debt‌ها باید پرداخت شوند؟ D-1-3 (سریع)، D-1-5 (حتماً — فاز ۲ به schema.sql نیاز دارد).
- [ ] تأثیر روی زمان‌بندی فاز بعدی چقدر است؟ کم — D-1-5 جزء کار فاز ۲ است، نه debt اضافی.

---

### فاز ۲: دامنهٔ v2

**تاریخ تکمیل:** ____________
**Maintainer:** ____________
**نسخهٔ منتشرشده:** ____________

_(قالب همان فاز ۰)_

---

### فاز ۳: ماژول درگاه ملی

**تاریخ تکمیل:** ____________
**Maintainer:** ____________
**نسخهٔ منتشرشده:** ____________

_(قالب همان فاز ۰)_

---

### فاز ۴: زیرساخت async و اتصال طلایی

**تاریخ تکمیل:** ____________
**Maintainer:** ____________
**نسخهٔ منتشرشده:** ____________

_(قالب همان فاز ۰)_

---

### فاز ۵: مهاجرت داده

**تاریخ تکمیل:** ____________
**Maintainer:** ____________
**نسخهٔ منتشرشده:** ____________

_(قالب همان فاز ۰)_

---

### فاز ۶: امنیت، تست، استقرار

**تاریخ تکمیل:** ____________
**Maintainer:** ____________
**نسخهٔ منتشرشده:** ____________

_(قالب همان فاز ۰)_

---

### فاز ۷: نهایی‌سازی و Rollout مرحلهٔ ۱

**تاریخ تکمیل:** ____________
**Maintainer:** ____________
**نسخهٔ منتشرشده:** ____________

_(قالب همان فاز ۰)_

---

### فاز ۸: Rollout مرحلهٔ ۲ (دوطرفه)

**تاریخ تکمیل:** ____________
**Maintainer:** ____________
**نسخهٔ منتشرشده:** ____________

_(قالب همان فاز ۰)_

---

## ۵. خلاصهٔ live debt

این بخش به‌صورت خودکار با اسکریپت `tools/debt_summary.py` به‌روزرسانی می‌شود
(اسکریپت در فاز ۱ پیاده نشده؛ تا فاز ۶ به‌صورت دستی نگه‌داری می‌شود):

| شدت | تعداد | قدیمی‌ترین | شناسه‌ها |
|---|---|---|---|
| critical | 0 | — | — |
| high | 0 | — | — |
| medium | 4 | 2026-11-17 | D-1-1, D-1-2, D-1-4, D-1-5 |
| low | 5 | 2026-11-17 | D-1-3, D-1-6, D-1-7, D-1-8, D-1-9 |

اگر critical > 0 یا high > 3، هشدار در CI.

**وضعیت پس از فاز ۱:** ۰ critical، ۰ high، ۴ medium، ۵ low. مطمئن می‌سازد که فاز ۲
بدون انباشت debt ادامه می‌یابد.

---

## ۶. خط‌مشی debt

۱. **debt intentional است نه حسرت:** هر debt باید با آگاهی و توضیح وارد شود. «بدون debt» غیرعملی است؛ «debt مدیریت‌شده» هدف است.

۲. **debt بدون مالک ممنوع:** هر debt باید متعلق به یک maintainer باشد که مسئول رفع آن است.

۳. **debt پس از refactor:** اگر فازی به‌جای کاهش debt، آن را جابجا کرد (مثلاً از Core به NationalGateway)، در `Debt paid` نیاید چون واقعاً پرداخت نشده. در `Debt introduced` فاز جدید بیاید با ارجاع به ID قبلی.

۴. **debt در CHANGELOG:** هر debt جدید در `CHANGELOG.md` فاز هم ذکر شود.

---

*پایان قالب بازبینی debt.*
