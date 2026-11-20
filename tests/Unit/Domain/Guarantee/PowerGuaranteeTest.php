<?php

/**
 * (431) Εγγύηση ρεύματος Protergia, πίνακες «Εγγυήσεις ΗΕ» v16 (σελ. 7 και 8).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Guarantee;

use EnergyCRM\Domain\Guarantee\PowerGuarantee;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PowerGuaranteeTest extends TestCase
{
    /** @return iterable<string, array{float, string, string, float}> */
    public static function amounts(): iterable
    {
        // Οικιακά: ίδια ποσά για ίδιο και νέο χρήστη.
        yield 'οικιακό 8 kVA'           => [8.0, 'home', 'change_provider', 68.0];
        yield 'οικιακό 25 kVA'          => [25.0, 'home', 'succession', 68.0];
        yield 'οικιακό 35 kVA'          => [35.0, 'home', 'change_provider', 110.0];
        yield 'οικιακό 55 kVA'          => [55.0, 'home', 'new_connection', 110.0];
        yield 'οικιακό 85 kVA'          => [85.0, 'home', 'change_provider', 735.0];
        yield 'κοινόχρηστο 25 kVA'      => [25.0, 'communal', 'change_provider', 68.0];
        // Κοινόχρηστο από 35 kVA: ο πίνακας των επαγγελματικών.
        yield 'κοινόχρηστο 35 kVA'      => [35.0, 'communal', 'change_provider', 480.0];
        // Επαγγελματικά, ίδιος χρήστης: ελάχιστη εγγύηση.
        yield 'επαγγ. 8 ίδιος'          => [8.0, 'business', 'change_provider', 130.0];
        yield 'επαγγ. 25 ίδιος'         => [25.0, 'business', 'change_provider', 170.0];
        yield 'επαγγ. 250 ίδιος'        => [250.0, 'business', 'change_provider', 4600.0];
        // Επαγγελματικά, νέος χρήστης.
        yield 'επαγγ. 8 νέος'           => [8.0, 'business', 'succession', 135.0];
        yield 'επαγγ. 12 νέος'          => [12.0, 'business', 'reconnection', 220.0];
        yield 'επαγγ. 25 νέος'          => [25.0, 'business', 'new_connection', 340.0];
        yield 'επαγγ. 135 νέος'         => [135.0, 'business', 'succession', 2900.0];
        yield 'επαγγ. 300 νέος'         => [300.0, 'business', 'succession', 5350.0];
    }

    #[DataProvider('amounts')]
    public function testTheTable(float $kva, string $category, string $activation, float $expected): void
    {
        $out = PowerGuarantee::suggest($kva, $category, 'manual', $activation);

        self::assertNotNull($out);
        self::assertSame($expected, $out['amount']);
    }

    public function testStandingOrderIsZero(): void
    {
        $out = PowerGuarantee::suggest(25.0, 'business', 'standing_order', 'succession');

        self::assertSame(0.0, $out['amount'] ?? null);
    }

    public function testKotOnlyForTheSameHomeUser(): void
    {
        $amount = static fn (float $kva, string $cat, string $act, string $special): ?float
            => PowerGuarantee::suggest($kva, $cat, '', $act, $special)['amount'] ?? null;

        self::assertSame(40.0, $amount(8.0, 'home', 'change_provider', 'ΚΟΤ Β'));
        self::assertSame(40.0, $amount(8.0, 'home', 'change_provider', 'Ευάλωτος Πελάτης'));
        self::assertSame(68.0, $amount(8.0, 'home', 'succession', 'ΚΟΤ Α'));
        self::assertSame(170.0, $amount(25.0, 'business', 'change_provider', 'ΚΟΤ Α'));
    }

    public function testTheSameBusinessUserIsToldTheOtherAmount(): void
    {
        $out = PowerGuarantee::suggest(25.0, 'business', '', 'change_provider');

        self::assertNotNull($out);
        self::assertStringContainsString('μεγαλύτερη', $out['reason']);
        self::assertStringContainsString('340 €', $out['reason']);
    }

    public function testNoSuggestionWhereTheTableDoesNotApply(): void
    {
        self::assertNull(PowerGuarantee::suggest(8.0, 'home', '', 'renewal'));
        self::assertNull(PowerGuarantee::suggest(8.0, 'home', '', 'program_change'));
        self::assertNull(PowerGuarantee::suggest(8.0, 'mobile', '', 'change_provider'));
    }

    public function testWithoutPowerTheRulesDecide(): void
    {
        self::assertNull(PowerGuarantee::suggest(0.0, 'home', '', 'change_provider'));
    }
}
