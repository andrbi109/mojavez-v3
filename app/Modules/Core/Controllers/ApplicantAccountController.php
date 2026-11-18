<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;

/**
 * ApplicantAccountController — admin management of `user_accounts`.
 *
 * IMPORTANT — these are the accounts that APPLICANTS use to log in to the
 * user panel (/panel/*), NOT the admin `users` table. The admin `users`
 * table is managed by UsersController (system admins + operators). This
 * controller is the applicant-side counterpart — it lets an admin:
 *   - list every applicant login account (with search by mobile/username)
 *   - view one account's details + its linked applicant rows
 *   - toggle is_active (disable / re-enable a login)
 *   - reset the password to a fresh random value (shown to the admin once)
 *
 * The applicant table links back via `applicants.user_account_id` (the
 * "view" action shows the linked applicants). The applicant login flow
 * is in AuthController (separate realm — /login, /signup, $_SESSION
 * 'user_logged_in'); this controller only does admin-side management.
 *
 * Per docs/00-MASTER.md §11 — only role='admin' (superadmin) can manage
 * applicant accounts. Other admin roles get 403. The 4 routes are
 * registered in Core/Module.php.
 *
 * Phase 42 (v2.0.8).
 */
final class ApplicantAccountController extends Controller
{
    private const PAGE_SIZE = 20;

    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly Connection $conn,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /admin/applicant-accounts — list all applicant login accounts.
     *
     * Paginated 20 per page. Search by mobile (LIKE) or username (LIKE)
     * via the `q` query parameter. Shows: id, username, mobile, email,
     * display_name, is_active, created_at, last_login_at.
     */
    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);

        $q      = trim((string)($request->queryParam('q', '') ?? ''));
        $page   = max(1, (int)($request->queryParam('page', 1) ?? 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $where = '1=1';
        $bind  = [];
        if ($q !== '') {
            // Search by mobile OR username — LIKE pattern, escaped.
            $like  = '%' . $this->conn->escapeLike($q) . '%';
            $where = '(mobile LIKE ? OR username LIKE ?)';
            $bind  = [$like, $like];
        }

        $total = (int)$this->conn->scalar(
            "SELECT COUNT(*) FROM user_accounts WHERE {$where}",
            $bind
        );
        $rows = $this->conn->all(
            "SELECT id, username, mobile, email, display_name,
                    is_active, created_at, last_login_at
               FROM user_accounts
              WHERE {$where}
              ORDER BY created_at DESC, id DESC
              LIMIT ? OFFSET ?",
            [...$bind, self::PAGE_SIZE, $offset]
        );

        $pages = (int)ceil($total / self::PAGE_SIZE);

        return $this->render('admin/applicant_accounts', [
            'title'  => 'حساب‌های متقاضیان',
            'rows'   => $rows,
            'q'      => $q,
            'page'   => $page,
            'pages'  => $pages,
            'total'  => $total,
        ]);
    }

    /**
     * GET /admin/applicant-accounts/{id} — detail view of one account.
     *
     * Shows the account fields plus the linked applicant rows (rows in
     * `applicants` where `user_account_id = $id`). Each linked applicant
     * row links back to /admin/view/{id} for the full applicant record.
     */
    public function view(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $account = $this->conn->one(
            'SELECT id, person_id, username, mobile, email, display_name,
                    is_active, failed_login_count, locked_until,
                    last_login_at, created_at
               FROM user_accounts
              WHERE id = ?',
            [$id]
        );
        if ($account === null) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>حساب متقاضی یافت نشد.</p>',
                404
            );
        }

        $applicants = $this->conn->all(
            'SELECT id, tracking_code, first_name, last_name, mobile,
                    national_code, current_status, license_state,
                    national_license_request_number, created_at
               FROM applicants
              WHERE user_account_id = ?
              ORDER BY created_at DESC, id DESC',
            [$id]
        );

        return $this->render('admin/applicant_account_view', [
            'title'      => 'جزئیات حساب متقاضی',
            'account'    => $account,
            'applicants' => $applicants,
        ]);
    }

    /**
     * POST /admin/applicant-accounts/{id}/toggle — flip is_active for one account.
     *
     * Disabling an account prevents future logins (the AuthController
     * login flow checks is_active=1 before issuing a session). Re-enabling
     * restores login access. The change is recorded only in user_accounts
     * (no audit-log entry — that's tracked in the application logs).
     */
    public function toggle(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->conn->one('SELECT id, is_active FROM user_accounts WHERE id = ?', [$id]);
        if ($row === null) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>حساب متقاضی یافت نشد.</p>',
                404
            );
        }
        $next = (int)$row['is_active'] === 1 ? 0 : 1;
        $this->conn->execute('UPDATE user_accounts SET is_active = ? WHERE id = ?', [$next, $id]);
        return Response::redirect($this->environment->baseUrl . 'admin/applicant-accounts');
    }

    /**
     * POST /admin/applicant-accounts/{id}/reset-password — generate a new
     * random password, hash it, update the row, and show the new password
     * to the admin (once, as a session flash) so they can pass it to the
     * applicant out-of-band (SMS, phone call, etc.).
     *
     * The generated password is 12 chars from [A-Za-z0-9] (~71 bits
     * entropy — strong enough for a one-shot reset). It is hashed with
     * PASSWORD_BCRYPT (cost 10) before storage — the raw value is never
     * persisted. The admin sees the raw value once via a session flash
     * redirect; a page refresh consumes the flash so the password isn't
     * left visible in the admin's browser history.
     */
    public function resetPassword(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->conn->one('SELECT id, mobile, username FROM user_accounts WHERE id = ?', [$id]);
        if ($row === null) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>حساب متقاضی یافت نشد.</p>',
                404
            );
        }

        $newPassword = $this->generateRandomPassword();
        $hash = $this->hash->passwordHash($newPassword);
        $this->conn->execute(
            'UPDATE user_accounts SET password = ?, failed_login_count = 0, locked_until = NULL WHERE id = ?',
            [$hash, $id]
        );

        $this->logger->info('Applicant account password reset by admin', [
            'account_id' => $id,
            'mobile'     => $row['mobile'],
            'admin_user'  => $this->session->get('admin_user_id', 0),
        ]);

        // Flash the raw password once so the admin can see and copy it.
        $this->session->set('applicant_account_flash', [
            'account_id' => $id,
            'mobile'     => $row['mobile'],
            'username'   => $row['username'],
            'password'   => $newPassword,
        ]);

        return Response::redirect(
            $this->environment->baseUrl . 'admin/applicant-accounts/' . $id
        );
    }

    /**
     * Generate a random password: 12 chars from [A-Za-z0-9].
     * Uses CSPRNG (random_int). 62^12 ≈ 3.2 × 10^21 combinations.
     */
    private function generateRandomPassword(int $length = 12): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max      = strlen($alphabet) - 1;
        $out      = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
