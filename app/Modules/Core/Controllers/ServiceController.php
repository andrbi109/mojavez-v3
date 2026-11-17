<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Shared\Contracts\ServiceTypeRepositoryInterface;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;

/**
 * ServiceController — public list of services + purchase (wizard entry).
 *
 * Phase 15 (v1.6.0): the `/services` page is a public catalog of the
 * service_types. Clicking "انتخاب و شروع ثبت‌نام" on a card POSTs to
 * `/services/{id}/purchase`, which requires user login and redirects
 * to `/register/service/{id}` to start the wizard.
 *
 * Routes:
 *   GET  /services                  → index       (public, no auth)
 *   POST /services/{id}/purchase    → purchase   (requireUserLogin + redirect)
 */
final class ServiceController extends Controller
{
    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly ServiceTypeRepositoryInterface $serviceTypes,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /services — public list of service_types as Bootstrap cards.
     */
    public function index(Request $request, array $params = []): Response
    {
        $serviceTypes = $this->serviceTypes->getActive();
        return $this->render('services/list', [
            'title'        => 'خدمات',
            'serviceTypes' => $serviceTypes,
        ]);
    }

    /**
     * POST /services/{id}/purchase — start the wizard for this service.
     *
     * Requires user login; on success redirects to /register/service/{id}
     * which creates the in-progress applicants row.
     */
    public function purchase(Request $request, array $params = []): Response
    {
        $this->requireUserLogin();
        $serviceTypeId = (int)($params['id'] ?? 0);
        $serviceType = $this->serviceTypes->findById($serviceTypeId);
        if ($serviceType === null) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>نوع خدمت یافت نشد.</p>',
                404
            );
        }
        return $this->redirect('/register/service/' . $serviceTypeId);
    }
}
