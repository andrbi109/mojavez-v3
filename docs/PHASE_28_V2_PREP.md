# Phase 28 — v2.0.0 preparation (v1.9.10)

> **تاریخ:** 2026-11-17
> **شاخه:** `phase/28-v2-prep` (merge به `main`)
> **هدف:** آماده‌سازی نهایی برای v2.0.0 — پروژه ZERO debt دارد.

## ۱) تأیید نهایی

### ۱-۱) Unit tests
```
Total: 580 assertions, 0 failures
```
همه ۳۴ فایل تست PASS می‌شوند.

### ۱-۲) HTTP endpoint sweep
- ۹ مسیر public: همه 200 ✅
- ۴ مسیر user (unauth): همه 302 ✅
- ۶ مسیر admin (unauth): همه 302 ✅
- ۱ مسیر ناموجود: 404 ✅

### ۱-۳) Session persistence
admin login → navigate to `/` → return to `/admin/dashboard` → session persists ✅

### ۱-۴) 18-status workflow
همه ۱۸ وضعیت (۱۴ مشترک + ۴ مخصوص مشاغل خانگی) فعال. تغییر وضعیت
دستی (admin/expert) + سیستمی (autoProgress + autoCancel) ✅

### ۱-۵) No stubs / no version-gated
هیچ قابلیتی غیرفعال نیست. هیچ قابلیتی به v2.0.0 رزرو نشده ✅

## ۲) آمار نهایی پروژه

| شاخص | مقدار |
|---|---|
| مسیرها | 200 (Core 157 + NationalGateway 43) |
| کنترلرها | 27 (Core 25 + NationalGateway 2) |
| ویوها | 62+ |
| migrations | 21 (Core 14 + NationalGateway 5 + Queue 2) |
| تست‌ها | 580 assertion، 0 failure |
| critical debt | 0 |
| high debt | 0 |
| medium debt | 0 |
| low debt | 0 |

## ۳) قابلیت‌های پیاده‌سازی‌شده (همه v2 + gateway)

### عمومی
home, guide, about, services, signup, login, tracking, health, license view by token

### کاربر
panel (overview + 7 sub-pages), profile + avatar, requests, payments,
licenses, notifications, tracking, registration wizard (4 steps),
revision request, my-records

### مدیر
dashboard, applications (list + view + edit), manual registration,
document upload/delete, status change (18 statuses, manual + automatic),
payment request, license state, postal-code requests, my-tasks, users
CRUD, service types CRUD, wizard steps CRUD + reorder, form fields
CRUD + reorder, license titles CRUD + toggle + by-service, fee tariff
CRUD + groups + items + tick, settings + image upload, sms templates +
entry review, payments review + csv + channels, bulk import (8 routes),
reports (5 dimensions), output (general + grouped + csv + pdf),
revisions (approve/reject + apply payload), license requests review,
license upload, sync (status + pending + run + readiness), status-cycle
diagram

### درگاه ملی
43 API v1 routes, sync orchestrator, stage 2 readiness checker,
outbound sync service, pattern matcher (fuzzy address), golden-link

### سرویس‌ها
AvatarService (GD), SmsService (Kavenegar), FileStorage,
FeeCalculatorService, AutoCancelService (cancel + auto-progress),
NotificationService, NationalSyncOrchestrator, OutboundSyncService,
Stage2ReadinessChecker, PatternMatcher

## ۴) گام بعدی

پروژه آمادهٔ تأیید کاربر برای ارتقا به v2.0.0 است. طبق دستور
کاربر، تا تأیید نهایی، نسخه به 2.0.0 نمی‌رسد.

وقتی کاربر تأیید کند:
- VERSION → 2.0.0
- README.md → 2.0.0
- CHANGELOG.md → `## [2.0.0] — 2026-11-17` (final release section)
- `git tag v2.0.0` + push

هیچ کد تغییر نمی‌کند (v1.9.10 → v2.0.0 فقط version-bump است).
