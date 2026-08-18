<?php

/**
 * Ποιος κανόνας προμήθειας ισχύει για μια σύμβαση, και για πόσα.
 *
 * Η απόφαση ζούσε μέσα στην `ECRM_Commissions::amount_for()`, ανάμεσα σε ένα
 * ερώτημα και μια οθόνη διαχείρισης. Είναι όμως το σημείο που αποφασίζει
 * **χρήματα**, και δύο πράγματα το έκαναν αδοκίμαστο εκεί που ήταν: χρειαζόταν
 * βάση για να τρέξει, και δεν το έβλεπε ούτε το phpstan (το `admin/` είναι μόνο
 * scanned).
 *
 * Εδώ είναι καθαρή λογική — καμία βάση, κανένα WordPress — όπως ο
 * `ContractStatus`, ο `NetworkPath` και ο `ClientIp`, και για τον ίδιο λόγο: οι
 * κανόνες που κρίνουν λεφτά ή δικαιώματα αξίζουν να δηλώνονται σε test αντί να
 * τους εμπιστεύεται κανείς.
 *
 * ## Η ισοβαθμία δεν ήταν ισοβαθμία, ήταν τύχη
 *
 * Δύο ενεργοί κανόνες με την ίδια ειδικότητα — π.χ. δύο κανόνες μόνο με
 * `provider_id`, με διαφορετικά ποσά, που τίποτα στη φόρμα δεν εμποδίζει —
 * έδιναν ίδιο score, και νικούσε **όποιος ερχόταν πρώτος από τη βάση**. Η MySQL
 * δεν εγγυάται σειρά χωρίς `ORDER BY`: μπορεί να αλλάξει μετά από διαγραφή
 * γραμμής ή αλλαγή πλάνου. Ο συνεργάτης έβλεπε 12 € στην οθόνη του και η
 * εκκαθάριση υπολόγιζε 10 €, χωρίς να έχει αλλάξει τίποτα.
 *
 * Η σειρά είναι πλέον συμβόλαιο του καλούντος: **νεότερος κανόνας πρώτος**, και
 * σε ισοβαθμία κερδίζει αυτός. Είναι και ο τρόπος που το σκέφτεται ο άνθρωπος —
 * προσθέτεις καινούργιο κανόνα για να υπερισχύσει του παλιού.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Commission;

final class RuleMatch
{
    /**
     * Πόσο βαραίνει κάθε κριτήριο που ο κανόνας ορίζει και η σύμβαση ικανοποιεί.
     *
     * ## ΕΚΚΡΕΜΕΙ ΑΠΟΦΑΣΗ — μη το αλλάξεις χωρίς να ρωτήσεις
     *
     * Τα βάρη κωδικοποιούν **σειρά προτεραιότητας πεδίων**, όχι ειδικότητα, και
     * οι δύο δεν συμπίπτουν. Ένας κανόνας «όλοι οι πάροχοι + πρόγραμμα Blue
     * Home» παίρνει 4 και **χάνει** από τον «ΔΕΗ, οτιδήποτε» που παίρνει 8 —
     * παρότι ένα πρόγραμμα ανήκει σε έναν και μόνο πάροχο, άρα ταιριάζει σε
     * αυστηρά λιγότερες συμβάσεις. Το κείμενο της οθόνης υπόσχεται «ο πιο
     * ειδικός κανόνας υπερισχύει»· αυτό δεν το κάνει.
     *
     * Η φόρμα επιτρέπει ρητά «Πάροχος: Όλοι» με επιλεγμένο πρόγραμμα, οπότε δεν
     * είναι θεωρητικό.
     *
     * **Δεν αλλάχτηκε στις 18/08/2026 σκόπιμα:** ο πίνακας `commission_rules`
     * ήταν άδειος, οπότε η αλλαγή δεν θα διόρθωνε τίποτα σήμερα και θα άλλαζε
     * ποσά την ημέρα που θα μπουν κανόνες, χωρίς κανείς να το συνδέσει. Ο
     * ιδιοκτήτης το ανέβαλε ρητά **μέχρι να υπάρχουν κανόνες**.
     *
     * Όταν έρθει η ώρα, η αλλαγή είναι μία γραμμή εδώ — `program_id` πάνω από
     * `provider_id`, δηλαδή 16/8/4/2 — και ένα test που ήδη περιγράφει το
     * σημερινό συμπέρασμα θα κοκκινίσει και θα δείξει ακριβώς τι μετακινήθηκε.
     * Δες `docs/AUDIT-BACKEND.md`, εύρημα 3.
     *
     * @var array<string, int>
     */
    private const WEIGHTS = [
        'provider_id' => 8,
        'program_id'  => 4,
        'energy_type' => 2,
        'category'    => 1,
    ];

    /** Τα δύο κριτήρια που συγκρίνονται ως αριθμοί και όχι ως κείμενο. */
    private const NUMERIC = ['provider_id', 'program_id'];

    private function __construct()
    {
    }

    /**
     * Το ποσό του κανόνα που ταιριάζει καλύτερα, ή 0 όταν δεν ταιριάζει κανείς.
     *
     * @param list<array<string, mixed>>  $rules    Ενεργοί κανόνες, **νεότερος πρώτος**.
     * @param array<string, mixed>        $contract Γραμμή σύμβασης.
     */
    public static function amountFor(array $rules, array $contract): float
    {
        $best      = null;
        $bestScore = -1;

        foreach ($rules as $rule) {
            $score = self::score($rule, $contract);

            // Το `<=` και όχι `<` είναι όλη η διόρθωση: σε ισοβαθμία κρατιέται
            // ο πρώτος, και ο πρώτος είναι ο νεότερος επειδή ο καλών ταξινομεί.
            if ($score === null || $score <= $bestScore) {
                continue;
            }

            $bestScore = $score;
            $best      = $rule;
        }

        if ($best === null) {
            return 0.0;
        }

        $amount = $best['amount'] ?? 0;

        return is_scalar($amount) ? (float) $amount : 0.0;
    }

    /**
     * Πόσο ειδικός είναι ο κανόνας για αυτή τη σύμβαση, ή null αν δεν ταιριάζει.
     *
     * Ένα κριτήριο που ο κανόνας αφήνει κενό δεν βαθμολογείται και δεν
     * αποκλείει: «κενό» σημαίνει «οποιοδήποτε».
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $contract
     */
    private static function score(array $rule, array $contract): ?int
    {
        $score = 0;

        foreach (self::WEIGHTS as $column => $weight) {
            $required = $rule[$column] ?? null;

            if (! self::isConstraint($required)) {
                continue;
            }

            $matches = in_array($column, self::NUMERIC, true)
                ? self::toInt($required) === self::toInt($contract[$column] ?? null)
                : self::toText($required) === self::toText($contract[$column] ?? null);

            if (! $matches) {
                return null;
            }

            $score += $weight;
        }

        return $score;
    }

    /**
     * Αναπαράγει τον έλεγχο αλήθειας του παλιού κώδικα (`if ($r['provider_id'])`)
     * ρητά, ώστε το `0`, το `'0'`, το `''` και το `null` να συνεχίσουν να
     * σημαίνουν «χωρίς περιορισμό» και μετά τη μεταφορά.
     */
    private static function isConstraint(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        $text = (string) $value;

        return $text !== '' && $text !== '0';
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
