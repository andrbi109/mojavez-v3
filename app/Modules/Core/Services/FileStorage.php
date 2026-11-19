<?php
declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Support\Config;
use App\Shared\Support\StructuredLogger;
use RuntimeException;

/**
 * FileStorage — 7-layer secure file upload storage.
 *
 * Inherited from v2's FileStorage, faithfully ported with the same 7-layer
 * security pipeline (see docs/00-MASTER.md §12.4):
 *
 *   1. UPLOAD_ERR_OK check.
 *   2. Filename normalisation (strip BOM, control chars, \\, basename).
 *   3. Extension extraction (supports Persian names, multi-dot).
 *   4. MIME-based extension fallback (if ext invalid, detect from finfo).
 *   5. Size check (≤ MAX_UPLOAD_SIZE = 2 MiB).
 *   6. Real MIME detection via finfo on tmp_name.
 *   7. ext↔MIME consistency check (defeats `shell.php` → `x.jpg` rename).
 *
 * Storage layout (Phase 52 / v2.1.8): storage/uploads/{tracking_code}/{doc_type}_{rand6}.ext
 *  - Outside public/ web root.
 *  - chmod 0640.
 *  - Images re-encoded with GD to max 1920px (defeats polyglot payloads).
 *
 * The directory is keyed by the applicant's tracking code (v2 style), NOT
 * the applicant row id. Rationale: the tracking code is the user-visible
 * handle for the application; grouping uploads under it keeps the
 * storage layout human-browsable (`ls storage/uploads/HG-AB12CD34/`) and
 * survives applicant-row id changes (e.g. a migrate-from-v2 import that
 * preserves tracking codes but allocates new row ids).
 *
 * The `$applicantId` parameter on storeUpload() is accepted for
 * forward-compat (future audit tables may reference it) but is NOT used
 * for the storage path — only `$trackingCode` is.
 *
 * Path traversal protection (docs/00-MASTER.md §12.5):
 *   - All access goes through getFullPath() which uses realpath() + str_starts_with().
 */
final class FileStorage
{
    public const MAX_IMAGE_DIMENSION = 1920;
    public const JPEG_QUALITY = 85;
    public const PNG_COMPRESSION = 9;

    public function __construct(
        private readonly StructuredLogger $logger
    ) {}

    /**
     * Store an uploaded file. Returns [success, error, document_id, file_path]
     * (document_id is filled by the caller via DocumentRepository).
     *
     * @param array<string,mixed> $file  $_FILES['field'] entry
     * @return array{success:bool, error:?string, document_id:?int, file_path:?string, file_name:?string, original_name:?string}
     */
    public function storeUpload(
        array $file,
        int $applicantId,
        string $trackingCode,
        string $docType
    ): array {
        // Layer 1: UPLOAD_ERR_OK
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->fail($file['error'] === UPLOAD_ERR_NO_FILE
                ? 'هیچ فایلی بارگذاری نشده است.'
                : 'خطا در بارگذاری فایل (کد: ' . $file['error'] . ').');
        }

        // Layer 2: filename normalisation
        $originalName = $this->normaliseFilename((string)($file['name'] ?? ''));
        if ($originalName === '') {
            return $this->fail('نام فایل نامعتبر است.');
        }

        // Layer 3: extension extraction
        $ext = $this->safeFileExtension($originalName);

        // Layer 4: MIME-based fallback
        if (!in_array($ext, Config::get('app.allowed_extensions', ['jpg', 'jpeg', 'png', 'pdf']), true)) {
            $detected = $this->detectExtensionFromMime((string)$file['tmp_name']);
            if ($detected !== null) {
                $ext = $detected;
            } else {
                return $this->fail('پسوند فایل مجاز نیست.');
            }
        }

        // Layer 5: size check
        $maxSize = (int)Config::get('app.max_upload_size', 2 * 1024 * 1024);
        if (($file['size'] ?? 0) > $maxSize) {
            return $this->fail('حجم فایل بیش از حد مجاز است.');
        }

        // Layer 6: real MIME detection
        $realMime = $this->realMime((string)$file['tmp_name']);
        if ($realMime === null) {
            return $this->fail('تشخیص نوع فایل ناموفق بود.');
        }

