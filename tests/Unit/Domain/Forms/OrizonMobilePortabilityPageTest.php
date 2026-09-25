<?php

/**
 * Η σελ. 10 της χειρόγραφης αίτησης φορητότητας (orizon_mobile) έχει δύο
 * γραμμές προς συμπλήρωση -- «ΗΜΕΡΟΜΗΝΙΑ:» και «ΟΝΟΜΑΤΕΠΩΝΥΜΟ ΚΑΙ ΥΠΟΓΡΑΦΗ
 * ΚΑΤΟΧΟΥ ΤΗΛΕΦΩΝΙΚΗΣ ΣΥΝΔΕΣΗΣ:» -- αλλά μέχρι το (292) ο χάρτης δεν είχε
 * καμία θέση σε αυτή τη σελίδα: το draw_pages() προσπερνούσε τα πεδία
 * `page !== 10` και το τυπωμένο χαρτί έβγαινε με τις δύο γραμμές κενές.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use PHPUnit\Framework\TestCase;

final class OrizonMobilePortabilityPageTest extends TestCase
{
    private const TEMPLATE = 'orizon_mobile';
    private const PAGE     = 10;

    public function testTheDateAndNameSignatureLinesGetStamped(): void
    {
        $map = $this->map();

        self::assertNotNull(
            $this->placementAt($map['fields']['imerominia_aitisis'], self::PAGE),
            'Η «ΗΜΕΡΟΜΗΝΙΑ:» της σελ. 10 μένει κενή.'
        );
        self::assertNotNull(
            $this->placementAt($map['fields']['onomateponymo_pelati'], self::PAGE),
            'Το «ΟΝΟΜΑΤΕΠΩΝΥΜΟ ΚΑΙ ΥΠΟΓΡΑΦΗ ΚΑΤΟΧΟΥ ΤΗΛΕΦΩΝΙΚΗΣ ΣΥΝΔΕΣΗΣ:» μένει κενό.'
        );
    }

    public function testTheSignatureOnPage10SitsToTheRightOfTheNameLabel(): void
    {
        $sig = null;

        foreach ($this->map()['sigs'] as $s) {
            if ((int) $s['page'] === self::PAGE) {
                $sig = $s;
            }
        }

        self::assertNotNull($sig, 'Λείπει θέση υπογραφής στη σελ. 10.');
        // Η ετικέτα «ΟΝΟΜΑΤΕΠΩΝΥΜΟ ΚΑΙ ΥΠΟΓΡΑΦΗ ΚΑΤΟΧΟΥ ΤΗΛΕΦΩΝΙΚΗΣ ΣΥΝΔΕΣΗΣ:»
        // φτάνει ώς τα ~143,5mm -- η υπογραφή πρέπει να ξεκινά μετά από αυτήν
        // και να μη βγαίνει έξω από το τυπώσιμο πλάτος της σελίδας (~199mm).
        self::assertGreaterThan(143.5, $sig['x']);
        self::assertLessThanOrEqual(199.0, $sig['x'] + $sig['w']);
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $placements */
    private function placementAt(array $placements, int $page): ?array
    {
        $list = array_is_list($placements) ? $placements : [$placements];

        foreach ($list as $p) {
            if ((int) ($p['page'] ?? 0) === $page) {
                return $p;
            }
        }

        return null;
    }

    /** @return array{fields: array<string, mixed>, sigs: list<array<string, mixed>>} */
    private function map(): array
    {
        $map = json_decode((string) file_get_contents(self::dir() . self::TEMPLATE . '.json'), true);

        self::assertIsArray($map);

        return $map;
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 4) . '/assets/forms/orizon/';
    }
}
