# Phase 19 — License + Document + Revision کامل (v1.9.1)

> **تاریخ:** 2026-11-17
> **شاخه:** `phase/19-license-document-revision` (merge به `main`)
> **هدف:** تکمیل ۳ کنترلر stub فاز ۱۵: LicenseController, DocumentController,
> RevisionController.

## ۱) زمینه

فاز ۱۵ (v1.6.0) این سه کنترلر را با متدهای stub ساخت — فقط signature
داشتند و `{"success": true, "data": [], "message": "endpoint under
construction"}` برمی‌گرداندند. فاز ۱۹ آنها را کاربردی می‌کند.

## ۲) LicenseController (۷ متد)

| متد | مسیر | شرح |
|---|---|---|
| `firstAccess` | `POST /tracking/license/first-access` | تأیید مالکیت (tracking_code + mobile)، ساخت license_access_requests، تولید توکن ۷۲ ساعته |
| `requestAccess` | `POST /tracking/license/request-access` | همان firstAccess اما با rate limit ۲/هفته |
| `submitReissuePayment` | `POST /tracking/license/pay` | آپلود رسید + ساخت payments row |
| `viewByToken` | `GET /license/view/{token}` | **عمومی** — نمایش مجوز با توکن ۷۲ ساعته |
| `upload` | `POST /admin/license/upload` (admin) | آپلود تصویر مجوز |
| `requests` | `GET /admin/license-requests` (admin) | لیست درخواست‌های دسترسی |
| `reviewRequest` | `POST /admin/license-requests/review` (admin) | تأیید/رد |

## ۳) DocumentController (۶ متد)

| متد | مسیر | شرح |
|---|---|---|
| `view` | `POST /document/view` | محتوای modal برای AJAX |
| `avatar` | `GET /document/avatar` | سرو آواتار کاربر |
| `thumb` | `GET /document/thumb/{id}` | بندانگشتی ۲۰۰×۲۰۰ با GD |
| `inline` | `GET /document/inline/{id}` | نمایش inline (مرورگر PDF) |
| `pdfViewer` | `GET /document/pdf-viewer` | iframe wrapper |
| `raw` | `GET /document/raw/{id}` | دانلود attachment |

همه متدها ownership check دارند.

## ۴) RevisionController (۶ متد)

| متد | مسیر | شرح |
|---|---|---|
| `form` | `GET /tracking/revision/{code}` (user) | فرم درخواست بازبینی |
| `submit` | `POST /tracking/revision/submit` (user) | ثبت درخواست |
| `index` | `GET /admin/revisions` (admin) | لیست درخواست‌ها |
| `detail` | `GET /admin/revisions/view/{id}` (admin) | مقایسه side-by-side |
| `approve` | `POST /admin/revisions/approve/{id}` (admin) | تأیید + اعمال payload |
| `reject` | `POST /admin/revisions/reject/{id}` (admin) | رد با دلیل |

## ۵) Viewهای جدید (۶)

- `admin/license_requests.php`
- `admin/revisions.php`
- `admin/revision_detail.php`
- `tracking/revision.php`
- `license/view.php` (عمومی)
- `document/modal.php`

## ۶) سایدبار ادمین

`admin_layout.php` با ۲ لینک جدید:
- `/admin/license-requests` (درخواست‌های مجوز, icon: bi-patch-check)
- `/admin/revisions` (بازبینی‌ها, icon: bi-arrow-clockwise)

## ۷) Debt

- **پرداخت‌شده:** D-2-3 (partial) — ۳ کنترلر جدید کامل شد
- **باقی‌مانده D-2-3:** TrackingController::submitPayment + submitSms
  (در فاز ۲۰)
- **بدون debt جدید**

## ۸) تست

- `php_lint_simple.py`: 284 files, 7 issues (همگی pre-existing)
- `static_analysis.py --strict`: 0 surviving warnings
- `check_schema_ownership.py`: 1 pre-existing failure (integration_log)

## ۹) نتیجه

همه مسیرهای stub فاز ۱۵ حالا کاربردی هستند:
- کاربر می‌تواند تصویر مجوز را با توکن ۷۲ ساعته مشاهده کند
- کاربر می‌تواند درخواست بازبینی پرونده بدهد
- مدیر می‌تواند مدارک را با thumbnail ببیند
- مدیر می‌تواند درخواست‌های مجوز و بازبینی را تأیید/رد کند

تنها کار باقی‌مانده D-2-3: TrackingController::submitPayment + submitSms
که در فاز ۲۰ انجام می‌شود.
