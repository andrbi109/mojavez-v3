<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Services\FileStorage;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\DocumentRepositoryInterface;
use App\Shared\Contracts\LicenseFileRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;

/**
 * LicenseController — license image access by token + admin license mgmt.
 *
 * Phase 15 (v1.6.0): the user-side actions are functional stubs that
 * record the request in the `license_access_requests` table. The
 * admin-side actions list + review the requests. The actual image
 * serving happens through the DocumentController once the request
 * is approved (and the access_token is generated).
 *
 * Routes (7):
 *   GET  /license/view/{token}                 → viewByToken (public)
 *   POST /tracking/license/first-access         → firstAccess (user, ownership)
 *   POST /tracking/license/request-access       → requestAccess (user, ownership, rate-limited 2/week)
 *   POST /tracking/license/pay                  → submitReissuePayment (user, ownership)
 *   POST /admin/license/upload                 → upload (admin)
 *   GET  /admin/license-requests                → requests (admin list)
 *   POST /admin/license-requests/review        → reviewRequest (admin approve/reject)
 *
 * The view is rendered inside tracking/status.php — the license view is
 * a modal there. So no separate user-side view file is needed.
 */
final class LicenseController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly DocumentRepositoryInterface $documents,
        private readonly LicenseFileRepositoryInterface $licenseFiles,
        private readonly FileStorage $fileStorage,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /license/view/{token} — public: serve the license image by
     * the 72h access token (no user login required — the token IS the auth).
     *
     * Validates the token + expiry, increments the download_count on the
     * license_files row, then streams the underlying document.
     */
    public function viewByToken(Request $request, array $params = []): Response
    {
        $token = (string)($params['token'] ?? '');
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>توکن نامعتبر.</p>', 404);
        }
        $row = $this->connection()->one(
            'SELECT lar.*, lf.document_id, lf.id AS license_file_id
               FROM license_access_requests lar
               JOIN license_files lf ON lf.applicant_id = lar.applicant_id
              WHERE lar.access_token = ? AND lar.expires_at > NOW()
              ORDER BY lar.created_at DESC LIMIT 1',
            [$token]
        );
        if ($row === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>توکن منقضی یا نامعتبر است.</p>', 404);
        }
        $document = $this->documents->findById((int)$row['document_id']);
        if ($document === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>تصویر مجوز یافت نشد.</p>', 404);
        }
        $fullPath = $this->fileStorage->getFullPath($document->filePath);
        if ($fullPath === null || !is_file($fullPath)) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>تصویر مجوز یافت نشد.</p>', 404);
        }
        // Record the first-access timestamp on the license_files row.
        if ($row['license_file_id'] !== null) {
            $this->licenseFiles->incrementDownload((int)$row['license_file_id']);
        }
        $mime = (string)(@mime_content_type($fullPath) ?: 'image/jpeg');
        $blob = (string)file_get_contents($fullPath);
        return (new Response(
            $blob,
            200,
            [
                'Content-Type'        => $mime,
                'Content-Disposition' => 'inline; filename="' . str_replace('"', '', $document->originalName) . '"',
                'Content-Length'      => (string)strlen($blob),
            ]
        ))->withHeader('Cache-Control', 'private, max-age=3600');
    }

    /**
     * POST /tracking/license/first-access — user initial license image
     * request. Records a `license_access_requests` row with
     * `is_first_access=1`, status='در انتظار'. The admin reviews and
     * generates the access_token (with a 72h expiry) via reviewRequest().
     */
    public function firstAccess(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $trackingCode = Validator::trackingCode($request->input('tracking_code'));
        if ($trackingCode === null) {
            return $this->jsonError(422, 'invalid_tracking_code', 'کد رهگیری نامعتبر.');
        }
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null || !$this->userOwnsApplicant($applicant->id)) {
            return $this->jsonError(403, 'forbidden', 'این پرونده به این حساب تعلق ندارد.');
        }
        $this->connection()->execute(
            'INSERT INTO license_access_requests (applicant_id, is_first_access, status)
             VALUES (?, 1, ?)',
            [$applicant->id, 'در انتظار']
        );
        $this->logger->info('First-access license request', [
            '_module'      => 'core',
            'applicant_id' => $applicant->id,
        ]);
        return $this->jsonSuccess(['requested' => true]);
    }

    /**
     * POST /tracking/license/request-access — user re-request the license
     * image (rate-limited to 2 per week per applicant). Records a
     * `license_access_requests` row with `is_first_access=0`.
     */
    public function requestAccess(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $trackingCode = Validator::trackingCode($request->input('tracking_code'));
        if ($trackingCode === null) {
            return $this->jsonError(422, 'invalid_tracking_code', 'کد رهگیری نامعتبر.');
        }
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null || !$this->userOwnsApplicant($applicant->id)) {
            return $this->jsonError(403, 'forbidden', 'این پرونده به این حساب تعلق ندارد.');
        }
        $recent = (int)$this->connection()->scalar(
            'SELECT COUNT(*) FROM license_access_requests
              WHERE applicant_id = ? AND is_first_access = 0
                AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            [$applicant->id]
        );
        if ($recent >= 2) {
            return $this->jsonError(
                429,
                'rate_limited',
                'سقف هفتگی ۲ درخواست مجاز است. بعداً تلاش کنید.'
            );
        }
        $this->connection()->execute(
            'INSERT INTO license_access_requests (applicant_id, is_first_access, status)
             VALUES (?, 0, ?)',
            [$applicant->id, 'در انتظار']
        );
        $this->logger->info('License re-access request', [
            '_module'      => 'core',
            'applicant_id' => $applicant->id,
        ]);
        return $this->jsonSuccess(['requested' => true]);
    }

    /**
     * POST /tracking/license/pay — user pays for a license reissue (when
     * the original is lost or expired). Records the request + marks it
     * `requires_payment=1` with the amount. The admin then approves the
     * payment via reviewRequest() which issues the access token.
     */
    public function submitReissuePayment(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $trackingCode = Validator::trackingCode($request->input('tracking_code'));
        if ($trackingCode === null) {
            return $this->jsonError(422, 'invalid_tracking_code', 'کد رهگیری نامعتبر.');
        }
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null || !$this->userOwnsApplicant($applicant->id)) {
            return $this->jsonError(403, 'forbidden', 'این پرونده به این حساب تعلق ندارد.');
        }
        $amount = (int)$request->input('amount', 0);
        if ($amount <= 0) {
            return $this->jsonError(422, 'invalid_amount', 'مبلغ نامعتبر.');
        }
        $this->connection()->execute(
            'INSERT INTO license_access_requests
                (applicant_id, is_first_access, requires_payment, amount, status)
             VALUES (?, 0, 1, ?, ?)',
            [$applicant->id, $amount, 'در انتظار']
        );
        $this->logger->info('License reissue payment request', [
            '_module'      => 'core',
            'applicant_id' => $applicant->id,
            'amount'       => $amount,
        ]);
        return $this->jsonSuccess(['requested' => true]);
    }

    /**
     * POST /admin/license/upload — admin uploads the license image for
     * a given applicant. Stores the file + inserts documents row +
     * upserts license_files row.
     */
    public function upload(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $applicantId = (int)$request->input('applicant_id', 0);
        $applicant = $this->applicants->findById($applicantId);
        if ($applicant === null) {
            return $this->jsonError(404, 'not_found', 'پرونده یافت نشد.');
        }
        $file = $request->file('license_image');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->jsonError(422, 'no_file', 'هیچ فایلی بارگذاری نشده است.');
        }
        $stored = $this->fileStorage->storeUpload($file, $applicantId, $applicant->trackingCode, 'license_image');
        if (!$stored['success'] || $stored['file_path'] === null) {
            return $this->jsonError(422, 'upload_failed', $stored['error'] ?? 'خطا در بارگذاری.');
        }
        $documentId = $this->documents->create([
            'applicant_id'  => $applicantId,
            'doc_type'      => 'license_image',
            'file_name'     => (string)$stored['file_name'],
            'original_name' => (string)$stored['original_name'],
            'file_path'     => (string)$stored['file_path'],
        ]);
        $this->licenseFiles->upsert($applicantId, $documentId);
        $this->logger->info('License image uploaded', [
            '_module'      => 'core',
            'applicant_id' => $applicantId,
            'document_id'  => $documentId,
        ]);
        return $this->jsonSuccess(['document_id' => $documentId]);
    }

    /**
     * GET /admin/license-requests — admin list of all license access
     * requests.
     */
    public function requests(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->connection()->all(
            'SELECT lar.id, lar.applicant_id, lar.is_first_access, lar.requires_payment,
                    lar.amount, lar.status, lar.created_at, lar.expires_at,
                    lar.reject_reason,
                    a.tracking_code, a.first_name, a.last_name
               FROM license_access_requests lar
               JOIN applicants a ON a.id = lar.applicant_id
              ORDER BY lar.created_at DESC'
        );
        return $this->render('admin/license_requests', [
            'title' => 'درخواست‌های دسترسی به مجوز',
            'rows'  => $rows,
        ]);
    }

    /**
     * POST /admin/license-requests/review — admin approve / reject a
     * license access request. On approve: generate a 64-hex access_token
     * with a 72h expiry. On reject: store the reject_reason.
     */
    public function reviewRequest(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)$request->input('id', 0);
        $decision = (string)$request->input('decision', '');
        $row = $this->connection()->one(
            'SELECT * FROM license_access_requests WHERE id = ?',
            [$id]
        );
        if ($row === null) {
            return $this->jsonError(404, 'not_found', 'درخواست یافت نشد.');
        }
        $adminId = (int)$this->session->get('admin_id');
        if ($decision === 'approve') {
            $token = $this->hash->randomToken(32);
            $expiresAt = date('Y-m-d H:i:s', time() + 72 * 3600);
            $this->connection()->execute(
                'UPDATE license_access_requests
                 SET status = ?, access_token = ?, expires_at = ?,
                     reviewed_by = ?, reviewed_at = NOW(), reject_reason = NULL
                 WHERE id = ?',
                ['تأیید شد', $token, $expiresAt, $adminId, $id]
            );
            $this->logger->info('License request approved', [
                '_module'      => 'core',
                'request_id'   => $id,
                'expires_at'  => $expiresAt,
            ]);
            return $this->jsonSuccess(['access_token' => $token, 'expires_at' => $expiresAt]);
        }
        if ($decision === 'reject') {
            $reason = Validator::sanitizeText($request->input('reject_reason', ''), 255);
            $this->connection()->execute(
                'UPDATE license_access_requests
                 SET status = ?, reviewed_by = ?, reviewed_at = NOW(), reject_reason = ?
                 WHERE id = ?',
                ['رد شد', $adminId, $reason !== '' ? $reason : null, $id]
            );
            $this->logger->info('License request rejected', [
                '_module'     => 'core',
                'request_id'  => $id,
                'reason'      => $reason,
            ]);
            return $this->jsonSuccess(['rejected' => true]);
        }
        return $this->jsonError(422, 'invalid_decision', 'تصمیم باید approve یا reject باشد.');
    }

    /* ------------------------------------------------------------------ */

    private function connection(): Connection
    {
        return $this->container->make(Connection::class);
    }

    private function userOwnsApplicant(int $applicantId): bool
    {
        $accountId = (int)$this->session->get('user_id');
        if ($accountId <= 0) {
            return false;
        }
        $owner = $this->connection()->scalar(
            'SELECT user_account_id FROM applicants WHERE id = ? AND deleted_at IS NULL',
            [$applicantId]
        );
        return $owner !== null && (int)$owner === $accountId;
    }
}
