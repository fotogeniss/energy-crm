<?php

/**
 * (326) Τι περνάει από μια αίτηση στη δίδυμή της (ρεύμα ↔ αέριο).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\CompanionDraft;
use PHPUnit\Framework\TestCase;

final class CompanionDraftTest extends TestCase
{
    public function testTheTwinOfPowerIsGasAndBackAndMobileHasNone(): void
    {
        self::assertSame('gas', CompanionDraft::target('power'));
        self::assertSame('power', CompanionDraft::target('gas'));
        self::assertSame('', CompanionDraft::target('mobile'));
        self::assertSame('', CompanionDraft::target(''));
        self::assertSame([], CompanionDraft::from(['energy_type' => 'mobile', 'customer_id' => 5]));
    }

    public function testTheCustomerCarriesOverButNotTheSupplyNorTheAgreement(): void
    {
        $draft = CompanionDraft::from($this->power());

        self::assertSame('gas', $draft['energy_type']);
        $carried = [
            'customer_id' => 7, 'provider_id' => 2, 'customer_type' => 'company', 'category' => 'business',
            'supply_street' => 'Αγίου Νικολάου', 'billing_city' => 'Χαλάνδρι',
        ];
        foreach ($carried as $k => $v) {
            self::assertSame($v, $draft[$k], $k);
        }

        // Η παροχή, το πρόγραμμα και η συμφωνία μένουν πίσω.
        $left = [
            'program_id', 'price_type', 'supply_number', 'meter_number', 'invoice_code', 'status',
            'code', 'consent_at', 'consent_ip', 'signed_at', 'signed_ip', 'start_date', 'end_date',
            'term_months', 'payout_id', 'notes', 'extracted_json',
        ];
        foreach ($left as $k) {
            self::assertArrayNotHasKey($k, $draft, $k);
        }
    }

    public function testTheElectricitySupplyNumberGoesToThePowerPlusGasFieldOfTheGasApplication(): void
    {
        $extra = json_decode(CompanionDraft::from($this->power())['extra_json'], true);

        self::assertSame('1 23456789-01', $extra['gas_power_supply_number']);
        self::assertSame('Μιχάλης', $extra['rep_first_name']);
        self::assertSame('standing_order', $extra['payment_method']);
        self::assertSame('yes', $extra['group_data_consent']);

        // Ό,τι είναι της παροχής ρεύματος δεν περνά στο αέριο.
        $supplyOnly = [
            'agreed_power', 'supply_phases', 'day_indication', 'guarantee', 'previous_provider',
            'promotion', 'eidiki_katigoria', 'meter_reading_type',
        ];
        foreach ($supplyOnly as $k) {
            self::assertArrayNotHasKey($k, $extra, $k);
        }
    }

    public function testFromGasTheKnownElectricitySupplyNumberBecomesTheSupplyOfThePowerApplication(): void
    {
        $gas = [
            'energy_type' => 'gas', 'customer_id' => 7, 'provider_id' => 2, 'supply_number' => 'GR-HKASP',
            'extra_json' => json_encode(['gas_power_supply_number' => ' 1 23456789-01 ', 'gas_meter_type' => 'G4']),
        ];

        $draft = CompanionDraft::from($gas);
        self::assertSame('power', $draft['energy_type']);
        self::assertSame('1 23456789-01', $draft['supply_number'], 'όχι ο ΗΚΑΣΠ του αερίου');
        self::assertArrayNotHasKey('extra_json', $draft, 'τύπος μετρητή και αριθμός παροχής δεν είναι του πελάτη');

        // Χωρίς γνωστό αριθμό ρεύματος: κενός, ποτέ ο ΗΚΑΣΠ.
        $gas['extra_json'] = json_encode(['gas_meter_type' => 'G4']);
        self::assertArrayNotHasKey('supply_number', CompanionDraft::from($gas));
    }

    public function testTheNoteSaysWhereItCameFrom(): void
    {
        $power = ['code' => 'PR-7190', 'energy_type' => 'power'];
        $gas   = ['code' => 'PR-7191', 'energy_type' => 'gas'];
        self::assertSame('Μαζί με PR-7190 (ρεύμα)', CompanionDraft::note($power));
        self::assertSame('Μαζί με PR-7191 (αέριο)', CompanionDraft::note($gas));
    }

    /** @return array<string, mixed> */
    private function power(): array
    {
        return [
            'id' => 90, 'code' => 'PR-7190', 'energy_type' => 'power', 'status' => 'presale',
            'customer_id' => 7, 'provider_id' => 2, 'program_id' => 51, 'price_type' => 'fixed',
            'customer_type' => 'company', 'category' => 'business', 'activation_type' => 'change_provider',
            'supply_number' => '1 23456789-01', 'meter_number' => 'M1', 'invoice_code' => 'X',
            'supply_street' => 'Αγίου Νικολάου', 'supply_street_no' => '18', 'supply_city' => 'Πειραιάς',
            'billing_street' => 'Ερμού', 'billing_city' => 'Χαλάνδρι', 'billing_region' => '',
            'consent_at' => '2026-10-29 10:00:00', 'consent_ip' => '1.2.3.4', 'signed_at' => '2026-10-29 10:05:00',
            'signed_ip' => '1.2.3.4', 'start_date' => '2026-11-01', 'end_date' => '2027-11-01', 'term_months' => 12,
            'payout_id' => 3, 'notes' => 'x', 'extracted_json' => '{}',
            'extra_json' => json_encode([
                'rep_first_name' => 'Μιχάλης', 'payment_method' => 'standing_order', 'group_data_consent' => 'yes',
                'agreed_power' => '25', 'supply_phases' => 'three', 'day_indication' => '4521', 'guarantee' => '0',
                'previous_provider' => 'ΔΕΗ', 'promotion' => 'x', 'eidiki_katigoria' => 'ΚΟΤ Α',
                'meter_reading_type' => 'day_night',
            ], JSON_UNESCAPED_UNICODE),
        ];
    }
}
