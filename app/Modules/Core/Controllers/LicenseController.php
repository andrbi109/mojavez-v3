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
 * Phase 15 (v1.6.0) shipped functional stubs (recorded requests, no token
 * generation on the user side). Phase 19 (v1.9.1) completes the
 * implementation:
 *
 *   - firstAccess() now generates a 72-hour access_token immediately and
 *     returns it to the user (so the user-side flow is end-to-end usable
 *     without admin intervention for the very first access).
 *   - requestAccess() applies the 2-per-week rate limit + generates a
 *     token if the admin has uploaded a license file.
 *   - submitReissuePayment() accepts a receipt_file upload (image/PDF ≤
 *     5 MiB), stores it under storage/uploads/receipts/{applicant_id}/,
 *     and inserts a payments row (purpose='هزینهٔ دریافت مجدد تصویر مجوز',
 *     amount=50000 default, status='در انتظار رسید'). The
 *     license_access_requests row is linked via linked_request_id.
 *   - viewByToken() looks up license_access_requests by token + 72h
 *     expiry + status='تأیید شد', finds the license_files row, and
 *     either streams the underlying document or renders a "license not
 *     yet issued" page.
 *   - upload() (admin) saves the file under storage/uploads/licenses/
 *     {applicant_id}/, inserts a documents row + upserts license_files,
 *     and approves any pending first-access requests for this applicant.
 *   - requests() lists license_access_requests joined with applicants.
 *   - reviewRequest() approves (sets status='تأیید شد' + access_token +
 *     expires_at) or rejects (status='رد شد' + reject_reason).
 *
 * Routes (7) — registered in app/Modules/Core/Module.php since Phase 15:
 *   GET  /license/view/{token}                 → viewByToken (public)
 *   POST /tracking/license/first-access         → firstAccess (user, ownership)
 *   POST /tracking/license/request-access       → requestAccess (user, ownership, rate-limited 2/week)
 *   POST /tracking/license/pay                  → submitReissuePayment (user, ownership)
 *   POST /admin/license/upload                 → upload (admin)
 *   GET  /admin/license-requests                → requests (admin list)
 *   POST /admin/license-requests/review        → reviewRequest (admin approve/reject)
 *
 * Auth:
 *   - viewByToken: PUBLIC (the token IS the auth — no session required).
 *   - firstAccess/requestAccess/submitReissuePayment: requireUserLogin() +
 *     ownership (the applicant's user_account_id matches the session user_id).
 *   - upload/requests/reviewRequest: requireRole(['admin']).
 *
 * Path-traversal defense:
 *   - All file serving goes through FileStorage::getFullPath() which uses
 *     realpath() + str_starts_with($base) on the configured upload_dir.
 *   - User-supplied filenames are NEVER used as the on-disk path — the
 *     storage path is always server-constructed (bin2hex(random_bytes(16))
 *     + extension).
 *
 * The "license not yet issued" view at /license/view/{token} (when a
 * request is approved but no license_files row exists yet for the
 * applicant) renders app/Views/license/view.php with $notIssued=true.
 */
final class LicenseController extends Controller
{
    /** Persian status string stored in license_access_requests.status. */
    private const STATUS_PENDING = 'در انتظار';

    /** Persian status stored when admin approves the request. */
    private const STATUS_APPROVED = 'تأیید شد';

    /** Persian status stored when admin rejects the request. */
    private const STATUS_REJECTED = 'رد شد';

    /** Token expiry in hours (matches config/app.php 'license_access_token_hours'). */
    private const TOKEN_HOURS = 72;

    /** Default reissue-fee in Rial (50,000 toman = 500,000 rial — but
     *  the v2 default was 50,000 toman stored as the amount column; we
     *  keep the v2 convention: amount column stores the toman value). */
    private const DEFAULT_REISSUE_AMOUNT = 50000;

    /** Max receipt file size: 5 MiB. */
    private const MAX_RECEIPT_SIZE = 5 * 1024 * 1024;

    /** Max license image size: 10 MiB (admin upload). */
    private const MAX_LICENSE_SIZE = 10 * 1024 * 1024;

    /** Allowed receipt MIME types (image/PDF). */
    private const RECEIPT_ALLOWED_MIME = ['image/jpeg', 'image/png', 'application/pdf'];

    /** Allowed license image MIME types. */
    private const LICENSE_ALLOWED_MIME = ['image/jpeg', 'image/png', 'application/pdf'];

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
     * GET /license/view/{token} — public: render the license image by
     * the 72h access token (no user login required — the token IS the
     * auth).
     *
     * If the request is approved + the token is valid + a license_files
     * row exists for the applicant: stream the underlying document with
     * Content-Disposition: inline.
     *
     * If the request is approved + the token is valid + NO license_files
     * row exists yet: render the "license not yet issued" page
     * (license/view.php with $notIssued=true).
     *
     * Token format: 64 hex chars (32 random bytes → 64 hex). Anything
     * else is a 404.
     */
    public function viewByToken(Request $request, array $params = []): Response
    {
        $token = (string)($params['token'] ?? '');
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>توکن نامعتبر است.</p>',
                404
            );
        }

        $row = $this->connection()->one(
            'SELECT lar.id, lar.applicant_id, lar.status, lar.expires_at,
                    lar.is_first_access, a.tracking_code, a.first_name, a.last_name
               FROM license_access_requests lar
               JOIN applicants a ON a.id = lar.applicant_id
              WHERE lar.access_token = ?
              ORDER BY lar.created_at DESC LIMIT 1',
            [$token]
        );
        if ($row === null) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>توکن یافت نشد.</p>',
                404
            );
        }
        if ($row['status'] !== self::STATUS_APPROVED) {
            return $this->render('license/view', [
                'title'      => 'دسترسی به تصویر مجوز',
                'notIssued'  => false,
                'notApproved' => true,
                'applicantName' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
                'trackingCode'  => (string)$row['tracking_code'],
            ], 'none');
        }
        // Check expiry.
        $expiresAt = (string)($row['expires_at'] ?? '');
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۳</h1><p>لینک دسترسی منقضی شده است.</p>',
                403
            );
        }

        $licenseFile = $this->licenseFiles->findByApplicantId((int)$row['applicant_id']);
        if ($licenseFile === null) {
            // Approved but no license image uploaded yet → render "not
            // issued" page.
            return $this->render('license/view', [
                'title'          => 'دسترسی به تصویر مجوز',
                'notIssued'      => true,
                'notApproved'    => false,
                'applicantName'  => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
                'trackingCode'   => (string)$row['tracking_code'],
            ], 'none');
        }

        $document = $this->documents->findById($licenseFile->documentId);
        if ($document === null) {
            return $this->render('license/view', [
                'title'         => 'دسترسی به تصویر مجوز',
                'notIssued'     => true,
                'notApproved'   => false,
                'applicantName' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
                'trackingCode'  => (string)$row['tracking_code'],
            ], 'none');
        }

        $fullPath = $this->fileStorage->getFullPath($document->filePath);
        if ($fullPath === null || !is_file($fullPath)) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>فایل مجوز روی سرور موجود نیست.</p>',
                404
            );
        }

        // Record the access (increment download_count + set first_accessed_at).
        $this->licenseFiles->incrementDownload($licenseFile->id);

        $mime = (string)(@mime_content_type($fullPath) ?: 'application/octet-stream');
        $blob = (string)file_get_contents($fullPath);
        $displayName = 'license-' . (string)$row['tracking_code'];

        return (new Response(
            $blob,
            200,
            [
                'Content-Type'        => $mime,
                'Content-Disposition' => 'inline; filename="' . str_replace('"', '', $displayName) . '"',
                'Content-Length'      => (string)strlen($blob),
            ]
        ))->withHeader('Cache-Control', 'private, max-age=300');
    }

    /**
     * POST /tracking/license/first-access — user's initial license image
     * request.
     *
     * Records a license_access_requests row with is_first_access=1,
     * status='در انتظار'. If a license_files row already exists for the
     * applicant, the request is auto-approved: status='تأیید شد' + a
     * 72h access_token is generated. The token is returned to the caller
     * so the user-side JS can immediately redirect to /license/view/{token}.
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

        // If a license image already exists, auto-approve + generate token.
        $licenseFile = $this->licenseFiles->findByApplicantId($applicant->id);
        $token = $licenseFile === null ? null : $this->hash->randomToken(32);
        $expiresAt = $token === null ? null : date('Y-m-d H:i:s', time() + self::TOKEN_HOURS * 3600);
        $status = $token === null ? self::STATUS_PENDING : self::STATUS_APPROVED;

        $this->connection()->execute(
            'INSERT INTO license_access_requests
                (applicant_id, is_first_access, status, access_token, expires_at)
             VALUES (?, 1, ?, ?, ?)',
            [$applicant->id, $status, $token, $expiresAt]
        );

        $this->logger->info('First-access license request', [
            '_module'      => 'core',
            'applicant_id' => $applicant->id,
            'auto_approved' => $token !== null,
        ]);

        return $this->jsonSuccess([
            'requested'    => true,
            'auto_approved' => $token !== null,
            'token'        => $token,
            'expires_at'   => $expiresAt,
        ]);
    }

    /**
     * POST /tracking/license/request-access — user re-request the license
     * image (rate-limited to 2 per week per applicant).
     *
     * If a license_files row exists, auto-approve + generate a 72h token
     * (so the user gets immediate access on re-request too — the admin
     * review is only needed if no license image is uploaded yet, which is
     * handled by reviewRequest()).
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

        $licenseFile = $this->licenseFiles->findByApplicantId($applicant->id);
        $token = $licenseFile === null ? null : $this->hash->randomToken(32);
        $expiresAt = $token === null ? null : date('Y-m-d H:i:s', time() + self::TOKEN_HOURS * 3600);
        $status = $token === null ? self::STATUS_PENDING : self::STATUS_APPROVED;

        $this->connection()->execute(
            'INSERT INTO license_access_requests
                (applicant_id, is_first_access, status, access_token, expires_at)
             VALUES (?, 0, ?, ?, ?)',
            [$applicant->id, $status, $token, $expiresAt]
        );

        $this->logger->info('License re-access request', [
            '_module'       => 'core',
            'applicant_id'  => $applicant->id,
            'auto_approved' => $token !== null,
        ]);

        return $this->jsonSuccess([
            'requested'    => true,
            'auto_approved' => $token !== null,
            'token'        => $token,
            'expires_at'   => $expiresAt,
        ]);
    }

    /**
     * POST /tracking/license/pay — user pays for a license reissue (when
     * the original is lost or expired).
     *
     * Accepts:
     *   - tracking_code (form field)
     *   - receipt_file (file upload, image/PDF ≤ 5 MiB)
     *
     * Saves the file under storage/uploads/receipts/{applicant_id}/,
     * inserts a documents row (doc_type='reissue_receipt'), inserts a
     * payments row (purpose='هزینهٔ دریافت مجدد تصویر مجوز',
     * amount=50000 default, status='در انتظار رسید'), and links it to
     * a new license_access_requests row (requires_payment=1,
     * payment_id=the new payment id).
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

        $file = $request->file('receipt_file');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->jsonError(422, 'no_file', 'فایل رسید را بارگذاری کنید.');
        }
        // Validate size + MIME.
        if (($file['size'] ?? 0) > self::MAX_RECEIPT_SIZE) {
            return $this->jsonError(422, 'too_large', 'حجم فایل بیش از ۵ مگابایت است.');
        }
        $realMime = $this->detectMime((string)$file['tmp_name']);
        if (!in_array($realMime, self::RECEIPT_ALLOWED_MIME, true)) {
            return $this->jsonError(422, 'invalid_type', 'فقط فرمت JPEG/PNG/PDF پذیرفته می‌شود.');
        }

        // Store under storage/uploads/receipts/{applicant_id}/{rand16}.ext
        $ext = $this->mimeToExtension($realMime);
        $relPath = $this->storeReceiptFile($file, $applicant->id, $ext);
        if ($relPath === null) {
            return $this->jsonError(422, 'upload_failed', 'خطا در ذخیرهٔ فایل.');
        }

        // Look up the user's first applicant (needed because documents
        // has a NOT NULL FK to applicants). The applicant we're paying
        // for is the right carrier row.
        $documentId = $this->documents->create([
            'applicant_id'   => $applicant->id,
            'doc_type'       => 'reissue_receipt',
            'file_name'       => basename($relPath),
            'original_name'  => $this->safeOriginalName((string)($file['name'] ?? 'receipt')),
            'file_path'      => $relPath,
        ]);

        $amount = (int)$request->input('amount', self::DEFAULT_REISSUE_AMOUNT);
        if ($amount <= 0) {
            $amount = self::DEFAULT_REISSUE_AMOUNT;
        }
        $userAccountId = (int)$this->session->get('user_id');

        // Insert the license_access_requests row first (so we can link
        // the payment back to it). status='در انتظار' (admin must review
        // the receipt before issuing the access token).
        $this->connection()->execute(
            'INSERT INTO license_access_requests
                (applicant_id, is_first_access, requires_payment, amount, status)
             VALUES (?, 0, 1, ?, ?)',
            [$applicant->id, $amount, self::STATUS_PENDING]
        );
        $requestId = (int)$this->connection()->pdo()->lastInsertId();

        // Insert the payments row, linked to the new request via
        // linked_request_id. The receipt document id goes into
        // receipt_document_id.
        $this->connection()->execute(
            'INSERT INTO payments
                (applicant_id, user_account_id, purpose, amount,
                 receipt_document_id, linked_request_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $applicant->id,
                $userAccountId > 0 ? $userAccountId : null,
                'هزینهٔ دریافت مجدد تصویر مجوز',
                $amount,
                $documentId,
                $requestId,
                'در انتظار رسید',
            ]
        );
        $paymentId = (int)$this->connection()->pdo()->lastInsertId();

        // Back-link: license_access_requests.payment_id = payments.id.
        $this->connection()->execute(
            'UPDATE license_access_requests SET payment_id = ? WHERE id = ?',
            [$paymentId, $requestId]
        );

        $this->logger->info('License reissue payment receipt uploaded', [
            '_module'      => 'core',
            'applicant_id' => $applicant->id,
            'payment_id'   => $paymentId,
            'request_id'   => $requestId,
            'amount'       => $amount,
        ]);

        return $this->jsonSuccess([
            'requested'  => true,
            'payment_id' => $paymentId,
            'amount'     => $amount,
        ]);
    }

    /**
     * POST /admin/license/upload — admin uploads the license image for
     * a given applicant.
     *
     * Stores the file under storage/uploads/licenses/{applicant_id}/
     * (a flat per-applicant folder), inserts a documents row, upserts
     * the license_files row, and approves any pending first-access
     * requests for this applicant (sets status='تأیید شد' + generates
     * a 72h access_token for each — but only the most recent one is
     * practically useful; the older ones are still marked approved so
     * the user can use any of them within the 72h window).
     *
     * Phase 55 (v2.2.1) — switched the response from JSON to a
     * PRG redirect back to /admin/view/{applicant_id}, mirroring the
     * Phase 50 (v2.1.6) requestPayment() pattern. The previous JSON
     * response surfaced as raw JSON text in the browser when invoked
     * from view_application.php's normal file-upload form (the form
     * has no JS to handle the JSON). The view's existing flash banner
     * (driven by the session keys 'view_app_message' +
     * 'view_app_message_kind') now picks up the upload result.
     */
    public function upload(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $applicantId = (int)$request->input('applicant_id', 0);
        $applicant = $this->applicants->findById($applicantId);

        // Helper: set the one-shot flash banner on the session and
        // bounce back to the view. The view reads + clears these keys
        // in its header (see AdminController::viewApplication).
        $flash = function (string $msg, string $kind = 'success') use ($applicantId): never {
            $this->session->set('view_app_message', $msg);
            $this->session->set('view_app_message_kind', $kind);
            $this->redirect('/admin/view/' . $applicantId);
        };

        if ($applicant === null) {
            $flash('پرونده یافت نشد.', 'danger');
        }
        $file = $request->file('license_image');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flash('هیچ فایلی بارگذاری نشده است.', 'danger');
        }
        if (($file['size'] ?? 0) > self::MAX_LICENSE_SIZE) {
            $flash('حجم فایل بیش از ۱۰ مگابایت است.', 'danger');
        }
        $realMime = $this->detectMime((string)$file['tmp_name']);
        if (!in_array($realMime, self::LICENSE_ALLOWED_MIME, true)) {
            $flash('فقط فرمت JPEG/PNG/PDF پذیرفته می‌شود.', 'danger');
        }

        $ext = $this->mimeToExtension($realMime);
        $relPath = $this->storeLicenseFile($file, $applicantId, $ext);
        if ($relPath === null) {
            $flash('خطا در ذخیرهٔ فایل.', 'danger');
        }

        $documentId = $this->documents->create([
            'applicant_id'   => $applicantId,
            'doc_type'       => 'license_image',
            'file_name'      => basename($relPath),
            'original_name'  => $this->safeOriginalName((string)($file['name'] ?? 'license')),
            'file_path'      => $relPath,
        ]);

        // Upsert the license_files row (1:1 with applicant).
        $this->licenseFiles->upsert($applicantId, $documentId);

        // Approve any pending first-access requests for this applicant
        // (so the user can immediately access the license after the
        // admin uploads it — without needing a separate review step).
        $adminId = (int)$this->session->get('admin_id', 0);
        $pending = $this->connection()->all(
            'SELECT id FROM license_access_requests
              WHERE applicant_id = ? AND status = ?',
            [$applicantId, self::STATUS_PENDING]
        );
        foreach ($pending as $p) {
            $token = $this->hash->randomToken(32);
            $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_HOURS * 3600);
            $this->connection()->execute(
                'UPDATE license_access_requests
                 SET status = ?, access_token = ?, expires_at = ?,
                     reviewed_by = ?, reviewed_at = NOW(), reject_reason = NULL
                 WHERE id = ?',
                [self::STATUS_APPROVED, $token, $expiresAt, $adminId, (int)$p['id']]
            );
        }

        $this->logger->info('License image uploaded', [
            '_module'      => 'core',
            'applicant_id' => $applicantId,
            'document_id'  => $documentId,
            'auto_approved_count' => count($pending),
        ]);

        $autoApprovedNote = count($pending) > 0
            ? sprintf(' و %d درخواست دسترسی در انتظار نیز به‌طور خودکار تأیید شد.', count($pending))
            : '';
        $flash('تصویر مجوز با موفقیت بارگذاری شد.' . $autoApprovedNote, 'success');
    }

    /**
     * GET /admin/license-requests — admin list of all license access
     * requests. Renders admin/license_requests.php with a joined
     * applicants row (tracking_code, first_name, last_name, mobile).
     */
    public function requests(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->connection()->all(
            'SELECT lar.id, lar.applicant_id, lar.is_first_access, lar.requires_payment,
                    lar.amount, lar.status, lar.created_at, lar.expires_at,
                    lar.reject_reason, lar.access_token,
                    a.tracking_code, a.first_name, a.last_name, a.mobile
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
     * license access request.
     *
     * On approve: generate a 64-hex access_token with a 72h expiry,
     * set status='تأیید شد', reviewed_by=adminId, reviewed_at=NOW().
     *
     * On reject: set status='رد شد', reject_reason=the provided reason,
     * reviewed_by + reviewed_at.
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
        $adminId = (int)$this->session->get('admin_id', 0);

        if ($decision === 'approve') {
            $token = $this->hash->randomToken(32);
            $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_HOURS * 3600);
            $this->connection()->execute(
                'UPDATE license_access_requests
                 SET status = ?, access_token = ?, expires_at = ?,
                     reviewed_by = ?, reviewed_at = NOW(), reject_reason = NULL
                 WHERE id = ?',
                [self::STATUS_APPROVED, $token, $expiresAt, $adminId, $id]
            );
            $this->logger->info('License request approved', [
                '_module'    => 'core',
                'request_id' => $id,
                'expires_at' => $expiresAt,
            ]);
            return $this->jsonSuccess([
                'access_token' => $token,
                'expires_at'   => $expiresAt,
            ]);
        }

        if ($decision === 'reject') {
            $reason = Validator::sanitizeText($request->input('reject_reason', ''), 255);
            $this->connection()->execute(
                'UPDATE license_access_requests
                 SET status = ?, reviewed_by = ?, reviewed_at = NOW(),
                     reject_reason = ?, access_token = NULL, expires_at = NULL
                 WHERE id = ?',
                [self::STATUS_REJECTED, $adminId, $reason !== '' ? $reason : null, $id]
            );
            $this->logger->info('License request rejected', [
                '_module'    => 'core',
                'request_id' => $id,
                'reason'     => $reason,
            ]);
            return $this->jsonSuccess(['rejected' => true]);
        }

        return $this->jsonError(422, 'invalid_decision', 'تصمیم باید approve یا reject باشد.');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function connection(): Connection
    {
        return $this->container->make(Connection::class);
    }

    /**
     * Whether the current user (session user_id) owns this applicant row.
     */
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

    /**
     * Detect the real MIME of an uploaded file via finfo (NOT the
     * client-supplied $file['type'] which is trivially forged).
     */
    private function detectMime(string $tmpPath): string
    {
        if (!is_file($tmpPath)) {
            return '';
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $result = $finfo->file($tmpPath);
        return is_string($result) ? $result : '';
    }

    /**
     * Map a MIME type to its canonical file extension.
     */
    private function mimeToExtension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'     => 'jpg',
            'image/png'      => 'png',
            'application/pdf' => 'pdf',
            default          => 'bin',
        };
    }

    /**
     * Store a receipt file under storage/uploads/receipts/{applicant_id}/.
     * Returns the relative path (relative to project root) or null on failure.
     */
    private function storeReceiptFile(array $file, int $applicantId, string $ext): ?string
    {
        return $this->storeUploadUnder($file, 'storage/uploads/receipts/' . $applicantId, $ext);
    }

    /**
     * Store a license file under storage/uploads/licenses/{applicant_id}/.
     * Returns the relative path (relative to project root) or null on failure.
     */
    private function storeLicenseFile(array $file, int $applicantId, string $ext): ?string
    {
        return $this->storeUploadUnder($file, 'storage/uploads/licenses/' . $applicantId, $ext);
    }

    /**
     * Move an uploaded file to {projectRoot}/{$relDir}/{rand16hex}.{ext}.
     * Returns the project-relative path (e.g.
     * "storage/uploads/receipts/42/abc123.jpg") or null on failure.
     */
    private function storeUploadUnder(array $file, string $relDir, string $ext): ?string
    {
        $projectRoot = dirname(__DIR__, 4);
        $absDir = $projectRoot . '/' . $relDir;
        if (!is_dir($absDir)) {
            @mkdir($absDir, 0775, true);
        }
        if (!is_dir($absDir) || !is_writable($absDir)) {
            $this->logger->error('Upload dir not writable', [
                '_module' => 'core', 'dir' => $absDir,
            ]);
            return null;
        }
        $rand = bin2hex(random_bytes(16));
        $fileName = $rand . '.' . $ext;
        $absPath = $absDir . '/' . $fileName;
        if (!move_uploaded_file((string)$file['tmp_name'], $absPath)) {
            return null;
        }
        @chmod($absPath, 0640);
        return $relDir . '/' . $fileName;
    }

    /**
     * Sanitize a user-supplied filename for the documents.original_name
     * column: basename + strip control chars + truncate to 250 chars.
     */
    private function safeOriginalName(string $raw): string
    {
        $name = basename($raw);
        $name = preg_replace('/[\x00-\x1F\x7F\\\\]/', '', $name) ?? $name;
        $name = trim($name);
        if (strlen($name) > 250) {
            $name = substr($name, 0, 250);
        }
        return $name;
    }
}
