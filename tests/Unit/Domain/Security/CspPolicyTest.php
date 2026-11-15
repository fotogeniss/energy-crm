<?php

/**
 * (394) Η Content-Security-Policy ως κείμενο, και οι αναφορές του browser.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Security;

use EnergyCRM\Domain\Security\CspPolicy;
use PHPUnit\Framework\TestCase;

final class CspPolicyTest extends TestCase
{
    public function testScriptsOnlyFromTheSiteOrWithTheNonce(): void
    {
        $h = CspPolicy::header('abc123', 'https://crm.test/wp-json/ecrm/v1/csp-report');

        self::assertStringContainsString("script-src 'self' 'nonce-abc123';", $h);
        self::assertStringNotContainsString("'unsafe-eval'", $h);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $h);
        self::assertStringContainsString("object-src 'none'", $h);
        self::assertStringContainsString("frame-ancestors 'self'", $h);
        self::assertStringContainsString("connect-src 'self';", $h);
        self::assertStringEndsWith('report-uri https://crm.test/wp-json/ecrm/v1/csp-report', $h);
    }

    public function testExtraScriptHostsOnlyWhenAsked(): void
    {
        self::assertStringNotContainsString('jsdelivr', CspPolicy::header('n', ''));
        self::assertStringContainsString(
            "script-src 'self' 'nonce-n' https://cdn.jsdelivr.net;",
            CspPolicy::header('n', '', ['https://cdn.jsdelivr.net'])
        );
        self::assertStringNotContainsString('report-uri', CspPolicy::header('n', ''));
    }

    public function testTheOldReportShapeLosesItsQueryString(): void
    {
        $r = CspPolicy::normalise(['csp-report' => [
            'document-uri'        => 'https://crm.test/?ecrm_track=12-secret-token#x',
            'effective-directive' => 'script-src-elem',
            'blocked-uri'         => 'https://evil.test/a.js?k=v',
            'line-number'         => 12,
        ]]);

        self::assertSame([
            'directive' => 'script-src-elem',
            'blocked'   => 'https://evil.test/a.js',
            'page'      => 'https://crm.test/',
            'line'      => 12,
        ], $r);
    }

    public function testTheReportingApiShapeToo(): void
    {
        $r = CspPolicy::normalise([
            'type' => 'csp-violation',
            'body' => [
                'documentURL'        => 'https://crm.test/app/',
                'effectiveDirective' => 'script-src-attr',
                'blockedURL'         => 'inline',
                'lineNumber'         => 3,
            ],
        ]);

        self::assertNotNull($r);
        self::assertSame('script-src-attr', $r['directive']);
        self::assertSame('inline', $r['blocked']);
    }

    public function testGarbageIsDropped(): void
    {
        self::assertNull(CspPolicy::normalise([]));
        self::assertNull(CspPolicy::normalise(['csp-report' => 'x']));
        self::assertNull(CspPolicy::normalise(['csp-report' => ['effective-directive' => '<b>script']]));
        self::assertNull(CspPolicy::normalise(['csp-report' => ['effective-directive' => ['script-src']]]));
        self::assertNull(CspPolicy::normalise(['type' => 'deprecation', 'body' => ['effectiveDirective' => 'x']]));
    }

    public function testLongValuesAreCutAndControlCharactersRemoved(): void
    {
        $r = CspPolicy::normalise(['csp-report' => [
            'effective-directive' => 'img-src',
            'blocked-uri'         => "https://a.test/\n" . str_repeat('x', 500),
        ]]);

        self::assertNotNull($r);
        self::assertSame(200, mb_strlen($r['blocked']));
        self::assertStringNotContainsString("\n", $r['blocked']);
    }

    public function testSameViolationSameKey(): void
    {
        $a = ['directive' => 'img-src', 'blocked' => 'x', 'page' => 'p', 'line' => 1];

        self::assertSame(CspPolicy::key($a), CspPolicy::key($a));
        self::assertNotSame(CspPolicy::key($a), CspPolicy::key(['line' => 2] + $a));
    }
}
