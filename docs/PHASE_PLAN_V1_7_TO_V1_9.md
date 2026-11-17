# Phase Plan — v1.7 → v1.9 (pre-v2.0.0)

> **تاریخ:** 2026-11-17
> **هدف:** تکمیل قابلیت‌های v2 و gateway در v3 قبل از ارتقا به v2.0.0
> **شرط کاربر:** تا تأیید نهایی، نسخه به 2.0.0 نرود.

## مختصر تحلیل v2 (نسخه فعال کاربران)

**v2 (نسخه 0.6.2)** شامل:
- **۱۴۶ مسیر** در `index.php` (vs v3 فعلی: ۱۰۴ مسیر)
- **۲۳ کنترلر** (vs v3 فعلی: ۱۶)
- **۶۲ view** (vs v3 فعلی: ~50)
- **۵ سرویس** (`ApplicantRegistrationService`, `AutoCancelService`, `FeeCalculatorService`, `FileStorage`, `PaymentReceiptService`)
- **۲۸ مدل**
- **۶ migration** (از 2025-11-09 تا 2025-11-13)
- **schema.sql کامل** با ۲۸ جدول (همه در v3 موجود، + ۱۴ جدول جدید در v3 برای national gateway/queue)

## gapهای v3 فعلی نسبت به v2

### A. کنترلرها / مسیرهای گمشده یا ناقص
1. **AdminController**: فقط ۵ متد (dashboard, applications, viewApplication, changeStatus + 3 stub). v2: ۱۹ متد شامل:
   - `loginForm`, `login`, `logout` (در v3 → AuthController)
   - `registerForm`, `registerProcess` (ثبت‌نام دستی توسط مدیر) — **گمشده**
   - `editApplication`, `updateApplication` — **گمشده**
   - `addDocument`, `deleteDocument` — **گمشده**
   - `requestPayment`, `updateLicenseState` — **گمشده**
   - `postalRequests`, `approvePostalRequest`, `rejectPostalRequest` — **گمشده**
   - `myTasks` (فیلتر نقش) — **گمشده**

2. **ServiceTypeController** — **گمشده کامل**: `/admin/service-types`, `/admin/service-types/save`, `/admin/service-types/delete`

3. **WizardStepController** — **گمشده کامل**: `/admin/wizard-steps` + CRUD کامل

4. **SmsController** — **گمشده کامل**: `/admin/sms`, `/admin/sms/save-template`, `/admin/sms/delete-template`, `/admin/sms/review-entry`

5. **BulkImportController** — **گمشده کامل**: ۸ مسیر `/admin/bulk-import/*` (آپلود CSV, preview, execute, result, missing-docs, documents upload)

6. **ReportController** — **گمشده کامل**: ۱۱ مسیر `/admin/reports/*` + `/admin/output/*` + `/admin/report/csv` + `/admin/report/pdf`

7. **PaymentController** — **گمشده کامل**: ۵ مسیر `/admin/payments/*` + `/admin/payment-channels/*`

8. **FeeTariffController** — در v3 فعلی فقط CRUD پایه. v2: ۶ مسیر شامل `fee-tariff/settings`, `fee-tariff/group/save`, `fee-tariff/group/{id}/delete`, `fee-tariff/items/save`, `payment/tick`

### B. مسیرهای تکمیلی گمشده
- `/admin/settings/upload-image` (آپلود عکس برای تنظیمات)
- `/admin/license-titles/by-service/{id}` (AJAX)
- `/document/raw/{id}` (سرو فایل خام)
- `/tracking/license/first-access`, `/tracking/license/request-access`, `/tracking/license/pay`
- `/license/view/{token}`
- `/admin/license/upload`, `/admin/license-requests`, `/admin/license-requests/review`

### C. سرویس‌ها
- **ApplicantRegistrationService** (484 خط) — منطق ثبت‌نام پیچیده که در v3 فعلی در controller inline شده. پورت شود.
- **PaymentReceiptService** — **گمشده**: رسید پرداخت با QR code.

### D. seed data
- `seed-admin.sql` — باید migration اضافه شود.
- `document_types` فقط ۱ ردیف (avatar) — v2 شامل انواع مدارک: تصویر کارت ملی، عکس پرسنلی، سند مالکیت، گواهی مهارت، ...
- `service_types` در v3 با ۵ ردیف seed شده، v2 ممکن است داده واقعی داشته باشد.

## برنامه فازبندی (۶ فاز)

### فاز 16 (v1.7.0) — تکمیل seed و document_types
**هدف:** seed پیش‌فرض document_types + فعال‌سازی `required` در step4 با doc_types واقعی.

**وظایف:**
1. Migration: `seed_document_types_default.sql` — اضافه‌کردن ۵ document_type پیش‌فرض:
   - `national_id` (تصویر کارت ملی) — required
   - `personal_photo` (عکس پرسنلی) — required
   - `ownership_deed` (سند مالکیت) — optional
   - `skill_cert` (گواهی مهارت) — optional
   - `business_license` (مجوز کسب) — optional
2. Migration: `seed_admin_default.sql` — admin پیش‌فرض با رمز هش‌شده.
3. اصلاح `step4.php`: `required` بر اساس `document_types.is_required` دینامیک شود (نه hardcode).
4. اصلاح `RegistrationController::requiredDocs()` — خواندن از DB.
5. افزودن `handleDocumentUploads` واقعی به `processStep4`: ذخیره فایل + ردیف در `documents`.
6. Test: flow کامل با آپلود مدرک واقعی.

