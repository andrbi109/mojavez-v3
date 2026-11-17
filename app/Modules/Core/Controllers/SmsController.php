<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\StatusLogRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;

/**
 * SmsController — admin panel for SMS templates + review of user-submitted
 * SMS entries.
 *
 * Phase 17 (v1.8.0): ported from v2's SmsController, adapted to v3's
 * modular architecture and the v3 `sms_templates` schema (which has
 * `title`, `sample_text`, `target_status`, `is_active`; v2 also had
 * `requires_code` and `sort_order` — we adapt accordingly).
 *
 * Two-role auth:
 *   - Template CRUD: role='admin' only.
 *   - Entry review: role='admin' or 'expert' (per v2).
 *
 * Routes (4 — registered in Core/Module.php):
 *   GET  /admin/sms                       → index (templates + recent entries)
 *   POST /admin/sms/save-template         → saveTemplate (insert or update)
 *   POST /admin/sms/delete-template       → deleteTemplate
 *   POST /admin/sms/review-entry          → reviewEntry (approve / reject)
 *
 * On approve: the entry's status becomes 'تأیید شده' and a status_log
 * row is added to the applicant's history. We do NOT auto-transition the
 * applicant's current_status to the template's target_status here — the
 * full v2 flow with workflow.php validation will be ported in a later
 * phase. The review outcome is recorded for the admin's audit trail.
 */
