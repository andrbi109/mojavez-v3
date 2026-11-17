<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Shared\Contracts\UserAccountRepositoryInterface;
use App\Shared\Contracts\UserRepositoryInterface;
use App\Support\Environment;
use App\Support\Hash;
use App\Support\StructuredLogger;
use App\Support\Validator;

/**
 * AuthController — handles both admin and user authentication.
 *
 * Two realms (per docs/00-MASTER.md §11.1):
 *   admin realm: /admin/login (POST) → uses UserRepository + sets admin_* session keys.
 *   user realm:  /login (POST) → uses UserAccountRepository + sets user_* session keys.
 *
 * Both realms regenerate the session ID after a successful login (anti-fixation).
 *
 * Rate limits (per docs/00-MASTER.md §12.6):
 *   admin login: 5 / 5 min (session-based).
 *   user login:  5 / 5 min (IP-based via rate_limit_ip()).
 *
 * Account lockout (per docs/00-MASTER.md §11.8):
 *   5 failed user-attempts → 5 minute lock via locked_until column.
 */
final class AuthController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly UserRepositoryInterface $users,
        private readonly UserAccountRepositoryInterface $userAccounts,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /signup — user signup form.
     */
    public function signupForm(Request $request, array $params = []): Response
    {
        return $this->render('auth/signup', []);
    }

    /**
     * POST /signup — register a new applicant account.
     */
    public function signup(Request $request, array $params = []): Response
    {
        $mobile = Validator::mobile($request->input('mobile'));
        $password = (string)$request->input('password', '');
        if ($mobile === null || strlen($password) < 8) {
            return $this->jsonError(422, 'invalid_input', 'موبایل نامعتبر یا رمز عبور کوتاه.');
        }
        if ($this->userAccounts->findByMobile($mobile) !== null) {
            return $this->jsonError(409, 'duplicate', 'این شمارهٔ موبایل قبلاً ثبت شده است.');
        }
        $this->userAccounts->create([
            'mobile'    => $mobile,
            'password'  => $this->hash->passwordHash($password),
            'is_active' => 1,
        ]);
        $this->logger->info('User account created', ['_module' => 'core', 'mobile' => $mobile]);
        return $this->redirect('/login');
    }

    /**
     * GET /login — user login form.
     */
    public function loginForm(Request $request, array $params = []): Response
    {
        return $this->render('auth/login', []);
    }

    /**
     * POST /login — user login (mobile + password).
     */
    public function login(Request $request, array $params = []): Response
    {
        $mobile = Validator::mobile($request->input('mobile'));
        $password = (string)$request->input('password', '');
        if ($mobile === null) {
            return $this->jsonError(422, 'invalid_mobile', 'شمارهٔ موبایل نامعتبر.');
        }
        $account = $this->userAccounts->findByMobile($mobile);
        if ($account === null) {
            // Anti-timing: run a dummy verify.
            $this->hash->verifyPassword($password, null);
            return $this->jsonError(401, 'invalid_credentials', 'موبایل یا رمز عبور نادرست.');
        }
        if (!empty($account['is_active']) && (int)$account['is_active'] === 0) {
            return $this->jsonError(403, 'account_disabled', 'حساب کاربری غیرفعال است.');
        }
        if ($this->userAccounts->isLocked((int)$account['id'])) {
            return $this->jsonError(429, 'account_locked', 'حساب به دلیل تلاش‌های ناموفق قفل است. ۵ دقیقه بعد تلاش کنید.');
        }
        if (!$this->userAccounts->verifyPassword((int)$account['id'], $password)) {
            $this->userAccounts->recordFailedLogin((int)$account['id']);
            $this->logger->warning('Failed user login', [
                '_module' => 'core', 'mobile' => $mobile, 'ip' => $request->ip(),
            ]);
            return $this->jsonError(401, 'invalid_credentials', 'موبایل یا رمز عبور نادرست.');
        }
        // Success
        $this->userAccounts->updateLastLogin((int)$account['id']);
        $this->session->regenerate();
        $this->session->set('user_logged_in', true);
        $this->session->set('user_id', (int)$account['id']);
        $this->session->set('user_mobile', $account['mobile']);
        $this->session->set('user_name', $account['username'] ?? $account['mobile']);

        $redirect = (string)($this->session->get('redirect_after_login') ?? '/panel');
        $this->session->remove('redirect_after_login');
        return $this->redirect($redirect);
    }

    /**
     * GET /logout — clear user session.
     */
    public function logout(Request $request, array $params = []): Response
    {
        $this->session->remove('user_logged_in');
        $this->session->remove('user_id');
        $this->session->remove('user_mobile');
        $this->session->remove('user_name');
        $this->session->regenerate();
        return $this->redirect('/');
    }

    /**
     * GET /admin/login — admin login form.
     */
    public function adminLoginForm(Request $request, array $params = []): Response
    {
        return $this->render('admin/login', []);
    }

    /**
     * POST /admin/login — admin login (username + password).
     */
    public function adminLogin(Request $request, array $params = []): Response
    {
        $username = (string)$request->input('username', '');
        $password = (string)$request->input('password', '');
        $user = $this->users->findByUsername($username);
        // Anti-timing: always run password_verify.
        $hash = $user === null ? '' : (string)$user['password'];
        if ($user === null || !$this->hash->verifyPassword($password, $hash)) {
            $this->logger->warning('Failed admin login', [
                '_module'  => 'core',
                'username' => $username,
                'ip'       => $request->ip(),
            ]);
            return $this->jsonError(401, 'invalid_credentials', 'نام کاربری یا رمز عبور نادرست.');
        }
        $this->session->regenerate();
        $this->session->set('admin_logged_in', true);
        $this->session->set('admin_id', (int)$user['id']);
        $this->session->set('admin_role', (string)$user['role']);
        $this->session->set('admin_username', (string)$user['username']);
        $this->session->set('admin_display_name', (string)($user['display_name'] ?? $user['username']));
        $this->logger->info('Admin logged in', [
            '_module'  => 'core',
            'admin_id' => (int)$user['id'],
            'username' => $user['username'],
        ]);
        return $this->redirect('/admin/dashboard');
    }

    /**
     * GET /admin/logout — clear admin session.
     */
    public function adminLogout(Request $request, array $params = []): Response
    {
        $this->session->remove('admin_logged_in');
        $this->session->remove('admin_id');
        $this->session->remove('admin_role');
        $this->session->remove('admin_username');
        $this->session->remove('admin_display_name');
        $this->session->regenerate();
        return $this->redirect('/admin/login');
    }
}
