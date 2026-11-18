<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Exceptions\CsrfException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;

/**
 * Base controller with guards + view rendering.
 *
 * Inherited from v2's Controller base, refactored to:
 *  - Take dependencies via constructor (DI via Container, not Database::getInstance()).
 *  - Use the new Request/Response value objects.
 *  - Use the new Csrf service (always-on for mutating requests).
 *  - Two separate session realms: admin_* (requireLogin) and user_* (requireUserLogin).
 *
 * Guards (per docs/00-MASTER.md §11.3):
 *   requireLogin()       — checks admin_logged_in + ADMIN_IP_ALLOWLIST.
 *   requireUserLogin()   — checks user_logged_in + preserves redirect_after_login.
 *   requireAnyLogin()    — accepts either admin or user.
 *   requireRole([...])   — requireLogin + checks admin_role against the list.
 *   isSuperAdmin()       — admin_role === 'admin'.
 *   validateStatusTransition() — uses config/workflow.php transition matrix.
 *   requireApiKey()      — for /api/v1/applicants/* (Phase 6 scope-based auth).
 */
abstract class Controller
{
    public function __construct(
        protected readonly Container $container,
        protected readonly Session $session,
        protected readonly Csrf $csrf,
        protected readonly Environment $environment,
        protected readonly Hash $hash,
        protected readonly StructuredLogger $logger
    ) {}

    /**
     * Require an admin session. Throws to redirect to /admin/login if missing.
     */
    protected function requireLogin(): void
    {
        if (!$this->session->get('admin_logged_in')) {
            $this->redirect('/admin/login');
        }
        $this->enforceAdminIpAllowlist();
    }

    /**
     * Require a user (applicant) session. Throws to redirect to /login if missing.
     */
    protected function requireUserLogin(): void
    {
        if (!$this->session->get('user_logged_in')) {
            $this->session->set('redirect_after_login', $_SERVER['REQUEST_URI'] ?? '/');
            $this->redirect('/login');
        }
    }

    /**
     * Accept either admin or user session.
     */
    protected function requireAnyLogin(): void
    {
        if (!$this->session->get('admin_logged_in') && !$this->session->get('user_logged_in')) {
            $this->redirect('/login');
        }
    }

    /**
     * Require an admin session with one of the given roles.
     *
     * @param array<int,string> $roles
     */
    protected function requireRole(array $roles): void
    {
        $this->requireLogin();
        $role = (string)$this->session->get('admin_role', '');
        if (!in_array($role, $roles, true)) {
            $this->forbidden();
        }
    }

    /**
     * Whether the current admin is the super-admin (role='admin').
     */
    protected function isSuperAdmin(): bool
    {
        return $this->session->get('admin_role') === 'admin';
    }

    /**
     * Validate that the current role may transition from $current to $new.
     * Throws a 422 if the transition is not allowed.
     *
     * Phase 53 (v2.1.9) — now delegates to {@see statusTransitionError()}
     * so AdminController::changeStatus() can branch on AJAX-ness BEFORE
     * calling the validation (and decide between a JSON error and a
     * flash + redirect for form POSTs). Existing callers (which use
     * validateStatusTransition directly) keep the same behavior —
     * the method still exits with a JSON 422 on failure.
     */
    protected function validateStatusTransition(ApplicantStatus $current, ApplicantStatus $new, string $role): void
    {
        $err = $this->statusTransitionError($current, $new, $role);
        if ($err !== null) {
            $this->failTransition($err);
        }
    }

    /**
     * Same rules as {@see validateStatusTransition()} but DOES NOT
     * exit — returns the Persian error message on a disallowed
     * transition, or null if the transition is allowed.
     *
     * Phase 53 (v2.1.9) — extracted so AdminController::changeStatus()
     * can branch on the request's AJAX-ness before deciding to send a
     * JSON error or a flash + redirect. (The legacy
     * validateStatusTransition() always exits with a JSON 422, which
     * is wrong for a form POST.)
     *
     * NOTE — the terminal-state check on $current is included here
     * (mirroring validateStatusTransition's behavior) BUT only for
     * non-admin roles. Admins are still allowed to bypass via the
     * early return. AdminController::changeStatus() adds a SEPARATE,
     * role-independent terminal check on $current BEFORE calling this
     * helper — that's how "even admin cannot re-open a closed case"
     * is enforced.
     *
     * @return string|null Error message in Persian, or null if allowed.
     */
    protected function statusTransitionError(ApplicantStatus $current, ApplicantStatus $new, string $role): ?string
    {
        // admin may always transition.
        if ($role === 'admin') {
            return null;
        }
        $workflow = \App\Shared\Support\Config::get('workflow.transitions', []);
        $roleRules = $workflow[$role] ?? ['from' => [], 'to' => []];
        $from = $roleRules['from'] ?? [];
        $to = $roleRules['to'] ?? [];
        // Empty `from` or `to` = unrestricted.
        if ($from !== [] && !in_array($current->value, $from, true)) {
            return "نقش «{$role}» نمی‌تواند از وضعیت «{$current->value}» انتقال دهد.";
        }
        if ($to !== [] && !in_array($new->value, $to, true)) {
            return "نقش «{$role}» نمی‌تواند به وضعیت «{$new->value}» انتقال دهد.";
        }
        // Forbid backward transitions (except to Cancelled/Rejected).
        if (!in_array($new, [ApplicantStatus::Cancelled, ApplicantStatus::Rejected], true)
            && $new->order() <= $current->order()) {
            return 'انتقال به وضعیت قبلی مجاز نیست.';
        }
        // Forbid transition from terminal (except to Cancelled/Rejected).
        if ($current->isTerminal() && !in_array($new, [ApplicantStatus::Cancelled, ApplicantStatus::Rejected], true)) {
            return 'پرونده در وضعیت پایانی است.';
        }
        return null;
    }

