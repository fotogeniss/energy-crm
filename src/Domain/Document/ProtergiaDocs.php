<?php

/**
 * (432) Τα δικαιολογητικά που ζητά η Protergia, ανά είδος αίτησης.
 *
 * Πηγή: Back Office, 19 και 20/11/2026 (email «ΔΙΚΑΙΟΛΟΓΗΤΙΚΑ-ΕΓΓΥΗΣΕΙΣ» για
 * το ρεύμα, «Απαραίτητα Δικαιολογητικά Φυσικού Αερίου» της Protergia v16).
 *
 * Ο γενικός πίνακας (`ECRM_Docs::required_map()`) ζητά για όλους τους
 * παρόχους λίγα και πάντα όλα μαζί. Η Protergia ζητά περισσότερα, και συχνά
 * «ένα από» (Ε9 ή μισθωτήριο ή τελικός λογαριασμός). Γι' αυτό κάθε απαίτηση
 * είναι ομάδα ειδών εγγράφου: αρκεί ένα από αυτά.
 *
 * Απόφαση ιδιοκτήτη 20/11: υποχρεωτικά μόνο σε αιτήσεις από το `FROM` και
 * μετά, ώστε όσες τρέχουν ήδη να μην κολλήσουν (ίδια λογική με το K1 στη
 * ΔΕΗ). Τα «αν ισχύει» (διακανονισμός, Καθολική Υπηρεσία, κοινόχρηστο) το CRM
 * δεν τα ξέρει: λέγονται ως υπενθύμιση, δεν μπλοκάρουν.
 *
 * Χωρίς WordPress, tested.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Document;

/**
 * @phpstan-type DocItem array{key: string, label: string, hint: string, kinds: list<string>, field: string,
 *                              unless: list<string>}
 * @phpstan-type DocList array{required: list<DocItem>, reminders: list<string>}
 */
final class ProtergiaDocs
{
    public const SLUG = 'protergia';

    /** Αιτήσεις που δημιουργούνται από εδώ και μετά ακολουθούν αυτόν τον πίνακα. */
    public const FROM = '2026-11-21 00:00:00';

    /** Επαγγελματικό ρεύμα από τόση ισχύ: χωρίς ενδείξεις (Γ22). */
    private const NO_READINGS_FROM_KVA = 35.0;

    private const DEBT = 'Διακανονισμός: δοσολόγιο και εξόφληση της τρέχουσας δόσης';

    private const UNIVERSAL = 'Καθολική Υπηρεσία: απόδειξη κατάθεσης της εγγύησης';

    private const EXPIRED_BILL = 'Ληγμένος λογαριασμός: απόδειξη εξόφλησης';

    private const COMMUNAL = 'Κοινόχρηστο: υπεύθυνη δήλωση διαχειριστή με την απόφαση της γενικής συνέλευσης '
        . '(όχι σε οικογενειακή πολυκατοικία)';

    private const EDA_THESS = 'ΕΔΑ Θεσσαλονίκης: φωτογραφία μετρητή ή υπεύθυνη δήλωση με τις ενδείξεις (υποχρεωτικό)';

    private const EDA_THESS_OPTIONAL = 'ΕΔΑ Θεσσαλονίκης: φωτογραφία μετρητή (προαιρετική)';

    private const GAS_NEW_USER = 'Αν αλλάζει και ο χρήστης: εξοφλημένος λογαριασμός, ή αποδεικτικό ΗΚΑΣΠ μαζί με '
        . 'μισθωτήριο / Ε9';

    private const GAS_OTHER_DETAILS = 'Αν η σύμβαση ΕΔΑ έχει άλλα στοιχεία από την αίτηση: και μισθωτήριο ή Ε1';

    private function __construct()
    {
    }

    /**
     * Ισχύει ο πίνακας για αυτή την αίτηση; Νέα αίτηση (χωρίς ημερομηνία)
     * μετρά ως νέα.
     */
    public static function applies(string $providerSlug, string $energy, string $createdAt): bool
    {
        if ($providerSlug !== self::SLUG || ! in_array($energy, ['power', 'gas'], true)) {
            return false;
        }

        return trim($createdAt) === '' || $createdAt >= self::FROM;
    }

