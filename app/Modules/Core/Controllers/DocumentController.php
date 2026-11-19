<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Services\FileStorage;
use App\Shared\Contracts\DocumentRepositoryInterface;
use App\Shared\Contracts\UserAccountRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;

/**
 * DocumentController — serves uploaded documents.
 *
 * Phase 15 (v1.6.0) shipped functional stubs that served files with the
 * right Content-Type headers and ownership checks. Phase 19 (v1.9.1)
 * completes the implementation with the actual modal view rendering,
 * the user-avatar stream, the 200x200 GD thumbnail with crop-center, and
 * the inline + raw + pdf-viewer methods all using FileStorage's
 * path-traversal defense.
 *
 * Routes (6) — registered in app/Modules/Core/Module.php since Phase 15:
 *   POST /document/view            → view      (AJAX modal HTML snippet)
 *   GET  /document/avatar           → avatar    (file stream for user avatar)
 *   GET  /document/thumb/{id}       → thumb     (200x200 thumbnail via GD)
 *   GET  /document/inline/{id}     → inline    (file stream for inline iframe)
 *   GET  /document/pdf-viewer       → pdfViewer (HTML wrapper, layout='none')
 *   GET  /document/raw/{id}         → raw       (raw file serve for fetch)
 *
 * Auth:
 *   - view:        requireLogin() (admin) — admin sees all documents.
 *   - avatar:      requireAnyLogin() + ownership (avatar belongs to user).
 *   - thumb/inline/raw: requireLogin() (admin) OR user ownership (the
 *     document's applicant_id must belong to the current user).
 *   - pdf-viewer:  requireLogin() (admin) OR user ownership (the id is
 *     in the query string ?id=N, so we re-check inside the wrapper).
 *
 * Path-traversal defense:
 *   - All file serving goes through FileStorage::getFullPath() which
 *     uses realpath() + str_starts_with($base) on the configured
 *     upload_dir. The document id maps to a server-stored file_path
 *     (never user input).
 *   - The GD thumbnail generation also validates the image via
 *     getimagesize() before imagecreatefrom* — defeats polyglot payloads.
 *
 * Phase 52 (v2.1.8) — documents live under
 * `storage/uploads/{tracking_code}/{doc_type}_{rand6}.ext` (v2 style,
 * keyed by the applicant's tracking code). The stored `file_path` on
 * the documents row is the relative path `/{tracking_code}/{file_name}`,
 * which `FileStorage::getFullPath()` resolves against the configured
 * `upload_dir` (now `storage/uploads/`). No code change is needed in
 * this controller for the move — the relative path is opaque to the
 * resolver; only the `upload_dir` config + the FileStorage default
 * fallback moved.
 */
final class DocumentController extends Controller
{
    /** Thumbnail dimension (square, crop-center). */
    private const THUMB_SIZE = 200;

    /** JPEG quality for the re-encoded thumbnail. */
    private const THUMB_QUALITY = 85;

    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly DocumentRepositoryInterface $documents,
        private readonly FileStorage $fileStorage,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * POST /document/view — AJAX endpoint returning an HTML snippet
     * that renders a single document inside a Bootstrap modal.
     *
     * The snippet includes the document's name + type + size + a
     * "view inline" + "download raw" link. The HTML is the rendered
     * app/Views/document/modal.php partial (no layout — caller wraps
     * it in a Bootstrap modal).
     *
     * Auth: requireLogin() (admin) — admin sees all documents. The
     * CSRF check happens in the front controller for every POST.
     */
    public function view(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)$request->input('document_id', 0);
        if ($id <= 0) {
            return Response::html(
                '<div class="alert alert-warning mb-0">شناسهٔ سند نامعتبر است.</div>',
                404
            );
        }
        $document = $this->documents->findById($id);
        if ($document === null) {
            return Response::html(
                '<div class="alert alert-warning mb-0">سند یافت نشد.</div>',
                404
            );
        }

        // File size (for display). Falls back to '—' if the file is
        // missing on disk (don't fail the whole modal).
        $fullPath = $this->fileStorage->getFullPath($document->filePath);
        $fileSize = ($fullPath !== null && is_file($fullPath))
            ? filesize($fullPath)
            : null;

