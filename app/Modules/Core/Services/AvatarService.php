<?php
declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Persistence\Connection;
use App\Shared\Support\StructuredLogger;
use InvalidArgumentException;

/**
 * AvatarService — user-profile image upload + storage.
 *
 * Per docs/00-MASTER.md §11.3 (user panel) — resolves debt D-9-1 (the last
 * medium-priority debt after Phase 11).
 *
 * Responsibilities:
 *   1. Accept a `$_FILES['avatar']`-style array from the multipart POST to
 *      `/panel/avatar`.
 *   2. Validate: upload error code, MIME whitelist (JPEG/PNG/WEBP), size ≤ 2 MB,
 *      real-image confirmation via `getimagesize()`.
 *   3. Read the source image with the GD extension (no Imagick, no external
 *      image libs — CODING_CONVENTIONS §11.5).
 *   4. Resize to a 256×256 square (crop-center, downscale larger, upscale
 *      smaller) via `imagecreatetruecolor` + `imagecopyresampled`.
 *   5. Always normalise to JPEG (quality 85) and save to
 *      `storage/uploads/avatars/account-{id}.jpg`. The directory is created
 *      if missing (mode 0755).
 *   6. Insert a `documents` row (the schema for `documents` requires a non-null
 *      `applicant_id` — so we look up the user's first existing applicant;
 *      if the user has not submitted any application yet, the upload is
 *      refused with a Persian error message). The `doc_type` is the string
 *      `'avatar'` (the `document_types` table is seeded with a matching row
 *      by migration 20261117_007 for forward-compat / future FK tightening,
 *      but the current `documents` schema uses a free-form `doc_type`
 *      VARCHAR, not a FK to `document_types.id`).
 *   7. Set `user_accounts.avatar_document_id` to the new document id.
 *   8. Best-effort delete of the previous avatar file + documents row.
 *
 * Path-traversal defense (CODING_CONVENTIONS §6.5):
 *   - The on-disk filename is ALWAYS server-constructed as
 *     `account-{id}.jpg` — the user-supplied name is NEVER used for the
 *     storage path.
 *   - The `original_name` column (varchar 255) is filled with the
 *     `basename()` of the user's filename, with non-ASCII characters and
 *     dots stripped — so even a crafted name like `../../../etc/passwd`
 *     becomes a safe `etcpasswd`.
 *
 * GD dependency: PHP's GD extension is required (already in the CI docker
 * image). `function_exists('imagecreatetruecolor')` is checked AFTER the
 * validation phase so that validation-path tests can run even on
 * environments without GD (see `tests/Unit/AvatarServiceTest.php`).
 *
 * Schema (see `database/schema.sql`):
 *   documents(id, applicant_id NOT NULL, doc_type, file_name, original_name,
 *             file_path, uploaded_at)
 *   user_accounts(id, ..., avatar_document_id NULLABLE FK→documents.id
 *                  ON DELETE SET NULL)
 *
 * The constructor receives `Connection` + `StructuredLogger` (both already
 * in the Container — autowire works; an explicit binding is also added in
 * `Core/Module.php::register()`).
 */
final class AvatarService
{
    /** Target avatar square dimension (pixels). */
    private const AVATAR_SIZE = 256;

    /** JPEG quality for the normalised avatar (0-100). */
    private const JPEG_QUALITY = 85;

    /** Max upload size: 2 MiB. */
    private const MAX_SIZE_BYTES = 2 * 1024 * 1024;

    /** Allowed source MIME types. */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /** Doc-type string written to `documents.doc_type` for avatar rows. */
    private const DOC_TYPE = 'avatar';

    public function __construct(
        private readonly Connection $conn,
        private readonly StructuredLogger $logger
    ) {}

