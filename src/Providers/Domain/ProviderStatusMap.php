<?php

/**
 * Το λεξιλόγιο καταστάσεων ενός παρόχου, μεταφρασμένο στο δικό μας.
 *
 * ## Τι πρόβλημα λύνει
 *
 * Κάθε πάροχος γράφει τις καταστάσεις με τα δικά του λόγια — «ΕΝΕΡΓΟΠΟΙΗΘΗΚΕ»,
 * «ΣΕ ΕΞΕΛΙΞΗ», «ΑΠΟΡΡΙΦΘΗ». Ως τις 28/08 η αντιστοίχιση προς τις δικές μας
 * καταστάσεις γινόταν **κάθε φορά από την αρχή, στο χέρι**: το `statusMap`
 * ζούσε σε μεταβλητή του browser και πέθαινε με τη σελίδα. Δέκα πάροχοι, ο
 * καθένας με δικό του λεξιλόγιο, και ο ίδιος κόπος σε κάθε εισαγωγή.
 *
 * ## Γιατί ο κανόνας ζει εδώ και όχι σε JavaScript
 *
 * Οι ευρετικές αναγνώρισης («ενεργ» → `active`) ήταν η `guessStatus()` του
 * `ecrm-view-import.js`. Ένα cron ή ένα webhook δεν έχει browser: κάθε κανόνας
 * γραμμένος σε JavaScript είναι κανόνας που το μελλοντικό integration **δεν
 * μπορεί να χρησιμοποιήσει** (`HANDOVER.md` §1.13). Ο browser ζωγραφίζει· δεν
 * αποφασίζει.
 *
 * Καθαρό PHP, μηδέν WordPress, μηδέν SQL — τρέχει σε unit test χωρίς βάση και
 * μεταφέρεται αυτούσιο (§1.12).
 *
 * ## Η ιεραρχία των απαντήσεων
 *
 * Για κάθε τιμή του παρόχου, με αυτή τη σειρά:
 *
 * 1. **Αποθηκευμένη** — κάποιος άνθρωπος το αποφάσισε ήδη. Νικάει πάντα.
 * 2. **Εικασία** — ευρετική πάνω στο κείμενο, μόνο για τιμές που δεν έχουν
 *    ξαναδεί απόφαση.
 * 3. **Κενό** — ούτε απόφαση ούτε εικασία· η οθόνη το δείχνει ως «αγνόησε».
 *
 * Η σειρά είναι η ίδια με το `keepExisting` της φόρμας και με το `apply=1` της
 * εξαγωγής: **ό,τι έγραψε άνθρωπος νικάει ό,τι μάντεψε μηχανή.**
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Providers\Domain;

use EnergyCRM\Domain\Contract\ContractStatus;

final class ProviderStatusMap
{
    /**
     * Οι ευρετικές, με τη σειρά που δοκιμάζονται.
     *
     * Μεταφέρθηκαν αυτούσιες από τη `guessStatus()` του `ecrm-view-import.js`
     * (28/08/2026) — ίδια μοτίβα, ίδια σειρά, ώστε η μετακόμιση να μην αλλάξει
     * ούτε μία εικασία. Η σειρά μετράει: το «ΠΡΟΣ ΥΠΟΓΡΑΦΗ» ταιριάζει και με
     * το `υπογρ` και με τίποτε άλλο, αλλά μια μελλοντική προσθήκη πιο πάνω θα
     * μπορούσε να το κλέψει.
     *
     * @var array<string, string> μοτίβο => slug κατάστασης
     */
    private const GUESSES = [
        '/(ενεργ|active)/u'       => 'active',
        '/(ακυρ|cancel)/u'        => 'cancelled',
        '/(εκκρεμ|pending)/u'     => 'pending',
        '/(δρομολ|rout)/u'        => 'routed',
        '/(επιλ|resolv)/u'        => 'resolved',
        '/(υπογρ|sign)/u'         => 'pending_signature',
        '/(επεξεργ|process)/u'    => 'processing',
        '/(τερματ|terminat)/u'    => 'terminated',
        '/(απορρ|reject)/u'       => 'rejected',
        '/(νέα|νεα|new)/u'        => 'new',
    ];

    /** Πόσες διαφορετικές τιμές παρόχου κρατάμε. Πέρα από αυτό, κάτι πάει λάθος. */
    public const MAX_ENTRIES = 200;

    /** @var array<string, string> τιμή παρόχου => slug δικής μας κατάστασης */
    private array $entries;

    /** @param array<string, string> $entries */
    private function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Από ό,τι ήρθε — αποθηκευμένο JSON ή σώμα αιτήματος — κρατώντας μόνο ό,τι
     * βγάζει νόημα.
     *
     * **Καθαρίζει αντί να απορρίπτει**, και είναι απόφαση: ο χάρτης είναι
     * βοήθημα, όχι κρίσιμο δεδομένο. Μια άγνωστη κατάσταση (π.χ. slug που
     * καταργήθηκε) δεν πρέπει να μπλοκάρει ολόκληρη την εισαγωγή — απλώς
     * ξεχνιέται και η οθόνη ξαναρωτά.
     *
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $entries = [];

        foreach ($raw as $providerValue => $slug) {
            $value = trim((string) $providerValue);
            $slug  = is_string($slug) ? trim($slug) : '';

            if ($value === '' || $slug === '') {
                continue;
            }

            // Άγνωστο slug σημαίνει είτε λάθος πληκτρολόγηση είτε κατάσταση που
            // δεν υπάρχει πια. Και στις δύο περιπτώσεις το σωστό είναι να
            // ξεχαστεί, όχι να αποθηκευτεί και να αποτύχει σιωπηλά αργότερα.
            if (ContractStatus::tryFromSlug($slug) === null) {
                continue;
            }

            $entries[$value] = $slug;

            if (count($entries) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return new self($entries);
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return self::empty();
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? self::fromArray($decoded) : self::empty();
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Η απάντηση για κάθε τιμή που είδε το αρχείο, και από πού ήρθε.
     *
     * Επιστρέφει και την **προέλευση** κάθε απάντησης, όχι μόνο την απάντηση:
     * η οθόνη πρέπει να μπορεί να πει «τέσσερις ήρθαν έτοιμες, η πέμπτη είναι
     * καινούρια και ζητά απόφαση». Χωρίς αυτό θα έδειχνε πέντε συμπληρωμένα
     * κουτιά και ο χρήστης δεν θα ήξερε ποιο μάντεψε η μηχανή.
     *
     * @param list<string> $values
     *
     * @return array{map: array<string, string>, saved: list<string>, guessed: list<string>, unknown: list<string>}
     */
    public function resolve(array $values): array
    {
        $map     = [];
        $saved   = [];
        $guessed = [];
        $unknown = [];

        foreach ($values as $raw) {
            $value = trim($raw);

            if ($value === '') {
                continue;
            }

            if (isset($this->entries[$value])) {
                $map[$value] = $this->entries[$value];
                $saved[]     = $value;

                continue;
            }

            $guess = self::guess($value);

            if ($guess !== '') {
                $map[$value] = $guess;
                $guessed[]   = $value;

                continue;
            }

            $map[$value] = '';
            $unknown[]   = $value;
        }

        return ['map' => $map, 'saved' => $saved, 'guessed' => $guessed, 'unknown' => $unknown];
    }

    /** Η ευρετική: τι φαίνεται να σημαίνει αυτό το κείμενο, ή '' αν τίποτα. */
    public static function guess(string $value): string
    {
        $needle = mb_strtolower(trim($value));

        if ($needle === '') {
            return '';
        }

        foreach (self::GUESSES as $pattern => $slug) {
            if (preg_match($pattern, $needle) === 1) {
                return $slug;
            }
        }

        return '';
    }
}
