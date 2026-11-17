<?php
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array $statusLog */
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>نسخهٔ چاپی <?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: 'Tahoma', 'Vazirmatn', sans-serif; color: #1a1a1a; max-width: 800px; margin: 2rem auto; padding: 0 1rem; line-height: 1.7; }
        .print-header { text-align: center; border-bottom: 3px solid #0f766e; padding-bottom: 1rem; margin-bottom: 1.5rem; }
        .print-header h1 { color: #0f766e; margin-bottom: 0.25rem; }
        .print-header .subtitle { color: #64748b; font-size: 0.9rem; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }
        .info-table th, .info-table td { padding: 0.6rem 0.75rem; border: 1px solid #cbd5e1; text-align: right; }
        .info-table th { background: #f1f5f9; width: 35%; font-weight: 600; }
        .tracking-code { font-family: monospace; font-weight: 700; color: #0f766e; letter-spacing: 0.05em; }
        h2 { color: #0f766e; font-size: 1.1rem; border-bottom: 1px solid #cbd5e1; padding-bottom: 0.5rem; margin-top: 1.5rem; }
        .status-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .status-table th, .status-table td { padding: 0.4rem 0.6rem; border-bottom: 1px solid #e2e8f0; text-align: right; }
        .status-table th { background: #f8fafc; }
        .print-footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #cbd5e1; text-align: center; color: #64748b; font-size: 0.85rem; }
        @media print { body { margin: 0; max-width: none; } .no-print { display: none; } }
        .no-print { text-align: center; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="padding: 0.5rem 1.5rem; cursor: pointer;">🖨 چاپ</button>
    </div>

    <div class="print-header">
        <h1>سامانهٔ مجوز</h1>
        <div class="subtitle">نسخهٔ چاپی پروندهٔ متقاضی</div>
    </div>

    <table class="info-table">
        <tr><th>کد رهگیری</th><td><span class="tracking-code"><?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></span></td></tr>
        <tr><th>نام و نام خانوادگی</th><td><?= htmlspecialchars($applicant->fullName(), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><th>کد ملی</th><td dir="ltr"><?= htmlspecialchars($applicant->nationalCode, ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><th>شماره موبایل</th><td dir="ltr"><?= htmlspecialchars($applicant->mobile, ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><th>وضعیت فعلی</th><td><?= htmlspecialchars($applicant->currentStatus->titleFa(), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><th>وضعیت مجوز</th><td><?= htmlspecialchars($applicant->licenseState->titleFa(), ENT_QUOTES, 'UTF-8') ?></td></tr>
    </table>

    <h2>تاریخچهٔ وضعیت</h2>
    <table class="status-table">
        <thead>
            <tr><th>#</th><th>وضعیت</th><th>تاریخ</th></tr>
        </thead>
        <tbody>
        <?php foreach ($statusLog as $i => $entry): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($entry->statusName, ENT_QUOTES, 'UTF-8') ?></td>
                <td dir="ltr"><?= htmlspecialchars($entry->createdAt, ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="print-footer">
        این برگه توسط سامانهٔ مجوز تولید شده است. تاریخ چاپ: <?= htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8') ?>
    </div>

    <script>window.print();</script>
</body>
</html>
