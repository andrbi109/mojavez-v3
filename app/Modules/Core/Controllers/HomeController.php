<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Shared\Contracts\SettingRepositoryInterface;

/**
 * HomeController — public-facing pages.
 *
 * Inherited from v2's HomeController. Phase 2 ships the route handlers;
 * the views themselves will be ported in Phase 6 (the layout pipeline
 * + Bootstrap 5 RTL + Vazirmatn fonts).
 */
final class HomeController extends Controller
{
    public function __construct(
        // Use late static binding so the parent constructor is invoked.
        \App\Container $container,
        \App\Http\Session $session,
        \App\Http\Csrf $csrf,
        \App\Shared\Support\Environment $environment,
        \App\Shared\Support\Hash $hash,
        \App\Shared\Support\StructuredLogger $logger,
        private readonly SettingRepositoryInterface $settings
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET / — landing page.
     */
    public function index(Request $request, array $params = []): Response
    {
        $siteName = $this->settings->get('site_name', 'mojavez-v3');
        $siteDescription = $this->settings->get('site_description', 'سامانهٔ صدور مجوز صنایع دستی');
        return $this->render('home', [
            'siteName'        => $siteName,
            'siteDescription' => $siteDescription,
        ]);
    }

    /**
     * GET /guide — user guide.
     */
    public function guide(Request $request, array $params = []): Response
    {
        $guideText = $this->settings->get('guide_text', '');
        return $this->render('guide', ['guideText' => $guideText]);
    }

    /**
     * GET /about — about page (content from settings).
     */
    public function about(Request $request, array $params = []): Response
    {
        $aboutText = $this->settings->get('about_text', '');
        return $this->render('about', ['aboutText' => $aboutText]);
    }
}