final class SmsController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly Connection $conn,
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly StatusLogRepositoryInterface $statusLog,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /admin/sms — render the combined templates + entries page.
     *
     * Query params:
     *   - status: filter entries by status ('در انتظار' default)
     *   - edit: template id to edit (highlights the edit form)
     */
    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $statusFilter = (string)$request->queryParam('status', 'در انتظار');
        if (!in_array($statusFilter, ['', 'در انتظار', 'تأیید شده', 'رد شده'], true)) {
            $statusFilter = '';
        }
        $editId = (int)$request->queryParam('edit', 0);

        $templates = $this->conn->all('SELECT * FROM sms_templates ORDER BY id');

        $entrySql = 'SELECT u.id, u.applicant_id, u.sms_template_id, u.sms_code,
                            u.sms_text, u.received_at, u.status, u.applied_status,
                            u.reviewed_by, u.reviewed_at, u.reject_reason, u.created_at,
                            a.tracking_code, a.first_name, a.last_name,
                            t.title AS template_title, t.target_status
                       FROM user_sms_entries u
                       JOIN applicants a ON a.id = u.applicant_id
                       JOIN sms_templates t ON t.id = u.sms_template_id';
        $entryArgs = [];
        if ($statusFilter !== '') {
            $entrySql .= ' WHERE u.status = ?';
            $entryArgs[] = $statusFilter;
        }
        $entrySql .= ' ORDER BY u.created_at DESC LIMIT 50';
        $entries = $this->conn->all($entrySql, $entryArgs);

        $editing = $editId > 0 ? $this->conn->one('SELECT * FROM sms_templates WHERE id = ?', [$editId]) : null;

        return $this->render('admin/sms', [
            'title'        => 'مدیریت پیامک‌ها',
            'templates'    => $templates,
            'entries'      => $entries,
            'statusFilter' => $statusFilter,
            'editing'      => $editing,
        ]);
    }

    /**
     * POST /admin/sms/save-template — insert or update a template.
     *
     * Body: id (optional), title, sample_text (body), target_status, is_active.
     */
    public function saveTemplate(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $id = (int)($body['id'] ?? 0);
        $title = Validator::nonEmptyString($body['title'] ?? '', 100);
        $sampleText = Validator::sanitizeText($body['sample_text'] ?? '', 2000);
        $targetStatus = Validator::sanitizeText($body['target_status'] ?? '', 100);
        $isActive = !empty($body['is_active']) ? 1 : 0;

        $errors = [];
        if ($title === null) {
            $errors[] = 'عنوان الگو الزامی است.';
        }

        if ($errors !== []) {
            $templates = $this->conn->all('SELECT * FROM sms_templates ORDER BY id');
            return $this->render('admin/sms', [
                'title'        => 'مدیریت پیامک‌ها',
                'templates'    => $templates,
                'entries'      => $this->conn->all('SELECT u.id, u.applicant_id, u.sms_template_id, u.sms_code, u.sms_text, u.received_at, u.status, u.created_at, a.tracking_code, a.first_name, a.last_name, t.title AS template_title, t.target_status FROM user_sms_entries u JOIN applicants a ON a.id = u.applicant_id JOIN sms_templates t ON t.id = u.sms_template_id ORDER BY u.created_at DESC LIMIT 50'),
                'statusFilter' => 'در انتظار',
                'editing'      => ['id' => $id, 'title' => $title ?? '', 'sample_text' => $sampleText, 'target_status' => $targetStatus, 'is_active' => $isActive],
            ]);
        }

        if ($id > 0) {
            $this->conn->execute(
                'UPDATE sms_templates SET title = ?, sample_text = ?, target_status = ?, is_active = ? WHERE id = ?',
                [$title, $sampleText !== '' ? $sampleText : null, $targetStatus !== '' ? $targetStatus : null, $isActive, $id]
            );
        } else {
            $this->conn->execute(
                'INSERT INTO sms_templates (title, sample_text, target_status, is_active) VALUES (?, ?, ?, ?)',
                [$title, $sampleText !== '' ? $sampleText : null, $targetStatus !== '' ? $targetStatus : null, $isActive]
            );
        }
        $this->logger->info('SMS template saved', [
            '_module' => 'core', 'id' => $id, 'title' => $title,
        ]);
        return Response::redirect('/admin/sms');
    }

    /**
     * POST /admin/sms/delete-template — delete a template.
     *
     * Refuses to delete a template that still has user_sms_entries (the FK
     * would cascade-delete them — losing audit history). Returns 400.
     */
    public function deleteTemplate(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)$request->input('id', 0);
        $existing = $this->conn->one('SELECT id, title FROM sms_templates WHERE id = ?', [$id]);
        if ($existing === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>الگو یافت نشد.</p>', 404);
        }
        $entryCount = (int)$this->conn->scalar(
            'SELECT COUNT(*) FROM user_sms_entries WHERE sms_template_id = ?',
            [$id]
        );
        if ($entryCount > 0) {
            // Soft-deactivate instead of delete — keeps the audit history.
            $this->conn->execute('UPDATE sms_templates SET is_active = 0 WHERE id = ?', [$id]);
            $this->logger->info('SMS template soft-deactivated (entries exist)', [
                '_module' => 'core', 'id' => $id, 'entry_count' => $entryCount,
            ]);
            return Response::redirect('/admin/sms');
        }
        $this->conn->execute('DELETE FROM sms_templates WHERE id = ?', [$id]);
        $this->logger->info('SMS template deleted', [
            '_module' => 'core', 'id' => $id, 'title' => $existing['title'],
        ]);
        return Response::redirect('/admin/sms');
    }

    /**
     * POST /admin/sms/review-entry — approve or reject a user-submitted SMS.
     *
     * Body: entry_id, review_status ∈ {approved, rejected}, note (optional).
     *
     * Auth: role='admin' or 'expert'.
     *
     * On approve: sets status='تأیید شده' + reviewed_by + reviewed_at.
     * On reject:  sets status='رد شده' + reject_reason + reviewed_by + reviewed_at.
     * In both cases, a status_log row is added to the applicant's history
     * for auditability. The applicant's current_status is NOT changed here
     * (full workflow.php-validated transition is left for a later phase).
     */
    public function reviewEntry(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin', 'expert']);
        $entryId = (int)$request->input('entry_id', 0);
        $reviewStatus = (string)$request->input('review_status', '');
        $note = Validator::sanitizeText($request->input('note', ''), 255);

        if (!in_array($reviewStatus, ['approved', 'rejected'], true)) {
            return $this->jsonError(422, 'invalid_review_status', 'وضعیت بازبینی نامعتبر.');
        }
        $entry = $this->conn->one(
            'SELECT u.*, t.title AS template_title, t.target_status
               FROM user_sms_entries u
               JOIN sms_templates t ON t.id = u.sms_template_id
              WHERE u.id = ?',
            [$entryId]
        );
        if ($entry === null) {
            return $this->jsonError(404, 'not_found', 'ورودی یافت نشد.');
        }
        if ($entry['status'] !== 'در انتظار') {
            return $this->jsonError(409, 'already_reviewed', 'این ورودی پیش‌تر بررسی شده است.');
        }

        $adminId = (int)$this->session->get('admin_id', 0);
        $newStatus = $reviewStatus === 'approved' ? 'تأیید شده' : 'رد شده';
        $this->conn->execute(
            'UPDATE user_sms_entries
                SET status = ?, reviewed_by = ?, reviewed_at = NOW(),
                    reject_reason = ?
              WHERE id = ?',
            [$newStatus, $adminId, $reviewStatus === 'rejected' ? ($note !== '' ? $note : null) : null, $entryId]
        );

        $applicantId = (int)$entry['applicant_id'];
        $description = $reviewStatus === 'approved'
            ? 'تأیید پیامک «' . (string)$entry['template_title'] . '»'
                . (!empty($entry['sms_code']) ? ' با کد ' . $entry['sms_code'] : '')
                . ' — توسط ' . (string)$this->session->get('admin_display_name', 'مدیر')
            : 'رد پیامک «' . (string)$entry['template_title'] . '»'
                . ($note !== '' ? ' — دلیل: ' . $note : '')
                . ' — توسط ' . (string)$this->session->get('admin_display_name', 'مدیر');
        $this->statusLog->create([
            'applicant_id' => $applicantId,
            'status_name'   => $reviewStatus === 'approved' ? 'تأیید پیامک ثبت‌شده' : 'رد پیامک ثبت‌شده',
            'description'   => $description,
            'triggered_by'  => 'admin',
        ]);

        $this->logger->info('SMS entry reviewed', [
            '_module'      => 'core',
            'entry_id'     => $entryId,
            'applicant_id' => $applicantId,
            'outcome'      => $reviewStatus,
        ]);

        return $this->jsonSuccess([
            'entry_id'     => $entryId,
            'review_status' => $reviewStatus,
            'new_status'   => $newStatus,
        ]);
    }
}
