<?php
/**
 * document/modal.php — AJAX modal content for POST /document/view.
 *
 * Phase 19 (v1.9.1): rendered by DocumentController::view() with
 * layout='none'. The returned HTML is injected into a Bootstrap modal
 * by the calling admin view (e.g. admin/view_application.php).
 *
 * Shows:
 *   - The document's original name (basename).
 *   - The doc_type (or the file extension as a fallback label).
 *   - The file size (human-readable: KB / MB).
 *   - For images: an inline preview via /document/inline/{id}.
 *   - For PDFs: a note + an "open in new tab" link to /document/pdf-viewer?id={id}.
 *   - A "download" link to /document/raw/{id} (Content-Disposition: attachment).
 *
 * Expected $data:
 *   $document    \App\Modules\Core\Domain\Document — the document.
 *   $fileSize    int|null  — bytes (null if the file is missing on disk).
 *   $fileExists  bool      — whether the underlying file exists on disk.
 *   $baseUrl     string.
 *
 * @var \App\Modules\Core\Domain\Document $document
 * @var int|null $fileSize
 * @var bool $fileExists
 * @var string $baseUrl
 * @var string $csrfField
 */

$document   ??= null;
$fileSize   ??= null;
$fileExists ??= false;
$baseUrl    ??= '/';
$csrfField  ??= '';

if ($document === null) {
    echo '<div class="alert alert-warning mb-0">سند یافت نشد.</div>';
    return;
}

$e = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Human-readable file size.
$sizeLabel = '—';
if ($fileSize !== null) {
    $bytes = (int)$fileSize;
    if ($bytes < 1024) {
        $sizeLabel = $bytes . ' B';
    } elseif ($bytes < 1024 * 1024) {
        $sizeLabel = number_format($bytes / 1024, 1) . ' KB';
    } else {
        $sizeLabel = number_format($bytes / (1024 * 1024), 2) . ' MB';
    }
}

$docId = (int)$document->id;
$inlineUrl = $baseUrl . 'document/inline/' . $docId;
$rawUrl = $baseUrl . 'document/raw/' . $docId;
$pdfViewerUrl = $baseUrl . 'document/pdf-viewer?id=' . $docId;

$isImage = $document->isImage();
$isPdf = $document->isPdf();
?>
<div class="text-center">
    <p class="text-muted small mb-2"><?= $e($document->originalName) ?></p>

    <?php if (!$fileExists): ?>
        <div class="alert alert-warning mb-3">
            <i class="bi bi-exclamation-triangle"></i>
            فایل روی سرور موجود نیست.
        </div>
    <?php elseif ($isImage): ?>
        <img src="<?= $e($inlineUrl) ?>"
             alt="<?= $e($document->originalName) ?>"
             class="img-fluid rounded mb-3"
             style="max-height: 65vh;"
             loading="lazy">
    <?php elseif ($isPdf): ?>
        <div class="alert alert-info mb-3">
            <i class="bi bi-filetype-pdf"></i>
            این یک فایل PDF است.
            <a href="<?= $e($pdfViewerUrl) ?>" target="_blank" rel="noopener" class="alert-link">
                برای مشاهده در تب جدید کلیک کنید.
            </a>
        </div>
    <?php else: ?>
        <div class="alert alert-secondary mb-3">
            <i class="bi bi-file-earmark"></i>
            پیش‌نمایش این نوع فایل پشتیبانی نمی‌شود.
        </div>
    <?php endif; ?>

    <div class="small text-muted mb-3">
        <span class="badge text-bg-secondary"><?= $e($document->docType) ?></span>
        حجم: <span><?= $e($sizeLabel) ?></span>
    </div>

    <div class="d-flex justify-content-center gap-2">
        <?php if ($fileExists): ?>
            <?php if ($isPdf): ?>
                <a href="<?= $e($pdfViewerUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-eye"></i> مشاهده
                </a>
            <?php else: ?>
                <a href="<?= $e($inlineUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-eye"></i> مشاهده
                </a>
            <?php endif; ?>
            <a href="<?= $e($rawUrl) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-download"></i> دانلود
            </a>
        <?php endif; ?>
    </div>
</div>