        // Layer 7: ext↔MIME consistency
        $expectedMime = $this->mimeForExtension($ext);
        if ($expectedMime === null || $realMime !== $expectedMime) {
            return $this->fail('عدم تطابق پسوند و نوع واقعی فایل.');
        }

        // Build the storage path: storage/uploads/{tracking_code}/{doc_type}_{rand6}.ext
        // Phase 52 (v2.1.8) — the directory is keyed by the applicant's
        // tracking code (v2 style), NOT by the applicant row id. The
        // $applicantId parameter is accepted for forward-compat but is
        // intentionally unused here.
        $rand = bin2hex(random_bytes(3));  // 6 hex chars
        $fileName = "{$docType}_{$rand}.{$ext}";
        $relativePath = "/{$trackingCode}/{$fileName}";
        $absolutePath = $this->getUploadDir() . $relativePath;

        // Create the tracking-code folder if needed.
        $folder = dirname($absolutePath);
        if (!is_dir($folder)) {
            @mkdir($folder, 0775, true);
        }
        if (!is_dir($folder) || !is_writable($folder)) {
            $this->logger->error('Upload folder not writable', ['_module' => 'core', 'folder' => $folder]);
            return $this->fail('پوشهٔ ذخیره‌سازی قابل نوشتن نیست.');
        }

