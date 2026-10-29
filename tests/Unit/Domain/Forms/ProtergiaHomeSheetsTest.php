<?php

/**
 * (325) Τα οικιακά φύλλα ρεύματος Protergia (αποστολή 29/10/2026).
 *
 * Οι γραμμές της σελ. 2 και οι γραμμές υπογραφής είναι μετρημένες στα PDF
 * της Protergia (tools/protergia-home-2026-maps.py) και γράφονται εδώ ξανά
 * με το χέρι, ώστε μια μετακίνηση στον χάρτη να μη «συμφωνεί» με τον εαυτό της.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\FormTemplates;
use EnergyCRM\Domain\Forms\ProtergiaBizSheets;
use EnergyCRM\Domain\Forms\ProtergiaCatalogue;
use EnergyCRM\Domain\Forms\ProtergiaHomePlans;
use EnergyCRM\Domain\Forms\ProtergiaHomeSheets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtergiaHomeSheetsTest extends TestCase
{
    private const PICASSO = 'protergia_oik_picasso_s1';

    /** Dynamic One και Picasso: μία σελίδα παραπάνω πριν από τους όρους. */
    private const FOUR_PAGES = ['protergia_oik_dynamic_one', self::PICASSO];

    public function testEveryHomeCatalogueTariffHasItsSheetExceptThoseAwaitingOne(): void
    {
        self::assertSame('protergia_oik_balance_12m', ProtergiaHomeSheets::templateKey('protergia_value_balance_12m'));
        self::assertSame('protergia_oik_sure4_12m', ProtergiaHomeSheets::templateKey('protergia_value_sure4_12m'));

        // Protergia 29/10: Student = Picasso 39,90· το «Picasso 2.0» του 07/10 και το S 39,90 το ίδιο.
        foreach (['protergia_picasso2', 'protergia_picasso2_student', 'protergia_pic_h_s1'] as $code) {
            self::assertSame(self::PICASSO, ProtergiaHomeSheets::templateKey($code), $code);
        }

        foreach (ProtergiaCatalogue::added() as $code => $plan) {
            if ($plan['energy'] !== 'power' || in_array($code, ProtergiaCatalogue::NOT_A_PROGRAM, true)) {
                continue;
            }
            $awaiting = in_array($code, ProtergiaCatalogue::AWAITING_SHEET, true);
            self::assertSame($awaiting, ProtergiaHomeSheets::templateKey($code) === '', $code);
        }

        foreach (ProtergiaCatalogue::AWAITING_SHEET as $code) {
            self::assertSame('', ProtergiaHomeSheets::templateKey($code), $code . ' έχει πια φύλλο');
        }

        // Όσα είχαν ήδη φύλλο (Lite 2.0, παλιά Sure/Bright) και τα επαγγελματικά: όχι εδώ.
        foreach ([...ProtergiaHomePlans::codes(), ...ProtergiaBizSheets::programs()] as $code) {
            self::assertSame('', ProtergiaHomeSheets::templateKey($code), $code);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function sheets(): iterable
    {
        foreach (ProtergiaHomeSheets::templates() as $tpl) {
            yield $tpl => [$tpl];
        }
    }

    #[DataProvider('sheets')]
    public function testTheApplicationIsFollowedByItsTermsAndBothAreSigned(string $tpl): void
    {
        $map = $this->map($tpl);
        $app = in_array($tpl, self::FOUR_PAGES, true) ? 4 : 3;
        $gos = in_array($tpl, self::FOUR_PAGES, true) ? 4 : 3;

        for ($p = 1; $p <= $app; $p++) {
            self::assertFileExists(self::dir() . "{$tpl}-{$p}.jpg");
        }
        self::assertFileDoesNotExist(self::dir() . $tpl . '-' . ($app + 1) . '.jpg');
        self::assertCount($app + $gos, FormTemplates::pagePaths(dirname(self::dir()), $tpl, $map), $tpl);

        $sigPages = array_map(static fn (array $s): int => (int) $s['page'], $map['sigs']);
        self::assertContains($app, $sigPages, "{$tpl}: η σελίδα όρων ανυπόγραφη");
        self::assertContains($app + $gos, $sigPages, "{$tpl}: οι ΓΟΣ ανυπόγραφοι");
        self::assertLessThanOrEqual($app + $gos, max($sigPages));
    }

    /**
     * Σελ. 1 και σελίδα όρων: ακριβώς οι θέσεις του Value Lite 2.0 (ίδιο
     * έντυπο). Σελ. 2: ίδια x.
     */
    #[DataProvider('sheets')]
    public function testTheCustomerPagesAreTheVerifiedLiteTwoLayout(string $tpl): void
    {
        $ref   = $this->map('protergia_oik_lite2')['fields'];
        $f     = $this->map($tpl)['fields'];
        $terms = in_array($tpl, self::FOUR_PAGES, true) ? 4 : 3;

        self::assertSame(array_keys($ref), array_keys($f), $tpl);

        foreach ($ref as $key => $v) {
            $want = isset($v[0]) ? $v : [$v];
            $got  = isset($f[$key][0]) ? $f[$key] : [$f[$key]];
            self::assertCount(count($want), $got, "{$tpl}:{$key}");

            foreach ($want as $i => $pos) {
                $page = [1 => 1, 2 => 2, 3 => $terms][$pos['page']];
                self::assertSame($page, $got[$i]['page'], "{$tpl}:{$key}");
                self::assertEqualsWithDelta($pos['x'], $got[$i]['x'], 0.001, "{$tpl}:{$key}");
                if ($pos['page'] !== 2) {
                    self::assertEqualsWithDelta($pos['y'], $got[$i]['y'], 0.001, "{$tpl}:{$key}");
                }
                if (empty($pos['check'])) {
                    self::assertGreaterThan(0, $got[$i]['maxw'] ?? 0, "{$tpl}:{$key} χωρίς maxw");
                    self::assertLessThanOrEqual(201.0, $got[$i]['x'] + $got[$i]['maxw'], "{$tpl}:{$key}");
                }
            }
        }
    }

    /** Σελ. 2: δύο ύψη πίνακα· μετρημένο στα PDF (top ετικέτας − 0,7). */
    public function testTheTariffPageFieldsFollowTheirOwnRow(): void
    {
        $tall = ['protergia_oik_balance_12m' => 152.7, 'protergia_oik_sure4_12m' => 152.7, self::PICASSO => 152.1];
        foreach ($tall as $tpl => $guarantee) {
            $f = $this->map($tpl)['fields'];
            self::assertEqualsWithDelta($guarantee - 0.7, $f['poso_eggiisis']['y'], 0.05, $tpl);
        }
        foreach (['protergia_oik_standard2', 'protergia_oik_special', 'protergia_oik_student_plan'] as $tpl) {
            $f    = $this->map($tpl)['fields'];
            $date = array_values(array_filter(
                isset($f['imerominia_aitisis'][0]) ? $f['imerominia_aitisis'] : [$f['imerominia_aitisis']],
                static fn (array $p): bool => $p['page'] === 2
            ));
            self::assertCount(1, $date, $tpl);
            self::assertEqualsWithDelta(14.3, $date[0]['y'], 0.05, $tpl);
            self::assertEqualsWithDelta(101.8, $f['poso_eggiisis']['y'], 0.05, $tpl);
        }
    }

    /**
     * @return iterable<string, array{string, int, float}>
     *         φύλλο, σελίδα, γραμμή υπογραφής (mm από πάνω)
     */
    public static function lines(): iterable
    {
        foreach (ProtergiaHomeSheets::templates() as $tpl) {
            if ($tpl === self::PICASSO) {
                yield 'Picasso εκκαθάριση' => [$tpl, 3, 203.9];
                yield 'Picasso Παλαιά Σύμβαση' => [$tpl, 3, 247.5];
                yield 'Picasso ΓΟΣ' => [$tpl, 8, 109.1];
                yield 'Picasso Πρόσθετοι Όροι' => [$tpl, 8, 186.2];
                continue;
            }
            if ($tpl === 'protergia_oik_dynamic_one') {
                yield 'Dynamic One δυναμική τιμολόγηση' => [$tpl, 3, 282.8];
                yield 'Dynamic One ΓΟΣ' => [$tpl, 8, 282.7];
                continue;
            }
            yield "{$tpl} ΓΟΣ" => [$tpl, 6, 282.7];
        }
    }

    #[DataProvider('lines')]
    public function testEachCustomerLineIsSigned(string $tpl, int $page, float $line): void
    {
        $found = false;
        foreach ($this->map($tpl)['sigs'] as $s) {
            $bottom = $s['y'] + $s['fit_h'];
            if ($s['page'] === $page && $bottom > $line - 3.0 && $bottom < $line + 0.6) {
                $found = true;
            }
        }
        self::assertTrue($found, "{$tpl}: καμία υπογραφή στη γραμμή {$line} της σελ. {$page}");
    }

    public function testThePicassoTermsAreSharedOnceOnDisk(): void
    {
        self::assertSame('protergia_gos_oik_picasso', FormTemplates::tail(self::PICASSO, $this->map(self::PICASSO)));
        self::assertFileExists(self::dir() . 'protergia_gos_oik_picasso-4.jpg');
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 4) . '/assets/forms/protergia/';
    }

    /** @return array<string, mixed> */
    private function map(string $tpl): array
    {
        $m = json_decode((string) file_get_contents(self::dir() . $tpl . '.json'), true);
        self::assertIsArray($m, $tpl);

        return $m;
    }
}
