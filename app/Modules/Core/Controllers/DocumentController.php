<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Services\AvatarService;
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
 * Phase 15 (v1.6.0): functional stubs that serve files with the right
 * Content-Type headers and ownership checks. The actual document files
 * live in `storage/uploads/`. The FileStorage service handles
 * path-traversal protection via `getFullPath()`.
 *
 * Routes (6):
 *   POST /document/view            → view      (AJAX modal HTML snippet)
 *   GET  /document/avatar           → avatar    (file stream for user avatar)
 *   GET  /document/thumb/{id}       → thumb     (200x200 thumbnail via GD)
 *   GET  /document/inline/{id}      → inline    (file stream for inline iframe)
 *   GET  /document/pdf-viewer       → pdfViewer (HTML wrapper)
 *   GET  /document/raw/{id}        → raw       (raw file serve for fetch)
 *
 * Auth:
 *   - view:        requireLogin() (admin) + CSRF
 *   - avatar:      requireAnyLogin() + ownership
 *   - thumb/inline/raw: requireLogin() (admin) OR user ownership
 *   - pdf-viewer:  requireLogin() (admin)
 */
final class DocumentController extends Controller
{
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
     * POST /document/view — AJAX endpoint returning an HTML snippet that
     * renders a single document inside a Bootstrap modal.
     *
     * Auth: requireLogin() (admin) + CSRF token check (the form is a POST).
     */
    public function view(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)$request->input('document_id', 0);
        $document = $this->documents->findById($id);
        if ($document === null) {
            return Response::html(
                '<div class="alert alert-warning mb-0">سند یافت نشد.</div>',
                404
            );
        }
        $html = sprintf(
            '<div class="text-center">'
            . '<p class="text-muted small mb-2">%s</p>'
            . '<img src="%s" alt="%s" class="img-fluid rounded" loading="lazy">'
            . '</div>',
            htmlspecialchars($document->originalName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->environment->baseUrl . 'document/inline/' . $document->id, ENT_QUOTES),
            htmlspecialchars($document->originalName, ENT_QUOTES, 'UTF-8')
        );
        return Response::html($html);
    }

    /**
     * GET /document/avatar — stream the current user's avatar file.
     *
     * Auth: requireAnyLogin() + ownership (the avatar_document_id must
     * belong to the current user_account, OR admin sees everyone).
     */
    public function avatar(Request $request, array $params = []): Response
    {
        $this->requireAnyLogin();
        $accountId = (int)$this->session->get('user_id');
        $isAdmin = (bool)$this->session->get('admin_logged_in');
        if ($accountId <= 0 && !$isAdmin) {
            return $this->forbiddenResponse();
        }
        $userAccounts = $this->container->make(UserAccountRepositoryInterface::class);
        $account = $userAccounts->findById($accountId);
        if ($account === null) {
            return $this->forbiddenResponse();
        }
        $docId = (int)($account['avatar_document_id'] ?? 0);
        if ($docId <= 0) {
            return $this->notFoundResponse('avatar');
        }
        $document = $this->documents->findById($docId);
        if ($document === null) {
            return $this->notFoundResponse('avatar');
        }
        return $this->streamFile($document->filePath, $document->originalName, inline: true);
    }

    /**
     * GET /document/thumb/{id} — generate + serve a 200x200 thumbnail via GD.
     *
     * Auth: requireLogin() (admin) OR user ownership (document belongs to
     * an applicant whose user_account_id = current user).
     */
    public function thumb(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)($params['id'] ?? 0);
        $document = $this->documents->findById($id);
        if ($document === null || !$this->canAccess($document->applicantId)) {
            return $this->forbiddenResponse();
        }
        $fullPath = $this->fileStorage->getFullPath($document->filePath);
        if ($fullPath === null || !is_file($fullPath)) {
            return $this->notFoundResponse('thumb');
        }
        // Generate a 200x200 thumbnail via GD (re-encoded to JPEG).
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $image = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($fullPath),
            'png'         => @imagecreatefrompng($fullPath),
            default       => false,
        };
        if ($image === false) {
            // Not an image — fall back to a 1x1 transparent pixel so the
            // caller's <img> doesn't render a broken-image icon for PDFs.
            return $this->placeholderPng();
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $size = 200;
        $ratio = min($size / $w, $size / $h);
        $newW = (int)($w * $ratio);
        $newH = (int)($h * $ratio);
        $thumb = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($image);
        ob_start();
        imagejpeg($thumb, null, 85);
        $blob = (string)ob_get_clean();
        imagedestroy($thumb);
        return $this->binaryResponse($blob, 'image/jpeg', cacheable: true);
    }

    /**
     * GET /document/inline/{id} — stream a file with Content-Disposition:inline.
     */
    public function inline(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)($params['id'] ?? 0);
        $document = $this->documents->findById($id);
        if ($document === null || !$this->canAccess($document->applicantId)) {
            return $this->forbiddenResponse();
        }
        return $this->streamFile($document->filePath, $document->originalName, inline: true);
    }

    /**
     * GET /document/raw/{id} — raw file serve (for fetch()).
     */
    public function raw(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)($params['id'] ?? 0);
        $document = $this->documents->findById($id);
        if ($document === null || !$this->canAccess($document->applicantId)) {
            return $this->forbiddenResponse();
        }
        return $this->streamFile($document->filePath, $document->originalName, inline: true);
    }

    /**
     * GET /document/pdf-viewer — HTML wrapper that uses the browser's
     * native PDF viewer via `<iframe src="/document/inline/{id}">`.
     *
     * Auth: requireLogin(). The id comes in via ?id= query param.
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
     * Stream a file from the uploads dir to the client.
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
        $filenameParam = '; filename="' . str_replace('"', '', $displayName) . '"';
        $response = (new Response(
            $blob,
            200,
            [
                'Content-Type'        => $mime,
                'Content-Disposition' => $disposition . $filenameParam,
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
     * 1x1 transparent PNG (placeholder for non-image documents).
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
