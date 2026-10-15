<?php

/**
 * (317) Οι χάρτες της Volton ξαναμετρήθηκαν πάνω στα υπόβαθρα, αφού η
 * δοκιμαστική εκτύπωση έδειξε στοιχεία πελάτη σε λάθος γραμμές και Χ έξω από
 * τα τετραγωνάκια. Τα όρια εδώ είναι μετρημένα στα volton_fa-1.jpg και
 * volton_he-1.jpg (1240x1754px).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\PlacementCondition;
use PHPUnit\Framework\TestCase;

final class VoltonFormMapsTest extends TestCase
{
    /** Το Χ (10pt, ~2,4 mm) ολόκληρο μέσα στο κουτί του. */
    public function testTheGasDurationAndTypeMarksLandInsideTheirBoxes(): void
    {
        $f = $this->fields('volton_fa');

        $boxes = [
            'diarkeia_aoristou'     => [40.1, 45.4],
            'diarkeia_12_mines'     => [62.7, 67.9],
            'diarkeia_18_mines'     => [85.2, 90.4],
            'diarkeia_24_mines'     => [107.7, 113.0],
            'typos_pelati_etaireia' => [28.5, 42.2],
            'typos_pelati_idiotis'  => [62.0, 77.6],
            'typos_pelati_atomiki'  => [112.8, 118.0],
        ];

        foreach ($boxes as $key => [$left, $right]) {
            self::assertTrue($f[$key]['check'], $key);
            self::assertGreaterThan($left, $f[$key]['x'], $key);
            self::assertLessThan($right, $f[$key]['x'] + 2.4, $key);
        }
    }

    public function testThePowerDurationMarksLandInsideTheirBoxes(): void
    {
        $f = $this->fields('volton_he');

        $boxes = [
            'diarkeia_aoristou' => [23.5, 28.1],
            'diarkeia_6_mines'  => [42.5, 47.1],
            'diarkeia_12_mines' => [62.3, 66.7],
            'diarkeia_18_mines' => [82.1, 86.3],
            'diarkeia_24_mines' => [101.4, 105.8],
            'diarkeia_36_mines' => [122.2, 126.5],
        ];

        foreach ($boxes as $key => [$left, $right]) {
            self::assertGreaterThan($left, $f[$key]['x'], $key);
            self::assertLessThan($right, $f[$key]['x'] + 2.4, $key);
        }
    }

    /**
     * Το έντυπο αερίου έχει τμήμα «Ιδιώτη ή Ατομικής» (y 84–100) και τμήμα
     * «Εταιρείας» (y 109–124). Για κάθε τύπο πελάτη, κάθε τιμή τυπώνεται σε
     * ΕΝΑ από τα δύο, ποτέ και στα δύο.
     */
    public function testACompanyFillsOnlyTheCompanySectionAndAPersonOnlyTheirs(): void
    {
        $f = $this->fields('volton_fa');

        foreach (['company', 'individual'] as $type) {
            $values = ['typos_pelati_etaireia' => $type === 'company' ? 'X' : ''];

            foreach ($f as $key => $entry) {
                $list = isset($entry[0]) ? $entry : [$entry];

                foreach ($list as $pos) {
                    if (! PlacementCondition::applies($pos, $values) || ! empty($pos['check'])) {
                        continue;
                    }

                    $inPerson  = $pos['y'] >= 82 && $pos['y'] <= 101;
                    $inCompany = $pos['y'] >= 105 && $pos['y'] <= 126;

                    if ($type === 'company') {
                        self::assertFalse($inPerson, "{$key} τυπώνεται στο τμήμα ιδιώτη για εταιρεία");
                    } else {
                        self::assertFalse($inCompany, "{$key} τυπώνεται στο τμήμα εταιρείας για ιδιώτη");
                    }
                }
            }
        }
    }

    public function testAPlacementWithoutAConditionAlwaysApplies(): void
    {
        self::assertTrue(PlacementCondition::applies(['x' => 1, 'y' => 1], []));
        $company = ['typos_pelati_etaireia' => 'X'];

        self::assertTrue(PlacementCondition::applies(['when' => 'company'], $company));
        self::assertFalse(PlacementCondition::applies(['when' => 'company'], ['typos_pelati_etaireia' => '']));
        self::assertTrue(PlacementCondition::applies(['when' => 'not_company'], []));
        self::assertFalse(PlacementCondition::applies(['when' => 'κάτι άλλο'], []));
    }

    /** @return array<string, mixed> */
    private function fields(string $key): array
    {
        $path = dirname(__DIR__, 4) . '/assets/forms/volton/' . $key . '.json';
        $map  = json_decode((string) file_get_contents($path), true);

        return $map['fields'];
    }
}
