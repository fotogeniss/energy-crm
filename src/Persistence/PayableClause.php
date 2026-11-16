<?php

/**
 * (404) Ποιες αιτήσεις έχουν κερδίσει προμήθεια: όσες έγιναν ΠΟΤΕ Ενεργές.
 *
 * Απόφαση ιδιοκτήτη 16/11 (επιλογή α), ίδια με τον κανόνα της ακύρωσης
 * (`CancellationGate::keepsCommission()`, 05/10): η αίτηση που δούλεψε κρατά
 * την προμήθειά της, όπου κι αν βρίσκεται τώρα. Ως τότε οι Προμήθειες και οι
 * Εκκαθαρίσεις κοίταζαν μόνο την ΤΩΡΙΝΗ κατάσταση: μια Ενεργή που περνούσε σε
 * «Εκκρεμότητα», «Οφειλή» ή «Ακυρώθηκε» πριν μπει σε παρτίδα δεν πληρωνόταν
 * ποτέ, αν και ο κανόνας της ακύρωσης έλεγε ότι κρατά την προμήθεια.
 *
 * Το ίδιο κείμενο SQL για κάθε ερώτημα που μετρά λεφτά, ώστε η οθόνη
 * Προμηθειών, οι Εκκαθαρίσεις και η κατάταξη να μη διαφωνούν ποτέ.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class PayableClause
{
    private function __construct()
    {
    }

    /**
     * «Είναι πληρωτέα τώρα Ή έγινε κάποτε πληρωτέα». Ο πίνακας των αιτήσεων
     * ΠΡΕΠΕΙ να έχει το $alias: στο υποερώτημα, ένα σκέτο `id` θα διάβαζε το
     * id του γεγονότος.
     *
     * @param list<string> $payable
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function earned(array $payable, string $alias): array
    {
        $in = self::placeholders($payable);

        return [
            "({$alias}.status IN ({$in}) OR EXISTS (SELECT 1 FROM %i pe "
            . "WHERE pe.contract_id = {$alias}.id AND pe.to_status IN ({$in})))",
            [...$payable, Tables::name(Tables::EVENTS), ...$payable],
        ];
    }

    /**
     * Η αντίθετη: δεν έγινε ποτέ πληρωτέα. Για την «αναμενόμενη» προμήθεια,
     * ώστε μια αίτηση που κέρδισε και μετά πήγε σε «Εκκρεμότητα» να μη
     * μετρά δύο φορές.
     *
     * @param list<string> $payable
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function neverEarned(array $payable, string $alias): array
    {
        $in = self::placeholders($payable);

        return [
            "NOT EXISTS (SELECT 1 FROM %i pe WHERE pe.contract_id = {$alias}.id AND pe.to_status IN ({$in}))",
            [Tables::name(Tables::EVENTS), ...$payable],
        ];
    }

    /**
     * Πότε κερδήθηκε: η ΠΡΩΤΗ φορά που έγινε πληρωτέα. Ο μήνας της
     * προμήθειας είναι αυτός, όχι η τελευταία αλλαγή της αίτησης (μια
     * διόρθωση τηλεφώνου τη μετακόμιζε στον τρέχοντα μήνα).
     *
     * @param list<string> $payable
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function earnedAt(array $payable, string $alias): array
    {
        $in = self::placeholders($payable);

        return [
            "(SELECT MIN(pa.created_at) FROM %i pa WHERE pa.contract_id = {$alias}.id AND pa.to_status IN ({$in}))",
            [Tables::name(Tables::EVENTS), ...$payable],
        ];
    }

    /**
     * Ο μήνας (Y-m) της προμήθειας μιας γραμμής: κέρδισης, αλλιώς τελευταίας αλλαγής.
     *
     * @param array<string, mixed> $row
     */
    public static function monthOf(array $row): string
    {
        $at = (string) ($row['earned_at'] ?? '');

        return substr($at !== '' ? $at : (string) ($row['updated_at'] ?? ''), 0, 7);
    }

    /** @param list<string> $values */
    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '%s'));
    }
}