    /**
     * @param array{activation: string, energy: string, category?: string, customer_type?: string,
     *              company_type?: string, kva?: float} $c
     *
     * @return DocList|null null: ο πίνακας δεν λέει κάτι γι' αυτό το είδος (ανανέωση, αλλαγή
     *        προγράμματος)· ισχύει ο γενικός.
     */
    public static function requirements(array $c): ?array
    {
        $activation = $c['activation'];
        $company    = ($c['customer_type'] ?? '') === 'company';
        $business   = ($c['category'] ?? '') === 'business' || $company;

        $id = self::item(
            'id',
            $company ? 'Ταυτότητα/Διαβατήριο νόμιμου εκπροσώπου' : 'Ταυτότητα/Διαβατήριο',
            '',
            ['id_card']
        );

        $list = $c['energy'] === 'gas'
            ? self::gas($activation, $id)
            : self::power($activation, $id, $business, (float) ($c['kva'] ?? 0));

        if ($list === null) {
            return null;
        }

        if ($c['energy'] === 'power' && $business) {
            $papers           = self::companyPapers((string) ($c['company_type'] ?? ''));
            $list['required'] = array_merge($list['required'], $papers);
        }

        if ($c['energy'] === 'power' && ($c['category'] ?? '') === 'communal') {
            $list['reminders'][] = self::COMMUNAL;
        }

        return $list;
    }

    /**
     * @param DocItem $id
     * @return DocList|null
     */
    private static function power(string $activation, array $id, bool $business, float $kva): ?array
    {
        $photo = self::item(
            'meter_photo',
            'Φωτογραφία μετρητή',
            'να φαίνεται ο αριθμός παροχής στο έλασμα',
            ['meter_photo']
        );

        switch ($activation) {
            case 'change_provider':
                $required = [
                    $id,
                    self::item('bill', 'Λογαριασμός παρόχου', 'ο τελευταίος ολοσέλιδος', ['provider_bill']),
                ];

                if (! ($business && $kva >= self::NO_READINGS_FROM_KVA)) {
                    $required[] = self::item(
                        'readings',
                        'Ενδείξεις μετρητή',
                        'γραμμένες στη φόρμα ή φωτογραφία μετρητή',
                        ['meter_photo'],
                        'day_indication'
                    );
                }

                return ['required' => $required, 'reminders' => [self::EXPIRED_BILL, self::DEBT, self::UNIVERSAL]];

            case 'new_connection':
                return [
                    'required'  => [
                        $id,
                        self::item('connection', 'Σύμβαση σύνδεσης με τον ΔΕΔΔΗΕ', '', ['connection_contract']),
                    ],
                    'reminders' => [self::UNIVERSAL],
                ];

            case 'reconnection':
                return [
                    'required'  => [
                        $id,
                        $photo,
                        self::item(
                            'right',
                            'Ε9, μισθωτήριο ή τελικός εξοφλημένος λογαριασμός',
                            'ένα από τα τρία',
                            ['e9', 'lease', 'provider_bill']
                        ),
                    ],
                    'reminders' => [self::UNIVERSAL],
                ];

            case 'succession':
                return [
                    'required'  => [
                        $id,
                        $photo,
                        self::item(
                            'right',
                            'Λογαριασμός παρόχου, Ε9 ή μισθωτήριο',
                            'ένα από τα τρία',
                            ['provider_bill', 'e9', 'lease']
                        ),
                    ],
                    'reminders' => [self::UNIVERSAL],
                ];
        }

        return null;
    }