        // Move the uploaded file.
        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            return $this->fail('انتقال فایل بارگذاری‌شده ناموفق بود.');
        }

        // Re-encode images via GD (defeats polyglot payloads).
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $this->compressImage($absolutePath, $absolutePath, $ext);
        }

        chmod($absolutePath, 0640);

        return [
            'success'       => true,
            'error'         => null,
            'document_id'   => null,  // caller fills via DocumentRepository::create
            'file_path'     => $relativePath,
            'file_name'     => $fileName,
            'original_name' => $originalName,
        ];
    }

    /**
     * Delete a single uploaded file by relative path.
     */
    public function deleteFile(string $relativePath): bool
    {
        $full = $this->getFullPath($relativePath);
        if ($full === null) {
            return false;
        }
        if (is_file($full)) {
            return @unlink($full);
        }
        return false;
    }

    /**
     * Delete an entire applicant's upload folder.
     */
    public function deleteApplicantFolder(string $trackingCode): bool
    {
        $folder = $this->getUploadDir() . '/' . $trackingCode;
        if (!is_dir($folder)) {
            return false;
        }
        $files = glob($folder . '/*') ?: [];
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        return @rmdir($folder);
    }

    /**
     * Resolve a relative path to a full path, with path-traversal protection.
     * Returns null if the path escapes UPLOAD_DIR.
     *
     * Phase 57b — now accepts BOTH storage-path conventions used in the
     * codebase:
     *   1. upload-dir-relative (e.g., `/{tracking_code}/{$fileName}`) —
     *      produced by FileStorage::storeUpload (Phase 52 layout).
     *   2. project-root-relative (e.g.,
     *      `storage/uploads/avatars/account-1.jpg` or
     *      `storage/uploads/licenses/{applicant_id}/{rand}.jpg`) —
     *      produced by AvatarService::upload and
     *      LicenseController::storeUploadUnder.
     *
     * Both interpretations are tried and the result is constrained to the
     * upload-dir subtree (path-traversal defense: `..` is stripped from
     * the input and the realpath() result must start with the realpath
     * of the upload_dir). The fallback (try 2) is what makes the avatar
     * (`/document/avatar`) and the admin document modal (`/document/
     * inline/{id}`) work again after the LicenseController + AvatarService
     * started storing project-root-relative paths.
     */
    public function getFullPath(string $relativePath): ?string
    {
        $uploadDir = $this->getUploadDir();
        // Strip .. and NUL from the path (path-traversal defense).
        $cleanPath = str_replace(['..', "\0"], '', $relativePath);
        $base = realpath($uploadDir);
        if ($base === false) {
            return null;
        }

        // Try 1 — upload-dir-relative (e.g., "/{tracking_code}/{$fileName}").
        // This is what FileStorage::storeUpload writes.
        $filePath = realpath($uploadDir . $cleanPath);
        if ($filePath !== false && str_starts_with($filePath, $base)) {
            return $filePath;
        }

        // Try 2 — project-root-relative (e.g.,
        // "storage/uploads/avatars/account-1.jpg"). This is what
        // AvatarService::upload and LicenseController::storeUploadUnder
        // write. We resolve against the project root, then re-check the
        // resolved path stays within the upload-dir subtree.
        $projectRoot = dirname(__DIR__, 4);
        $filePath2 = realpath($projectRoot . '/' . $cleanPath);
        if ($filePath2 !== false && str_starts_with($filePath2, $base)) {
            return $filePath2;
        }

        return null;
    }

    /**
     * Get the uploads directory (from config).
     *
     * Phase 52 (v2.1.8) — defaults to `storage/uploads/` to match the
     * AvatarService layout (`storage/uploads/avatars/`). Configured via
     * `app.upload_dir` in `config/app.php`.
     */
    public function getUploadDir(): string
    {
        return (string)Config::get('app.upload_dir', dirname(__DIR__, 4) . '/storage/uploads');
    }

    // ---------------- private helpers ----------------

    private function normaliseFilename(string $name): string
    {
        // Strip BOM.
        if (strlen($name) >= 3 && substr($name, 0, 3) === "\xEF\xBB\xBF") {
            $name = substr($name, 3);
        }
        // Strip control characters and backslashes.
        $name = preg_replace('/[\x00-\x1F\x7F\\\\]/', '', $name) ?? $name;
        // Basename only (strip directory traversal).
        $name = basename($name);
        return trim($name);
    }

    private function safeFileExtension(string $filename): string
    {
        // Find the LAST dot (multi-dot names like `foo.bar.jpg`).
        $dot = strrpos($filename, '.');
        if ($dot === false) {
            return '';
        }
        return strtolower(substr($filename, $dot + 1));
    }

    private function detectExtensionFromMime(string $tmpPath): ?string
    {
        $mime = $this->realMime($tmpPath);
        if ($mime === null) {
            return null;
        }
        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'application/pdf' => 'pdf',
        ];
        return $map[$mime] ?? null;
    }

    private function realMime(string $tmpPath): ?string
    {
        if (!is_file($tmpPath)) {
            return null;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $result = $finfo->file($tmpPath);
        return is_string($result) ? $result : null;
    }

    private function mimeForExtension(string $ext): ?string
    {
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'pdf'  => 'application/pdf',
        ];
        return $map[$ext] ?? null;
    }

    /**
     * Re-encode an image file via GD to defeat polyglot payloads and enforce
     * the MAX_IMAGE_DIMENSION cap.
     */
    private function compressImage(string $srcPath, string $destPath, string $ext): bool
    {
        $ext = strtolower($ext);
        $image = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($srcPath),
            'png'         => @imagecreatefrompng($srcPath),
            default       => false,
        };
        if ($image === false) {
            return false;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width > self::MAX_IMAGE_DIMENSION || $height > self::MAX_IMAGE_DIMENSION) {
            $ratio = min(self::MAX_IMAGE_DIMENSION / $width, self::MAX_IMAGE_DIMENSION / $height);
            $newWidth = (int)($width * $ratio);
            $newHeight = (int)($height * $ratio);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            @imagedestroy($image);
            $image = $resized;
        }
        $result = match ($ext) {
            'jpg', 'jpeg' => imagejpeg($image, $destPath, self::JPEG_QUALITY),
            'png'         => imagepng($image, $destPath, self::PNG_COMPRESSION),
            default       => false,
        };
        @imagedestroy($image);
        return $result;
    }

    /**
     * @return array{success:bool, error:?string, document_id:?int, file_path:?string, file_name:?string, original_name:?string}
     */
    private function fail(string $error): array
    {
        return [
            'success'       => false,
            'error'         => $error,
            'document_id'   => null,
            'file_path'     => null,
            'file_name'     => null,
            'original_name' => null,
        ];
    }
}
