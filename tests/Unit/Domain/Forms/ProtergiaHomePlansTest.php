<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\ProtergiaHomePlans;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtergiaHomePlansTest extends TestCase
{
    /** @return list<array{string}> */
    public static function plans(): array
    {
        return array_map(static fn (string $code): array => [$code], ProtergiaHomePlans::codes());
    }

    public function testTheFourTariffsProtergiaPrintsAreAllHere(): void
    {
        self::assertSame(
            [
                'protergia_oik_sure12',
                'protergia_oik_sure18',
                'protergia_oik_lite2',
                'protergia_oik_bright',
            ],
            ProtergiaHomePlans::codes()
        );
    }

    /**
     * A plan whose template key differed from its code would be two names for
     * one thing, and the second one would drift.
     */
    #[DataProvider('plans')]
    public function testAPlanPrintsOnTheSheetNamedAfterIt(string $code): void
    {
        self::assertSame($code, ProtergiaHomePlans::templateKey($code));
    }

    /**
     * The bundled background and map must exist for every plan, or the sheet
     * fails to render at the moment an agent presses print.
     */
    #[DataProvider('plans')]
    public function testEveryPlanHasItsBundledTemplate(string $code): void
    {
        $dir = dirname(__DIR__, 4) . '/assets/forms/';

        self::assertFileExists($dir . $code . '.json');

        // Έξι σελίδες: 3 αίτησης + 3 όρων. Λείπει μία → το έντυπο τυπώνεται
        // κουτσό χωρίς κανένα σφάλμα, γιατί ο βρόχος σταματά στο πρώτο κενό.
        for ($page = 1; $page <= 6; $page++) {
            self::assertFileExists($dir . $code . '-' . $page . '.jpg');
        }
    }

    /**
     * A tariff we have no sheet for must say so, not hand back a sheet naming
     * some other tariff.
     */
    public function testAnUnknownPlanHasNoTemplate(): void
    {
        self::assertSame('', ProtergiaHomePlans::templateKey('protergia_picasso_2_0'));
        self::assertSame('', ProtergiaHomePlans::templateKey(''));
        self::assertFalse(ProtergiaHomePlans::exists('orizon_5gb'));
    }

    /**
     * The floating tariffs price off the day-ahead market, so they have no
     * fixed rate. Recording an invented one would show in the agent's dropdown
     * as though it were the price the customer pays.
     */
    public function testOnlyTheFixedTariffsCarryARate(): void
    {
        $plans = ProtergiaHomePlans::all();

        self::assertSame(0.269, $plans[ProtergiaHomePlans::SURE_12]['priceKwh']);
        self::assertSame(0.259, $plans[ProtergiaHomePlans::SURE_18]['priceKwh']);
        self::assertNull($plans[ProtergiaHomePlans::LITE_2]['priceKwh']);
        self::assertNull($plans[ProtergiaHomePlans::BRIGHT]['priceKwh']);

        self::assertSame('fixed', $plans[ProtergiaHomePlans::SURE_12]['priceType']);
        self::assertSame('variable', $plans[ProtergiaHomePlans::BRIGHT]['priceType']);
    }

    /**
     * The standing charges as printed on the four forms. They reach the agent's
     * dropdown, so a typo here is a price quoted wrong on the phone.
     */
    public function testTheStandingChargesMatchTheForms(): void
    {
        $plans = ProtergiaHomePlans::all();

        self::assertSame(9.90, $plans[ProtergiaHomePlans::SURE_12]['fixedCharge']);
        self::assertSame(11.90, $plans[ProtergiaHomePlans::SURE_18]['fixedCharge']);
        self::assertSame(0.00, $plans[ProtergiaHomePlans::LITE_2]['fixedCharge']);
        self::assertSame(5.00, $plans[ProtergiaHomePlans::BRIGHT]['fixedCharge']);
    }
}
