<?php
/**
 * admin/print_single.php — dedicated A4 admin print view for a single applicant.
 *
 * Phase 38 (v2.0.4): replaces the previous reuse of the public print.php for
 * the /admin/output/single/{trackingCode} route. Unlike print.php (which only
 * shows the public-facing subset — name, national code, mobile, status,
 * license state + a 3-column timeline), this admin-only view shows EVERY
 * applicant field, including the admin-only ones the public page omits:
 *
 *   - income_decile (admin-only demographic)
 *   - golden-link match info (national_license_request_number, match_confidence,
 *     matched_at — the public page never reveals the upstream national anchor)
 *   - documents list (file type + original name + upload date — admin-only
 *     because the public tracking page has no document browser)
 *   - full status timeline WITH triggered_by (admin|user|system|auto_sync —
 *     the public page only shows the status name and date, never who/what
 *     caused the transition)
 *   - license section with license_valid_until date + validity badge
 *
 * The view is a COMPLETE HTML document (its own <html>/<head>/<body>) so it
 * must be rendered with layout='none'. It carries its own inline CSS so it
 * stays self-contained for the browser's "Save as PDF" print dialog.
 *
 * Expected $data (passed by ReportController::singleApplicantReport):
 *   $applicant         Applicant              — the domain object
 *   $row               array<string,mixed>     — raw applicants row
 *                                                  (father_name, birth_date,
 *                                                   applicant_type,
 *                                                   marital_status,
 *                                                   children_count,
 *                                                   physical_condition,
 *                                                   disability_details,
 *                                                   income_decile,
 *                                                   has_court_case,
 *                                                   has_active_loan,
 *                                                   has_overdue_installments)
 *   $jobDetail         ?JobDetail              — job_details domain object
 *                                                  (license_title,
 *                                                   experience_months,
 *                                                   training_method)
 *   $propertyDetail    ?PropertyDetail         — property_details domain object
 *   $documents         array<int,Document>    — Document domain objects
 *   $statusLog         array<int,StatusLogEntry> — StatusLogEntry domain objects
 *   $serviceTypeTitle  string                  — service_types.title
 *   $siteName          string                  — from settings.site_name
 *   $persianDate       PersianDate            — Jalali date helper
 *   $baseUrl           string                 — app base URL
 *   $csrfField         string                 — CSRF token field (unused here,
 *                                                  but injected by render())
 */

/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array<string,mixed> $row */
/** @var ?\App\Modules\Core\Domain\JobDetail $jobDetail */
/** @var ?\App\Modules\Core\Domain\PropertyDetail $propertyDetail */
/** @var array<int,\App\Modules\Core\Domain\Document> $documents */
/** @var array<int,\App\Modules\Core\Domain\StatusLogEntry> $statusLog */
/** @var string $serviceTypeTitle */
/** @var string $siteName */
/** @var string $ownerName */
/** @var \App\Shared\Support\PersianDate $persianDate */
/** @var string $baseUrl */
/** @var string $csrfField */

use App\Modules\Core\Domain\Document;
use App\Modules\Core\Domain\Enums\LicenseState;
use App\Modules\Core\Domain\StatusLogEntry;
use App\Shared\Support\PersianDate;

// Defensive defaults — render() injects $csrfField/$baseUrl, but every other
// variable is optional so the view never white-screens if a section's data
// is missing (e.g. a legacy v2 import without job_details).
$row              ??= [];
$jobDetail        ??= null;
$propertyDetail   ??= null;
$documents        ??= [];
$statusLog        ??= [];
$serviceTypeTitle ??= '';
$siteName         ??= 'سامانهٔ مجوز';
$ownerName        ??= '';
$persianDate      ??= null;

