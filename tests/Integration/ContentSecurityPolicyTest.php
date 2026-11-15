<?php

/**
 * (394) Content-Security-Policy σε φάση καταγραφής.
 *
 * Τα σενάρια που τυπώνει το WordPress παίρνουν το nonce του header, και οι
 * αναφορές του browser φτάνουν στη «Υγεία» χωρίς query string (εκεί μπορεί
 * να είναι το token της σελίδας του πελάτη).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\ContentSecurityPolicy;
use EnergyCRM\Infrastructure\CspReports;
use WP_REST_Request;

final class ContentSecurityPolicyTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new CspReports())->clear();
        wp_set_current_user(0);
    }

    public function testWordPressScriptsCarryTheSameNonceAsTheHeader(): void
    {
        $nonce = ContentSecurityPolicy::nonce();

        self::assertStringContainsString('nonce="' . $nonce . '"', wp_get_inline_script_tag('var a = 1;'));
        self::assertStringContainsString("'nonce-" . $nonce . "'", ContentSecurityPolicy::policy(false));
        self::assertStringContainsString('/ecrm/v1/csp-report', ContentSecurityPolicy::policy(false));
        self::assertStringContainsString('https://cdn.jsdelivr.net', ContentSecurityPolicy::policy(true));
        self::assertStringNotContainsString('https://cdn.jsdelivr.net', ContentSecurityPolicy::policy(false));
    }

    public function testAnAnonymousReportIsKeptWithoutTheToken(): void
    {
        $response = $this->report([
            'csp-report' => [
                'document-uri'        => home_url('/?ecrm_track=1-abcdef'),
                'effective-directive' => 'script-src-attr',
                'blocked-uri'         => 'inline',
                'line-number'         => 4,
            ],
        ]);

        self::assertSame(204, $response->get_status());

        $kept = (new CspReports())->recent();

        self::assertCount(1, $kept);
        self::assertSame('script-src-attr', $kept[0]['directive']);
        self::assertStringNotContainsString('ecrm_track', (string) $kept[0]['page']);
    }

    public function testTheSameViolationIsCountedNotRepeated(): void
    {
        $one = ['csp-report' => ['effective-directive' => 'img-src', 'blocked-uri' => 'https://x.test/a.png']];

        $this->report($one);
        $this->report($one);

        $kept = (new CspReports())->recent();

        self::assertCount(1, $kept);
        self::assertSame(2, $kept[0]['count']);
    }

    public function testGarbageIsAcceptedAndIgnored(): void
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/csp-report');
        $request->set_body('not json');

        self::assertSame(204, rest_do_request($request)->get_status());
        self::assertSame([], (new CspReports())->recent());
    }

    /** @param array<string, mixed> $body */
    private function report(array $body): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/csp-report');
        $request->set_header('Content-Type', 'application/csp-report');
        $request->set_body((string) wp_json_encode($body));

        return rest_do_request($request);
    }
}
