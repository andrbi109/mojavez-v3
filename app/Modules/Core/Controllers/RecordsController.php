<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\ServiceTypeRepositoryInterface;
use App\Shared\Contracts\UserAccountRepositoryInterface;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;

/**
 * RecordsController — user can see their own application records.
 *
 * Phase 15 (v1.6.0): the `/my-records` flow lets a logged-in user enter
 * their mobile to confirm ownership, then list all applicants rows
 * whose `user_account_id` matches the current session user.
 *
 * Routes:
 *   GET  /my-records          → form      (show mobile verification form)
 *   POST /my-records/verify   → verify    (verify mobile, then list)
 *
 * Auth: requireUserLogin() at the top of both actions.
 */
final class RecordsController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly UserAccountRepositoryInterface $userAccounts,
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly ServiceTypeRepositoryInterface $serviceTypes,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /my-records — show the mobile-verification form.
     */
    public function form(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        return $this->render('records', [
            'title'    => 'پرونده‌های من',
            'account'  => $account,
            'records'  => [],
            'verified' => false,
            'error'    => '',
        ]);
    }

    /**
     * POST /my-records/verify — verify the mobile matches the account,
     * then list the applicants rows owned by this account.
     */
    public function verify(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $mobile = Validator::mobile($request->input('mobile'));
        if ($mobile === null || $mobile !== (string)$account['mobile']) {
            return $this->render('records', [
                'title'    => 'پرونده‌های من',
                'account'  => $account,
                'records'  => [],
                'verified' => false,
                'error'    => 'شمارهٔ موبایل واردشده با حساب کاربری شما مطابقت ندارد.',
            ]);
        }
        $records = $this->connection()->all(
            'SELECT a.id, a.tracking_code, a.current_status, a.created_at,
                    a.service_type_id, a.first_name, a.last_name,
                    st.title AS service_type_title
               FROM applicants a
               LEFT JOIN service_types st ON st.id = a.service_type_id
              WHERE a.user_account_id = ? AND a.deleted_at IS NULL
              ORDER BY a.created_at DESC',
            [(int)$account['id']]
        );
        return $this->render('records', [
            'title'    => 'پرونده‌های من',
            'account'  => $account,
            'records'  => $records,
            'verified' => true,
            'error'    => '',
        ]);
    }

    /**
     * Pull the current account array from the session.
     *
     * @return array<string,mixed>
     */
    private function currentAccount(): array
    {
        $id = (int)$this->session->get('user_id');
        $account = $this->userAccounts->findById($id);
        if ($account === null) {
            $this->session->remove('user_logged_in');
            $this->session->remove('user_id');
            $this->redirect('/login');
        }
        return $account;
    }

    private function connection(): Connection
    {
        return $this->container->make(Connection::class);
    }
}
