<?php
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array $statusLog */
/** @var string $siteName */
/** @var string $ownerName */

use App\Shared\Support\PersianDate;

$siteName ??= 'سامانهٔ مجوز';
$ownerName ??= '';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>نسخهٔ چاپی <?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        /* Phase 20 (v1.9.2): ported + polished from v2 layout_print.php.
           Kept inline (no external CSS) so the page is a single
           self-contained printable HTML document. */
        :root {
            --brand: #0f766e;
            --brand-dark: #0c5d56;
            --brand-light: #e6f3f2;
            --ink: #1a1a1a;
            --muted: #64748b;
            --card-border: #cbd5e1;
            --cream: #faf6ee;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', 'Vazir', 'Tahoma', sans-serif;
            color: var(--ink);
            background: var(--cream);
            margin: 0;
            padding: 0;
            font-size: 14px;
            line-height: 1.7;
            /* Phase 53 (v2.1.9) — sticky footer: make body a 100vh
               column so the print-container grows to fill the
               viewport and the footer (margin-top:auto) sticks to
               the bottom. Without this, a short case (e.g. an
               applicant with no status history) leaves the footer
               floating somewhere in the middle of the page. */
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        /* A4 sheet */
        .print-container {
            max-width: 210mm;
            min-height: 297mm;
            margin: 1.5rem auto;
            background: #fff;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(22,40,60,.08);
            /* Phase 53 (v2.1.9) — flex column so the footer's
               margin-top:auto pushes it to the bottom of the A4
               sheet (filling the 297mm min-height). flex:1 0 auto
               lets the container grow taller than 297mm if the
               content overflows (long status histories). */
            flex: 1 0 auto;
            display: flex;
            flex-direction: column;
        }
        /* Header — gradient bar with tracking-code box, like v2 */
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
            background: rgba(255,255,255,.12);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .print-header h1 { font-size: 1.1rem; margin: 0; font-weight: 700; }
        .print-header .subtitle { font-size: .78rem; opacity: .85; margin: 2px 0 0; }
        .print-header .tracking-box {
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 8px;
            padding: 6px 14px;
            text-align: center;
        }
        .print-header .tracking-box .label { font-size: .65rem; opacity: .7; }
        .print-header .tracking-box .code {
            font-family: monospace; font-weight: 700;
            letter-spacing: .05em; font-size: .9rem;
        }
        /* Body */
        .print-body { padding: 22px 28px; }
        /* Section titles */
        h2 {
            color: var(--brand-dark);
            font-size: .9rem; font-weight: 700;
            border-bottom: 2px solid var(--brand-light);
            padding-bottom: 5px;
            margin: 22px 0 10px;
            display: flex; align-items: center; gap: 8px;
        }
        h2:first-child { margin-top: 0; }
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
        /* Info table */
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .info-table td {
            border: 1px solid var(--card-border);
            padding: 6px 10px;
            font-size: .85rem;
            vertical-align: top;
        }
        .info-table td:first-child {
            width: 35%;
            font-weight: 600;
            color: var(--brand-dark);
            background: var(--brand-light);
        }
        .tracking-code { font-family: monospace; font-weight: 700; color: var(--brand-dark); letter-spacing: .05em; }
        /* Status log table */
        .status-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
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
        /* Footer */
        .print-footer {
            /* Phase 53 (v2.1.9) — margin-top:auto inside the
               print-container's flex column pushes the footer to
               the bottom of the A4 sheet (which has min-height
               297mm). On short content (e.g. a draft with no
               status history), this prevents the footer from
               floating awkwardly in the middle of the page. */
            margin-top: auto;
            padding: 10px 28px;
            background: #fbfaf7;
            border-top: 1px solid var(--card-border);
            text-align: center;
            color: var(--muted);
            font-size: .72rem;
        }
        /* Print toolbar (no-print) */
        .print-toolbar {
            position: fixed;
            bottom: 1.5rem;
            left: 50%;
            transform: translateX(-50%);
            display: flex; gap: .5rem;
            z-index: 9999;
            background: #fff;
            border: 1px solid var(--card-border);
            border-radius: 50px;
            padding: .35rem .5rem;
            box-shadow: 0 4px 20px rgba(22,40,60,.12);
        }
        .print-toolbar button {
            border-radius: 50px;
            padding: .35rem 1rem;
            font-size: .82rem;
            cursor: pointer;
            border: 1px solid var(--card-border);
            background: #fff;
        }
        .print-toolbar button.btn-primary {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand-dark);
        }
        @media print {
            body { margin: 0; max-width: none; background: #fff; font-size: 12px; }
            .print-container { box-shadow: none; margin: 0; border: none; border-radius: 0; max-width: none; min-height: auto; }
            .print-toolbar { display: none !important; }
            .print-header { border-radius: 0; }
            .print-body { padding: 14px 0; }
            .info-table td, .status-table tbody td { padding: 4px 7px; }
            /* Phase 57a — let the footer flow naturally at the end of
               the printed content. The previous position:fixed;bottom:0
               rule repeated the footer on EVERY printed page (CSS
               position:fixed inside @media print is the standard
               pattern for repeating headers/footers per page, but
               here we only want ONE footer at the end of the document,
               not one per page). The .print-footer's natural-flow
               margin-top:auto on the flex column container already
               pushes it to the bottom of the last page when the
               content is shorter than one A4 sheet. When the content
               spans multiple pages, the footer simply sits at the
               end of the content as a normal block. */
            .print-footer {
                position: static;
                margin-top: auto;
            }
            @page { size: A4 portrait; margin: 14mm 12mm; }
        }
        @media (max-width: 575.98px) {
            .print-container { margin: .5rem; border-radius: 6px; }
            .print-header { padding: 14px 16px; flex-direction: column; align-items: flex-start; }
            .print-body { padding: 14px 16px; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar no-print">
        <button class="btn-primary" onclick="window.print()">🖨 چاپ</button>
        <button onclick="history.back()">↩ بازگشت</button>
    </div>

    <div class="print-container">
        <div class="print-header">
            <div class="brand">
                <span class="mark">✓</span>
                <div>
                    <h1><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></h1>
                    <div class="subtitle">نسخهٔ چاپی پروندهٔ متقاضی</div>
                </div>
            </div>
            <div class="tracking-box">
                <div class="label">کد رهگیری</div>
                <div class="code"><?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <div class="print-body">
            <h2><span class="num">۱</span> اطلاعات متقاضی</h2>
            <table class="info-table">
                <tr><td>نام و نام خانوادگی</td><td><?= htmlspecialchars($applicant->fullName(), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><td>کد ملی</td><td><?= htmlspecialchars($applicant->nationalCode, ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><td>شماره موبایل</td><td><?= htmlspecialchars($applicant->mobile, ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><td>وضعیت فعلی</td><td><?= htmlspecialchars($applicant->currentStatus->titleFa(), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><td>وضعیت مجوز</td><td><?= htmlspecialchars($applicant->licenseState->titleFa(), ENT_QUOTES, 'UTF-8') ?></td></tr>
            </table>

            <h2><span class="num">۲</span> تاریخچهٔ وضعیت</h2>
            <table class="status-table">
                <thead>
                    <tr><th>#</th><th>وضعیت</th><th>تاریخ</th></tr>
                </thead>
                <tbody>
                <?php foreach ($statusLog as $i => $entry): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($entry->statusName, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(PersianDate::format($entry->createdAt), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="print-footer">
            این برگه توسط <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> تولید شده است.
            <?php if ($ownerName !== ''): ?>
                · مدیریت: <?= htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
            · تاریخ چاپ: <?= htmlspecialchars(PersianDate::format(date('Y-m-d H:i')), ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>

    <script>window.print();</script>
</body>
</html>
