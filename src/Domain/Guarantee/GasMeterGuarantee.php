<?php

/**
 * (329) Εγγύηση φυσικού αερίου Protergia από τον τύπο μετρητή.
 *
 * Πίνακας «Εγγυήσεις ΦΑ», Version 16 (Protergia, 22/10/2026). Το ποσό εξαρτάται
 * από τον τύπο μετρητή (G2,5 … G160) και την κατηγορία: Οικιακά, Κοινόχρηστα,
 * Εμπορικά. Οι κανόνες εγγύησης της σελίδας Ρυθμίσεων ξέρουν μόνο kVA, οπότε
 * για το αέριο ο πωλητής έψαχνε τον πίνακα στο PDF.
 *
 * Τι λέει ο πίνακας πέρα από τα ποσά:
 * - «Μηδενική Εγγύηση με ενεργοποίηση πάγιας εντολής».
 * - Αλλαγή προμηθευτή ή επανενεργοποίηση στον ΙΔΙΟ χρήστη: «Εγγύηση
 *   Προηγούμενου Προμηθευτή» -- το ποσό του τελευταίου λογαριασμού. Ο πίνακας
 *   ισχύει όταν ο λογαριασμός δεν αναγράφει εγγύηση, και σε νέο/άλλο χρήστη,
 *   νέα τροφοδοσία ή διακανονισμό. Το CRM δεν ξέρει αν ο χρήστης είναι ο ίδιος,
 *   άρα προτείνει το ποσό του πίνακα και το λέει στη σημείωση.
 * - Κοινόχρηστα από G40 και πάνω: «-», δεν προβλέπεται.
 *
 * Πρόταση, ΠΟΤΕ αυτόματο γέμισμα -- απόφαση ιδιοκτήτη 01/09, όπως όλες οι
 * προτάσεις εγγύησης.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Guarantee;

final class GasMeterGuarantee
{
    public const SOURCE = 'Πίνακας Εγγυήσεων ΦΑ Protergia v16';

    /** Τύπος μετρητή => [Οικιακά, Κοινόχρηστα, Εμπορικά]· null = δεν προβλέπεται. */
    private const TABLE = [
        'G2,5' => [60, 100, 100],
        'G4'   => [60, 100, 100],
        'G6'   => [150, 150, 300],
        'G10'  => [250, 250, 500],
        'G16'  => [450, 450, 700],
        'G25'  => [500, 500, 1000],
        'G40'  => [780, null, 1500],
        'G65'  => [780, null, 2500],
        'G100' => [780, null, 4500],
        'G160' => [780, null, 4500],
    ];

    private const COLUMN = ['home' => 0, 'communal' => 1, 'business' => 2];

    private const CATEGORY_LABEL = ['home' => 'Οικιακό', 'communal' => 'Κοινόχρηστο', 'business' => 'Εμπορικό'];

    private function __construct()
    {
    }

    /**
     * @return array{amount: float|null, reason: string}|null null όταν ο
     *         πίνακας δεν έχει λόγο (άγνωστος μετρητής ή κατηγορία).
     */
    public static function suggest(string $meter, string $category, string $payment, string $activation): ?array
    {
        $meter = self::meter($meter);

        if ($meter === '' || ! isset(self::COLUMN[$category])) {
            return null;
        }

        $label = $meter . ' · ' . self::CATEGORY_LABEL[$category];

        if ($payment === 'standing_order') {
            return ['amount' => 0.0, 'reason' => $label . ' · μηδενική με πάγια εντολή'];
        }

        $amount = self::TABLE[$meter][self::COLUMN[$category]];

        if ($amount === null) {
            return ['amount' => null, 'reason' => $label . ' · ο πίνακας δεν προβλέπει εγγύηση'];
        }

        $reason = $label;

        if (in_array($activation, ['change_provider', 'reconnection'], true)) {
            $reason .= ' · αν ο ίδιος χρήστης και ο λογαριασμός γράφει εγγύηση, ισχύει εκείνη';
        }

        return ['amount' => (float) $amount, 'reason' => $reason];
    }

    /** «G 2.5», «g2,5» → «G2,5»· ό,τι δεν είναι στον πίνακα → ''. */
    public static function meter(string $value): string
    {
        $value = strtoupper((string) preg_replace('/\s+/', '', $value));
        $value = str_replace('.', ',', $value);

        return isset(self::TABLE[$value]) ? $value : '';
    }
}
