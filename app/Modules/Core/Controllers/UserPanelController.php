<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Shared\Contracts\LicenseFileRepositoryInterface;
use App\Shared\Contracts\PaymentRepositoryInterface;
use App\Shared\Contracts\ServiceTypeRepositoryInterface;
use App\Shared\Contracts\StatusLogRepositoryInterface;
use App\Shared\Contracts\UserAccountRepositoryInterface;
use App\Shared\Contracts\UserNotificationRepositoryInterface;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;

/**
 * UserPanelController — the applicant storefront panel (`/panel/*`).
 *
 * Phase 9: closes the v2→v3 gap reported by the user (404 after login).
 * v2's UserPanelController had: index/profile/requests/payments/licenses/
 * notifications/tracking. v3 had the auth→/panel redirect in place since
 * Phase 6 but no controller and no routes — so login always landed on 404.
 *
 * Auth model: every action calls requireUserLogin(), which preserves
 * redirect_after_login and redirects to /login if the user session is
 * missing. The current account id is read from `user_id` (set by
 * AuthController::login() line 122).
 *
 * Data sources (all already in v3, no new schema):
 *   - UserAccountRepositoryInterface::getApplications(accountId)
 *   - UserAccountRepositoryInterface::getPayments(accountId)
 *   - UserNotificationRepositoryInterface::findByUser(accountId, 5)
 *   - LicenseFileRepositoryInterface::findByApplicantId(applicantId)
 *   - ServiceTypeRepositoryInterface::findById(id)  (for service titles)
 *   - StatusLogRepositoryInterface::findByApplicantId(id) (for the timeline)
 *
 * The controller deliberately keeps a thin read-only surface for Phase 9 —
 * POST handlers (updateProfile, uploadAvatar, markAllRead) are wired but
 * only markAllRead is implemented here; the others are acknowledged in
 * DEBT_REVIEW D-9-1 for v1.3 (they need an AvatarService + validation work
 * that is out of scope for closing the 404).
 */
