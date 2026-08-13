<?php

/**
 * Every electricity and gas form must be able to print the standing order.
 *
 * `pliromi_pagia_entoli` is the box that says the customer authorises the
 * provider to take the bill from their bank account. ECRM_FormFill has fed it
 * from `payment_method` all along, but for months only four of the ten forms
 * had a coordinate for it — so a customer who chose a standing order on
 * Protergia electricity received a form with the box **empty**, while the same
 * provider's gas form ticked it correctly.
 *
 * Nothing failed. A fill key with no coordinate is simply not drawn, and the
 * document looks complete. It was found by reading a printed page, which is
 * the only place it was ever visible.
 *
 * The list is written out by name rather than derived, because deriving it
 * from the directory would make the test agree with whatever is there — and
 * "the file no longer has the field" is precisely the state it exists to catch.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StandingOrderCoordinateTest extends TestCase
{
    private const KEY = 'pliromi_pagia_entoli';

    /**
     * Every template whose paper has the box.
     *
     * @return list<array{string}>
     */
    public static function templates(): array
    {
        return [
            // Had it from the start.
            ['nrg_he'],
            ['nrg_he_biz'],
            ['nrg_fa'],
            ['protergia_fa'],
            // Added 2026-08-13, after the box was found empty on a real print.
            ['protergia_he'],
            ['protergia_he_biz'],
            ['protergia_oik_bright'],
            ['protergia_oik_lite2'],
            ['protergia_oik_sure12'],
            ['protergia_oik_sure18'],
        ];
    }

    /**
     * @return array{page: int, x: float, y: float, check?: bool}
     */
    private function field(string $template): array
    {
        $path = dirname(__DIR__, 4) . '/assets/forms/' . $template . '.json';

        self::assertFileExists($path);

        $map = json_decode((string) file_get_contents($path), true);

        self::assertIsArray($map);
        self::assertArrayHasKey('fields', $map);
        self::assertArrayHasKey(
            self::KEY,
            $map['fields'],
            "Το {$template} δεν έχει συντεταγμένη για την πάγια εντολή — το κουτάκι θα τυπωθεί άδειο."
        );

        /** @var array{page: int, x: float, y: float, check?: bool} $field */
        $field = $map['fields'][self::KEY];

        return $field;
    }

    #[DataProvider('templates')]
    public function testTheStandingOrderBoxHasACoordinate(string $template): void
    {
        $field = $this->field($template);

        self::assertGreaterThan(0, $field['page']);
        self::assertGreaterThan(0.0, $field['x']);
        self::assertGreaterThan(0.0, $field['y']);
    }

    /**
     * Drawn as a tick, not as text.
     *
     * Without `check` the renderer writes the raw value into the box — 'X' as a
     * string is the same thing here, but a future value that is not 'X' would
     * print itself onto a bank mandate.
     */
    #[DataProvider('templates')]
    public function testItIsMarkedAsACheckbox(string $template): void
    {
        self::assertTrue(
            $this->field($template)['check'] ?? false,
            "Το {$template} έχει την πάγια εντολή χωρίς check: true."
        );
    }

    /**
     * The two Protergia home maps are shared by two tariffs each, and the pairs
     * must not drift apart.
     *
     * bright/lite2 and sure12/sure18 are byte-identical files by design — one
     * layout, two price lists. A coordinate edited in one and not the other is
     * how two of the four start printing in the wrong place with nothing to
     * show for it.
     */
    public function testTheSharedHomeMapsStayIdentical(): void
    {
        $dir = dirname(__DIR__, 4) . '/assets/forms/';

        foreach ([['bright', 'lite2'], ['sure12', 'sure18']] as [$a, $b]) {
            self::assertSame(
                file_get_contents($dir . 'protergia_oik_' . $a . '.json'),
                file_get_contents($dir . 'protergia_oik_' . $b . '.json'),
                "Οι χάρτες protergia_oik_{$a} και protergia_oik_{$b} απέκλιναν."
            );
        }
    }
}
