# mojavez-v3

> **نسخهٔ پایه:** 2.0.6 · **وضعیت:** انتشار نهایی (v2.0.6)
> **فلسفه:** PHP 8.2 خالص، بدون Composer، بدون فریم‌ورک.
> **مخزن‌های مرجع:** [`andrbi109/Mojavez-v2`](https://github.com/andrbi109/Mojavez-v2) (سامانهٔ صدور مجوز صنایع دستی) و [`andrbi108/mojavez-gateway`](https://github.com/andrbi108/mojavez-gateway) (scraper درگاه ملی).

> **وضعیت نهایی:** ۲۰۵+ مسیر یکتا + ۲۸ کنترلر + ۴۲ جدول + **ZERO debt** + اتصال درگاه ملی فعال + صفحات اختصاصی درگاه ملی — **v2.0.8 منتشر شد**.

**mojavez-v3** جانشین یکپارچهٔ دو پروژهٔ پیشین است که از صفر و در یک پایگاه کد واحد بازنویسی می‌شود. این پروژه دو زنجیرهٔ مجزا را به یک سامانهٔ واحد پیوند می‌زند:

1. **زنجیرهٔ داخلی صدور مجوز** (وارث Mojavez-v2): ثبت‌نام متقاضی، گردش‌کار ۱۸ وضعیتی، پرداخت رسید-محور، بارگذاری مدارک، صدور و تحویل تصویر مجوز، پنل مدیریت چندنقشی، پنل کاربری متقاضی، گزارش‌گیری، نرخ‌نامهٔ دهک‌بندی شده.
2. **زنجیرهٔ ارتباط با درگاه ملی** (وارث Mojavez Gateway): خواندن فقط‌خواندنی و فیلترشده از `qr.mojavez.ir`، کش محلی، جستجوی چندوجهی، استخراج صفحهٔ track با RSC parser، خروجی PDF، جستجوی دسته‌ای CSV.

### اتصال طلایی

ارزش راهبردی کل پروژه: scraper هر از گاهی درگاه ملی را می‌خواند، مجوز صادرشده را با **تطبیق الگویی + آرای چندفیلدی** (ماسک به‌عنوان لنگر) به متقاضی محلی نگاشت می‌کند، وضعیت پرونده را خودکار به «صدور مجوز» / «تحویل مجوز» تغییر می‌دهد و به متقاضی پیام می‌دهد (اعلان درون‌سامانه + SMS الگو-محور).

## پشتهٔ فنی

- **زبان:** PHP 8.2+ خالص (بدون Composer، بدون فریم‌ورک).
- **پایگاه داده:** MySQL 5.7+ / MariaDB 10.3+ با InnoDB و `utf8mb4_unicode_ci`.
- **DI Container:** Container دستی با autowire + setter injection (وارث gateway).
- **Frontend:** Bootstrap 5 RTL + Vazirmatn (self-host) + jsPDF + html2canvas + qrcode + PDF.js.
- **استقرار:** Apache/Nginx/Docker/Shared hosting.

## معماری

```
app/
├── Modules/
│   ├── Core/              # وارث v2 (applicants, persons, payments, ...)
│   ├── NationalGateway/   # وارث gateway (MojavecClient, TrackScraper, sync)
│   ├── Api/               # لایهٔ API + scope-based auth
│   ├── Queue/             # JobQueue + EventBus + Worker
│   └── Reporting/         # CSV/PDF
├── Shared/                # معادل packages/shared/
│   ├── Contracts/         # interface‌های مشترک
│   ├── Support/           # Config, StructuredLogger, PersianDate, Normalizer, ...
│   ├── Domain/            # Value Objects مشترک
│   └── Persistence/       # Connection, MigrationRunner
├── Container.php
└── Http/                  # Router, Session, Csrf, Request, Response
```

هر ماژول در `app/Modules/<Name>/Module.php` اینترفیس `App\Modules\ModuleInterface` را پیاده‌سازی می‌کند. `ModuleLoader` ماژول‌ها را بارگذاری، مسیرها را تجمیع، migration‌ها را به runner می‌سپارد و subscriberها را به EventBus متصل می‌کند.

## نقشهٔ راه (۸ فازی)

| فاز | محتوا |
|---|---|
| فاز ۱ | هسته و معماری: `ModuleInterface` + `ModuleLoader` + `MigrationRunner` + `StructuredLogger` + Container + HTTP + Connection |
| فاز ۲ | دامنهٔ v2: Value Objects، Enums، Repository interfaces، ماژول‌های Registration/License/Payment/Revision/Reporting |
| فاز ۳ | ماژول درگاه ملی: `CircuitBreaker` + `MojavecClient` + `TrackScraper` + `ApplicantSyncAdapter` (ACL) |
| فاز ۴ | زیرساخت async و اتصال طلایی: `integration_log` + `idempotency_key` + `NationalSyncOrchestrator` |
| فاز ۵ | مهاجرت داده از v2 و gateway به v3 |
| فاز ۶ | امنیت، تست، استقرار: Contract Test + CI/CD با GitHub Actions + scope-based API key |
| فاز ۷ | نهایی‌سازی: Rollout مرحلهٔ ۱ (یک‌طرفه ورودی) |
| فاز ۸ | Rollout مرحلهٔ ۲ (دوطرفه) پس از تثبیت ۳ ماههٔ مرحلهٔ ۱ |

## مستندات

مستندات مهندسی‌شده در `docs/`:

- [`docs/00-MASTER.md`](docs/00-MASTER.md) — سند اصلی معماری و طراحی v3 (مرجع اول).
- [`docs/01-v2-reference.md`](docs/01-v2-reference.md) — مرجع دادهٔ v2.
- [`docs/02-gateway-reference.md`](docs/02-gateway-reference.md) — مرجع scraper.
- [`docs/03-strategy-and-risk.md`](docs/03-strategy-and-risk.md) — مرجع استراتژی و ریسک.
- [`docs/04-comparison-and-improvements.md`](docs/04-comparison-and-improvements.md) — تحلیل مقایسه.
- [`docs/SCHEMA_OWNERSHIP.md`](docs/SCHEMA_OWNERSHIP.md) — ماتریس مالکیت schema.
- [`docs/CODING_CONVENTIONS.md`](docs/CODING_CONVENTIONS.md) — قراردادهای کدنویسی.
- [`docs/integration-contract.md`](docs/integration-contract.md) — قرارداد یکپارچه‌سازی نسخه‌دار.
- [`docs/DEBT_REVIEW.md`](docs/DEBT_REVIEW.md) — بازبینی debt فنی در پایان هر فاز.

## اجرای محلی

```bash
php -S localhost:8000 -t public public/router.php
```

## مجوز

MIT — تداوم مجوز gateway (Copyright ۲۰۲۶).
