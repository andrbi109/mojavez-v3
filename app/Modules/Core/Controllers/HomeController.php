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
 *
 * Phase 43 (v2.0.9) — ports ALL v2 settings to v3 views. Each public
 * page now reads every setting the v2 layout/about/guide/print pages
 * consumed (site_name, site_description, about_title, about_content,
 * owner_name, owner_username, owner_image, social_eitaa/rubika/bale,
 * guide_text) and passes them down to the view. The about() key was
 * previously `about_text` (wrong — the v2 key is `about_content`); this
 * is fixed here too.
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
     * Build the standard footer/nav social-links array shared by every
     * public-facing view. Empty IDs fall back to the owner_username so
     * the link still points somewhere sensible (mirrors v2 helper.php).
     *
     * @return array<string,array{label:string,url:string,handle:string}>
     */
    private function socialLinks(string $ownerUsername): array
    {
        $eitaa  = $this->settings->get('social_eitaa',  $ownerUsername);
        $rubika = $this->settings->get('social_rubika', $ownerUsername);
        $bale   = $this->settings->get('social_bale',   $ownerUsername);
        return [
            'eitaa'  => ['label' => 'ایتا',   'handle' => $eitaa,  'url' => $eitaa  !== '' ? 'https://eitaa.com/'  . $eitaa  : ''],
            'rubika' => ['label' => 'روبیکا', 'handle' => $rubika, 'url' => $rubika !== '' ? 'https://rubika.ir/' . $rubika : ''],
            'bale'   => ['label' => 'بله',   'handle' => $bale,   'url' => $bale   !== '' ? 'https://bale.ai/'   . $bale   : ''],
        ];
    }

    /**
     * GET / — landing page.
     */
    public function index(Request $request, array $params = []): Response
    {
        $siteName        = $this->settings->get('site_name', 'سامانهٔ مجوزهای صنایع دستی');
        $siteDescription = $this->settings->get('site_description', 'صدور مجوز صنایع دستی');
        $ownerName       = $this->settings->get('owner_name', '');
        $ownerUsername   = $this->settings->get('owner_username', '');
        $aboutTitle      = $this->settings->get('about_title', 'دربارهٔ ما');

        return $this->render('home', [
            'siteName'        => $siteName,
            'siteDescription' => $siteDescription,
            'ownerName'       => $ownerName,
            'ownerUsername'   => $ownerUsername,
            'socialLinks'     => $this->socialLinks($ownerUsername),
            'aboutTitle'      => $aboutTitle,
        ]);
    }

    /**
     * GET /guide — user guide.
     */
    public function guide(Request $request, array $params = []): Response
    {
        $siteName      = $this->settings->get('site_name', 'سامانهٔ مجوزهای صنایع دستی');
        $ownerName     = $this->settings->get('owner_name', '');
        $ownerUsername = $this->settings->get('owner_username', '');
        $guideText     = $this->settings->get('guide_text', '');

        return $this->render('guide', [
            'siteName'      => $siteName,
            'ownerName'     => $ownerName,
            'ownerUsername' => $ownerUsername,
            'socialLinks'   => $this->socialLinks($ownerUsername),
            'guideText'     => $guideText,
        ]);
    }

    /**
     * GET /about — about page (content from settings).
     *
     * Phase 43 fix: v2 uses `about_content` as the settings key; v3 was
     * previously reading `about_text` (a typo from Phase 2). Fixed here
     * so the about page actually shows the admin-configured content.
     */
    public function about(Request $request, array $params = []): Response
    {
        $siteName      = $this->settings->get('site_name', 'سامانهٔ مجوزهای صنایع دستی');
        $aboutTitle    = $this->settings->get('about_title', 'دربارهٔ ما');
        $aboutContent  = $this->settings->get('about_content', '');
        $ownerName     = $this->settings->get('owner_name', '');
        $ownerUsername = $this->settings->get('owner_username', '');
        $ownerImage    = $this->settings->get('owner_image', '');

        return $this->render('about', [
            'siteName'      => $siteName,
            'aboutTitle'    => $aboutTitle,
            'aboutContent'  => $aboutContent,
            'ownerName'     => $ownerName,
            'ownerUsername' => $ownerUsername,
            'ownerImage'    => $ownerImage,
            'socialLinks'   => $this->socialLinks($ownerUsername),
        ]);
    }
}
