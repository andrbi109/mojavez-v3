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
 * UsersController — admin management of system users (admins + operators).
 *
 * Per docs/00-MASTER.md §11 — role-based access: only role='admin' can
 * manage users (superadmin). Other roles (operator, sync_operator) get 403.
 *
 * Operations:
 *   - index():   GET  /admin/users             list all users
 *   - create():  GET  /admin/users/new          show create form
 *   - store():   POST /admin/users              create a new user
 *   - edit():    GET  /admin/users/{id}/edit    show edit form
 *   - update():  POST /admin/users/{id}         update user
 *   - destroy(): POST /admin/users/{id}/delete  delete user
 *
 * Passwords are bcrypt-hashed. Role is one of: admin, operator, sync_operator.
 *
 * Phase 10 fix: the constructor previously called parent::__construct() with
 * only 4 args (container, session, csrf, environment) while the base Controller
 * requires 6 (the above + Hash + StructuredLogger). This caused a fatal
 * "Too few arguments" error on every /admin/users* route — a 500, not a 404,
 * but functionally the same broken-page outcome for the user. The render()
 * calls also passed a Persian title string as the third argument, which the
 * base render() interprets as the layout name — so admin/users/* pages were
 * rendered without the admin_layout wrapping. Both fixed.
 */
final class UsersController extends Controller
{
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

    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $users = $this->conn->all(
            'SELECT id, username, display_name, role, created_at FROM users ORDER BY created_at DESC'
        );
        return $this->render('admin/users/index', [
            'title' => 'مدیریت کاربران',
            'users' => $users,
        ]);
    }

    public function create(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        return $this->render('admin/users/create', [
            'title'      => 'کاربر جدید',
            'username'   => '',
            'displayName' => '',
            'role'       => 'operator',
            'errors'     => [],
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $username = trim($request->body()['username'] ?? '');
        $password = (string)($request->body()['password'] ?? '');
        $displayName = trim($request->body()['display_name'] ?? '');
        $role = (string)($request->body()['role'] ?? 'operator');

        $errors = [];
        if (mb_strlen($username) < 3) {
            $errors[] = 'نام کاربری حداقل ۳ نویسه باید باشد.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'رمز عبور حداقل ۸ کاراکتر باید باشد.';
        }
        if (!in_array($role, ['admin', 'operator', 'sync_operator'], true)) {
            $errors[] = 'نقش نامعتبر.';
        }
        $existing = $this->conn->one('SELECT id FROM users WHERE username = ?', [$username]);
        if ($existing !== null) {
            $errors[] = 'این نام کاربری قبلاً گرفته شده.';
        }

        if ($errors !== []) {
            return $this->render('admin/users/create', [
                'title'      => 'کاربر جدید',
                'username'   => $username,
                'displayName' => $displayName,
                'role'       => $role,
                'errors'     => $errors,
            ]);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->conn->execute(
            'INSERT INTO users (username, password, display_name, role, created_at) VALUES (?, ?, ?, ?, NOW())',
            [$username, $hash, $displayName, $role]
        );
        return Response::redirect('/admin/users');
    }

    public function edit(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $user = $this->conn->one('SELECT id, username, display_name, role FROM users WHERE id = ?', [$id]);
        if ($user === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>کاربر یافت نشد.</p>', 404);
        }
        return $this->render('admin/users/edit', [
            'title' => 'ویرایش کاربر',
            'user'  => $user,
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $body = $request->body();
        $displayName = trim($body['display_name'] ?? '');
        $role = (string)($body['role'] ?? 'operator');
        $newPassword = (string)($body['password'] ?? '');

        if (!in_array($role, ['admin', 'operator', 'sync_operator'], true)) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۰</h1><p>نقش نامعتبر.</p>', 400);
        }

        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۰</h1><p>رمز عبور حداقل ۸ کاراکتر.</p>', 400);
            }
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $this->conn->execute(
                'UPDATE users SET display_name = ?, role = ?, password = ? WHERE id = ?',
                [$displayName, $role, $hash, $id]
            );
        } else {
            $this->conn->execute(
                'UPDATE users SET display_name = ?, role = ? WHERE id = ?',
                [$displayName, $role, $id]
            );
        }
        return Response::redirect('/admin/users');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        // Prevent self-deletion (the admin deleting their own account).
        if ($id === (int)$this->session->get('admin_id', 0)) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۰</h1><p>نمی‌توانید حساب خود را حذف کنید.</p>', 400);
        }
        $this->conn->execute('DELETE FROM users WHERE id = ?', [$id]);
        return Response::redirect('/admin/users');
    }
}
