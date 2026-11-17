<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Domain\Enums\ApplicantStatus;
use App\Modules\Core\Services\AutoCancelService;
use App\Shared\Contracts\ApplicantRepositoryInterface;
use App\Shared\Contracts\StatusLogRepositoryInterface;
use App\Shared\Support\Config;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;
use App\Shared\Support\Validator;

/**
 * AdminController — admin panel (dashboard, applications, status changes).
 *
 * Inherited from v2's AdminController. Phase 2 ships the core admin
 * operations (dashboard, applications list, view, change-status). Other
 * admin operations (register manual, edit, postal requests, my-tasks)
 * will be added incrementally — the route registration in Module::routes()
 * covers what's implemented.
 */
final class AdminController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly ApplicantRepositoryInterface $applicants,
        private readonly StatusLogRepositoryInterface $statusLog,
        private readonly AutoCancelService $autoCancel,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /admin/dashboard — admin dashboard with summary stats.
     */
    public function dashboard(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        // Poor man's cron: AutoCancelService on every dashboard view.
        try {
            $cancelled = $this->autoCancel->cancelExpired();
            $prioritised = $this->autoCancel->updatePriorities();
        } catch (\Throwable $e) {
            $cancelled = 0;
            $prioritised = 0;
            $this->logger->warning('AutoCancel failed on dashboard view', [
                '_module' => 'core', 'error' => $e->getMessage(),
            ]);
        }

        $totalApplicants = (int)$this->connection()->scalar('SELECT COUNT(*) FROM applicants WHERE deleted_at IS NULL');
        $pendingReview = (int)$this->connection()->scalar(
            "SELECT COUNT(*) FROM applicants
             WHERE deleted_at IS NULL AND current_status IN ('تأیید اولیه','بررسی کارشناسی رشته','احراز نشانی')"
        );
        $issuedThisMonth = (int)$this->connection()->scalar(
            "SELECT COUNT(*) FROM applicants
             WHERE deleted_at IS NULL
               AND license_state = 'صادر شده'
               AND matched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        return $this->render('admin/dashboard', [
            'cancelled'         => $cancelled,
            'prioritised'       => $prioritised,
            'totalApplicants'   => $totalApplicants,
            'pendingReview'     => $pendingReview,
            'issuedThisMonth'   => $issuedThisMonth,
        ]);
    }

    /**
     * GET /admin/applications — paginated list with filters.
     */
    public function applications(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $page = max(1, (int)$request->queryParam('page', 1));
        $pageSize = 20;
        $filters = [
            'tracking_code'   => $request->queryParam('tracking_code'),
            'national_code'   => $request->queryParam('national_code'),
            'current_status'  => $request->queryParam('current_status'),
            'license_state'   => $request->queryParam('license_state'),
            'service_type_id' => $request->queryParam('service_type_id'),
            'unmatched_only'   => $request->queryParam('unmatched_only'),
        ];
        $filters = array_filter($filters, static fn ($v): bool => $v !== null && $v !== '');
        $result = $this->applicants->paginate($filters, $page, $pageSize);
        return $this->render('admin/applications', [
            'rows'       => $result['rows'],
            'total'      => $result['total'],
            'page'       => $page,
            'pageSize'   => $pageSize,
            'filters'    => $filters,
        ]);
    }

    /**
     * GET /admin/view/{id} — view a single applicant's case.
     */
    public function viewApplication(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)($params['id'] ?? 0);
        $applicant = $this->applicants->findById($id);
        if ($applicant === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>پرونده یافت نشد.</p>', 404);
        }
        $statusLog = $this->statusLog->findByApplicantId($id);
        return $this->render('admin/view_application', [
            'applicant' => $applicant,
            'statusLog' => $statusLog,
        ]);
    }

    /**
     * POST /admin/change-status — change an applicant's status.
     */
    public function changeStatus(Request $request, array $params = []): Response
    {
        $this->requireLogin();
        $id = (int)$request->input('applicant_id', 0);
        $newStatusSlug = (string)$request->input('new_status', '');
        $description = (string)$request->input('description', '');

        $applicant = $this->applicants->findById($id);
        if ($applicant === null) {
            return $this->jsonError(404, 'not_found');
        }
        $newStatus = ApplicantStatus::fromSlug($newStatusSlug);
        if ($newStatus === null) {
            return $this->jsonError(422, 'invalid_status', 'وضعیت نامعتبر.');
        }
        $role = (string)$this->session->get('admin_role', 'admin');
        $this->validateStatusTransition($applicant->currentStatus, $newStatus, $role);

        $triggeredBy = $role === 'admin' ? 'admin' : $role;
        $this->applicants->changeStatus($id, $newStatus, $triggeredBy, $description);
        $this->logger->info('Admin changed applicant status', [
            '_module'        => 'core',
            'applicant_id'   => $id,
            'from'           => $applicant->currentStatus->value,
            'to'             => $newStatus->value,
            'by'             => $this->session->get('admin_id'),
        ]);
        return $this->jsonSuccess(['changed' => true, 'new_status' => $newStatus->value]);
    }

    private function connection(): \App\Shared\Persistence\Connection
    {
        return $this->container->make(\App\Shared\Persistence\Connection::class);
    }
}
