<?php

/**
 * (350) Οι αλλαγές κατάστασης προς το site: τι στέλνεται, σε ποιον, πότε ξαναδοκιμάζει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\SiteIntake;

use EnergyCRM\SiteIntake\Domain\SiteStatusUpdate;
use PHPUnit\Framework\TestCase;

final class SiteStatusUpdateTest extends TestCase
{
    private const REF = 'site-20261105120000-42';

    public function testOnlyLeadsFromTheSiteAreTold(): void
    {
        self::assertNull(SiteStatusUpdate::forSite(null, 'active', 'finalisation', null, null, 1));
        self::assertNull(SiteStatusUpdate::forSite('import-123456', 'active', '', null, null, 1));
        self::assertNull(SiteStatusUpdate::forSite('site-; DROP', 'active', '', null, null, 1));
        self::assertNotNull(SiteStatusUpdate::forSite(self::REF, 'active', 'finalisation', null, null, 1));
    }

    public function testDraftAndUnknownStatusesStayInside(): void
    {
        self::assertNull(SiteStatusUpdate::forSite(self::REF, 'draft', '', null, null, 1));
        self::assertNull(SiteStatusUpdate::forSite(self::REF, 'made_up', '', null, null, 1));
    }

    public function testThePayloadCarriesTheStatusAndTheTermButNoPeople(): void
    {
        $update = SiteStatusUpdate::forSite(
            self::REF,
            'active',
            'finalisation',
            '2026-11-05',
            '2027-11-04 00:00:00',
            1793900000
        );

        self::assertSame([
            'ref'        => self::REF,
            'status'     => 'active',
            'previous'   => 'finalisation',
            'start_date' => '2026-11-05',
            'end_date'   => '2027-11-04',
            'changed_at' => 1793900000,
        ], $update?->toArray());
    }

    public function testBrokenDatesAndUnknownPreviousAreDropped(): void
    {
        $update = SiteStatusUpdate::forSite(self::REF, 'cancelled', 'whatever', '0000', '', 1);

        self::assertSame('', $update?->previous);
        self::assertNull($update?->startDate);
        self::assertNull($update?->endDate);
    }

    public function testOnlyAFirmNoStopsTheRetries(): void
    {
        foreach ([400, 404, 410, 422] as $code) {
            self::assertTrue(SiteStatusUpdate::isPermanent($code), (string) $code);
        }
        // Άλλαξε το μυστικό, ο server έπεσε, ή δεν απάντησε καθόλου: ξαναδοκιμάζει.
        foreach ([0, 401, 403, 429, 500, 503] as $code) {
            self::assertFalse(SiteStatusUpdate::isPermanent($code), (string) $code);
        }
    }

    public function testRetriesBackOffUpToSixHours(): void
    {
        self::assertSame(60, SiteStatusUpdate::retryDelay(1));
        self::assertSame(120, SiteStatusUpdate::retryDelay(2));
        self::assertSame(480, SiteStatusUpdate::retryDelay(4));
        self::assertSame(6 * 3600, SiteStatusUpdate::retryDelay(15));
        self::assertSame(6 * 3600, SiteStatusUpdate::retryDelay(SiteStatusUpdate::MAX_ATTEMPTS));
    }

    public function testTheSiteAddressMustBeHttpsUnlessItIsLocal(): void
    {
        self::assertTrue(SiteStatusUpdate::validUrl('https://priza.myreuma.gr/api/crm/status'));
        self::assertTrue(SiteStatusUpdate::validUrl('http://energy-site.test/api/crm/status'));
        self::assertTrue(SiteStatusUpdate::validUrl('http://localhost:8010/api/crm/status'));
        self::assertFalse(SiteStatusUpdate::validUrl('http://priza.myreuma.gr/api/crm/status'));
        self::assertFalse(SiteStatusUpdate::validUrl('ftp://priza.myreuma.gr/x'));
        self::assertFalse(SiteStatusUpdate::validUrl('not a url'));
        self::assertFalse(SiteStatusUpdate::validUrl(''));
    }
}
