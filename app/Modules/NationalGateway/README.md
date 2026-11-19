# NationalGateway Module

> **Owner:** `app/Modules/NationalGateway/` · **Version:** 1.0.0

## مسئولیت

NationalGateway وارث `mojavez-gateway` (scraper درگاه ملی `qr.mojavez.ir`) است. این ماژول
مسئول خواندن فقط‌خواندنی و فیلترشده از درگاه ملی، کش محلی، جستجوی چندوجهی، استخراج
صفحهٔ track با RSC parser، خروجی CSV/PDF، و جستجوی دسته‌ای است.

همچنین در فاز ۴، اتصال طلایی (golden-link) را از طریق `ApplicantSyncAdapter` (ACL) به
ماژول Core متصل می‌کند: scraper هر از گاهی درگاه ملی را می‌خواند، مجوز صادرشده را با
تطبیق الگویی به متقاضی محلی نگاشت می‌کند، وضعیت پرونده را خودکار تغییر می‌دهد و به
متقاضی پیام می‌دهد.

## مالکیت جدول‌ها (per `docs/SCHEMA_OWNERSHIP.md`)

NationalGateway مالک 5 جدول است:

- `national_licenses` — کش محلی مجوزهای upstream با فیلد golden-link anchor
  (`masked_national_code`).
- `national_lookups` — استان‌ها، شهرستان‌ها، سازمان‌های upstream (24h TTL).
- `sync_audit_log` — رد ممیزی اتصال طلایی (auto_confirmed / operator_flag /
  auto_rejected).
- `national_filters` — فیلترهای ذخیره‌شدهٔ admin.
- `national_batches` — jobs جستجوی دسته‌ای CSV.

## ساختار

```
app/Modules/NationalGateway/
├── Controllers/                 # (Phase 4) SyncAdminController, BatchController
├── Domain/
│   ├── Enums/
│   │   ├── LicenseStatus.php    # active|expired|unknown
│   │   ├── MatchConfidence.php  # AutoConfirmed(90)|OperatorFlag(50)|AutoRejected(0)
│   │   └── ConflictResolution.php  # ACL decision tree
│   ├── Filter.php               # immutable upstream filter VO
│   ├── License.php              # upstream license entity
│   └── LicenseCollection.php    # paginated result
├── Infrastructure/
│   ├── AntiDetectionMiddleware.php  # fixed browser headers (Chrome 120 Windows)
│   ├── CircuitBreaker.php          # NEW in v3 — CLOSED/OPEN/HALF_OPEN
│   ├── HttpClient.php               # cURL + cookie jar
│   ├── HttpResponse.php             # immutable response DTO
│   ├── MojavecClient.php            # upstream API client + retry
│   ├── TrackScraper.php             # Next.js RSC payload parser
│   └── Parser/
│       ├── JsonSearchParser.php     # {data:{...}} envelope parser
│       └── ResponseParserInterface.php
├── Services/
│   ├── CacheService.php             # file-based cache (30min search, 24h lookups)
│   ├── RateLimiterService.php       # min delay + burst + cooldown + jitter
│   ├── FilterService.php            # saved-filter CRUD (Phase 4 wires persistence)
│   ├── CsvBatchService.php          # CSV batch import (Phase 4 wires persistence)
│   └── ExportService.php            # CSV/PDF export (Phase 4 wires persistence)
├── migrations/
│   └── 20261117_001_national_gateway_tables.sql
└── Module.php                       # implements ModuleInterface
```

## API عمومی

سایر ماژول‌ها به NationalGateway از طریق `MojavecClient`، `TrackScraper`، و `CircuitBreaker`
دسترسی دارند (که در `Module::register()` به Container بسته می‌شوند). Phase 4 این‌ها را
با `NationalSyncOrchestrator` + `PatternMatcher` + `ApplicantSyncAdapter` به ماژول Core
متصل می‌کند.

## وابستگی‌ها

- `app/Shared/Support/*` برای PersianDate، StructuredLogger.
- `app/Shared/Persistence/Connection` برای repository‌های Phase 4.
- به ماژول Core (از طریق ACL در فاز ۴) وابسته است.
- در boot order بعد از Core و Queue می‌آید.

## مرجع پیاده‌سازی

کد این ماژول مستقیماً از مخزن [`andrbi108/mojavez-gateway`](https://github.com/andrbi108/mojavez-gateway)
پورت شده است. namespaceها از `App\*` به `App\Modules\NationalGateway\*` تغییر کرده‌اند
و use statements برای `Logger` و `PersianDate` به `App\Shared\Support\*` به‌روز شده‌اند.