    /**
     * Require an API key for /api/v1/* endpoints. Phase 6 will replace
     * this with scope-based auth via the api_keys table.
     *
     * For Phase 2, accepts the legacy GATEWAY_API_KEY env var (constant-time).
     */
    protected function requireApiKey(Request $request): void
    {
        $provided = (string)($request->header('X-API-Key') ?? '');
        if ($provided === '') {
            $this->jsonError(401, 'missing_api_key');
        }
        $known = $this->environment->gatewayApiKey;
        if ($known === '' || !$this->hash->timingSafeCompare($known, $provided)) {
            $this->jsonError(401, 'invalid_api_key');
        }
    }

    /**
     * Render a view inside a layout.
     *
     * The view file at app/Views/<view>.php is captured into $content
     * via output buffering, then the layout at app/Views/layout.php
     * is rendered with $content available.
     *
     * For admin views (paths starting with `admin/`), the admin_layout.php
     * is used by default. (Phase 6 ports the full Bootstrap-based layouts.)
     *
     * Special layout values:
     *   - 'none'  → render the view as-is with NO layout wrapping. Use this
     *               for views that are already complete HTML documents
     *               (e.g. admin/login.php, print.php). Without this, such
     *               views would be nested inside a layout, producing invalid
     *               HTML (a <html> inside a <body>).
     *
     * Phase 10: 'none' was added to fix the broken nested-HTML output on
     * /admin/login (full HTML doc wrapped by admin_layout) and on
     * /tracking/print/{code} (full HTML doc wrapped by layout.php).
     */
    protected function render(string $view, array $data = [], string $layout = 'layout'): Response
    {
        $viewsDir = dirname(__DIR__, 3) . '/Views';
        $viewPath = $viewsDir . '/' . $view . '.php';
        if (!is_file($viewPath)) {
            return Response::html("View not found: {$view}", 500);
        }
        // Make CSRF field available to views as $csrfField (for forms).
        $csrfField = $this->csrf->field();
        $data['csrfField'] = $csrfField;
        // Make base URL available.
        $data['baseUrl'] = $this->environment->baseUrl;
        extract($data, EXTR_SKIP);

        ob_start();
        require $viewPath;
        $content = (string)ob_get_clean();

        // 'none' = render the view as-is (already a complete HTML document).
        // Used by print.php and admin/login.php — both of which ship their
        // own <html>/<head>/<body> and would otherwise be nested in a layout.
        if ($layout === 'none') {
            return Response::html($content);
        }

        // Pick layout: admin/* views use admin_layout.php.
        if ($layout === 'layout' && str_starts_with($view, 'admin/')) {
            $layout = 'admin_layout';
        }
        $layoutPath = $viewsDir . '/' . $layout . '.php';
        if (!is_file($layoutPath)) {
            // No layout available — return just the content.
            return Response::html($content);
        }
        ob_start();
        require $layoutPath;
        $html = (string)ob_get_clean();
        return Response::html($html);
    }

    /**
     * Redirect to a URL. Throws a special exception that the front
     * controller catches to emit a 302.
     */
    protected function redirect(string $url, int $status = 302): never
    {
        Response::redirect($url, $status)->send();
        exit;
    }

    /**
     * Render a 403 forbidden page.
     */
    protected function forbidden(): never
    {
        Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۳</h1><p>دسترسی ممنوع</p>', 403)->send();
        exit;
    }

    /**
     * Emit a JSON error and exit.
     */
    protected function jsonError(int $status, string $code, string $message = '', array $details = []): never
    {
        $payload = [
            'success' => false,
            'error'   => [
                'code'    => $code,
                'message' => $message !== '' ? $message : $code,
            ],
        ];
        if ($details !== []) {
            $payload['error']['details'] = $details;
        }
        Response::json($payload, $status)->send();
        exit;
    }

    /**
     * Emit a JSON success response.
     */
    protected function jsonSuccess(mixed $data, int $status = 200): Response
    {
        return Response::json([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'contract_version' => '1.0.0',
                'request_id'       => $_SERVER['X_REQUEST_ID'] ?? null,
            ],
        ], $status);
    }

    private function enforceAdminIpAllowlist(): void
    {
        $allowlist = $this->environment->adminIpAllowlist;
        if ($allowlist === '') {
            return;
        }
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $allowed = array_map('trim', explode(',', $allowlist));
        if (!in_array($clientIp, $allowed, true)) {
            $this->forbidden();
        }
    }

    private function failTransition(string $message): never
    {
        $this->jsonError(422, 'invalid_status_transition', $message);
    }
}