### فاز 17 (v1.8.0) — پنل ادمین کامل (AdminController, ServiceType, WizardStep, Sms)
**هدف:** پیاده‌سازی ۶ کنترلر گمشده با ۳۵+ مسیر.

**وظایف:**
1. **AdminController** کامل: registerForm/registerProcess, editApplication, updateApplication, addDocument, deleteDocument, requestPayment, updateLicenseState, postalRequests, approvePostalRequest, rejectPostalRequest, myTasks (۱۲ متد جدید).
2. **ServiceTypeController** کامل: index, save, delete.
3. **WizardStepController** کامل: index, save, toggle, delete, move.
4. **SmsController** کامل: index, saveTemplate, deleteTemplate, reviewEntry.
5. **AdminController::editApplication** + view `admin/edit_application.php` — فرم ویرایش کامل پرونده.
6. **AdminController::registerForm** + view `admin/register.php` — ثبت‌نام دستی متقاضی توسط مدیر.
7. **AdminController::postalRequests** + view `admin/postal_requests.php`.
8. اضافه‌کردن مسیرها به `Core/Module.php`.
9. اضافه‌کردن لینک‌های سایدبار در `admin_layout.php`.

### فاز 18 (v1.9.0) — مالی کامل (Payment, FeeTariff, BulkImport, Report)
**هدف:** پورت کامل کنترلرهای مالی و گزارش‌گیری.

**وظایف:**
1. **PaymentController** کامل: index (لیست پرداخت‌ها), review (تأیید/رد), csv (خروجی CSV), saveChannel, deleteChannel.
2. **FeeTariffController** کامل: saveSettings, groupSave, groupDelete, itemsSave, toggleTick (۵ متد جدید).
3. **BulkImportController** کامل: index, upload, preview, execute, result, missingDocs, uploadDocs, saveDocs (۸ مسیر).
4. **ReportController** کامل: byProvince, byCity, byVillage, byLicense, byDate, generalReport, singleApplicantReport, groupedReportForm, groupedReportResult, csvReport, pdfReport (۱۱ مسیر).
5. Viewهای جدید: `admin/payments.php`, `admin/bulk_import.php`, `admin/bulk_preview.php`, `admin/bulk_result.php`, `admin/bulk_missing_docs.php`, `admin/bulk_upload_docs.php`, `admin/report_stats.php`, `admin/output_general.php`, `admin/output_grouped_form.php`.

### فاز 19 (v1.9.1) — License Controller کامل + سرویس‌های گمشده
**هدف:** تکمیل LicenseController + پورت PaymentReceiptService + ApplicantRegistrationService.

**وظایف:**
1. **LicenseController** کامل: firstAccess, requestAccess, submitReissuePayment, viewByToken, upload, requests, reviewRequest.
2. **PaymentReceiptService**: سرویس رسید پرداخت با QR code.
3. **ApplicantRegistrationService** پورت: استخراج منطق RegistrationController به سرویس.
4. Viewهای گمشده: `admin/license_requests.php` (در v3 هست ولی ناقص).

### فاز 20 (v1.9.2) — Tracking + Document + Revision کامل
**هدف:** تکمیل مسیرهای tracking + document + revision.

**وظایف:**
1. **TrackingController** کامل: submitPayment, submitSms (در v3 stub هستند).
2. **DocumentController** کامل: view, thumb, inline, pdf-viewer, raw (در v3 stub).
3. **RevisionController** کامل: form, submit, index, detail, approve, reject.
4. Viewهای گمشده: `tracking/revision.php`, `admin/revisions.php`, `admin/revision_detail.php`.

### فاز 21 (v1.9.3) — تست نهایی + پولیش + آماده‌سازی برای v2.0.0
**هدف:** تست کامل همه مسیرها + رفع باگ + آماده‌سازی release.

**وظایف:**
1. تست HTTP کامل همه ۱۴۶ مسیر با curl.
2. تست agent-browser flow کامل: login → register → admin → reports → bulk-import.
3. رفع باگ‌های کشف‌شده.
4. به‌روزرسانی CHANGELOG + DEBT_REVIEW + README.
5. (در انتظار تأیید کاربر برای v2.0.0).

## نکات مهم

- **هیچ فاز به 2.0.0 نرسد** — همه فازها زیر 2.0.0 منتشر شوند (v1.7.0, v1.8.0, v1.9.0, v1.9.1, v1.9.2, v1.9.3).
- **هر فاز**: کامیت + merge --no-ff + تگ + push.
- **هر فاز یک ایجنت**: برای تسریع، ایجنت استخدام شود.
- **آزمون نهایی**: قبل از v2.0.0، همه مسیرها باید کار کنند.
- **در پایان فاز 21**: منتظر تأیید کاربر برای ارتقا به v2.0.0 بمان.

## نسخه‌بندی

| فاز | نسخه | شرح |
|---|---|---|
| 16 | v1.7.0 | seed document_types + active required |
| 17 | v1.8.0 | پنل ادمین کامل |
| 18 | v1.9.0 | مالی + گزارش + bulk-import |
| 19 | v1.9.1 | License + سرویس‌ها |
| 20 | v1.9.2 | Tracking + Document + Revision |
| 21 | v1.9.3 | تست نهایی + پولیش |
| (تأیید) | v2.0.0 | release نهایی پس از تأیید کاربر |
