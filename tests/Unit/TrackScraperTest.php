<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Regression test for App\Modules\NationalGateway\Infrastructure\TrackScraper.
 *
 * Loads the committed HAR fixture (tests/fixtures/har/qr.mojavez.ir-track-I17981871.har)
 * and feeds the track-page HTML through a stub HttpClient (no cURL) to the
 * real TrackScraper. Locks the expected extracted values so any change to
 * the scraper that breaks extraction fails this test before commit.
 *
 * This is the PHP-native replacement for the gateway's Python
 * scripts/test_track_scraper.py — now that PHP is available in the dev env,
 * we test the actual PHP code, not a Python port.
 */
final class TrackScraperTest extends TestCase
{
    private string $fixturePath;

    public function name(): string
    {
        return 'TrackScraperTest';
    }

    public function run(): void
    {
        $this->fixturePath = dirname(__DIR__, 2) . '/tests/fixtures/har/qr.mojavez.ir-track-I17981871.har';
        if (!is_file($this->fixturePath)) {
            $this->recordFailure('HAR fixture not found: ' . $this->fixturePath);
            return;
        }

        // Build a stub HttpClient that returns the fixture's track-page HTML
        // instead of making a real HTTP request.
        $stubHttp = new StubHttpClient($this->fixturePath);
        $logger = new \App\Shared\Support\StructuredLogger(sys_get_temp_dir() . '/trackscraper-test.log', 'debug');
        $scraper = new \App\Modules\NationalGateway\Infrastructure\TrackScraper(
            $stubHttp,
            'https://qr.mojavez.ir',
            $logger
        );

        $result = $scraper->fetch('I17981871', false);

        // 1. Found flag + no error.
        $this->assertTrue($result['found'], 'found flag true');
        $this->assertNull($result['error'], 'no error');

        // 2. License data object extracted.
        $this->assertSame('کسب و کار خانگی دوخت چادر شب باف', $result['license_title'], 'license_title');
        $this->assertSame('131202', $result['isic_code'], 'isic_code');
        $this->assertSame('نساجی سنتی', $result['isic_title'], 'isic_title');
        $this->assertSame('ثبت محور', $result['issuance_type'], 'issue_type');

        // 3. Status object.
        $this->assertNotNull($result['status'], 'status object present');
        $this->assertSame('active', $result['status']['status_slug'], 'status_slug');
        $this->assertSame('معتبر', $result['status']['status_title'], 'status_title');

        // 4. Applicant identity (label→value extraction from RSC tree).
        $this->assertSame('عبدالكریم', $result['father_name'], 'father_name');
        $this->assertSame('073****269', $result['national_id'], 'national_id (masked)');
        // Mobile is publicly empty on the upstream page.
        $this->assertNull($result['mobile'], 'mobile (empty upstream)');

        // 5. Business location.
        $this->assertSame('خراسان رضوی', $result['business_province'], 'business_province');
        $this->assertSame('تربت جام', $result['business_township'], 'business_township');
        $this->assertSame('9571968***', $result['postal_code'], 'postal_code (masked)');

        // 6. Business history table — 1 row for this fixture.
        $this->assertCount(1, $result['business_history'], 'history_count');
        if (!empty($result['business_history'])) {
            $row = $result['business_history'][0];
            $this->assertSame('صدور مجوز', $row['operation'], 'history_0_operation');
            $this->assertSame('1405-08-17', $row['date'], 'history_0_date');
        }

        // 7. Tracking code echoes the request number.
        $this->assertSame('I17981871', $result['tracking_code'], 'tracking_code');
    }
}

/**
 * Stub HttpClient that reads a HAR fixture and returns the track-page HTML
 * as if it were a real upstream response. Lets us test TrackScraper without
 * cURL or network access.
 */
final class StubHttpClient implements \App\Modules\NationalGateway\Infrastructure\HttpClientInterface
{
    private string $html;
    private string $trackUrl;

    public function __construct(string $harPath)
    {
        $har = json_decode((string)file_get_contents($harPath), true);
        $this->html = '';
        $this->trackUrl = '';
        if (!is_array($har)) {
            return;
        }
        foreach ($har['log']['entries'] ?? [] as $entry) {
            $url = $entry['request']['url'] ?? '';
            if (str_contains($url, '/track/')) {
                $this->html = (string)($entry['response']['content']['text'] ?? '');
                $this->trackUrl = $url;
                break;
            }
        }
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $contentType = null
    ): \App\Modules\NationalGateway\Infrastructure\HttpResponse {
        // Advanced-data endpoint — return empty so specialized_items is [].
        if (str_contains($url, '/advanced-data')) {
            return new \App\Modules\NationalGateway\Infrastructure\HttpResponse(
                200,
                '{"data":{"biServices":{"advanceData":{"Status":"ERROR","Result":[]}}}}',
                [],
                0.0
            );
        }
        // Track page — return the fixture HTML.
        return new \App\Modules\NationalGateway\Infrastructure\HttpResponse(
            200,
            $this->html,
            ['content-type' => 'text/html; charset=utf-8'],
            0.1
        );
    }
}

return new TrackScraperTest();
