# Phase 17 — Complete admin panel (v1.8.0)

> **تاریخ:** 2026-11-17
> **شاخه:** `phase/17-admin-panel-complete` (merge به `main`)
> **هدف:** تکمیل پنل ادمین — افزودن ۱۲ متد گمشده به AdminController + ۳
> کنترلر جدید (ServiceTypeController, WizardStepController, SmsController)
> + ۶ view جدید.

## ۱) زمینه

پس از فاز ۱۶ (v1.7.0) که seed document_types و admin پیش‌فرض را اضافه کرد،
پنل ادمین همچنان ناقص بود. AdminController فقط ۵ متد داشت (dashboard,
applications, viewApplication, changeStatus + ۳ stub از فاز ۱۱) در حالی
که v2 دارای ۱۹ متد بود. این فاز کار گمشده را پورت می‌کند.

## ۲) AdminController — ۱۲ متد جدید

| متد | مسیر | شرح |
|---|---|---|
| `registerForm` | `GET /admin/register` | فرم ثبت‌نام دستی متقاضی توسط مدیر |
| `registerProcess` | `POST /admin/register` | پردازش فرم، ساخت applicant با status='تأیید اولیه' |
| `editApplication` | `GET /admin/edit/{id}` | فرم ویرایش کامل پرونده (applicants + property + job + documents) |
| `updateApplication` | `POST /admin/update` | اعتبارسنجی + به‌روزرسانی applicant + related rows |
| `addDocument` | `POST /admin/add-document` | آپلود مدرک توسط مدیر برای applicant |
| `deleteDocument` | `POST /admin/delete-document` | حذف مدرک + unlink فایل |
| `requestPayment` | `POST /admin/request-payment` | درخواست پرداخت برای applicant (set payment_required=1) |
| `updateLicenseState` | `POST /admin/license-state` | تغییر وضعیت مجوز ('جاری', 'صادر شده', 'باطل') |
| `postalRequests` | `GET /admin/postal-requests` | لیست درخواست‌های تغییر کد پستی در انتظار |
| `approvePostalRequest` | `POST /admin/postal-requests/approve` | تأیید درخواست تغییر کد پستی + به‌روزرسانی applicants |
| `rejectPostalRequest` | `POST /admin/postal-requests/reject` | رد درخواست + ثبت دلیل |
| `myTasks` | `GET /admin/my-tasks` | فیلتر نقش-محور از /admin/applications (admin: همه، expert: فقط بررسی کارشناسی) |

## ۳) کنترلرهای جدید

### ServiceTypeController (۳ مسیر)
- `index` → `GET /admin/service-types` — لیست service_types
- `save` → `POST /admin/service-types/save` — INSERT/UPDATE
- `delete` → `POST /admin/service-types/delete` — DELETE

### WizardStepController (۵ مسیر)
- `index` → `GET /admin/wizard-steps` — لیست wizard_steps
- `save` → `POST /admin/wizard-steps/save` — INSERT/UPDATE
- `toggle` → `POST /admin/wizard-steps/toggle/{id}` — toggle is_active
- `delete` → `POST /admin/wizard-steps/delete` — DELETE
- `move` → `POST /admin/wizard-steps/move` — reorder (up/down)

### SmsController (۴ مسیر)
- `index` → `GET /admin/sms` — لیست sms_templates + user_sms_entries اخیر
- `saveTemplate` → `POST /admin/sms/save-template` — INSERT/UPDATE template
- `deleteTemplate` → `POST /admin/sms/delete-template` — DELETE template
- `reviewEntry` → `POST /admin/sms/review-entry` — تأیید/رد SMS entry

## ۴) Viewهای جدید (۶)

- `admin/register.php` — فرم ثبت‌نام دستی
- `admin/edit_application.php` — فرم ویرایش کامل
- `admin/postal_requests.php` — لیست درخواست‌های کد پستی
- `admin/service_types.php` — CRUD service_types
- `admin/wizard_steps.php` — CRUD wizard_steps
- `admin/sms.php` — CRUD sms_templates + review user_sms_entries

## ۵) سایدبار ادمین

`admin_layout.php` با ۶ لینک جدید گسترش یافت:
- `/admin/my-tasks` (وظایف من)
- `/admin/service-types` (انواع خدمت)
- `/admin/wizard-steps` (مراحل ویزارد)
- `/admin/sms` (پیامک)
- `/admin/postal-requests` (درخواست‌های کد پستی)
- `/admin/register` (ثبت‌نام دستی)

## ۶) آمار مسیرها

| ماژول | قبل | بعد | Δ |
|---|---|---|---|
| Core | 102 | 126 | +24 |
| NationalGateway | 43 | 43 | 0 |
| **مجموع** | **145** | **169** | **+24** |

## ۷) Debt

- **پرداخت‌شده:** D-2-2 (AdminController فقط ۵ متد → حالا ۱۷ متد)
- **بخشی پرداخت‌شده:** D-2-3 (۳ کنترلر جدید: ServiceType, WizardStep, Sms)
- **باقی‌مانده D-2-3:** Payment, BulkImport, Report, License, Document,
  Revision, Tracking (در فازهای ۱۸-۲۰)

## ۸) تست

- `php_lint_simple.py`: 270 files, 7 issues (همگی pre-existing)
- `static_analysis.py --strict`: 0 surviving warnings
- `check_schema_ownership.py`: 1 pre-existing failure (integration_log)
- تست HTTP: همه مسیرهای جدید با requireRole درست محافظت می‌شوند.

## ۹) نتیجه

پنل ادمین حالا کاربردی است. مدیر می‌تواند:
- ثبت‌نام دستی متقاضی
- ویرایش کامل پرونده
- آپلود/حذف مدارک
- درخواست پرداخت
- تغییر وضعیت مجوز
- تأیید/رد درخواست‌های کد پستی
- مدیریت انواع خدمت، مراحل ویزارد، الگوهای پیامک
- مشاهده وظایف فیلترشده بر اساس نقش

قابلیت‌های باقی‌مانده (مالی، گزارش، bulk-import) در فاز ۱۸ پیاده می‌شوند.
