<?php

/**
 * (431) Εγγύηση ρεύματος Protergia από την ισχύ και τον χρήστη.
 *
 * Πίνακες «Εγγυήσεις ΗΕ», Version 16 (Protergia, σελ. 7 και 8· Back Office
 * 20/11/2026). Οι κανόνες της σελίδας Ρυθμίσεων ξέρουν μόνο ισχύ· ο πίνακας
 * της Protergia κρίνει και από το αν αλλάζει ο χρήστης, την ειδική κατηγορία
 * (ΚΟΤ) και την πάγια εντολή.
 *
 * Τι λέει ο πίνακας και πώς διαβάζεται εδώ:
 * - Οικιακά και κοινόχρηστα έως 25 kVA (σελ. 7): ίδια ποσά για ίδιο και νέο
 *   χρήστη (68 / 110 / 735 €). Στον ίδιο χρήστη ισχύει η ΜΙΚΡΟΤΕΡΗ ανάμεσα σε
 *   αυτό και στην εγγύηση του προηγούμενου προμηθευτή. ΚΟΤ και ευάλωτοι: 40 €.
 * - Επαγγελματικά και κοινόχρηστα από 35 kVA (σελ. 8): ίδιος χρήστης = η
 *   ΜΕΓΑΛΥΤΕΡΗ ανάμεσα στον πίνακα ελάχιστης εγγύησης και στην εγγύηση του
 *   προηγούμενου προμηθευτή· νέος χρήστης = δικός του πίνακας.
 * - «Μηδενική εγγύηση με πάγια εντολή» (εκτός Καθολικής Υπηρεσίας).
 * - Διακανονισμός πάνω από 400 € και Καθολική Υπηρεσία έχουν άλλα ποσά· το
 *   CRM δεν τα ξέρει, οπότε προτείνει τη συνηθισμένη περίπτωση και το λέει.
 *
 * Ίδιος χρήστης = «Αλλαγή προμηθευτή»· νέος χρήστης = διαδοχή, επανασύνδεση,
 * νέα σύνδεση (ο πίνακας: «αλλαγή προμηθευτή στα στοιχεία νέου χρήστη,
 * επανενεργοποίηση, νέα ενεργοποίηση, αλλαγή χρήσης»). Ανανέωση και αλλαγή
 * προγράμματος μένουν στον ίδιο πάροχο με την εγγύηση που ήδη έχει: καμία
 * πρόταση από τον πίνακα.
 *
 * Πρόταση, ΠΟΤΕ αυτόματο γέμισμα (απόφαση ιδιοκτήτη 01/09).
 *
 * Χωρίς WordPress, tested.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Guarantee;

final class PowerGuarantee
{
    public const SOURCE = 'Πίνακας Εγγυήσεων ΗΕ Protergia v16';

    /** Οικιακά / κοινόχρηστα ≤ 25 kVA: [έως kVA, ευρώ]. */
    private const HOME = [[25, 68], [55, 110], [PHP_INT_MAX, 735]];

    /** Επαγγελματικά, ίδιος χρήστης: ελάχιστη εγγύηση. */
    private const BUSINESS_SAME = [
        [15, 130], [25, 170], [35, 480], [55, 790], [85, 1680], [135, 2700], [PHP_INT_MAX, 4600],
    ];

    /** Επαγγελματικά, νέος χρήστης. */
    private const BUSINESS_NEW = [
        [8, 135], [15, 220], [25, 340], [35, 570], [55, 950], [85, 1850], [135, 2900], [PHP_INT_MAX, 5350],
    ];

    private const KOT = 40;

    /** Ίδιος χρήστης: οι πίνακες με διακανονισμό και η Καθολική Υπηρεσία έχουν άλλα ποσά. */
    private const NOTE_SAME = ' · σε διακανονισμό πάνω από 400 € ή Καθολική Υπηρεσία ισχύει άλλο ποσό';

    /** Νέος χρήστης: μόνο η Καθολική Υπηρεσία (προκαταβολή) έχει άλλο ποσό. */
    private const NOTE_UNIVERSAL = ' · σε Καθολική Υπηρεσία ισχύει άλλο ποσό (προκαταβολή)';

    /** Μένουν στον ίδιο πάροχο, με την εγγύηση που ήδη έχουν. */
    private const NO_TABLE = ['renewal', 'program_change'];

    private const NEW_USER = ['succession', 'reconnection', 'new_connection'];

    private const LABEL = ['home' => 'Οικιακό', 'communal' => 'Κοινόχρηστο', 'business' => 'Επαγγελματικό'];

    private function __construct()
    {
    }

    /**
     * @return array{amount: float, reason: string}|null null όταν ο πίνακας δεν
     *         έχει λόγο (ανανέωση, αλλαγή προγράμματος, χωρίς ισχύ, άγνωστη κατηγορία).
     */
    public static function suggest(
        float $kva,
        string $category,
        string $payment,
        string $activation,
        string $special = '',
    ): ?array {
        // Χωρίς ισχύ ο πίνακας δεν απαντά, και αποφασίζουν οι κανόνες των
        // Ρυθμίσεων, όπως στο αέριο χωρίς τύπο μετρητή.
        if (! isset(self::LABEL[$category]) || in_array($activation, self::NO_TABLE, true) || $kva <= 0) {
            return null;
        }

        $label   = self::LABEL[$category] . ' · ' . self::kvaText($kva) . ' kVA';
        $newUser = in_array($activation, self::NEW_USER, true);

        if ($payment === 'standing_order') {
            return ['amount' => 0.0, 'reason' => $label . ' · μηδενική με πάγια εντολή (όχι σε Καθολική Υπηρεσία)'];
        }

        // Ο πίνακας της σελ. 7 είναι «κοινόχρηστες ≤ 25 kVA & οικιακές»· τα
        // κοινόχρηστα πάνω από 25 kVA πάνε στη σελ. 8, μαζί με τα επαγγελματικά.
        $homeTable = $category === 'home' || ($category === 'communal' && $kva <= 25);

        if ($homeTable) {
            if (! $newUser && self::isKot($special)) {
                return ['amount' => (float) self::KOT, 'reason' => $label . ' · ΚΟΤ / ευάλωτος'];
            }

            $reason = $label . ($newUser
                ? ' · νέος χρήστης' . self::NOTE_UNIVERSAL
                : ' · ίδιος χρήστης: ή της εγγύησης του προηγούμενου προμηθευτή, αν είναι μικρότερη' . self::NOTE_SAME);

            return ['amount' => (float) self::from(self::HOME, $kva), 'reason' => $reason];
        }

        if ($newUser) {
            return [
                'amount' => (float) self::from(self::BUSINESS_NEW, $kva),
                'reason' => $label . ' · νέος χρήστης' . self::NOTE_UNIVERSAL,
            ];
        }

        // (431β) Και στα επαγγελματικά ο πίνακας έχει άλλα ποσά με διακανονισμό
        // πάνω από 400 € και στην Καθολική Υπηρεσία (σελ. 8)· η αιτία το λέει.
        return [
            'amount' => (float) self::from(self::BUSINESS_SAME, $kva),
            'reason' => $label . ' · ίδιος χρήστης: ή της εγγύησης του προηγούμενου προμηθευτή, αν είναι μεγαλύτερη'
                . ' · αν αλλάζει χρήστης: ' . self::euro(self::from(self::BUSINESS_NEW, $kva)) . ' €'
                . self::NOTE_SAME,
        ];
    }

    /** «ΚΟΤ Α», «κοτ», «Ευάλωτος Πελάτης»: ο πίνακας τους χειρίζεται το ίδιο. */
    public static function isKot(string $special): bool
    {
        return preg_match('/κοτ|kot|ευάλωτ|ευαλωτ/iu', $special) === 1;
    }

    /** @param list<array{int, int}> $table */
    private static function from(array $table, float $kva): int
    {
        foreach ($table as [$upTo, $euro]) {
            if ($kva <= $upTo) {
                return $euro;
            }
        }

        return $table[count($table) - 1][1];
    }

    private static function kvaText(float $kva): string
    {
        return rtrim(rtrim(number_format($kva, 1, ',', ''), '0'), ',');
    }

    private static function euro(int $amount): string
    {
        return number_format($amount, 0, ',', '.');
    }
}
