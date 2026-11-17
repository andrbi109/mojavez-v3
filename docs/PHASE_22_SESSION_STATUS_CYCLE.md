# Phase 22 — Session persistence fix + 18-status workflow (v1.9.4)

> **تاریخ:** 2026-11-17
> **شاخه:** `phase/22-session-fix-status-cycle` (merge به `main`)
> **هدف:** رفع مشکل حیاتی نشست + تکمیل چرخه ۱۸ وضعیت + پرداخت low debt.

## ۱) رفع مشکل حیاتی نشست

### ریشه

کاربر گزارش داد: "اطلاعات ورود کاربر یا مدیر در مرورگر ذخیره نمی‌شود و
با تغییر مسیر از داشبورد به صفحه اصلی و بازگشت، عملیات ورود باید دوباره
انجام شود."

**ریشه یابی:**
- `Session` class در `app/Http/Session.php` flag `secure` را بر اساس
  `Environment::isProduction()` تنظیم می‌کرد.
- `.env.example` پیش‌فرض `APP_ENV=production` داشت، پس `secure: true`
  می‌شد.
- مرورگرها cookie با flag `secure` را فقط روی HTTPS ذخیره می‌کنند،
  نه HTTP.
- سرور توسعه روی HTTP (port 8000) اجرا می‌شد، پس session cookie در
  هر navigation حذف می‌شد.

### رفع

1. `Session::__construct()` حالا `?bool $secure = null` می‌پذیرد.
   وقتی null باشد، متد `start()` به‌صورت خودکار HTTPS را از
   `$_SERVER['HTTPS']` تشخیص می‌دهد.
2. `Container.php` به‌جای `$this->environment->isProduction()`،
   `secure: null` (تشخیص خودکار) پاس می‌دهد.
3. `.env.example` حالا به‌صورت پیش‌فرض `APP_ENV=development` است.

### تأیید

```bash
curl -sS -D - http://127.0.0.1:8000/login | grep Set-Cookie
# Set-Cookie: MOJAVEZSESSID=...; HttpOnly; SameSite=Lax  (no "secure")
```

agent-browser flow: admin login → navigate to `/` → navigate back to
`/admin/dashboard` → نشست حفظ می‌شود (بدون redirect به login). ✅

## ۲) چرخه ۱۸ وضعیت

### ۱۸ وضعیت (۱۴ مشترک + ۴ مخصوص مشاغل خانگی)

**۱۴ وضعیت مشترک (all services):**
1. پیش‌نویس (Draft) — order 1
2. تکمیل اطلاعات (InfoComplete) — order 2
3. احراز نشانی (AddressVerification) — order 3
4. بررسی کارشناسی رشته (FieldExpertReview) — order 4
5. ثبت درخواست صدور (IssueRequest) — order 5
6. بارگذاری مدارک به سازمان (DocUploadToOrg) — order 6
7. درخواست اصلاح مدارک (DocCorrectionRequest) — order 7
8. تأیید اولیه (InitialApproval) — order 8
9. تأیید نهایی (FinalApproval) — order 9
10. صدور مجوز (LicenseIssuance) — order 10
11. تحویل مجوز (LicenseDelivery) — order 11 (terminal)
12. مشاهده و چاپ (ViewAndPrint) — order 12
13. رد درخواست (Rejected) — order 98 (terminal)
14. لغو (Cancelled) — order 99 (terminal)

**۴ وضعیت مخصوص مشاغل خانگی (service_type_id=2):**
15. تطبیق آدرس با ثبت احوال (AddressSabtAhvalMatch) — order 15
16. دریافت تصویر مجوز (LicenseImageReceipt) — order 25
17. تأیید اولیه در سامانه مشاغل خانگی (InitialApprovalSMKH) — order 35
18. تأیید نهایی دریافت کد رهگیری (FinalApprovalTrackingCode) — order 36 (terminal)

### تغییر وضعیت دستی (manual)

`AdminController::changeStatus()` (از فاز ۱۷):
- ورودی: `applicant_id` + `new_status` (Persian string) + `description`
- تبدیل با `ApplicantStatus::fromSlug()`
- اعتبارسنجی transition با `validateStatusTransition()`
- ثبت در `status_log` با `triggered_by = $role` (admin/expert)

### تغییر وضعیت سیستمی (automatic)

`AutoCancelService::autoProgress()` (جدید):
- برای هر متقاضی در وضعیت pre-issue که بیش از N روز در همان وضعیت مانده
- یک گام به جلو (forward) بر اساس `config/workflow.php`
- `triggered_by = 'system'`
- لاگ در `status_log`

`AutoCancelService::cancelExpired()` (موجود):
- متقاضیان با `current_status = 'ثبت درخواست صدور'` که ۱۴ روز گذشته
- → `لغو` با `triggered_by = 'auto_cancel'`

### صفحه نمودار چرخه

`GET /admin/status-cycle` → `app/Views/admin/status_cycle.php`:
- ۱۸ کارت Bootstrap با order, color, manual flag, pre_issue flag
- ۴ وضعیت مخصوص خانگی در بخش جداگانه
- لینک سایدبار اضافه شد

## ۳) Low debt پرداخت‌شده

### D-16-1 — document_types.is_required column

**قبل:** `RegistrationController::requiredDocs()` به‌صورت hardcode
`national_id` و `personal_photo` را required می‌دانست.

**بعد:**
- migration 012: `ALTER TABLE document_types ADD COLUMN is_required TINYINT(1) DEFAULT 0`
- `UPDATE document_types SET is_required = 1 WHERE doc_type_key IN ('national_id', 'personal_photo')`
- `RegistrationController::requiredDocs()` حالا از ستون `is_required`
  می‌خواند.
- `step4.php` از مقدار DB استفاده می‌کند.

### D-14-1 — notifyAdmins username-bridge gap

**قبل:** `NotificationService::notifyAdmins()` admins را با username
در `user_accounts` جستجو می‌کرد. اگر تطبیق نبود، silent skip.

**بعد:** هنگام عدم تطبیق، warning لاگ می‌شود (به‌جای silent skip).
محدودیت برای `admin_notifications` table آینده ثبت شد.

## ۴) نتیجه

- ✅ مشکل حیاتی نشست برطرف شد (کاربر不需要 re-login بعد از هر redirect)
- ✅ چرخه ۱۸ وضعیت کامل (manual + automatic)
- ✅ D-16-1 و D-14-1 پرداخت شد
- ✅ تست agent-browser تأیید کرد: login → navigation → session persists
- ✅ lint + static analysis strict PASS
