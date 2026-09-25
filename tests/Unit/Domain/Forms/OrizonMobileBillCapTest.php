<?php

/**
 * (293) Σελ.3 του orizon_mobile: «ΧΡΗΣΗ ΥΠΗΡΕΣΙΑΣ "ΑΝΩΤΑΤΟ ΟΡΙΟ ΛΟΓΑΡΙΑΣΜΟΥ
 * ΑΝΑ ΑΡΙΘΜΟ ΣΥΝΔΕΣΗΣ"» ΝΑΙ/ΟΧΙ και «ΑΝΩΤΑΤΟ ΟΡΙΟ:» -- ώς τώρα τυπωνόταν μόνο
 * ο πίνακας της σελ.4, και η σελ.3 έμενε κενή.
 *
 * Τα όρια των κελιών είναι μετρημένα στο υπόβαθρο orizon_mobile-3.jpg
 * (1191x1684px = 210x297mm): γραμμή ΧΡΗΣΗ 236.3-244.7, ΝΑΙ 162.2-170.9,
 * ΟΧΙ 191.0-199.6, γραμμή ΑΝΩΤΑΤΟ ΟΡΙΟ από 244.7.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use PHPUnit\Framework\TestCase;

final class OrizonMobileBillCapTest extends TestCase
{
    /** Το X (10pt, ~2,4mm πλάτος) πρέπει να πέφτει ολόκληρο μέσα στο κελί. */
    public function testTheYesAndNoMarksLandInsideTheirBoxes(): void
    {
        $f = $this->fields();

        foreach (['mobile_cap_nai' => [162.2, 170.9], 'mobile_cap_oxi' => [191.0, 199.6]] as $key => [$l, $r]) {
            self::assertSame(3, $f[$key]['page'], $key);
            self::assertTrue($f[$key]['check'], $key);
            self::assertGreaterThan($l, $f[$key]['x'], $key);
            self::assertLessThan($r, $f[$key]['x'] + 2.4, $key);

            // Baseline = y + 2.5 (ECRM_FormFill::BASELINE), μέσα στη γραμμή.
            self::assertGreaterThan(236.3, $f[$key]['y'], $key);
            self::assertLessThan(244.7, $f[$key]['y'] + 2.5, $key);
        }
    }

    public function testTheCapAmountIsWrittenOnItsOwnLine(): void
    {
        $f = $this->fields()['mobile_anotato_orio'];

        self::assertSame(3, $f['page']);
        self::assertGreaterThan(38.4, $f['x'], 'Πάνω στην ετικέτα «ΑΝΩΤΑΤΟ ΟΡΙΟ:».');
        self::assertGreaterThan(244.7, $f['y']);
    }

    /** @return array<string, array<string, mixed>> */
    private function fields(): array
    {
        $map = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/assets/forms/orizon/orizon_mobile.json'),
            true
        );

        self::assertIsArray($map);

        return $map['fields'];
    }
}
