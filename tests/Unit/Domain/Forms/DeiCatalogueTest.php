<?php

/**
 * (380) ΔΕΗ: κατάλογος, τιμές και έντυπα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\DeiCatalogue;
use EnergyCRM\Domain\Forms\FormTemplates;
use EnergyCRM\Domain\Pricing\DeiPrices2026;
use EnergyCRM\Domain\Pricing\ProgramPricing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeiCatalogueTest extends TestCase
{
    private static function formsDir(): string
    {
        return dirname(__DIR__, 4) . '/assets/forms';
    }

    /** @return array<string, mixed> */
    private static function map(string $key): array
    {
        $map = json_decode((string) file_get_contents(FormTemplates::mapPath(self::formsDir(), $key)), true);
        self::assertIsArray($map, $key);

        return $map;
    }

    public function testThirteenElectricityProgramsWithCodesThatFitTheColumn(): void
    {
        self::assertCount(13, DeiCatalogue::all());

        foreach (DeiCatalogue::all() as $code => $plan) {
            self::assertStringStartsWith('dei_', $code);
            self::assertLessThanOrEqual(32, strlen($code), $code);
            self::assertContains($plan['priceType'], ['fixed', 'variable', 'dynamic', 'special'], $code);
            self::assertContains($plan['category'], ['home', 'business'], $code);
            self::assertSame($plan['priceType'] === 'dynamic', $plan['dynamic'], $code);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        foreach (DeiCatalogue::templates() as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('templates')]
    public function testEverySheetIsOnDisk(string $key): void
    {
        self::assertSame('dei', FormTemplates::provider($key));
        self::assertFileExists(FormTemplates::pagePath(self::formsDir(), $key, 1));
        self::assertNotSame([], FormTemplates::pages(self::formsDir(), $key, self::map($key)));
    }

    public function testTheApplicationDependsOnPowerAndOnDynamicPricing(): void
    {
        self::assertSame(DeiCatalogue::APP_SMALL, DeiCatalogue::application('dei_myhome_enter', 0.0));
        self::assertSame(DeiCatalogue::APP_SMALL, DeiCatalogue::application('dei_mybusiness_enter', 25.0));
        self::assertSame(DeiCatalogue::APP_LARGE, DeiCatalogue::application('dei_mybusiness_enter', 25.1));
        self::assertSame(DeiCatalogue::APP_DYN_SMALL, DeiCatalogue::application('dei_myhome_dynamic', 12.0));
        self::assertSame(DeiCatalogue::APP_DYN_LARGE, DeiCatalogue::application('dei_mybusiness_dynamic', 50.0));
        // Πρόγραμμα φτιαγμένο με το χέρι: η απλή αίτηση.
        self::assertSame(DeiCatalogue::APP_SMALL, DeiCatalogue::application('', 0.0));
    }

    public function testThePaperworkFollowsTheOrderOfTheApplication(): void
    {
        self::assertSame(
            ['dei_aitisi_mikrou', 'dei_eo_myhome_enter', 'dei_gos_mikrou'],
            DeiCatalogue::paperwork('dei_myhome_enter', 8.0)
        );
        self::assertSame(
            ['dei_aitisi_megalou', 'dei_eo_mybusiness_enter', 'dei_gos_megalou'],
            DeiCatalogue::paperwork('dei_mybusiness_enter', 35.0)
        );
        self::assertSame(
            ['dei_aitisi_dyn_mikrou', 'dei_eo_myhome_dynamic', 'dei_gos_mikrou',
            'dei_prosymv_oikiakou'],
            DeiCatalogue::paperwork('dei_myhome_dynamic', 0.0)
        );
        self::assertSame(
            ['dei_aitisi_dyn_megalou', 'dei_eo_mybusiness_dynamic', 'dei_gos_dyn_megalou',
            'dei_prosymv_epaggelmatikou'],
            DeiCatalogue::paperwork('dei_mybusiness_dynamic', 40.0)
        );
        self::assertSame(['dei_aitisi_mikrou', 'dei_gos_mikrou'], DeiCatalogue::paperwork('', 0.0));
    }

    public function testTypedPowerIsRead(): void
    {
        self::assertSame(8.0, DeiCatalogue::kva('8'));
        self::assertSame(17.3, DeiCatalogue::kva('17,3 kVA'));
        self::assertSame(0.0, DeiCatalogue::kva(''));
        self::assertSame(0.0, DeiCatalogue::kva('—'));
    }

    public function testTheApplicationsAskForWhatTheDeiFormAsks(): void
    {
        $apps = [
            DeiCatalogue::APP_SMALL, DeiCatalogue::APP_LARGE,
            DeiCatalogue::APP_DYN_SMALL, DeiCatalogue::APP_DYN_LARGE,
        ];

        foreach ($apps as $key) {
            $map = self::map($key);

            foreach (
                ['arithmos_aitisis', 'kodikos_synergati', 'onoma_timologiou', 'topos_aitisis', 'imerominia_aitisis',
                'onomateponymo_pelati', 'patronymo_pelati', 'afm_pelati', 'doy_pelati', 'adt_pelati',
                'eponymia_etaireias', 'onomateponymo_ekprosopou', 'tasi_paroxis', 'isxis_paroxis',
                'odos_paroxis', 'arithmos_odou_paroxis', 'tk_paroxis', 'arithmos_paroxis',
                'odos_arithmos_apostolis', 'kinito_pelati', 'email_pelati'] as $field
            ) {
                self::assertArrayHasKey($field, $map['fields'], $key . ': ' . $field);
            }

            self::assertCount(1, $map['sigs'], $key);
        }
    }

    /**
     * Υπογραφή στους Ειδικούς Όρους μόνο όπου τη ζητά η ΔΕΗ· όπου όχι, το
     * φύλλο είναι μόνο για εκτύπωση. Οι Γενικοί Όροι και η Προσυμβατική
     * ποτέ δεν συμπληρώνονται.
     */
    public function testSignaturesOnlyWhereTheDeiAsksForThem(): void
    {
        $unsigned = ['dei_myhome_4all', 'dei_myhome_4all_onerate', 'dei_g1', 'dei_mybusiness_4all'];

        foreach (array_keys(DeiCatalogue::all()) as $code) {
            $map = self::map(DeiCatalogue::termsSheet($code));
            self::assertSame(in_array($code, $unsigned, true), ! empty($map['print_only']), $code);
            self::assertSame(in_array($code, $unsigned, true), empty($map['sigs']), $code);
        }

        foreach (
            [DeiCatalogue::TERMS_SMALL, DeiCatalogue::TERMS_LARGE, DeiCatalogue::TERMS_DYN_LARGE,
            DeiCatalogue::PRECONTRACT_HOME, DeiCatalogue::PRECONTRACT_BUSINESS] as $key
        ) {
            $map = self::map($key);
            self::assertTrue($map['print_only'], $key);
            self::assertSame([], $map['fields'], $key);
        }

        // Οι Γενικοί Όροι έως 25 kVA είναι σε χαρτί Letter.
        self::assertSame(215.9, self::map(DeiCatalogue::TERMS_SMALL)['page_w']);
    }

    public function testTheTariffCarriesTheVersionOfTheForm(): void
    {
        self::assertSame('ΔΕΗ myHome Enter 11.26', DeiCatalogue::tariff('dei_myhome_enter'));
        self::assertSame('Γ1Ν Οικιακό', DeiCatalogue::tariff('dei_g1', true));
        self::assertSame('Γ1 Οικιακό', DeiCatalogue::tariff('dei_g1'));
        self::assertSame('', DeiCatalogue::tariff('protergia_value_sure5_12m'));
    }

    public function testNotesAreTheDeiConditions(): void
    {
        $notes = DeiCatalogue::notes();

        self::assertStringContainsString('διζωνικό', $notes['dei_myhome_entertwo']);
        self::assertStringContainsString('τηλεμετρούμενο', $notes['dei_myhome_dynamic']);
        self::assertStringContainsString('myΔΕΗ', $notes['dei_myhome_online']);
        self::assertArrayNotHasKey('dei_myhome_4all', $notes);
    }

    public function testEveryProgramButTheDynamicOnesHasAPrice(): void
    {
        $prices = DeiPrices2026::all();

        foreach (DeiCatalogue::all() as $code => $plan) {
            self::assertSame(! $plan['dynamic'], isset($prices[$code]), $code);
        }

        self::assertSame([], array_diff(array_keys($prices), array_keys(DeiCatalogue::all())));
    }

    public function testThePricesReadAsTheForms(): void
    {
        $program = static function (string $code): array {
            $p = DeiPrices2026::all()[$code];

            return [
                'price_kwh'    => $p['price_kwh'],
                'fixed_charge' => $p['fixed_charge'],
                'pricing'      => json_encode($p['pricing']),
                'price_type'   => DeiCatalogue::all()[$code]['priceType'],
            ];
        };

        // Enter 11.26: 0,179 €/kWh, πάγιο 7,5 → 1.000 kWh: 179 + 90.
        self::assertSame(269.0, ProgramPricing::annualCost($program('dei_myhome_enter'), 1000.0));

        // Maxima 11.26: κλιμακωτό 600 kWh/30 ημέρες.
        $rule = ProgramPricing::rule($program('dei_myhome_maxima'));
        self::assertSame('tiered', $rule['kind']);
        self::assertSame([[600.0, 0.175], [null, 0.163]], $rule['tiers']);
        self::assertSame(13.5, $rule['fixed']);

        // Τα κυμαινόμενα παλιώνουν όπως όλα.
        $variable = $program('dei_myhome_4all') + ['price_updated' => '2026-11-13'];
        self::assertFalse(ProgramPricing::isStale($variable, '2026-12-01'));
        self::assertTrue(ProgramPricing::isStale($variable, '2027-01-15'));
    }
}
