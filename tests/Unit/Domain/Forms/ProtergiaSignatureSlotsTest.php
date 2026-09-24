<?php

/**
 * Πού πέφτει η υπογραφή του πελάτη στα έντυπα της Protergia.
 *
 * ## Αφορμή (288)
 *
 * Τυπωμένη αίτηση επαγγελματικού: στη σελ. 3 η υπογραφή έπεφτε πάνω στο «Β2»
 * αντί για το «ΥΠΟΓΡΑΦΗ ΠΕΛΑΤΗ:....» και πάνω στο «ΙΒ» αντί για το
 * «ΥΠΟΓΡΑΦΗ/ΣΦΡΑΓΙΔΑ ΠΕΛΑΤΗ ....», και στους ΓΟΣ (τελευταία σελίδα) δεν
 * έμπαινε καθόλου, ενώ το έντυπο έχει γραμμή «ΥΠΟΓΡΑΦΗ / ΣΦΡΑΓΙΔΑ ΠΕΛΑΤΗ».
 *
 * ## Πώς μετράει
 *
 * Κάθε θέση είναι κουτί (`SignatureBox`): η υπογραφή ακουμπά στην κάτω πλευρά
 * του. Άρα αρκεί να ελεγχθεί πού πέφτει αυτή η κάτω πλευρά: **κάτω** από τις
 * τελείες της γραμμής υπογραφής, και **πάνω** από ό,τι ακολουθεί (η επόμενη
 * σειρά κειμένου, ή η μπάρα «Εξόφληση λογαριασμών»). Τα όρια είναι
 * μετρημένα πάνω στα υπόβαθρα (mm από πάνω, σε κλίμακα `page_h` του χάρτη) --
 * δες docs/PROTERGIA-COORDS.md.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtergiaSignatureSlotsTest extends TestCase
{
    /** Φύλλα με την ίδια σελ. 3 του επαγγελματικού: «ΥΠΟΓΡΑΦΗ/ΣΦΡΑΓΙΔΑ» στα 243.8mm. */
    private const BIZ_LAYOUT = ['protergia_epag_seasonal', 'protergia_epag_simple2', 'protergia_epag_sure12'];

    /** Φύλλα με την ίδια σελ. 3 του οικιακού (μία σειρά «ΙΓ.» παραπάνω): στα 250.8mm. */
    private const HOME_LAYOUT = [
        'protergia_oik_bright', 'protergia_oik_lite2', 'protergia_oik_sure12', 'protergia_oik_sure18',
    ];

    /**
     * @return iterable<string, array{string, int, float, float, float}>
     *         template, σελίδα, x της θέσης, τελείες (κάτω άκρη), εμπόδιο από κάτω
     */
    public static function slots(): iterable
    {
        foreach (self::BIZ_LAYOUT as $t) {
            yield "{$t} ΥΠΟΓΡΑΦΗ ΠΕΛΑΤΗ"            => [$t, 3, 150.0, 157.3, 160.2];
            yield "{$t} ΥΠΟΓΡΑΦΗ/ΣΦΡΑΓΙΔΑ ΠΕΛΑΤΗ"   => [$t, 3, 110.0, 243.8, 247.2];
            yield "{$t} ΓΟΣ"                         => [$t, 6, 113.0, 281.5, 283.5];
        }

        foreach (self::HOME_LAYOUT as $t) {
            yield "{$t} ΥΠΟΓΡΑΦΗ ΠΕΛΑΤΗ"            => [$t, 3, 150.0, 157.3, 160.2];
            yield "{$t} ΥΠΟΓΡΑΦΗ/ΣΦΡΑΓΙΔΑ ΠΕΛΑΤΗ"   => [$t, 3, 110.0, 250.8, 252.1];
            yield "{$t} ΓΟΣ"                         => [$t, 6, 113.0, 281.5, 283.5];
        }

        // Τα παλιά τετρασέλιδα: ίδιες σελίδες όρων, στη σελ. 4.
        yield 'protergia_he ΥΠΟΓΡΑΦΗ ΠΕΛΑΤΗ'                => ['protergia_he', 4, 150.0, 157.3, 160.2];
        yield 'protergia_he ΥΠΟΓΡΑΦΗ/ΣΦΡΑΓΙΔΑ ΠΕΛΑΤΗ'       => ['protergia_he', 4, 110.0, 250.8, 252.1];
        yield 'protergia_he_biz ΥΠΟΓΡΑΦΗ ΠΕΛΑΤΗ'            => ['protergia_he_biz', 4, 150.0, 157.3, 160.2];
        yield 'protergia_he_biz ΥΠΟΓΡΑΦΗ/ΣΦΡΑΓΙΔΑ ΠΕΛΑΤΗ'   => ['protergia_he_biz', 4, 110.0, 243.8, 247.2];

        // Φυσικό αέριο: οι ΓΟΣ είναι A4 τεντωμένοι στα 330.1mm του εντύπου.
        yield 'protergia_fa_sure ΓΟΣ'   => ['protergia_fa_sure', 5, 113.0, 312.9, 314.9];
        yield 'protergia_fa_single ΓΟΣ' => ['protergia_fa_single', 4, 113.0, 312.9, 314.9];
    }

    #[DataProvider('slots')]
    public function testTheSignatureRestsOnItsOwnLine(
        string $template,
        int $page,
        float $x,
        float $line,
        float $below
    ): void {
        $slot = $this->slotAt($template, $page, $x);

        self::assertGreaterThan(
            0.0,
            (float) ($slot['fit_h'] ?? 0),
            "{$template}: θέση χωρίς κουτί (fit_h)."
        );

        $bottom = (float) $slot['y'] + (float) $slot['fit_h'];

        self::assertGreaterThanOrEqual(
            $line,
            $bottom,
            "{$template} σελ. {$page}: η υπογραφή κάθεται πάνω από τη γραμμή της."
        );
        self::assertLessThanOrEqual(
            $below,
            $bottom,
            "{$template} σελ. {$page}: η υπογραφή πέφτει πάνω σε ό,τι ακολουθεί."
        );
    }

    /**
     * Η τελευταία σελίδα κάθε εντύπου με ΓΟΣ έχει τη γραμμή «ΥΠΟΓΡΑΦΗ /
     * ΣΦΡΑΓΙΔΑ ΠΕΛΑΤΗ» -- και πρέπει να υπογράφεται. Μετράει τις σελίδες από
     * τα υπόβαθρα, ώστε ένα έντυπο που θα αποκτήσει σελίδα να μη «χάσει»
     * σιωπηλά την υπογραφή του.
     *
     * @return iterable<string, array{string}>
     */
    public static function termsSheets(): iterable
    {
        foreach ([...self::BIZ_LAYOUT, ...self::HOME_LAYOUT, 'protergia_fa_sure', 'protergia_fa_single'] as $t) {
            yield $t => [$t];
        }
    }

    #[DataProvider('termsSheets')]
    public function testTheLastPageOfTheTermsIsSigned(string $template): void
    {
        $last = 1;

        while (is_file(self::dir() . $template . '-' . ($last + 1) . '.jpg')) {
            $last++;
        }

        $pages = array_map(static fn (array $s): int => (int) $s['page'], $this->map($template)['sigs']);

        self::assertContains($last, $pages, "{$template}: οι ΓΟΣ (σελ. {$last}) μένουν ανυπόγραφοι.");
    }

    /** @return array<string, mixed> */
    private function slotAt(string $template, int $page, float $x): array
    {
        foreach ($this->map($template)['sigs'] as $slot) {
            if ((int) $slot['page'] === $page && abs((float) $slot['x'] - $x) < 0.01) {
                return $slot;
            }
        }

        self::fail("{$template}: δεν υπάρχει θέση υπογραφής στη σελ. {$page}, x = {$x}.");
    }

    /** @return array{sigs: list<array<string, mixed>>} */
    private function map(string $template): array
    {
        $map = json_decode((string) file_get_contents(self::dir() . $template . '.json'), true);

        self::assertIsArray($map);
        self::assertIsArray($map['sigs'] ?? null, "{$template}: λείπει το sigs.");

        return $map;
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 4) . '/assets/forms/protergia/';
    }
}
