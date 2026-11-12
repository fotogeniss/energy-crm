<?php

/**
 * (322) Τα επαγγελματικά φύλλα ρεύματος Protergia του Οκτωβρίου 2026.
 *
 * Οι γραμμές υπογραφής είναι μετρημένες στα διανυσματικά σχήματα των PDF της
 * Protergia (tools/protergia-biz-2026-maps.py) και γράφονται εδώ ξανά με το
 * χέρι, ώστε μια μετακίνηση στον χάρτη να μη «συμφωνεί» με τον εαυτό της.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\FormTemplates;
use EnergyCRM\Domain\Forms\ProtergiaBizCatalogue;
use EnergyCRM\Domain\Forms\ProtergiaBizPlans;
use EnergyCRM\Domain\Forms\ProtergiaBizSheets;
use EnergyCRM\Domain\Forms\ProtergiaPicassoPlans;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtergiaBizSheetsTest extends TestCase
{
    private const CAPTION = 'nomimos_ekprosopos_ypografi';

    /** Dynamic One και Picasso: μία σελίδα παραπάνω πριν από τους όρους. */
    private const FOUR_PAGES = [
        'protergia_epag1_dynamic_one', 'protergia_epag2_dynamic_one', ...ProtergiaBizSheets::PICASSO,
    ];

    public function testEveryBusinessPowerTariffAndPicassoPackageHasItsOwnSheet(): void
    {
        // (371) +1: Επαγγελματικό 1 Value Balance 2.0 12M (το παλιό μένει για τις αιτήσεις του).
        self::assertCount(23, ProtergiaBizSheets::programs());

        foreach (ProtergiaBizSheets::programs() as $code) {
            self::assertSame($code, ProtergiaBizSheets::templateKey($code));
        }

        // Τα 9 επαγγελματικά Picasso είναι αυτά του καταλόγου προγραμμάτων.
        $business = array_keys(array_filter(
            ProtergiaPicassoPlans::all(),
            static fn (array $p): bool => $p['category'] === 'business'
        ));
        self::assertSame($business, ProtergiaBizSheets::PICASSO);

        // Αέριο, οικιακό Picasso, και όσα έχουν ήδη φύλλο από το (283): όχι εδώ.
        $none = ['protergia_epag_gas_flex', 'protergia_pic_h_s1', 'protergia_oik_sure12', ''];
        foreach ([...$none, ...ProtergiaBizPlans::codes()] as $code) {
            self::assertSame('', ProtergiaBizSheets::templateKey($code), $code);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function sheets(): iterable
    {
        foreach (ProtergiaBizSheets::programs() as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('sheets')]
    public function testTheApplicationIsFollowedByItsTermsAndBothAreSigned(string $tpl): void
    {
        $map  = $this->map($tpl);
        $app  = in_array($tpl, self::FOUR_PAGES, true) ? 4 : 3;
        $gos  = in_array($tpl, ProtergiaBizSheets::PICASSO, true) ? 4 : 3;

        for ($p = 1; $p <= $app; $p++) {
            self::assertFileExists(self::dir() . "{$tpl}-{$p}.jpg");
        }
        self::assertFileDoesNotExist(self::dir() . $tpl . '-' . ($app + 1) . '.jpg');

        $pages = FormTemplates::pagePaths(dirname(self::dir()), $tpl, $map);
        self::assertCount($app + $gos, $pages, "{$tpl}: λείπουν οι ΓΟΣ (tail)");

        $sigPages = array_map(static fn (array $s): int => (int) $s['page'], $map['sigs']);
        self::assertContains($app, $sigPages, "{$tpl}: η σελίδα όρων ανυπόγραφη");
        self::assertContains($app + $gos, $sigPages, "{$tpl}: οι ΓΟΣ ανυπόγραφοι");
        self::assertLessThanOrEqual($app + $gos, max($sigPages));

        foreach ($map['fields'] as $key => $v) {
            foreach (isset($v[0]) ? $v : [$v] as $pos) {
                self::assertLessThanOrEqual($app + $gos, $pos['page'], "{$tpl}:{$key}");
            }
        }
    }

    public function testThePicassoPackagesShareOneSetOfTermsAndTheRestKeepTheirOwn(): void
    {
        $tails = [];
        foreach (ProtergiaBizSheets::programs() as $tpl) {
            $tails[$tpl] = FormTemplates::tail($tpl, $this->map($tpl));
            self::assertNotSame('', $tails[$tpl], $tpl);
        }

        foreach (ProtergiaBizSheets::PICASSO as $tpl) {
            self::assertSame('protergia_gos_pic_b', $tails[$tpl]);
            unset($tails[$tpl]);
        }

        // Οι ΓΟΣ κάθε άλλου προγράμματος έχουν δικό του άρθρο 2 (χρεώσεις).
        // (371) +1: οι ΓΟΣ του Value Balance 2.0 12M (Ε1).
        self::assertCount(14, array_unique($tails));
    }

    /**
     * Σελ. 1 και σελίδα όρων: ακριβώς οι θέσεις του simple2 (ίδιο έντυπο,
     * μετρημένο). Σελ. 2: ίδια x, και η ημερομηνία πάνω από την εγγύηση.
     */
    #[DataProvider('sheets')]
    public function testTheCustomerPagesAreTheVerifiedSimpleTwoLayout(string $tpl): void
    {
        $ref   = $this->map('protergia_epag_simple2')['fields'];
        $f     = $this->map($tpl)['fields'];
        $terms = in_array($tpl, self::FOUR_PAGES, true) ? 4 : 3;

        foreach ($ref as $key => $v) {
            if ($key === self::CAPTION) {
                continue;
            }
            self::assertArrayHasKey($key, $f, "{$tpl}: λείπει το {$key}");
            $want = isset($v[0]) ? $v : [$v];
            $got  = isset($f[$key][0]) ? $f[$key] : [$f[$key]];
            self::assertCount(count($want), $got, "{$tpl}:{$key}");

            foreach ($want as $i => $pos) {
                $page = ['1' => 1, '2' => 2, '3' => $terms][(string) $pos['page']];
                self::assertSame($page, $got[$i]['page'], "{$tpl}:{$key}");
                self::assertEqualsWithDelta($pos['x'], $got[$i]['x'], 0.001, "{$tpl}:{$key}");
                if ($pos['page'] !== 2) {
                    self::assertEqualsWithDelta($pos['y'], $got[$i]['y'], 0.001, "{$tpl}:{$key}");
                }
            }
        }

        self::assertLessThan($f['poso_eggiisis']['y'], $f['imerominia_aitisis']['y']);
        self::assertArrayNotHasKey('protergia_oros_th', $f, 'όρος υπαναχώρησης μόνο στα οικιακά');
    }

    /** Σελ. 2 έχει άλλο ύψος πίνακα ανά πρόγραμμα· μετρημένο στα PDF. */
    public function testTheTariffPageFieldsFollowTheirOwnRow(): void
    {
        // Ψηλή κεφαλίδα (Picasso, Balance, Power, Sure): ημερομηνία 17,8 / εγγύηση 144,4 (top ετικέτας).
        foreach (['protergia_pic_b_m2', 'protergia_epag1_balance_12m', 'protergia_epag3_sure12m_2'] as $tpl) {
            $f = $this->map($tpl)['fields'];
            self::assertEqualsWithDelta(17.1, $f['imerominia_aitisis']['y'], 0.05, $tpl);
            self::assertEqualsWithDelta(143.7, $f['poso_eggiisis']['y'], 0.05, $tpl);
        }
        // Χαμηλή (Standard, Simple, Special, Seasonal): 15,0 / 97,6.
        foreach (['protergia_epag2_standard2', 'protergia_epag3_special', 'protergia_epag_seasonal'] as $tpl) {
            $f = $this->map($tpl)['fields'];
            self::assertEqualsWithDelta(14.3, $f['imerominia_aitisis']['y'], 0.05, $tpl);
            self::assertEqualsWithDelta(96.9, $f['poso_eggiisis']['y'], 0.05, $tpl);
        }
    }

    /**
     * @return iterable<string, array{string, int, float}>
     *         φύλλο, σελίδα, γραμμή υπογραφής (mm από πάνω)
     */
    public static function lines(): iterable
    {
        foreach (ProtergiaBizSheets::programs() as $tpl) {
            if (in_array($tpl, ProtergiaBizSheets::PICASSO, true)) {
                yield "{$tpl} εκκαθάριση" => [$tpl, 3, 204.1];
                yield "{$tpl} Παλαιά Σύμβαση" => [$tpl, 3, 246.3];
                yield "{$tpl} ΓΟΣ" => [$tpl, 8, 69.1];
                yield "{$tpl} Πρόσθετοι Όροι" => [$tpl, 8, 149.3];
                continue;
            }
            if (in_array($tpl, ['protergia_epag1_dynamic_one', 'protergia_epag2_dynamic_one'], true)) {
                yield "{$tpl} δυναμική τιμολόγηση" => [$tpl, 3, 282.8];
                yield "{$tpl} ΓΟΣ" => [$tpl, 7, $tpl === 'protergia_epag1_dynamic_one' ? 287.4 : 282.7];
                continue;
            }
            yield "{$tpl} ΓΟΣ" => [$tpl, 6, 282.7];
        }
    }

    /**
     * Η υπογραφή κάθεται στη γραμμή της (ΓΟΣ) ή λίγο πάνω της, αφήνοντας
     * χώρο για τη λεζάντα εταιρείας (κουτιά Picasso / Dynamic One, όπου η
     * ετικέτα κολλάει κάτω από τη γραμμή).
     */
    #[DataProvider('lines')]
    public function testEachCustomerLineIsSignedAndCaptioned(string $tpl, int $page, float $line): void
    {
        $map  = $this->map($tpl);
        $slot = null;
        foreach ($map['sigs'] as $s) {
            $bottom = $s['y'] + $s['fit_h'];
            if ($s['page'] === $page && $bottom > $line - 3.0 && $bottom < $line + 0.6) {
                $slot = $s;
            }
        }
        self::assertNotNull($slot, "{$tpl}: καμία υπογραφή στη γραμμή {$line} της σελ. {$page}");

        $box = $page === 3;   // μέσα σε πλαίσιο της αίτησης
        $bottom = $slot['y'] + $slot['fit_h'];
        if ($box) {
            self::assertEqualsWithDelta($line - 2.6, $bottom, 0.1);
        } else {
            self::assertGreaterThanOrEqual($line, $bottom);
        }

        $caps = array_values(array_filter(
            $this->placements($map['fields'][self::CAPTION]),
            static fn (array $c): bool => $c['page'] === $page && abs($c['x'] - $slot['x']) < 1.5
                && abs(($c['y'] + 2.5) - $line) < 10.0
        ));
        self::assertNotEmpty($caps, "{$tpl}: χωρίς λεζάντα νόμιμου εκπροσώπου στη σελ. {$page}");
        if ($box) {
            // πάνω στη γραμμή, κάτω από την υπογραφή
            self::assertLessThan($line, $caps[0]['y'] + 2.5);
            self::assertGreaterThan($bottom, $caps[0]['y'] + 2.5 - 1.6);
        }
    }

    /** Μία λεζάντα «ΝΟΜΙΜΟΣ ΕΚΠΡΟΣΩΠΟΣ» για κάθε υπογραφή (Protergia 22/10). */
    #[DataProvider('sheets')]
    public function testEverySignatureHasACaption(string $tpl): void
    {
        $map = $this->map($tpl);

        self::assertCount(count($map['sigs']), $this->placements($map['fields'][self::CAPTION]));
    }

    public function testTheCatalogueStillListsTheGenericSheetAsALastResort(): void
    {
        foreach (ProtergiaBizCatalogue::all() as $code => $plan) {
            if ($plan['energy'] === 'power') {
                self::assertSame($code, ProtergiaBizSheets::templateKey($code));
            }
        }
        self::assertFileExists(self::dir() . ProtergiaBizCatalogue::TEMPLATE . '.json');
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $v
     * @return list<array<string, mixed>>
     */
    private function placements(array $v): array
    {
        return isset($v[0]) ? $v : [$v];
    }

    /** @return array<string, mixed> */
    private function map(string $tpl): array
    {
        $map = json_decode((string) file_get_contents(self::dir() . $tpl . '.json'), true);
        self::assertIsArray($map, $tpl);

        return $map;
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 4) . '/assets/forms/protergia/';
    }
}