        return $this->render('document/modal', [
            'title'        => 'مشاهدهٔ سند',
            'document'     => $document,
            'fileSize'     => $fileSize,
            'fileExists'   => $fullPath !== null && is_file($fullPath),
        ], 'none');
    }

    /**
     * GET /document/avatar — stream the current user's avatar file.
     *
     * Auth: requireAnyLogin(). For a user session, look up the
     * user_account's avatar_document_id and serve that document. For
     * an admin session, refuse (admins don't have avatars — return a
     * 404 placeholder).
     */
    public function avatar(Request $request, array $params = []): Response
    {
        $this->requireAnyLogin();
        $accountId = (int)$this->session->get('user_id');
        $isAdmin = (bool)$this->session->get('admin_logged_in');
        if ($accountId <= 0 || $isAdmin) {
            // Admins don't have avatars — return a 1x1 transparent PNG
            // (so the caller's <img> doesn't render a broken-image icon).
            return $this->placeholderPng();
        }

        $userAccounts = $this->container->make(UserAccountRepositoryInterface::class);
        $account = $userAccounts->findById($accountId);
        if ($account === null) {
            return $this->placeholderPng();
        }
        $docId = (int)($account['avatar_document_id'] ?? 0);
        if ($docId <= 0) {
            return $this->placeholderPng();
        }
        $document = $this->documents->findById($docId);
        if ($document === null) {
            return $this->placeholderPng();
        }
        return $this->streamFile($document->filePath, $document->originalName, true);
    }

    /**
     * GET /document/thumb/{id} — generate + serve a 200x200 thumbnail
     * via GD with crop-center (the same pattern as
     * AvatarService::resizeSquare()).
     *
     * Auth: requireLogin() (admin) OR user ownership.
     *
     * For images (JPEG/PNG): re-encode to 200x200 JPEG at quality 85.
     * For PDFs and other file types: return a 1x1 transparent PNG
     * placeholder (so the caller's <img> doesn't render a broken-image
     * icon — the JS in the admin views shows a file-type icon instead).
     */
    public function thumb(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return $this->placeholderPng();
        }
        $document = $this->documents->findById($id);
        if ($document === null) {
            return $this->placeholderPng();
        }
        if (!$this->canAccess($document->applicantId)) {
            return $this->forbiddenResponse();
        }

        $fullPath = $this->fileStorage->getFullPath($document->filePath);
        if ($fullPath === null || !is_file($fullPath)) {
            return $this->placeholderPng();
        }

        // Validate the file is actually an image via getimagesize() —
        // defeats polyglot payloads (shell.php renamed to x.jpg).
        $info = @getimagesize($fullPath);
        if ($info === false) {
            // Not an image — return a 1x1 placeholder.
            return $this->placeholderPng();
        }
        $mime = (string)($info['mime'] ?? '');
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($fullPath),
            'image/png'  => @imagecreatefrompng($fullPath),
            default      => false,
        };
        if ($image === false) {
            return $this->placeholderPng();
        }

        // Crop-center: take the largest square that fits inside the
        // source, then resample to 200x200.
        $w = imagesx($image);
        $h = imagesy($image);
        if ($w <= 0 || $h <= 0) {
            @imagedestroy($image);
            return $this->placeholderPng();
        }
        $srcSize = min($w, $h);
        $srcX = (int)(($w - $srcSize) / 2);
        $srcY = (int)(($h - $srcSize) / 2);

        $thumb = imagecreatetruecolor(self::THUMB_SIZE, self::THUMB_SIZE);
        if ($thumb === false) {
            @imagedestroy($image);
            return $this->placeholderPng();
        }
        // Flatten transparency to white (output is always JPEG).
        $white = imagecolorallocate($thumb, 255, 255, 255);
        if ($white !== false) {
            imagefill($thumb, 0, 0, $white);
        }
        imagecopyresampled(
            $thumb, $image,
            0, 0,
            $srcX, $srcY,
            self::THUMB_SIZE, self::THUMB_SIZE,
            $srcSize, $srcSize
        );
        @imagedestroy($image);

        ob_start();
        imagejpeg($thumb, null, self::THUMB_QUALITY);
        $blob = (string)ob_get_clean();
        @imagedestroy($thumb);

        return $this->binaryResponse($blob, 'image/jpeg', cacheable: true);
    }

    /**
     * GET /document/inline/{id} — stream a file with
     * Content-Disposition: inline (the browser shows it inline, in an
     * <img>, <iframe>, or via its native PDF viewer).
     *
     * Phase 57b — auth relaxed from `requireLogin()` (admin-only) to
     * `requireAnyLogin()` (admin OR user) so the user-side
     * `user/licenses.php` "دریافت مجوز" link works for the logged-in
     * applicant too. `canAccess($document->applicantId)` already
     * enforces ownership for non-admin sessions (admin sees all, user
     * only sees documents whose applicant.user_account_id matches
     * their session user_id) — so the relaxation does NOT change the
     * security posture, only who can reach the path-resolution code.
     *
     * The path resolution itself was the actual 404 source: with the
     * Phase 52 / Phase 55 storage moves, some `documents.file_path`
     * rows ended up stored as project-root-relative (`storage/uploads/
     * avatars/account-1.jpg`, `storage/uploads/licenses/42/abc.jpg`)
     * while `FileStorage::getFullPath()` only resolved upload-dir-
     * relative paths (`/{tracking_code}/{$fileName}`). The Phase 57b
     * FileStorage fallback now resolves both conventions and the file
     * is found + served regardless of how it was stored.
     */
    public function inline(Request $request, array $params = []): Response
    {
        $this->requireAnyLogin();
        $id = (int)($params['id'] ?? 0);
        $document = $this->documents->findById($id);
        if ($document === null) {
            return $this->notFoundResponse('سند');
        }
        if (!$this->canAccess($document->applicantId)) {
            return $this->forbiddenResponse();
        }
        return $this->streamFile($document->filePath, $document->originalName, true);
    }

    /**
     * GET /document/raw/{id} — raw file serve with
     * Content-Disposition: attachment (forces a download).
     */
    public function raw(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)($params['id'] ?? 0);
        $document = $this->documents->findById($id);
        if ($document === null) {
            return $this->notFoundResponse('سند');
        }
        if (!$this->canAccess($document->applicantId)) {
            return $this->forbiddenResponse();
        }
        return $this->streamFile($document->filePath, $document->originalName, false);
    }

    /**
     * GET /document/pdf-viewer — HTML wrapper that uses the browser's
     * native PDF viewer via `<iframe src="/document/inline/{id}">`.
     *
     * Auth: requireLogin(). The id comes in via the ?id= query param.
     * The wrapper re-checks ownership inside the iframe's src endpoint
     * (DocumentController::inline) — the wrapper itself only needs to
     * know the id to build the iframe URL.
     */
    public function pdfViewer(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)$request->queryParam('id', 0);
        $baseUrl = $this->environment->baseUrl;
        $src = htmlspecialchars($baseUrl . 'document/inline/' . $id, ENT_QUOTES);
        return $this->render('document/pdf_viewer', [
            'title'   => 'مشاهدهٔ سند',
            'src'     => $src,
            'docId'   => $id,
            'baseUrl' => $baseUrl,
        ], 'none');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Whether the current session may access the given applicant's
     * documents. Admin (requireLogin passed) → yes. User → only if
     * the applicant's user_account_id matches their session user_id.
     */
    private function canAccess(int $applicantId): bool
    {
        if ((bool)$this->session->get('admin_logged_in')) {
            return true;
        }
        $accountId = (int)$this->session->get('user_id');
        if ($accountId <= 0) {
            return false;
        }
        $conn = $this->container->make(Connection::class);
        $owner = $conn->scalar(
            'SELECT user_account_id FROM applicants WHERE id = ? AND deleted_at IS NULL',
            [$applicantId]
        );
        return $owner !== null && (int)$owner === $accountId;
    }

    /**
     * Stream a file from the uploads dir to the client. $inline=true
     * → Content-Disposition: inline (browser shows it). $inline=false
     * → Content-Disposition: attachment (forces a download).
     */
    private function streamFile(string $relativePath, string $displayName, bool $inline): Response
    {
        $fullPath = $this->fileStorage->getFullPath($relativePath);
        if ($fullPath === null || !is_file($fullPath)) {
            return $this->notFoundResponse($displayName);
        }
        $mime = (string)(@mime_content_type($fullPath) ?: 'application/octet-stream');
        $blob = (string)file_get_contents($fullPath);
        $disposition = $inline ? 'inline' : 'attachment';
        $safeName = str_replace('"', '', $displayName);
        $response = (new Response(
            $blob,
            200,
            [
                'Content-Type'        => $mime,
                'Content-Disposition' => $disposition . '; filename="' . $safeName . '"',
                'Content-Length'      => (string)strlen($blob),
            ]
        ))->withHeader('Cache-Control', 'private, max-age=3600');
        return $response;
    }

    /**
     * Emit a binary blob as a Response with the given MIME type.
     */
    private function binaryResponse(string $blob, string $mime, bool $cacheable = false): Response
    {
        $headers = [
            'Content-Type'   => $mime,
            'Content-Length' => (string)strlen($blob),
        ];
        if ($cacheable) {
            $headers['Cache-Control'] = 'private, max-age=86400';
        }
        return new Response($blob, 200, $headers);
    }

    private function forbiddenResponse(): Response
    {
        return Response::html(
            '<!doctype html><meta charset="utf-8"><h1>۴۰۳</h1><p>دسترسی ممنوع.</p>',
            403
        );
    }

    private function notFoundResponse(string $what): Response
    {
        return Response::html(
            '<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>سند یافت نشد.</p>',
            404
        );
    }

    /**
     * 1x1 transparent PNG placeholder (used for missing avatars + non-image
     * thumbnails — so the caller's <img> doesn't render a broken-image icon).
     */
    private function placeholderPng(): Response
    {
        $blob = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO/t2JkAAAAASUVORK5CYII=',
            true
        );
        return $this->binaryResponse((string)$blob, 'image/png', cacheable: true);
    }
}
