<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;

/**
 * RevisionController — user-side revision request + admin review.
 *
 * Phase 15 (v1.6.0): the user can request a revision to their own
 * application data after it's been submitted. Admins can see, approve
 * or reject these requests.
 *
 * Routes (6):
 *   GET  /tracking/revision/{trackingCode}      → form        (user form)
 *   POST /tracking/revision/submit               → submit     (user submit)
 *   GET  /admin/revisions                         → index       (admin list)
 *   GET  /admin/revisions/view/{id}              → detail       (admin detail)
 *   POST /admin/revisions/approve/{id}           → approve     (admin approve)
 *   POST /admin/revisions/reject/{id}            → reject       (admin reject)
 *
 * Auth:
 *   - form/submit: requireUserLogin() + ownership (the applicant's
 *     user_account_id must match the session user_id).
 *   - admin/*: requireRole(['admin']).
 *
 * The `applicant_revisions` table (already in schema) stores each
 * request as: payload JSON + note + status + reviewed_by + reviewed_at +
 * reject_reason.
 */
final class RevisionController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly ApplicantRepositoryInterface $applicants,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /tracking/revision/{trackingCode} — show the revision request form.
     */
    public function form(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $trackingCode = Validator::trackingCode($params['trackingCode'] ?? '');
        if ($trackingCode === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>کد رهگیری نامعتبر.</p>', 404);
        }
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>پرونده یافت نشد.</p>', 404);
        }
        if (!$this->userOwnsApplicant($applicant->id)) {
            return $this->forbidden();
        }
        return $this->render('tracking/revision', [
            'title'        => 'درخواست بازبینی',
            'applicant'    => $applicant,
            'trackingCode' => $trackingCode,
        ]);
    }

    /**
     * POST /tracking/revision/submit — process the revision request.
     */
    public function submit(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $trackingCode = Validator::trackingCode($request->input('tracking_code'));
        if ($trackingCode === null) {
            return $this->jsonError(422, 'invalid_tracking_code', 'کد رهگیری نامعتبر.');
        }
        $applicant = $this->applicants->findByTrackingCode($trackingCode);
        if ($applicant === null) {
            return $this->jsonError(404, 'not_found', 'پرونده یافت نشد.');
        }
        if (!$this->userOwnsApplicant($applicant->id)) {
            return $this->jsonError(403, 'forbidden', 'این پرونده به این حساب تعلق ندارد.');
        }
        $note = Validator::sanitizeText($request->input('note', ''), 500);
        $payload = [
            'first_name'   => Validator::sanitizeText($request->input('first_name', ''), 50),
            'last_name'    => Validator::sanitizeText($request->input('last_name', ''), 50),
            'national_code' => (string)$request->input('national_code', ''),
            'father_name'  => Validator::sanitizeText($request->input('father_name', ''), 50),
            'mobile'       => (string)$request->input('mobile', ''),
            'note'         => $note,
        ];
        $this->connection()->execute(
            'INSERT INTO applicant_revisions (applicant_id, payload, note, status)
             VALUES (?, ?, ?, ?)',
            [
                $applicant->id,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $note !== '' ? $note : null,
                'در انتظار',
            ]
        );
        $this->logger->info('Revision requested', [
            '_module'      => 'core',
            'applicant_id' => $applicant->id,
        ]);
        return $this->redirect('/tracking/status/' . rawurlencode($trackingCode));
    }

    /**
     * GET /admin/revisions — admin list of all revision requests.
     */
    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->connection()->all(
            'SELECT r.id, r.applicant_id, r.payload, r.note, r.status,
                    r.created_at, r.reviewed_at, r.reject_reason,
                    a.tracking_code, a.first_name, a.last_name
               FROM applicant_revisions r
               JOIN applicants a ON a.id = r.applicant_id
              ORDER BY r.created_at DESC'
        );
        return $this->render('admin/revisions', [
            'title' => 'درخواست‌های بازبینی',
            'rows'  => $rows,
        ]);
    }

    /**
     * GET /admin/revisions/view/{id} — admin detail view (side-by-side).
     */
    public function detail(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->connection()->one(
            'SELECT r.*, a.tracking_code, a.first_name, a.last_name,
                    a.national_code, a.father_name, a.mobile
               FROM applicant_revisions r
               JOIN applicants a ON a.id = r.applicant_id
              WHERE r.id = ?',
            [$id]
        );
        if ($row === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>درخواست یافت نشد.</p>', 404);
        }
        $payload = json_decode((string)($row['payload'] ?? '{}'), true) ?: [];
        return $this->render('admin/revision_detail', [
            'title'  => 'جزئیات درخواست بازبینی',
            'row'    => $row,
            'payload' => $payload,
        ]);
    }

    /**
     * POST /admin/revisions/approve/{id} — admin approves the revision:
     * apply the payload to the applicants row, mark the revision done.
     */
    public function approve(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->connection()->one(
            'SELECT * FROM applicant_revisions WHERE id = ?',
            [$id]
        );
        if ($row === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>درخواست یافت نشد.</p>', 404);
        }
        $payload = json_decode((string)($row['payload'] ?? '{}'), true) ?: [];
        $update = [];
        foreach (['first_name', 'last_name', 'national_code', 'father_name', 'mobile'] as $f) {
            if (isset($payload[$f]) && is_string($payload[$f]) && $payload[$f] !== '') {
                $update[$f] = $payload[$f];
            }
        }
        if ($update !== []) {
            $this->applicants->update((int)$row['applicant_id'], $update);
        }
        $adminId = (int)$this->session->get('admin_id');
        $this->connection()->execute(
            'UPDATE applicant_revisions
             SET status = ?, reviewed_by = ?, reviewed_at = NOW(), reject_reason = NULL
             WHERE id = ?',
            ['تأیید شد', $adminId, $id]
        );
        $this->logger->info('Revision approved', [
            '_module'         => 'core',
            'revision_id'     => $id,
            'applicant_id'    => (int)$row['applicant_id'],
            'applied_fields'  => array_keys($update),
        ]);
        return $this->redirect('/admin/revisions');
    }

    /**
     * POST /admin/revisions/reject/{id} — admin rejects the revision.
     */
    public function reject(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->connection()->one(
            'SELECT * FROM applicant_revisions WHERE id = ?',
            [$id]
        );
        if ($row === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>درخواست یافت نشد.</p>', 404);
        }
        $reason = Validator::sanitizeText($request->input('reject_reason', ''), 255);
        $adminId = (int)$this->session->get('admin_id');
        $this->connection()->execute(
            'UPDATE applicant_revisions
             SET status = ?, reviewed_by = ?, reviewed_at = NOW(), reject_reason = ?
             WHERE id = ?',
            ['رد شد', $adminId, $reason !== '' ? $reason : null, $id]
        );
        $this->logger->info('Revision rejected', [
            '_module'      => 'core',
            'revision_id'  => $id,
            'reason'       => $reason,
        ]);
        return $this->redirect('/admin/revisions');
    }

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
}