    /**
     * Validate, resize, store, and register an avatar upload.
     *
     * @param int   $accountId  The logged-in user's `user_accounts.id`.
     * @param array $file       A `$_FILES['avatar']`-style array with keys
     *                          `name`, `tmp_name`, `size`, `error`, `type`.
     *
     * @return array{success:bool, document_id:int, file_path:string}
     *
     * @throws InvalidArgumentException On any validation or processing
     *         failure. The exception message is Persian (user-facing).
     */
    public function upload(int $accountId, array $file): array
    {
        // ----- validation phase -----

        // 1. Upload error code.
        $errCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->persianUploadError($errCode));
        }

        // 2. Declared MIME whitelist.
        $declaredMime = (string)($file['type'] ?? '');
        if (!in_array($declaredMime, self::ALLOWED_MIME, true)) {
            throw new InvalidArgumentException(
                'تنها فرمت‌های JPEG، PNG و WEBP پذیرفته می‌شوند.'
            );
        }

        // 3. Size cap.
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new InvalidArgumentException('حجم فایل نامعتبر است.');
        }
        if ($size > self::MAX_SIZE_BYTES) {
            throw new InvalidArgumentException(
                'حجم فایل نباید بیش از ۲ مگابایت باشد.'
            );
        }

        // 4. Real-image confirmation via getimagesize(). Also re-validates
        // the MIME against the file content (defeats `shell.php` renamed to
        // `x.jpg`).
        $tmpPath = (string)($file['tmp_name'] ?? '');
        if (!is_file($tmpPath)) {
            throw new InvalidArgumentException('فایل موقت بارگذاری یافت نشد.');
        }
        $info = @getimagesize($tmpPath);
        if ($info === false) {
            throw new InvalidArgumentException('فایل یک تصویر معتبر نیست.');
        }
        $realMime = (string)($info['mime'] ?? '');
        if (!in_array($realMime, self::ALLOWED_MIME, true)) {
            throw new InvalidArgumentException(
                'محتوای فایل با نوع اعلام‌شده هم‌خوانی ندارد.'
            );
        }

        // 4b. WEBP requires that GD be built with WEBP support. Not all
        // builds have it (Debian's php-gd by default does, but minimal
        // Alpine builds may not). Refuse early with a clear message.
        if ($realMime === 'image/webp' && !function_exists('imagecreatefromwebp')) {
            throw new InvalidArgumentException(
                'پشتیبانی از WEBP روی این سرور فعال نیست. لطفاً از JPEG یا PNG استفاده کنید.'
            );
        }

        // ----- GD sanity check -----
        // Placed AFTER validation so that validation-path tests can run
        // even on environments without GD (which is the case for the
        // AvatarServiceTest — see tests/Unit/AvatarServiceTest.php).
        if (!function_exists('imagecreatetruecolor')) {
            throw new InvalidArgumentException('پسوند GD روی سرور فعال نیست.');
        }

        // ----- path-traversal defense -----

        // The on-disk filename is ALWAYS server-constructed. The user's
        // supplied `name` is sanitised (basename + ASCII strip + dot strip)
        // and stored only in the `original_name` DB column.
        $rawName = (string)($file['name'] ?? '');
        $originalName = $this->sanitizeOriginalName($rawName);

        // ----- storage path -----
        $relPath  = 'storage/uploads/avatars/account-' . $accountId . '.jpg';
        $absPath  = dirname(__DIR__, 4) . '/' . $relPath;
        $storDir  = dirname($absPath);
        if (!is_dir($storDir)) {
            @mkdir($storDir, 0755, true);
        }
        if (!is_dir($storDir) || !is_writable($storDir)) {
            $this->logger->error('Avatar storage dir not writable', [
                '_module' => 'core', 'dir' => $storDir,
            ]);
            throw new InvalidArgumentException(
                'پوشهٔ ذخیرهٔ تصویر پروفایل قابل نوشتن نیست.'
            );
        }

        // ----- load + resize -----
        $image = $this->loadImage($realMime, $tmpPath);
        if ($image === false) {
            throw new InvalidArgumentException('خواندن تصویر ناموفق بود.');
        }

        $resized = $this->resizeSquare($image, self::AVATAR_SIZE);
        imagedestroy($image);
        if ($resized === false) {
            throw new InvalidArgumentException('تغییر اندازهٔ تصویر ناموفق بود.');
        }

        // ----- save as JPEG -----
        // PNG/WEBP transparency is lost when converting to JPEG — we fill
        // the canvas with white before resampling (in resizeSquare()).
        if (!imagejpeg($resized, $absPath, self::JPEG_QUALITY)) {
            imagedestroy($resized);
            throw new InvalidArgumentException('ذخیرهٔ تصویر ناموفق بود.');
        }
        imagedestroy($resized);
        @chmod($absPath, 0640);

        // ----- DB write -----
        // `documents.applicant_id` is NOT NULL with FK→applicants. The
        // current schema does not allow a "user-level" document without an
        // applicant carrier, so we use the user's first existing
        // applicant. If the user has not submitted any application yet,
        // the upload is refused (we also delete the just-saved file so
        // we don't leak orphans on disk).
        $applicantId = $this->findApplicantIdForAccount($accountId);
        if ($applicantId === null) {
            @unlink($absPath);
            throw new InvalidArgumentException(
                'برای بارگذاری تصویر پروفایل ابتدا یک درخواست ثبت کنید.'
            );
        }

        // Look up the previous avatar document id (so we can delete the
        // old file + row after the new one is wired up).
        $oldRow = $this->conn->one(
            'SELECT avatar_document_id FROM user_accounts WHERE id = ? LIMIT 1',
            [$accountId]
        );
        $oldDocId = ($oldRow === null || $oldRow['avatar_document_id'] === null)
            ? 0
            : (int)$oldRow['avatar_document_id'];

        // Insert the new documents row. `file_name` is the on-disk
        // server-constructed filename; `original_name` is the sanitised
        // user-supplied name.
        $fileName = 'account-' . $accountId . '.jpg';
        $newDocId = (int)$this->conn->insert(
            'INSERT INTO documents (applicant_id, doc_type, file_name, original_name, file_path) '
            . 'VALUES (?, ?, ?, ?, ?)',
            [$applicantId, self::DOC_TYPE, $fileName, $originalName, $relPath]
        );

        // Point user_accounts at the new document. We do this BEFORE
        // deleting the old document so the FK ON DELETE SET NULL clause
        // never fires (the user_accounts row no longer references the
        // old document id by the time we delete it).
        $this->conn->execute(
            'UPDATE user_accounts SET avatar_document_id = ? WHERE id = ?',
            [$newDocId, $accountId]
        );

        // Best-effort cleanup of the previous avatar (file + row).
        if ($oldDocId > 0) {
            $this->deleteOldAvatar($oldDocId, $absPath);
        }

        $this->logger->info('Avatar uploaded', [
            '_module'     => 'core',
            'account_id'  => $accountId,
            'document_id' => $newDocId,
            'applicant_id' => $applicantId,
        ]);

        return [
            'success'     => true,
            'document_id' => $newDocId,
            'file_path'   => $relPath,
        ];
    }

    /**
     * Resolve a `documents.id` to an absolute filesystem path, or null
     * if the document row doesn't exist.
     *
     * The returned path is NOT validated for existence on disk — callers
     * should `is_file()` before serving. Path-traversal defense: the
     * `file_path` column is read from the DB (server-controlled, set by
     * `upload()` above), never from user input.
     */
    public function getPath(int $documentId): ?string
    {
        if ($documentId <= 0) {
            return null;
        }
        $row = $this->conn->one(
            'SELECT file_path FROM documents WHERE id = ? LIMIT 1',
            [$documentId]
        );
        if ($row === null) {
            return null;
        }
        $relPath = (string)($row['file_path'] ?? '');
        if ($relPath === '') {
            return null;
        }
        return dirname(__DIR__, 4) . '/' . $relPath;
    }

    // ---------------- private helpers ----------------

    /**
     * Map a `$_FILES['error']` code to a Persian user-facing message.
     */
    private function persianUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'حجم فایل بیش از حد مجاز سرور است.',
            UPLOAD_ERR_FORM_SIZE => 'حجم فایل بیش از حد مجاز فرم است.',
            UPLOAD_ERR_PARTIAL    => 'بارگذاری فایل ناقص بود.',
            UPLOAD_ERR_NO_FILE    => 'هیچ فایلی بارگذاری نشده است.',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشهٔ موقت سرور یافت نشد.',
            UPLOAD_ERR_CANT_WRITE => 'سرور قادر به نوشتن فایل نیست.',
            UPLOAD_ERR_EXTENSION  => 'پسوند PHP بارگذاری فایل را متوقف کرد.',
            default               => 'خطای ناشناخته در بارگذاری فایل (کد: ' . $code . ').',
        };
    }

    /**
     * Sanitise the user-supplied original filename for storage in the
     * `documents.original_name` column. Path-traversal defense:
     *   1. basename() — strip any directory component.
     *   2. Strip non-ASCII bytes ( Persian / control chars / UTF-8 multibyte).
     *   3. Strip dots (so `foo.bar.jpg` becomes `foobarjpg` — slightly
     *      aggressive but defeats any `.htaccess`-style hidden extension).
     *   4. Truncate to 250 chars (column is varchar(255)).
     *
     * Returns a safe ASCII string (possibly empty — caller decides).
     */
    private function sanitizeOriginalName(string $raw): string
    {
        // 1. Basename (strip directory component).
        $name = basename($raw);
        // 2. Strip non-ASCII (keep 0x20-0x7E printable ASCII).
        $name = preg_replace('/[^\x20-\x7E]/', '', $name) ?? '';
        // 3. Strip dots (defeats double-extension tricks).
        $name = str_replace('.', '', $name);
        // 4. Trim whitespace + truncate.
        $name = trim($name);
        if (strlen($name) > 250) {
            $name = substr($name, 0, 250);
        }
        return $name;
    }

    /**
     * Load the source image into a GD resource, return false on failure.
     *
     * @return \GdImage|false
     */
    private function loadImage(string $mime, string $tmpPath): \GdImage|false
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png'  => @imagecreatefrompng($tmpPath),
            'image/webp' => @imagecreatefromwebp($tmpPath),
            default      => false,
        };
    }

    /**
     * Resize the source image to a $size×$size square using crop-center.
     * PNG/WEBP transparency is flattened to white (since the destination
     * is always JPEG — no alpha channel).
     *
     * @param \GdImage $src
     * @return \GdImage|false
     */
    private function resizeSquare(\GdImage $src, int $size): \GdImage|false
    {
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0) {
            return false;
        }

        // Crop-center: take the largest square that fits inside the source.
        $srcSize = min($w, $h);
        $srcX = (int)(($w - $srcSize) / 2);
        $srcY = (int)(($h - $srcSize) / 2);

        $dst = imagecreatetruecolor($size, $size);
        if ($dst === false) {
            return false;
        }
        // Flatten transparency to white (JPEG has no alpha).
        $white = imagecolorallocate($dst, 255, 255, 255);
        if ($white !== false) {
            imagefill($dst, 0, 0, $white);
        }
        imagecopyresampled(
            $dst, $src,
            0, 0,           // dst x, y
            $srcX, $srcY,   // src x, y (crop origin)
            $size, $size,   // dst w, h
            $srcSize, $srcSize  // src w, h (the cropped square)
        );
        return $dst;
    }

    /**
     * Find the user's first applicant (by user_account_id). Returns null
     * if the user has not submitted any application yet.
     */
    private function findApplicantIdForAccount(int $accountId): ?int
    {
        $row = $this->conn->one(
            'SELECT id FROM applicants WHERE user_account_id = ? AND deleted_at IS NULL
             ORDER BY id ASC LIMIT 1',
            [$accountId]
        );
        return $row === null ? null : (int)$row['id'];
    }

    /**
     * Best-effort cleanup of a previous avatar document: delete the file
     * from disk (if it's not the same path as the new file) and delete
     * the documents row. All errors are logged + swallowed — this is
     * best-effort.
     */
    private function deleteOldAvatar(int $oldDocId, string $newAbsPath): void
    {
        try {
            $row = $this->conn->one(
                'SELECT file_path FROM documents WHERE id = ? LIMIT 1',
                [$oldDocId]
            );
            if ($row !== null) {
                $oldPath = dirname(__DIR__, 4) . '/' . (string)$row['file_path'];
                if ($oldPath !== $newAbsPath && is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $this->conn->execute('DELETE FROM documents WHERE id = ?', [$oldDocId]);
        } catch (\Throwable $e) {
            $this->logger->warning('Old avatar cleanup failed (best-effort)', [
                '_module'       => 'core',
                'old_doc_id'    => $oldDocId,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
