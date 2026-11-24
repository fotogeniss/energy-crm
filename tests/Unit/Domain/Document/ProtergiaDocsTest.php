<?php

/**
 * (432) Τα δικαιολογητικά της Protergia (Back Office 19-20/11, διευκρινίσεις 23/11).
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

    /** (432β) Αέριο, επανασύνδεση: λογαριασμός, αλλιώς ΗΚΑΣΠ ΜΑΖΙ με μισθωτήριο/Ε9. */
    public function testGasReconnectionNeedsTheProofTogetherWithTheTitle(): void
    {
        $r = $this->req(['activation' => 'reconnection', 'energy' => 'gas']);

        self::assertSame(['id', 'proof', 'title'], array_column($r['required'], 'key'));
        self::assertSame(['provider_bill', 'connection_contract'], $r['required'][1]['kinds']);
        self::assertSame(['lease', 'e9'], $r['required'][2]['kinds']);
        self::assertSame(['provider_bill'], $r['required'][2]['unless']);
    }

    /** (432β) Αέριο, αλλαγή προμηθευτή: η φωτογραφία της ΕΔΑ Θεσσαλονίκης είναι προαιρετική. */
    public function testGasChangeOfSupplierReminders(): void
    {
        $r = $this->req(['activation' => 'change_provider', 'energy' => 'gas']);

        self::assertStringContainsString('προαιρετική', implode(' | ', $r['reminders']));
        self::assertStringContainsString('αλλάζει και ο χρήστης', implode(' | ', $r['reminders']));
    }

    public function testRenewalAndProgramChangeFallBack(): void
    {
        self::assertNull(ProtergiaDocs::requirements(['activation' => 'renewal', 'energy' => 'power']));
        self::assertNull(ProtergiaDocs::requirements(['activation' => 'program_change', 'energy' => 'gas']));
    }

    /**
     * (454) Back Office 23/11: αέριο, «Άλλες Κατηγορίες Πελατών» = τα ίδια, και σε
     * ατομική η έναρξη δραστηριότητας, σε εταιρεία καταστατικό και ΓΕΜΗ.
     */
    public function testGasCompanyPapers(): void
    {
        $gas = ['activation' => 'new_connection', 'energy' => 'gas', 'customer_type' => 'company'];

        $company = $this->req($gas + ['company_type' => 'Ι.Κ.Ε.']);
        self::assertSame(['id', 'connection', 'statute'], array_column($company['required'], 'key'));
        self::assertSame(['gemi'], $company['required'][2]['kinds']);
        self::assertStringContainsString('άλλα στοιχεία', $company['reminders'][0]);

        $sole = $this->req($gas + ['company_type' => 'Ατομική']);
        self::assertSame(['id', 'connection', 'kad'], array_column($sole['required'], 'key'));

        // Χωρίς νομική μορφή: και τα δύο.
        self::assertSame(['id', 'connection', 'kad', 'statute'], array_column($this->req($gas)['required'], 'key'));

        // Οικιακό αέριο: τίποτα από αυτά.
        $home = $this->req(['activation' => 'new_connection', 'energy' => 'gas', 'category' => 'home']);
        self::assertSame(['id', 'connection'], array_column($home['required'], 'key'));
    }

    /** (454) Διαδοχή λόγω θανάτου: υπενθύμιση, σε ρεύμα και αέριο. */
    public function testSuccessionRemindsAboutDeathCertificates(): void
    {
        foreach (['power', 'gas'] as $energy) {
            $r = $this->req(['activation' => 'succession', 'energy' => $energy, 'category' => 'home']);

            self::assertStringContainsString('πιστοποιητικό θανάτου', implode(' | ', $r['reminders']), $energy);
        }
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