// htmlspecialchars short helper — same idiom as v2 print.php.
$e = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Persian-digit conversion for inline numbers (NOT for the tracking code,
// which is a monospace Latin string by convention).
$faNum = static fn ($s): string => str_replace(
    ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
    ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
    (string)$s
);

// Gregorian → Jalali formatter (date only). Returns '—' for null/empty/zero.
//
// Phase 45 (v2.1.1) — falls back to the static PersianDate::format()
// helper when no $persianDate instance is injected.
$fmtDate = static function (?string $greg) use ($persianDate, $e): string {
    if ($greg === null || $greg === '' || $greg === '0000-00-00' || $greg === '0000-00-00 00:00:00') {
        return '—';
    }
    if ($persianDate instanceof PersianDate) {
        return $e($persianDate->formatGregorianDateTime($greg));
    }
    return $e(PersianDate::format((string)$greg));
};

// Gregorian → Jalali formatter (date + time). Used in the timeline.
$fmtDateTime = static function (?string $greg) use ($persianDate, $e): string {
    if ($greg === null || $greg === '' || $greg === '0000-00-00 00:00:00') {
        return '—';
    }
    if ($persianDate instanceof PersianDate) {
        return $e($persianDate->formatGregorianDateTimeFull($greg));
    }
    return $e(PersianDate::format((string)$greg));
};

// Document type label map (mirror of v2 print.php's $docLabels + the
// admin view_application.php labels). Falls back to the raw doc_type key
// for any type not in the canonical list.
$docLabels = [
    'national_card'        => 'کارت ملی',
    'birth_cert'           => 'شناسنامه',
    'ownership_doc'        => 'مدرک مالکیت',
    'lease_agreement'      => 'اجاره‌نامه',
    'vocational_cert'      => 'گواهی فنی‌وحرفه‌ای',
    'education_degree'     => 'مدرک تحصیلی',
    'product_photos'       => 'تصویر محصولات',
    'optional'             => 'سایر',
    'payment_receipt'      => 'رسید پرداخت',
    'avatar'               => 'آواتار',
    'revision_doc'         => 'بازبینی',
];

// Triggered-by label map (Persian) for the status timeline column.
$triggeredByLabels = [
    'admin'     => 'مدیر',
    'user'      => 'متقاضی',
    'system'    => 'سیستم',
    'auto_sync' => 'اتصال طلایی',
];

// Section counter (by-reference so each section auto-increments).
$sectionNum = 0;

$licenseState    = $applicant->licenseState;
$licenseColorCss = match ($licenseState) {
    LicenseState::Issued  => '#16a34a',
    LicenseState::Current => '#0f766e',
    LicenseState::Revoked => '#b91c1c',
    LicenseState::Expired => '#d97706',
};

// Today's Jalali date for the print-date stamp in the header.
//
// Phase 45 (v2.1.1) — when no $persianDate instance is injected, fall
// back to PersianDate::format() (which works statically) instead of
// returning a Gregorian date string.
if ($persianDate instanceof PersianDate) {
    $todayJalali = $persianDate->todayJalali();
} else {
    // PersianDate::format() returns "1405/08/26 HH:MM" — strip the time.
    $formatted = PersianDate::format(date('Y-m-d H:i'));
    $todayJalali = trim(strtok($formatted, ' ')) ?: date('Y-m-d');
}
$printStamp  = $faNum($todayJalali) . ' — ' . $faNum(date('H:i'));
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <title>پروندهٔ چاپی متقاضی — <?= $e($applicant->trackingCode) ?></title>
    <?php if (!empty($baseUrl)): ?>
        <!-- Local Vazirmatn @font-face (self-hosted, CSP-safe). Falls back to Tahoma
             if the woff2 files are unreachable (e.g. offline Save-as-PDF). -->
        <link rel="stylesheet" href="<?= $e($baseUrl) ?>assets/vendor/vazirmatn/css/vazirmatn.css">
    <?php endif; ?>
    <style>
        :root {
            --brand:        #0f766e;
            --brand-dark:   #0c5d56;
            --brand-light:  #e6f3f2;
            --ink:          #1a1a1a;
            --muted:        #64748b;
            --card-border:  #cbd5e1;
            --cream:        #faf6ee;
            --gold:         #b9862c;
            --gold-light:   #fbf3e0;
        }
        * { box-sizing: border-box; }
        html, body {
            font-family: 'Vazirmatn', 'Vazir', 'Tahoma', sans-serif;
            color: var(--ink);
            background: var(--cream);
            margin: 0;
            padding: 0;
            font-size: 14px;
            line-height: 1.7;
        }
        /* === A4 sheet === */
        .print-container {
            max-width: 210mm;
            min-height: 297mm;
            margin: 1.5rem auto;
            background: #fff;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(22, 40, 60, .08);
        }
        /* === Header === */
        .print-header {
            background: linear-gradient(135deg, var(--brand-dark) 0%, var(--brand) 100%);
            color: #fff;
            padding: 18px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .print-header .brand { display: flex; align-items: center; gap: .6rem; }
        .print-header .brand .mark {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .12);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .print-header h1 { font-size: 1.1rem; margin: 0; font-weight: 700; }
        .print-header .subtitle { font-size: .78rem; opacity: .85; margin: 2px 0 0; }
        .print-header .header-boxes {
            display: flex;
            gap: .5rem;
            align-items: stretch;
        }
        .print-header .tracking-box {
            background: rgba(255, 255, 255, .1);
            border: 1px solid rgba(255, 255, 255, .25);
            border-radius: 8px;
            padding: 6px 14px;
            text-align: center;
            min-width: 140px;
        }
        .print-header .tracking-box .label { font-size: .65rem; opacity: .7; }
        .print-header .tracking-box .code {
            font-family: monospace;
            font-weight: 700;
            letter-spacing: .05em;
            font-size: .9rem;
            direction: ltr;
        }
        /* === Body === */
        .print-body { padding: 22px 28px; }
        /* === Section titles === */
        h2 {
            color: var(--brand-dark);
            font-size: .9rem;
            font-weight: 700;
            border-bottom: 2px solid var(--brand-light);
            padding-bottom: 5px;
            margin: 22px 0 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        h2:first-of-type { margin-top: 0; }
        h2 .num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px; height: 22px;
            background: var(--brand);
            color: #fff;
            border-radius: 5px;
            font-size: .72rem;
            font-weight: 700;
        }
        /* === Info table === */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .info-table td {
            border: 1px solid var(--card-border);
            padding: 6px 10px;
            font-size: .85rem;
            vertical-align: top;
        }
        .info-table td:first-child {
            width: 32%;
            font-weight: 600;
            color: var(--brand-dark);
            background: var(--brand-light);
        }
        .info-table td.ltr { direction: ltr; text-align: right; }
        /* === Status log table === */
        .status-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem;
        }
        .status-table thead th {
            background: var(--brand-light);
            color: var(--brand-dark);
            font-weight: 700;
            border: 1px solid var(--card-border);
            padding: 5px 8px;
            text-align: right;
        }
        .status-table tbody td {
            border: 1px solid var(--card-border);
            padding: 4px 8px;
            vertical-align: top;
        }
        .status-table tbody tr:nth-child(even) { background: #fbfaf7; }
        .status-table .badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: .72rem;
            font-weight: 600;
        }
        .badge-by-admin     { background: #dbeafe; color: #1e40af; }
        .badge-by-user       { background: #e0e7ff; color: #3730a3; }
        .badge-by-system     { background: #f1f5f9; color: #475569; }
        .badge-by-auto_sync  { background: #d1fae5; color: #065f46; }
        /* === Documents list === */
        .doc-list { list-style: none; padding: 0; margin: 0; }
        .doc-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 10px;
            border: 1px solid var(--card-border);
            border-radius: 6px;
            margin-bottom: 4px;
            font-size: .82rem;
            background: #fbfaf7;
        }
        .doc-list li .doc-type {
            font-size: .68rem;
            font-weight: 600;
            min-width: 110px;
            text-align: center;
            padding: 2px 8px;
            border-radius: 4px;
            background: var(--brand-light);
            color: var(--brand-dark);
        }
        .doc-list li .doc-name { direction: ltr; flex: 1; word-break: break-all; }
        .doc-list li .doc-date { color: var(--muted); font-size: .72rem; direction: ltr; }
        /* === Document thumbnails grid (Phase 51, v2.1.7; Phase 57a) ===
           Four-up row of thumbnail images (or filename-only boxes for
           PDFs/other types). Phase 57a changes from 5-up to 4-up so
           each thumb + its title fits cleanly on a single A4 row. The
           `.doc-thumb-img` class replaces the inline style on <img>
           so all thumbnail images share a single CSS rule. */
        .doc-thumbs {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
            margin-top: 6px;
        }
        .doc-thumb {
            border: 1px solid var(--card-border);
            border-radius: 6px;
            padding: 4px;
            background: #fbfaf7;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .doc-thumb img,
        .doc-thumb-img {
            display: block;
            width: 130px;
            height: 150px;
            object-fit: cover;
            margin: 0.5rem auto;
            border-radius: 3px;
        }
        .doc-thumb-fallback {
            width: 130px;
            height: 150px;
            margin: 0.5rem auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border: 1px dashed #b8b8b8;
            border-radius: 4px;
            color: var(--muted);
            background: #f3f3f3;
            text-align: center;
            direction: ltr;
            font-size: .62rem;
            word-break: break-all;
            padding: 6px;
            box-sizing: border-box;
        }
        .doc-thumb-fallback .ico {
            font-size: 1.4rem;
            color: #dc3545;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 4px;
        }
        .doc-thumb-fallback .ico-pdf { color: #dc3545; }
        .doc-thumb-fallback .ico-other { color: var(--muted); }
        .doc-thumb-meta {
            margin-top: 4px;
            display: flex;
            flex-direction: column;
            gap: 1px;
            font-size: .68rem;
            line-height: 1.25;
        }
        .doc-thumb-type {
            font-weight: 600;
            color: var(--brand-dark);
            text-align: center;
        }
        /* === Golden-link callout === */
        .golden-callout {
            background: var(--gold-light);
            border: 1px solid var(--gold);
            border-radius: 8px;
            padding: 10px 14px;
            margin: 10px 0;
        }
        .golden-callout .conf-high { color: #065f46; font-weight: 700; }
        .golden-callout .conf-mid  { color: #b45309; font-weight: 700; }
        .golden-callout .conf-low  { color: #64748b; font-weight: 700; }
        /* === License state strip === */
        .license-strip {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: 10px 14px;
            border: 1px solid <?= $e($licenseColorCss) ?>;
            border-radius: 8px;
            background: color-mix(in srgb, <?= $e($licenseColorCss) ?> 8%, #fff);
            margin: 10px 0;
        }
        .license-strip .pill {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            color: #fff;
            font-weight: 700;
            font-size: .82rem;
            background: <?= $e($licenseColorCss) ?>;
        }
        /* === Sign area === */
        .sign-area {
            margin-top: 32px;
            display: flex;
            justify-content: space-between;
            gap: 2rem;
            page-break-inside: avoid;
        }
        .sign-box {
            flex: 1;
            border-top: 1.5px dashed var(--card-border);
            padding-top: 6px;
            text-align: center;
            font-size: .78rem;
            color: var(--muted);
        }
        /* === Footer === */
        .print-footer {
            margin-top: 1.5rem;
            padding: 10px 28px;
            background: #fbfaf7;
            border-top: 1px solid var(--card-border);
            text-align: center;
            color: var(--muted);
            font-size: .72rem;
        }
        /* === Print toolbar (screen-only) === */
        .print-toolbar {
            position: fixed;
            bottom: 1.5rem;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: .5rem;
            z-index: 9999;
            background: #fff;
            border: 1px solid var(--card-border);
            border-radius: 50px;
            padding: .35rem .5rem;
            box-shadow: 0 4px 20px rgba(22, 40, 60, .12);
        }
        .print-toolbar button {
            border-radius: 50px;
            padding: .35rem 1rem;
            font-size: .82rem;
            cursor: pointer;
            border: 1px solid var(--card-border);
            background: #fff;
            font-family: inherit;
        }
        .print-toolbar button.btn-primary {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand-dark);
        }
        /* === Print CSS === */
        @media print {
            body { margin: 0; max-width: none; background: #fff; font-size: 12px; }
            .print-container {
                box-shadow: none;
                margin: 0;
                border: none;
                border-radius: 0;
                max-width: none;
                min-height: auto;
            }
            .print-toolbar { display: none !important; }
            .print-header { border-radius: 0; }
            .print-body { padding: 14px 0; }
            .info-table td, .status-table tbody td { padding: 4px 7px; }
            h2 { margin: 16px 0 8px; }
            .sign-area { page-break-inside: avoid; }
            /* Phase 57a — keep the document thumbnails section on a
               single page: never break inside a thumb card, never
               break inside the thumbs grid, and never break between
               the section heading and the grid. The grid is 4-up so
               on A4 portrait one row of 4 thumbs + their titles fits
               inside the 14mm/12mm page margins. */
            .doc-thumbs,
            .doc-thumb { page-break-inside: avoid; break-inside: avoid; }
            h2 + .doc-thumbs { page-break-before: avoid; break-before: avoid; }
            @page { size: A4 portrait; margin: 14mm 12mm; }
        }
        @media (max-width: 575.98px) {
            .print-container { margin: .5rem; border-radius: 6px; }
            .print-header {
                padding: 14px 16px;
                flex-direction: column;
                align-items: flex-start;
            }
            .print-body { padding: 14px 16px; }
            .sign-area { flex-direction: column; gap: 1rem; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar no-print">
        <button class="btn-primary" type="button" onclick="window.print()">چاپ / ذخیرهٔ PDF</button>
        <button type="button" onclick="history.back()">بازگشت</button>
    </div>

    <div class="print-container">
        <!-- ====== Header: site name + tracking code + print date ====== -->
        <div class="print-header">
            <div class="brand">
                <span class="mark">✓</span>
                <div>
                    <h1><?= $e($siteName) ?></h1>
                    <div class="subtitle">نسخهٔ چاپی پروندهٔ متقاضی — نمای مدیریت</div>
                </div>
            </div>
            <div class="header-boxes">
                <div class="tracking-box">
                    <div class="label">کد رهگیری</div>
                    <div class="code"><?= $e($applicant->trackingCode) ?></div>
                </div>
                <div class="tracking-box">
                    <div class="label">تاریخ چاپ</div>
                    <div class="code"><?= $e($printStamp) ?></div>
                </div>
            </div>
        </div>

        <div class="print-body">
            <!-- ====== 1. Identity section ====== -->
            <h2><span class="num"><?= $faNum(++$sectionNum) ?></span> اطلاعات هویتی</h2>
            <table class="info-table">
                <tr>
                    <td>نام و نام خانوادگی</td>
                    <td><?= $e($applicant->fullName()) ?></td>
                </tr>
                <tr>
                    <td>کد ملی</td>
                    <td class="ltr"><?= $e($applicant->nationalCode) ?></td>
                </tr>
                <tr>
                    <td>نام پدر</td>
                    <td><?= $e($row['father_name'] ?? '—') ?></td>
                </tr>
                <tr>
                    <td>تاریخ تولد</td>
                    <td class="ltr"><?= $e($fmtDate($row['birth_date'] ?? null)) ?></td>
                </tr>
                <tr>
                    <td>شماره موبایل</td>
                    <td class="ltr"><?= $e($applicant->mobile) ?></td>
                </tr>
                <tr>
                    <td>نوع متقاضی</td>
                    <td><?= $e($row['applicant_type'] ?? 'حقیقی') ?></td>
                </tr>
                <tr>
                    <td>وضعیت تأهل</td>
                    <td>
                        <?= $e($row['marital_status'] ?? '—') ?>
                        <?php if (($row['marital_status'] ?? '') === 'متأهل'): ?>
                            <small class="ms-2" style="color:var(--muted)">
                                (فرزندان: <?= $faNum((int)($row['children_count'] ?? 0)) ?>)
                            </small>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>وضعیت جسمانی</td>
                    <td>
                        <?= $e($row['physical_condition'] ?? '—') ?>
                        <?php if (($row['physical_condition'] ?? '') === 'معلولیت' && !empty($row['disability_details'])): ?>
                            <small class="ms-2" style="color:var(--muted)">
                                — <?= $e($row['disability_details']) ?>
                            </small>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>دهک درآمدی</td>
                    <td>
                        <?php if (isset($row['income_decile']) && $row['income_decile'] !== null && $row['income_decile'] !== ''): ?>
                            دهک <?= $faNum((int)$row['income_decile']) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>پرونده قضایی / وام / اقساط معوق</td>
                    <td>
                        <?= $e($row['has_court_case'] ?? '—') ?> /
                        <?= $e($row['has_active_loan'] ?? '—') ?> /
                        <?= $e($row['has_overdue_installments'] ?? '—') ?>
                    </td>
                </tr>
            </table>

            <!-- ====== 2. Service section ====== -->
            <h2><span class="num"><?= $faNum(++$sectionNum) ?></span> اطلاعات خدمت و شغل</h2>
            <table class="info-table">
                <tr>
                    <td>نوع خدمت</td>
                    <td><?= $e($serviceTypeTitle !== '' ? $serviceTypeTitle : '—') ?></td>
                </tr>
                <tr>
                    <td>عنوان مجوز</td>
                    <td><?= $e($jobDetail !== null ? $jobDetail->licenseTitle : '—') ?></td>
                </tr>
                <tr>
                    <td>سابقه فعالیت</td>
                    <td>
                        <?php if ($jobDetail !== null): ?>
                            <?= $e($jobDetail->experienceLabel()) ?>
                            <small style="color:var(--muted)">
                                (<?= $faNum($jobDetail->experienceMonths) ?> ماه)
                            </small>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>نحوه آموزش</td>
                    <td><?= $e($jobDetail !== null ? $jobDetail->trainingMethod : '—') ?></td>
                </tr>
            </table>

            <!-- ====== 3. Property section ====== -->
            <h2><span class="num"><?= $faNum(++$sectionNum) ?></span> اطلاعات ملک</h2>
            <?php if ($propertyDetail !== null): ?>
                <table class="info-table">
                    <tr>
                        <td>وضعیت مالکیت</td>
                        <td><?= $e($propertyDetail->ownershipStatus) ?></td>
                    </tr>
                    <tr>
                        <td>استان</td>
                        <td><?= $e($propertyDetail->province !== '' ? $propertyDetail->province : '—') ?></td>
                    </tr>
                    <tr>
                        <td>شهرستان</td>
                        <td><?= $e($propertyDetail->county !== '' ? $propertyDetail->county : '—') ?></td>
                    </tr>
                    <tr>
                        <td>شهر</td>
                        <td><?= $e($propertyDetail->city !== '' ? $propertyDetail->city : '—') ?></td>
                    </tr>
                    <tr>
                        <td>بخش</td>
                        <td><?= $e($propertyDetail->district ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td>دهستان</td>
                        <td><?= $e($propertyDetail->ruralDistrict ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td>روستا</td>
                        <td><?= $e($propertyDetail->village ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td>کد پستی</td>
                        <td class="ltr"><?= $e((string)($applicant->postalCode ?? '—')) ?></td>
                    </tr>
                    <tr>
                        <td>آدرس</td>
                        <td>
                            <?php
                                $addrParts = [];
                                if (!empty($propertyDetail->addressStreet)) $addrParts[] = 'خیابان ' . $propertyDetail->addressStreet;
                                if (!empty($propertyDetail->addressAlley))  $addrParts[] = 'کوچه ' . $propertyDetail->addressAlley;
                                if (!empty($propertyDetail->addressPlaque)) $addrParts[] = 'پلاک ' . $propertyDetail->addressPlaque;
                                echo $e($addrParts !== [] ? implode('، ', $addrParts) : '—');
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td>شناسه قبض برق</td>
                        <td class="ltr"><?= $e($propertyDetail->electricityBillId !== '' ? $propertyDetail->electricityBillId : '—') ?></td>
                    </tr>
                </table>
            <?php else: ?>
                <p style="font-size:.82rem;color:var(--muted)">اطلاعات ملک ثبت نشده است.</p>
            <?php endif; ?>

            <!-- ====== 4. Golden-link section (if matched) ====== -->
            <?php if ($applicant->isMatchedToNational()): ?>
                <?php
                    $conf = (int)($applicant->matchConfidence ?? 0);
                    $confClass = $conf >= 80 ? 'conf-high' : ($conf >= 50 ? 'conf-mid' : 'conf-low');
                ?>
                <h2><span class="num"><?= $faNum(++$sectionNum) ?></span> اتصال طلایی (تطبیق ملی)</h2>
                <div class="golden-callout">
                    <table class="info-table" style="margin:0;background:transparent;">
                        <tr>
                            <td style="background:transparent;border-color:var(--gold);">شماره درخواست ملی</td>
                            <td style="border-color:var(--gold);" class="ltr">
                                <?= $e((string)$applicant->nationalLicenseRequestNumber) ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="background:transparent;border-color:var(--gold);">اطمینان تطبیق</td>
                            <td style="border-color:var(--gold);">
                                <span class="<?= $e($confClass) ?>"><?= $faNum($conf) ?>٪</span>
                            </td>
                        </tr>
                        <tr>
                            <td style="background:transparent;border-color:var(--gold);">تاریخ تطبیق</td>
                            <td style="border-color:var(--gold);" class="ltr">
                                <?= $e($fmtDateTime($applicant->matchedAt)) ?>
                            </td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <!-- ====== 5. Documents section ====== -->
            <h2>
                <span class="num"><?= $faNum(++$sectionNum) ?></span>
                فهرست مدارک
                <small style="font-weight:400;color:var(--muted)">
                    (<?= $faNum(count($documents)) ?> فایل)
                </small>
            </h2>
            <?php if (!empty($documents)): ?>
                <!-- Phase 51 (v2.1.7); Phase 57a: thumbnails 4 per row
                     (.doc-thumb-img class replaces the inline style so
                     all thumb images share one CSS rule). PDFs and other
                     non-image files show only the filename inside a
                     same-sized placeholder box so the 4-up grid stays
                     aligned. Phase 58 (v2.2.4): removed the duplicate name
                     and date spans below each thumbnail — only the doc-type
                     label remains so the print output stays clean. -->
                <div class="doc-thumbs">
                    <?php foreach ($documents as $doc): ?>
                        <?php \assert($doc instanceof Document); ?>
                        <?php $label = $docLabels[$doc->docType] ?? $doc->docType; ?>
                        <?php $thumbUrl = rtrim((string)$baseUrl, '/') . '/document/thumb/' . (int)$doc->id; ?>
                        <div class="doc-thumb">
                            <?php if ($doc->isImage()): ?>
                                <img src="<?= $e($thumbUrl) ?>"
                                     class="doc-thumb-img"
                                     alt="<?= $e($label) ?>">
                            <?php elseif ($doc->isPdf()): ?>
                                <div class="doc-thumb-fallback">
                                    <span class="ico ico-pdf">PDF</span>
                                    <span style="word-break:break-all"><?= $e($doc->originalName) ?></span>
                                </div>
                            <?php else: ?>
                                <div class="doc-thumb-fallback">
                                    <span class="ico ico-other">FILE</span>
                                    <span style="word-break:break-all"><?= $e($doc->originalName) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="doc-thumb-meta">
                                <span class="doc-thumb-type"><?= $e($label) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="font-size:.82rem;color:var(--muted)">مدرکی ثبت نشده است.</p>
            <?php endif; ?>

            <!-- ====== 6. Status timeline (with triggered_by) ====== -->
            <h2>
                <span class="num"><?= $faNum(++$sectionNum) ?></span>
                تاریخچهٔ وضعیت
                <small style="font-weight:400;color:var(--muted)">
                    (<?= $faNum(count($statusLog)) ?> رویداد)
                </small>
            </h2>
            <table class="status-table">
                <thead>
                    <tr>
                        <th style="width:5%">#</th>
                        <th style="width:24%">رویداد</th>
                        <th style="width:14%">توسط</th>
                        <th style="width:20%">تاریخ</th>
                        <th>توضیحات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statusLog as $i => $entry): ?>
                        <?php \assert($entry instanceof StatusLogEntry); ?>
                        <?php
                            $by   = $entry->triggeredBy;
                            $byFa = $triggeredByLabels[$by] ?? $by;
                            $byClass = 'badge-by-' . (in_array($by, ['admin', 'user', 'system', 'auto_sync'], true) ? $by : 'system');
                        ?>
                        <tr>
                            <td><?= $faNum($i + 1) ?></td>
                            <td><?= $e($entry->statusName) ?></td>
                            <td>
                                <span class="badge <?= $e($byClass) ?>"><?= $e($byFa) ?></span>
                            </td>
                            <td class="ltr"><?= $e($fmtDateTime($entry->createdAt)) ?></td>
                            <td><?= $e($entry->description ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($statusLog)): ?>
                        <tr>
                            <td colspan="5" style="color:var(--muted);text-align:center">
                                تاریخچه‌ای ثبت نشده است.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- ====== 7. License section ====== -->
            <h2><span class="num"><?= $faNum(++$sectionNum) ?></span> وضعیت مجوز</h2>
            <div class="license-strip">
                <span class="pill"><?= $e($licenseState->titleFa()) ?></span>
                <?php if ($licenseState === LicenseState::Issued && $applicant->licenseValidUntil !== null): ?>
                    <span>
                        اعتبار تا:
                        <strong class="ltr" style="font-family:monospace"><?= $e($fmtDate($applicant->licenseValidUntil)) ?></strong>
                        <?php if ($applicant->hasValidLicense()): ?>
                            <span style="color:#065f46;font-weight:700">— معتبر</span>
                        <?php else: ?>
                            <span style="color:#b45309;font-weight:700">— منقضی‌شده</span>
                        <?php endif; ?>
                    </span>
                <?php elseif ($licenseState === LicenseState::Issued): ?>
                    <span>بدون تاریخ انقضا (دائمی)</span>
                <?php else: ?>
                    <span style="color:var(--muted)">
                        <?= $licenseState === LicenseState::Current ? 'در حال بررسی / در دست اقدام' : '' ?>
                        <?= $licenseState === LicenseState::Revoked  ? 'مجوز باطل شده است.' : '' ?>
                        <?= $licenseState === LicenseState::Expired ? 'مجوز منقضی شده است.' : '' ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- ====== Sign area ====== -->
            <div class="sign-area">
                <div class="sign-box">امضای متقاضی</div>
                <div class="sign-box">امضا و مهر متصدی سامانه</div>
                <div class="sign-box">امضای مسئول صادرکننده</div>
            </div>
        </div>

        <!-- ====== Footer ====== -->
        <div class="print-footer">
            این برگه به‌صورت خودکار توسط <?= $e($siteName) ?> تولید شده است —
            <?php if ($ownerName !== ''): ?>
                مدیریت: <?= $e($ownerName) ?> —
            <?php endif; ?>
            تاریخ چاپ: <?= $e($printStamp) ?> —
            کد رهگیری: <span><?= $e($applicant->trackingCode) ?></span>
        </div>
    </div>

    <script>
        // Auto-trigger the print dialog on load (best-effort — only if the
        // document is the top-level window, not inside an iframe preview).
        (function () {
            if (window.self === window.top) {
                window.addEventListener('load', function () {
                    // Slight delay so the Vazirmatn @font-face has a chance to
                    // resolve before the print snapshot is taken.
                    setTimeout(function () { window.print(); }, 250);
                });
            }
        })();
    </script>
</body>
</html>