final class UserPanelController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly UserAccountRepositoryInterface $userAccounts,
        private readonly UserNotificationRepositoryInterface $notifications,
        private readonly PaymentRepositoryInterface $payments,
        private readonly LicenseFileRepositoryInterface $licenseFiles,
        private readonly ServiceTypeRepositoryInterface $serviceTypes,
        private readonly StatusLogRepositoryInterface $statusLog,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /panel — overview dashboard for the logged-in user.
     *
     * Mirrors v2 UserPanelController::index(): a stats row (total / pending /
     * issued applications), the latest application with its status timeline,
     * the last few payments, and unread notifications.
     */
    public function index(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();

        $applications = $this->userAccounts->getApplications((int)$account['id']);
        $payments    = $this->userAccounts->getPayments((int)$account['id']);
        $notifs      = $this->notifications->findByUser((int)$account['id'], 5);

        $total   = count($applications);
        $issued  = 0;
        $pending = 0;
        foreach ($applications as $a) {
            $st = (string)($a['current_status'] ?? '');
            if (in_array($st, ['صدور مجوز', 'تحویل مجوز'], true)) {
                $issued++;
            } elseif (!in_array($st, ['لغو', 'صدور مجوز', 'تحویل مجوز'], true)) {
                $pending++;
            }
        }

        $latestApp = $applications[0] ?? null;
        $timeline  = [];
        if ($latestApp) {
            try {
                $timeline = $this->statusLog->findByApplicantId((int)$latestApp['id']);
            } catch (\Throwable) {
                // timeline is a nice-to-have; never fatal.
            }
        }

        $unread = $this->notifications->unreadCount((int)$account['id']);

        return $this->render('user/overview', [
            'title'        => 'پنل کاربری',
            'activeMenu'   => 'overview',
            'account'      => $account,
            'applications' => $applications,
            'payments'     => array_slice($payments, 0, 5),
            'notifications' => $notifs,
            'latestApp'    => $latestApp,
            'timeline'     => $timeline,
            'serviceTypes' => $this->serviceTypeMap(),
            'unreadCount'  => $unread,
            'stats' => [
                'total_apps'   => $total,
                'pending_apps' => $pending,
                'issued_apps'  => $issued,
            ],
        ], 'user_layout');
    }

    /**
     * GET /panel/profile — read-only profile view.
     *
     * POST /panel/profile (updateProfile) and POST /panel/avatar
     * (uploadAvatar) are routed but return a 501 with a TODO marker for
     * v1.3 (see DEBT_REVIEW D-9-1). Phase 9's contract is only to stop the
     * 404s — full profile editing is a deliberate later scope.
     */
    public function profile(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        return $this->render('user/profile', [
            'title'      => 'پروفایل',
            'activeMenu' => 'profile',
            'account'    => $account,
            'unreadCount' => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /** POST /panel/profile — stub (v1.3, D-9-1). */
    public function updateProfile(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        return Response::html(
            '<div class="container py-5"><div class="alert alert-warning">'
            . 'ویرایش پروفایل در نسخهٔ ۱.۳ فعال خواهد شد (DEBT_REVIEW D-9-1).'
            . '</div><a href="/panel/profile" class="btn btn-secondary">بازگشت</a></div>',
            501
        );
    }

    /** POST /panel/avatar — stub (v1.3, D-9-1). */
    public function uploadAvatar(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        return Response::json(['status' => 'not_implemented', 'message' => 'avatar upload in v1.3 (D-9-1)'], 501);
    }

    /**
     * GET /panel/requests — list of the user's applications.
     */
    public function requests(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $applications = $this->userAccounts->getApplications((int)$account['id']);
        return $this->render('user/requests', [
            'title'        => 'درخواست‌های من',
            'activeMenu'   => 'requests',
            'account'      => $account,
            'applications' => $applications,
            'serviceTypes' => $this->serviceTypeMap(),
            'unreadCount'  => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /**
     * GET /panel/payments — list of the user's payments.
     */
    public function payments(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $rows = $this->userAccounts->getPayments((int)$account['id']);
        return $this->render('user/payments', [
            'title'      => 'پرداخت‌های من',
            'activeMenu' => 'payments',
            'account'    => $account,
            'payments'   => $rows,
            'unreadCount' => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /**
     * GET /panel/licenses — issued licenses for the user's applications.
     */
    public function licenses(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $applications = $this->userAccounts->getApplications((int)$account['id']);

        // Attach the license file (if any) to each issued application.
        $rows = [];
        foreach ($applications as $a) {
            $license = null;
            if (in_array((string)($a['current_status'] ?? ''), ['صدور مجوز', 'تحویل مجوز'], true)) {
                try {
                    $license = $this->licenseFiles->findByApplicantId((int)$a['id']);
                } catch (\Throwable) {
                    // ignore — license file lookup is best-effort
                }
            }
            $a['license'] = $license;
            $rows[] = $a;
        }

        return $this->render('user/licenses', [
            'title'        => 'مجوزهای من',
            'activeMenu'   => 'licenses',
            'account'      => $account,
            'applications' => $rows,
            'serviceTypes' => $this->serviceTypeMap(),
            'unreadCount'  => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /**
     * GET /panel/notifications — full list of the user's notifications.
     */
    public function notifications(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $rows = $this->notifications->findByUser((int)$account['id']);
        return $this->render('user/notifications', [
            'title'         => 'اعلان‌ها',
            'activeMenu'    => 'notifications',
            'account'       => $account,
            'notifications' => $rows,
            'unreadCount'   => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /**
     * POST /panel/notifications/read — mark every unread notification as read.
     */
    public function markAllRead(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        $this->notifications->markAllRead((int)$account['id']);
        return $this->redirect('/panel/notifications');
    }

    /**
     * GET /panel/tracking — quick tracking form embedded in the panel.
     */
    public function tracking(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $account = $this->currentAccount();
        return $this->render('user/tracking', [
            'title'      => 'پیگیری درخواست',
            'activeMenu' => 'tracking',
            'account'    => $account,
            'unreadCount' => $this->notifications->unreadCount((int)$account['id']),
        ], 'user_layout');
    }

    /* ------------------------------------------------------------------ */

    /**
     * Pull the current account array from the session, or redirect to /login.
     *
     * @return array<string,mixed>
     */
    private function currentAccount(): array
    {
        $id = (int)$this->session->get('user_id');
        $account = $this->userAccounts->findById($id);
        if ($account === null) {
            // Session said user_id but the row is gone — force re-login.
            $this->session->remove('user_logged_in');
            $this->session->remove('user_id');
            $this->redirect('/login');
        }
        return $account;
    }

    /**
     * Build a {id => service_type} lookup so views can render service titles
     * without N+1 queries.
     *
     * @return array<int,string>
     */
    private function serviceTypeMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            try {
                foreach ($this->serviceTypes->getAll() as $s) {
                    $map[(int)$s['id']] = (string)($s['title'] ?? $s['name'] ?? '—');
                }
            } catch (\Throwable) {
                // service types are a display nicety, never fatal.
            }
        }
        return $map;
    }
}
