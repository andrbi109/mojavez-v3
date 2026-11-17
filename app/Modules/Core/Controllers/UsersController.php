<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Shared\Persistence\Connection;

/**
 * UsersController — admin management of system users (admins + operators).
 *
 * Per docs/00-MASTER.md §11 — role-based access: only role='admin' can
 * manage users (superadmin). Other roles (operator, sync_operator) get 403.
 *
 * Operations:
 *   - index():  GET  /admin/users            list all users
 *   - create(): GET  /admin/users/new        show create form
 *   - store():  POST /admin/users             create a new user
 *   - edit():   GET  /admin/users/{id}/edit   show edit form
 *   - update(): POST /admin/users/{id}        update user
 *   - destroy():POST /admin/users/{id}/delete delete user
 *
 * Passwords are bcrypt-hashed. Role is one of: admin, operator, sync_operator.
 */
final class UsersController extends Controller
{
    public function __construct(
        \App\Container $container,
        \App\Http\Session $session,
        \App\Http\Csrf $csrf,
        \App\Shared\Support\Environment $environment,
        private readonly Connection $conn,
    ) {
        parent::__construct($container, $session, $csrf, $environment);
    }

    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $users = $this->conn->all(
            'SELECT id, username, display_name, role, created_at FROM users ORDER BY created_at DESC'
        );
        return $this->render('admin/users/index', ['users' => $users], 'مدیریت کاربران');
    }

    public function create(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        return $this->render('admin/users/create', [
            'username' => '', 'displayName' => '', 'role' => 'operator', 'errors' => [],
        ], 'کاربر جدید');
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
                'username' => $username, 'displayName' => $displayName, 'role' => $role, 'errors' => $errors,
            ], 'کاربر جدید');
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
            return Response::html('<h1>۴۰۴</h1><p>کاربر یافت نشد.</p>', 404);
        }
        return $this->render('admin/users/edit', ['user' => $user], 'ویرایش کاربر');
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
            return Response::html('<h1>۴۰۰</h1><p>نقش نامعتبر.</p>', 400);
        }

        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                return Response::html('<h1>۴۰۰</h1><p>رمز عبور حداقل ۸ کاراکتر.</p>', 400);
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
            return Response::html('<h1>۴۰۰</h1><p>نمی‌توانید حساب خود را حذف کنید.</p>', 400);
        }
        $this->conn->execute('DELETE FROM users WHERE id = ?', [$id]);
        return Response::redirect('/admin/users');
    }
}
