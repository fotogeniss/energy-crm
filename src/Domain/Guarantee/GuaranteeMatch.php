<?php

/**
 * Ποιο ποσό εγγύησης προτείνεται για μια σύμβαση, και γιατί αυτό.
 *
 * Δίδυμο του `Domain\Commission\RuleMatch` — ίδια ιδέα (κανόνες με κενά
 * κριτήρια = «οποιοδήποτε», νικά ο πιο ειδικός), καθαρή λογική χωρίς βάση και
 * χωρίς WordPress, ώστε η απόφαση να δηλώνεται σε test αντί να την
 * εμπιστεύεται κανείς. Δύο πράγματα όμως ΔΕΝ αντιγράφηκαν, και είναι και τα
 * δύο σκόπιμα:
 *
 * ## 1. Το «κανένας κανόνας» δεν είναι μηδέν ευρώ
 *
 * Ο `RuleMatch::amountFor()` επιστρέφει `0.0` όταν δεν ταιριάζει κανείς. Εκεί
 * είναι σωστό: προμήθεια που δεν έχει κανόνα είναι όντως μηδέν.
 *
 * Εδώ θα ήταν σφάλμα. **Η μηδενική εγγύηση είναι υπαρκτή τιμή** — ο πάροχος
 * που δεν ζητά εγγύηση είναι κανόνας με `amount = 0`, όχι απουσία κανόνα. Αν
 * τα δύο έδιναν την ίδια απάντηση, η οθόνη δεν θα μπορούσε ποτέ να ξεχωρίσει
 * το «δεν έχω πρόταση, γράψε εσύ» από το «η πρόταση είναι 0 €». Γι' αυτό η
 * επιστροφή είναι `?float`: `null` σημαίνει σιωπή.
 *
 * ## 2. Τα βάρη μπαίνουν σωστά από την πρώτη μέρα
 *
 * Ο `RuleMatch` κουβαλά τεκμηριωμένη ανοιχτή απόφαση: τα βάρη του κωδικοποιούν
 * **σειρά πεδίων** και όχι ειδικότητα, οπότε ένας κανόνας «όλοι οι πάροχοι +
 * συγκεκριμένο πρόγραμμα» χάνει από τον «συγκεκριμένος πάροχος + οτιδήποτε»,
 * παρότι ταιριάζει σε αυστηρά λιγότερες συμβάσεις. Δεν διορθώθηκε εκεί επειδή
 * θα άλλαζε ποσά την ημέρα που θα μπουν κανόνες, χωρίς κανείς να το συνδέσει.
 *
 * Εδώ δεν υπάρχει τέτοιο εμπόδιο — ο πίνακας γεννιέται άδειος. Άρα το
 * `program_id` βαραίνει περισσότερο από το `provider_id` (ένα πρόγραμμα ανήκει
 * σε έναν πάροχο, άρα είναι πάντα στενότερο κριτήριο), δηλαδή ακριβώς η
 * διάταξη 16/8/4/2/1 που ο `RuleMatch` περιγράφει ως τη μελλοντική του
 * διόρθωση. **Τα δύο αρχεία διαφωνούν συνειδητά** μέχρι να ληφθεί εκείνη η
 * απόφαση· δεν είναι αντιγραφή που ξέφυγε.
 *
 * ## Η κλίμακα kVA
 *
 * Το μόνο κριτήριο που δεν είναι ισότητα. `kva_min`/`kva_max` κενά σημαίνουν
 * «για κάθε ισχύ» — όπως κάθε άλλο κενό κριτήριο. Όταν οριστούν, τα όρια είναι
 * **και τα δύο συμπεριληπτικά**, επειδή έτσι διαβάζει ένας άνθρωπος το «8-15
 * kVA» όταν το γράφει στην οθόνη. Η επικάλυψη στα άκρα είναι υπαρκτή και
 * λύνεται από την ισοβαθμία (νεότερος κανόνας νικά), όχι από σιωπηλή
 * στρογγυλοποίηση.
 *
 * Αν ο κανόνας ορίζει ισχύ και η σύμβαση δεν έχει αναγνώσιμη τιμή, ο κανόνας
 * **δεν** ταιριάζει. Η εναλλακτική θα ήταν να προταθεί ποσό που κανείς δεν
 * μπορεί να δικαιολογήσει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Guarantee;

final class GuaranteeMatch
{
    /**
     * Πόσο βαραίνει κάθε κριτήριο που ο κανόνας ορίζει και η σύμβαση ικανοποιεί.
     *
     * Ειδικότητα, όχι σειρά πεδίων — δες το σχόλιο του αρχείου.
     *
     * @var array<string, int>
     */
    private const WEIGHTS = [
        'program_id'  => 16,
        'provider_id' => 8,
        'kva'         => 4,
        'energy_type' => 2,
        'category'    => 1,
    ];

    /** Τα κριτήρια που συγκρίνονται ως αριθμοί και όχι ως κείμενο. */
    private const NUMERIC = ['provider_id', 'program_id'];

    private function __construct()
    {
    }

    /**
     * Το ποσό του κανόνα που ταιριάζει καλύτερα, ή null όταν δεν ταιριάζει κανείς.
     *
     * @param list<array<string, mixed>> $rules    Ενεργοί κανόνες, **νεότερος πρώτος**.
     * @param array<string, mixed>       $contract Γραμμή σύμβασης· το `agreed_power` έρχεται
     *                                             από το extras bag, ως κείμενο.
     */
    public static function amountFor(array $rules, array $contract): ?float
    {
        $best      = null;
        $bestScore = -1;

        foreach ($rules as $rule) {
            $score = self::score($rule, $contract);

            // `<=` και όχι `<`: σε ισοβαθμία κρατιέται ο πρώτος, και ο πρώτος
            // είναι ο νεότερος επειδή ο καλών ταξινομεί. Ίδιο συμβόλαιο με τον
            // RuleMatch, για τον ίδιο λόγο (η MySQL δεν εγγυάται σειρά χωρίς
            // ORDER BY).
            if ($score === null || $score <= $bestScore) {
                continue;
            }

            $bestScore = $score;
            $best      = $rule;
        }

        if ($best === null) {
            return null;
        }

        $amount = $best['amount'] ?? 0;

        return is_scalar($amount) ? (float) $amount : 0.0;
    }

    /**
     * Πόσο ειδικός είναι ο κανόνας για αυτή τη σύμβαση, ή null αν δεν ταιριάζει.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $contract
     */
    private static function score(array $rule, array $contract): ?int
    {
        $score = 0;

        foreach (self::WEIGHTS as $criterion => $weight) {
            $verdict = 'kva' === $criterion
                ? self::matchesPower($rule, $contract)
                : self::matchesColumn($criterion, $rule, $contract);

            if (null === $verdict) {
                continue;
            }

            if (false === $verdict) {
                return null;
            }

            $score += $weight;
        }

        return $score;
    }

    /**
     * true ταιριάζει, false αποκλείει, null ο κανόνας δεν ορίζει το κριτήριο.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $contract
     */
    private static function matchesColumn(string $column, array $rule, array $contract): ?bool
    {
        $required = $rule[$column] ?? null;

        if (! self::isConstraint($required)) {
            return null;
        }

        return in_array($column, self::NUMERIC, true)
            ? self::toInt($required) === self::toInt($contract[$column] ?? null)
            : self::toText($required) === self::toText($contract[$column] ?? null);
    }

    /**
     * Η κλίμακα ισχύος: και τα δύο όρια συμπεριληπτικά, κενό όριο = ανοιχτό.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $contract
     */
    private static function matchesPower(array $rule, array $contract): ?bool
    {
        $min = self::toNumber($rule['kva_min'] ?? null);
        $max = self::toNumber($rule['kva_max'] ?? null);

        if (null === $min && null === $max) {
            return null;
        }

        $power = self::toNumber($contract['agreed_power'] ?? null);

        // Ο κανόνας μιλά για ισχύ και η σύμβαση δεν τη δηλώνει: δεν μπορεί να
        // επιβεβαιωθεί, άρα δεν ταιριάζει.
        if (null === $power) {
            return false;
        }

        if (null !== $min && $power < $min) {
            return false;
        }

        return null === $max || $power <= $max;
    }

    /**
     * Αναπαράγει ρητά τον έλεγχο αλήθειας του `RuleMatch`, ώστε το `0`, το `'0'`,
     * το `''` και το `null` να σημαίνουν «χωρίς περιορισμό».
     */
    private static function isConstraint(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        $text = (string) $value;

        return '' !== $text && '0' !== $text;
    }

    /**
     * Αριθμός από ό,τι γράφτηκε σε πεδίο κειμένου, ή null αν δεν υπάρχει.
     *
     * Το `agreed_power` είναι ελεύθερο πεδίο του extras bag: έχει γραφτεί «8»,
     * «8,5» και «8 kVA» από τον ίδιο άνθρωπο μέσα στην ίδια εβδομάδα. Το κόμμα
     * γίνεται τελεία και κρατιέται το αριθμητικό πρόθεμα· ό,τι δεν αρχίζει με
     * αριθμό δεν είναι ισχύς και επιστρέφει null αντί για σιωπηλό 0.
     */
    private static function toNumber(mixed $value): ?float
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = str_replace(',', '.', trim((string) $value));

        if ('' === $text || 1 !== preg_match('/^-?\d+(\.\d+)?/', $text, $m)) {
            return null;
        }

        return (float) $m[0];
    }

    private static function toInt(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }

    private static function toText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
