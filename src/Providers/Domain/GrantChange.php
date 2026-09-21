<?php

/**
 * Τι σημαίνει «αποθήκευσε αυτή τη λίστα παρόχων» για έναν υφιστάμενο.
 *
 * ## Γιατί δεν είναι απλή αντικατάσταση
 *
 * Αυτός που αλλάζει τη λίστα βλέπει και πειράζει μόνο ΟΣΟΥΣ παρόχους έχει ο
 * ίδιος (`$editable`). Ο υφιστάμενος μπορεί να έχει γραμμένους κι άλλους --
 * π.χ. από το αρχικό γέμισμα (0037), που έδωσε σε όλους ΚΑΘΕ πάροχο μαζί με
 * τους ανενεργούς. Αν η αποθήκευση αντικαθιστούσε ολόκληρη τη λίστα με ό,τι
 * τσέκαρε ο manager, θα έσβηνε σιωπηλά πράγματα που ο manager ούτε είδε ούτε
 * αποφάσισε. Εδώ λοιπόν αλλάζει μόνο το κομμάτι που ήταν στα χέρια του:
 *
 *     νέα = (παλιά − όσα μπορούσε να πειράξει) ∪ όσα τσέκαρε
 *
 * ## Το `removed`
 *
 * Οσα ήταν γραμμένα, ήταν στα χέρια του, και δεν τα τσέκαρε. Αυτά είναι που
 * κατεβαίνουν σε ολόκληρο το υποδέντρο του υφισταμένου (δες
 * `ProviderGrantRepository::strip()`): η τομή θα τα έκρυβε ούτως ή άλλως, αλλά
 * αν έμεναν γραμμένα από κάτω, την ημέρα που ο admin τα ξαναέδινε στον manager
 * θα ξαναεμφανίζονταν μόνα τους στους πωλητές του χωρίς να το αποφασίσει
 * κανείς. Απόφαση ιδιοκτήτη 21/09: ό,τι αφαιρείται, αφαιρείται.
 *
 * Καθαρή λογική, χωρίς WordPress (§1.12).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Providers\Domain;

final class GrantChange
{
    /**
     * @param list<int> $grants  Η νέα λίστα που γράφεται.
     * @param list<int> $removed Οσα έφυγαν και πρέπει να φύγουν και από κάτω.
     * @param list<int> $added   Οσα μπήκαν τώρα (για το ιστορικό/μήνυμα).
     */
    private function __construct(
        public readonly array $grants,
        public readonly array $removed,
        public readonly array $added,
    ) {
    }

    /**
     * @param array<int|string> $current   Οσα είναι γραμμένα σήμερα.
     * @param array<int|string> $editable  Οσα μπορεί να πειράξει αυτός που αλλάζει.
     * @param array<int|string> $requested Οσα τσέκαρε (πρέπει να είναι μέσα στα $editable
     *                                     -- ο έλεγχος γίνεται πριν, δες `ProviderAccess::covers()`).
     */
    public static function replaceWithin(array $current, array $editable, array $requested): self
    {
        $current   = self::clean($current);
        $editable  = self::clean($editable);
        $requested = array_values(array_intersect(self::clean($requested), $editable));

        $untouched = array_values(array_diff($current, $editable));
        $grants    = self::clean(array_merge($untouched, $requested));

        return new self(
            $grants,
            array_values(array_diff(array_intersect($current, $editable), $requested)),
            array_values(array_diff($requested, $current)),
        );
    }

    /**
     * Ενας πάροχος σε όλους / από όλους -- η μαζική ενέργεια της οθόνης Ομάδα.
     *
     * @param array<int|string> $current
     */
    public static function toggle(array $current, int $providerId, bool $grant): self
    {
        $current = self::clean($current);

        if ($grant) {
            $had = in_array($providerId, $current, true);

            return new self(self::clean([...$current, $providerId]), [], $had ? [] : [$providerId]);
        }

        // Το `removed` εδώ είναι ΠΑΝΤΑ ο πάροχος, ακόμα κι αν ο ίδιος δεν τον
        // είχε: μετά από μετακίνηση στο δίκτυο μπορεί να τον έχει κάποιος από
        // κάτω του, και η μαζική αφαίρεση υπόσχεται «από όλους».
        return new self(
            array_values(array_diff($current, [$providerId])),
            [$providerId],
            [],
        );
    }

    public function changed(): bool
    {
        return $this->removed !== [] || $this->added !== [];
    }

    /**
     * @param array<int|string> $ids
     *
     * @return list<int>
     */
    private static function clean(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            $value = (int) $id;

            if ($value > 0 && ! in_array($value, $clean, true)) {
                $clean[] = $value;
            }
        }

        sort($clean);

        return $clean;
    }
}
