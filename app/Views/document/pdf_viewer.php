<?php
/**
 * document/pdf_viewer.php — minimal HTML wrapper for inline PDF viewing.
 *
 * Phase 15 (v1.6.0): rendered by DocumentController::pdfViewer(). Since
 * the CSP forbids external CDNs (no PDF.js from a CDN), we use the
 * browser's native PDF viewer via `<iframe src="/document/inline/{id}">`.
 *
 * Rendered with layout='none' because it's a self-contained HTML doc.
 *
 * Expected $data:
 *   $src    string — full URL to the inline file (already escaped).
 *   $docId  int    — the document id (for reference / download link).
 *   $baseUrl string.
 */
/** @var string $src */
/** @var string $baseUrl */
/** @var int $docId */

$src    ??= '';
$baseUrl ??= '';
$docId  ??= 0;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>مشاهدهٔ سند</title>
    <style>
        html, body {
            margin: 0; padding: 0; height: 100%;
            background: rgb(248, 249, 250);
        }
        .moj-pdf-toolbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 8px 16px; background: rgb(255, 255, 255);
            border-bottom: 1px solid rgb(222, 226, 230);
            font-family: Tahoma, sans-serif;
        }
        .moj-pdf-toolbar a {
            color: rgb(15, 118, 110); text-decoration: none; font-size: 14px;
        }
        .moj-pdf-frame {
            width: 100%; height: calc(100vh - 50px); border: 0; display: block;
        }
    </style>
</head>
<body>
    <div class="moj-pdf-toolbar">
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>">« بازگشت</a>
        <strong>مشاهدهٔ سند</strong>
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>document/raw/<?= (int)$docId ?>">دانلود</a>
    </div>
    <iframe class="moj-pdf-frame" src="<?= $src ?>" title="سند"></iframe>
</body>
</html>
