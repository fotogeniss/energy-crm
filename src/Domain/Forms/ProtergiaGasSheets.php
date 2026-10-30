<?php

/**
 * (321) Τα φύλλα φυσικού αερίου Protergia του Οκτωβρίου 2026, και οι κανόνες
 * που η ίδια η Protergia όρισε για την εκτύπωσή τους (email 21 και 22/10).
 *
 * ## Ποιο φύλλο
 *
 * Το πρόγραμμα αποφασίζει, όχι ο τύπος πελάτη -- ίδιος κανόνας με το (283)
 * στο ρεύμα. Τα οικιακά Flex/Standard τυπώνουν το οικιακό φύλλο
 * («Αυτόνομο/Κοινόχρηστο»), τα επαγγελματικά το «Εμπορικό» με επωνυμία,
 * Γ.Ε.ΜΗ., ΚΑΔ και νόμιμο εκπρόσωπο. Ως τώρα και τα τέσσερα έπεφταν στο
 * γενικό `protergia_fa`, που είναι το ΟΙΚΙΑΚΟ Value Gas Sure με τυπωμένο
 * πίνακα σταθερής τιμής -- ενώ τα τέσσερα είναι κυμαινόμενα.
 *
 * Το Value Gas Sure 2.0 και το Power+Gas δεν έχουν ακόμα δικό τους φύλλο
 * και μένουν στο `protergia_fa`. Η Protergia (26/10): το Sure 2.0 είναι
 * μόνο οικιακό, και το PDF του θα σταλεί μόλις ξαναμπεί στο site τους. Τα
 * παλιά Value Gas Sure / Single Value (ΕΟ.1250-2_94 / _87) δεν πωλούνται
 * πια -- ήδη ανενεργά από το `ProtergiaCatalogue::RETIRED`.
 *
 * ## Γενικοί Όροι (323)
 *
 * «Αποτελούν τη συνέχεια του συμβολαίου» και υπογράφονται στην τελευταία
 * σελίδα, στο «ΥΠΟΓΡΑΦΗ / ΣΦΡΑΓΙΔΑ ΠΕΛΑΤΗ». Τυπώνονται αμέσως μετά την
 * αίτηση (`tail`, σε A4 -- η αίτηση είναι 210×330). Η «Προσφορά
 * προμήθειας» του Standard είναι πληροφοριακή, χωρίς υπογραφή, και δεν
 * τυπώνεται.
 *
 * ## Οι κανόνες της Protergia
 *
 * - Διάρκεια ισχύος τιμολογίου ΚΑΙ σύμβασης: 12 μήνες σε όλα (τυπικό· στα
 *   κυμαινόμενα ο πελάτης φεύγει όποτε θέλει).
 * - Παρατηρήσεις: το πρόγραμμα και, αν υπάρχει, το promotion. ΟΧΙ ΚΟΤ.
 * - «Καταβολή εγγύησης με τον 1ο λογαριασμό»: όταν πληρώνει με μετρητά.
 *   Με πάγια εντολή μένει κενό και τικάρεται η «Πάγια εντολή εξόφλησης».
 * - «Ευάλωτος πελάτης» και «Άλλο»: ποτέ.
 * - Αριθμός παροχής ρεύματος (Power+Gas): μόνο αν τον έδωσε ο πωλητής·
 *   ποτέ ξανά ο ΗΚΑΣΠ.
 * - Εταιρεία (όχι ατομική): κάτω από την υπογραφή «ΟΝΟΜΑΤΕΠΩΝΥΜΟ - ΝΟΜΙΜΟΣ
 *   ΕΚΠΡΟΣΩΠΟΣ».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class ProtergiaGasSheets
{
    /** Πρόγραμμα => φύλλο. */
    private const SHEETS = [
        'protergia_gas_flex'          => 'protergia_fa_oik_flex',
        'protergia_gas_standard'      => 'protergia_fa_oik_standard',
        'protergia_epag_gas_flex'     => 'protergia_fa_epag_flex',
        'protergia_epag_gas_standard' => 'protergia_fa_epag_standard',
        // (337) Αίτηση + ΓΟΣ σε ένα A4 έξι σελίδων (Protergia, 30/10/2026).
        'protergia_gas_sure2'         => 'protergia_fa_oik_sure2',
    ];

    /** Το όνομα του προγράμματος όπως το γράφει η Protergia στα δικά της έντυπα. */
    private const NAMES = [
        'protergia_gas_flex'          => 'Value Gas Flex',
        'protergia_gas_standard'      => 'Value Gas Standard',
        'protergia_epag_gas_flex'     => 'Value Gas Flex',
        'protergia_epag_gas_standard' => 'Value Gas Standard',
        'protergia_gas_sure2'         => 'Value Gas Sure 2.0',
    ];

    /**
     * Οι τύποι μετρητή του πίνακα εγγυήσεων ΦΑ της Protergia (Version 16).
     * Η φόρμα τους δείχνει με αυτή τη σειρά.
     */
    public const METER_TYPES = ['G2,5', 'G4', 'G6', 'G10', 'G16', 'G25', 'G40', 'G65', 'G100', 'G160'];

    /** Διάρκεια ισχύος τιμολογίου και διάρκεια σύμβασης, σε όλα. */
    public const DURATION = '12 μήνες';

    private function __construct()
    {
    }

    /** Το φύλλο του προγράμματος, ή '' αν δεν έχει δικό του. */
    public static function templateKey(string $program): string
    {
        return self::SHEETS[$program] ?? '';
    }

    /** @return list<string> */
    public static function templates(): array
    {
        return array_values(self::SHEETS);
    }

    /**
     * Οι «Παρατηρήσεις»: πρόγραμμα · promotion. Χωρίς ειδική κατηγορία.
     *
     * Για πρόγραμμα που δεν ξέρουμε (παλιές αιτήσεις, πρόγραμμα που έφτιαξε ο
     * διαχειριστής) γράφεται το όνομα όπως είναι αποθηκευμένο στη σύμβαση.
     */
    public static function remarks(string $program, string $storedName, string $promotion): string
    {
        $name  = self::NAMES[$program] ?? trim($storedName);
        $promo = trim((string) preg_replace('/\s+/u', ' ', $promotion));

        return implode(' · ', array_values(array_filter([$name, $promo], static fn (string $s): bool => $s !== '')));
    }

    /** Δεκτή τιμή τύπου μετρητή, ή '' (π.χ. ό,τι έμεινε από παλιά). */
    public static function meterType(string $value): string
    {
        $v = trim($value);

        return in_array($v, self::METER_TYPES, true) ? $v : '';
    }

    /**
     * Η λεζάντα κάτω από την υπογραφή εταιρείας.
     *
     * Μόνο για `company`: η Protergia ζητά ρητά για ΟΕ, ΕΕ, ΑΕ, ΕΠΕ, ΙΚΕ κ.λπ.,
     * ενώ στην ατομική επιχείρηση υπογράφει ο ίδιος ο επιχειρηματίας.
     */
    public static function legalRepCaption(string $customerType, string $repName): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $repName));

        if ($customerType !== 'company' || $name === '') {
            return '';
        }

        // Ελληνικά κεφαλαία χωρίς τόνους: το mb_strtoupper κρατά το «Ά».
        $upper = strtr(mb_strtoupper($name, 'UTF-8'), [
            'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω',
        ]);

        return $upper . ' - ΝΟΜΙΜΟΣ ΕΚΠΡΟΣΩΠΟΣ';
    }
}
