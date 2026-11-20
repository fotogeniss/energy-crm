<?php

/**
 * (432) Τα δικαιολογητικά της Protergia (Back Office 19-20/11).
 *
 * @kanonas K4
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Document;

use EnergyCRM\Domain\Document\ProtergiaDocs;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type DocList from ProtergiaDocs
 */
final class ProtergiaDocsTest extends TestCase
{
    public function testOnlyNewProtergiaPowerAndGas(): void
    {
        self::assertTrue(ProtergiaDocs::applies('protergia', 'power', ''));
        self::assertTrue(ProtergiaDocs::applies('protergia', 'gas', ProtergiaDocs::FROM));
        // Όσες τρέχουν ήδη δεν κολλάνε.
        self::assertFalse(ProtergiaDocs::applies('protergia', 'power', '2026-11-19 10:00:00'));
        self::assertFalse(ProtergiaDocs::applies('protergia', 'mobile', ''));
        self::assertFalse(ProtergiaDocs::applies('volton', 'power', ''));
    }

    public function testHomeChangeOfSupplierAsksForReadings(): void
    {
        $r = $this->req(['activation' => 'change_provider', 'energy' => 'power', 'category' => 'home']);

        self::assertSame(['id', 'bill', 'readings'], array_column($r['required'], 'key'));
        self::assertSame('day_indication', $r['required'][2]['field']);
        self::assertNotEmpty($r['reminders']);
    }

    public function testBusinessFrom35KvaNeedsNoReadings(): void
    {
        $base = ['activation' => 'change_provider', 'energy' => 'power', 'category' => 'business'];

        self::assertContains('readings', array_column($this->req($base + ['kva' => 25.0])['required'], 'key'));
        self::assertNotContains('readings', array_column($this->req($base + ['kva' => 35.0])['required'], 'key'));
    }

    public function testReconnectionIsOneOfThree(): void
    {
        $r     = $this->req(['activation' => 'reconnection', 'energy' => 'power', 'category' => 'home']);
        $right = array_values(array_filter($r['required'], static fn (array $i): bool => $i['key'] === 'right'))[0];

        self::assertSame(['e9', 'lease', 'provider_bill'], $right['kinds']);
        self::assertContains('meter_photo', array_column($r['required'], 'key'));
    }

    public function testCompanyPapers(): void
    {
        $company = [
            'activation'    => 'succession',
            'energy'        => 'power',
            'category'      => 'business',
            'customer_type' => 'company',
        ];

        $keys = array_column($this->req($company + ['company_type' => 'Α.Ε.'])['required'], 'key');
        self::assertSame(['kad', 'statute', 'fek'], array_slice($keys, -3));

        $sole = array_column($this->req($company + ['company_type' => 'Ατομική επιχείρηση'])['required'], 'key');
        self::assertContains('kad', $sole);
        self::assertNotContains('fek', $sole);

        // Χωρίς νομική μορφή: και τα τρία, αλλιώς μια Α.Ε. θα περνούσε χωρίς ΦΕΚ.
        self::assertContains('fek', array_column($this->req($company)['required'], 'key'));
    }

    public function testGasHasNoCompanyPapersAndRenewalFallsBack(): void
    {
        $gas = $this->req(['activation' => 'new_connection', 'energy' => 'gas', 'customer_type' => 'company']);

        self::assertSame(['id', 'connection'], array_column($gas['required'], 'key'));
        self::assertNull(ProtergiaDocs::requirements(['activation' => 'renewal', 'energy' => 'power']));
        self::assertNull(ProtergiaDocs::requirements(['activation' => 'program_change', 'energy' => 'gas']));
    }

    /**
     * @param array{activation: string, energy: string, category?: string, customer_type?: string,
     *              company_type?: string, kva?: float} $c
     * @return DocList
     */
    private function req(array $c): array
    {
        $r = ProtergiaDocs::requirements($c);
        self::assertNotNull($r);

        return $r;
    }
}
