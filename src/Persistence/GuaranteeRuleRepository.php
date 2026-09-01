<?php

/**
 * Οι κανόνες εγγύησης, όπως τους διαβάζει η μηχανή πρότασης.
 *
 * Το ερώτημα ζει εδώ και όχι δίπλα στην οθόνη διαχείρισης — αντίθετα από τους
 * κανόνες προμήθειας, που ξεκίνησαν μέσα στην `ECRM_Commissions` και
 * χρειάστηκε να ξεριζωθεί από εκεί η απόφαση για να γίνει δοκιμάσιμη. Το
 * μάθημα ήταν ήδη πληρωμένο· δεν υπάρχει λόγος να ξαναγίνει το ίδιο λάθος με
 * καλύτερα ρούχα.
 *
 * Καμία ρήτρα εμβέλειας χρήστη: οι κανόνες δεν ανήκουν σε πωλητή, είναι
 * ρύθμιση του συστήματος — όπως τα προγράμματα και οι πάροχοι.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class GuaranteeRuleRepository
{
    /**
     * Μνήμη ενός αιτήματος. Ο πίνακας είναι μικρός και σχεδόν στατικός, ενώ η
     * πρόταση ζητιέται μία φορά ανά άνοιγμα φόρμας — αλλά ένα μελλοντικό
     * μαζικό πέρασμα δεν πρέπει να πληρώσει ένα ερώτημα ανά σύμβαση.
     *
     * @var list<array<string, mixed>>|null
     */
    private static ?array $cache = null;

    /**
     * Ενεργοί κανόνες, **νεότερος πρώτος**.
     *
     * Η σειρά είναι συμβόλαιο, όχι διακόσμηση: ο `GuaranteeMatch` κρατά τον
     * πρώτο σε ισοβαθμία, οπότε χωρίς `ORDER BY` δύο εξίσου ειδικοί κανόνες θα
     * νικούσαν εναλλάξ ανάλογα με το τι θα επέστρεφε η MySQL εκείνη τη στιγμή.
     *
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        if (null !== self::$cache) {
            return self::$cache;
        }

        global $wpdb;

        $table = Tables::name(Tables::GUARANTEE_RULES);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE active = 1 ORDER BY id DESC',
                [$table]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        self::$cache = $rows;

        return $rows;
    }

    /**
     * Ξεχνά ό,τι διαβάστηκε — για την οθόνη διαχείρισης, που γράφει και μετά
     * ξαναδιαβάζει μέσα στο ίδιο αίτημα, και για τα tests.
     */
    public static function forget(): void
    {
        self::$cache = null;
    }
}