    /**
     * @param DocItem $id
     * @return DocList|null
     */
    private static function gas(string $activation, array $id): ?array
    {
        // (432β) Σελ. 9 του PDF, γραμμή-γραμμή. «Νέα στοιχεία χρήστη» και
        // «Επανασύνδεση»: εξοφλημένος λογαριασμός, ΑΛΛΙΩΣ αποδεικτικό ΗΚΑΣΠ
        // ΜΑΖΙ με μισθωτήριο ή Ε9. Δύο γραμμές: η δεύτερη δεν χρειάζεται όταν
        // υπάρχει λογαριασμός (`unless`).
        $billOrProof = static fn (string $bill): array => [
            self::item(
                'proof',
                $bill . ' ή αποδεικτικό ΗΚΑΣΠ',
                'ένα από τα δύο',
                ['provider_bill', 'connection_contract']
            ),
            self::item(
                'title',
                'Μισθωτήριο ή Ε9',
                'μαζί με το αποδεικτικό ΗΚΑΣΠ· δεν χρειάζεται με λογαριασμό',
                ['lease', 'e9'],
                '',
                ['provider_bill']
            ),
        ];

        switch ($activation) {
            case 'change_provider':
                return [
                    'required'  => [$id, self::item('bill', 'Λογαριασμός παρόχου', 'ο τελευταίος', ['provider_bill'])],
                    'reminders' => [self::GAS_NEW_USER, self::EDA_THESS_OPTIONAL],
                ];

            case 'new_connection':
                return [
                    'required'  => [
                        $id,
                        self::item('connection', 'Σύμβαση σύνδεσης με την ΕΔΑ', '', ['connection_contract']),
                    ],
                    'reminders' => [self::GAS_OTHER_DETAILS],
                ];

            case 'reconnection':
                return ['required' => array_merge([$id], $billOrProof('Τελικός λογαριασμός')), 'reminders' => []];

            case 'succession':
                // «Διαδοχή (Μεταβίβαση Παροχής Protergia)»: λογαριασμός, ή
                // μισθωτήριο / Ε9, χωρίς ΗΚΑΣΠ.
                return [
                    'required'  => [
                        $id,
                        self::item(
                            'right',
                            'Εξοφλημένος λογαριασμός, μισθωτήριο ή Ε9',
                            'ένα από τα τρία',
                            ['provider_bill', 'lease', 'e9']
                        ),
                    ],
                    'reminders' => [self::EDA_THESS],
                ];
        }

        return null;
    }

    /**
     * Έναρξη δραστηριότητας πάντα· καταστατικό και ΦΕΚ μόνο σε εταιρεία (όχι
     * ατομική επιχείρηση). Χωρίς νομική μορφή γραμμένη, ζητούνται και τα τρία:
     * αλλιώς μια Α.Ε. χωρίς συμπληρωμένο πεδίο θα περνούσε χωρίς ΦΕΚ.
     *
     * @return list<DocItem>
     */
    private static function companyPapers(string $companyType): array
    {
        $papers = [self::item('kad', 'Έναρξη δραστηριότητας με ΚΑΔ', '', ['kad'])];

        if (self::isSoleTrader($companyType)) {
            return $papers;
        }

        $papers[] = self::item('statute', 'Καταστατικό', 'σε ατομική επιχείρηση δεν χρειάζεται', ['gemi']);
        $papers[] = self::item('fek', 'ΦΕΚ εκπροσώπησης', 'σε ατομική επιχείρηση δεν χρειάζεται', ['fek']);

        return $papers;
    }

    public static function isSoleTrader(string $companyType): bool
    {
        return preg_match('/ατομικ|atomik/iu', $companyType) === 1;
    }

    /**
     * @param list<string> $kinds
     * @param list<string> $unless Αν υπάρχει ένα από αυτά, η γραμμή δεν χρειάζεται.
     * @return DocItem
     */
    private static function item(
        string $key,
        string $label,
        string $hint,
        array $kinds,
        string $field = '',
        array $unless = [],
    ): array {
        return [
            'key'    => $key,
            'label'  => $label,
            'hint'   => $hint,
            'kinds'  => $kinds,
            'field'  => $field,
            'unless' => $unless,
        ];
    }
}
