<?php

/**
 * Οι παλιές καταστάσεις γίνονται οι νέες, σε συμβάσεις και σε γεγονότα.
 *
 * ## Γιατί υπάρχει, αφού «τα δεδομένα είναι καθαρά»
 *
 * Ο ιδιοκτήτης το είπε ρητά όταν σχεδιαζόταν το νέο μοντέλο: καθαρό ξεκίνημα,
 * καμία πραγματική σύμβαση ακόμα. Αυτό δικαιολογεί να ΜΗΝ κρατηθεί
 * συμβατότητα προς τα πίσω στον κώδικα -- δεν δικαιολογεί να αφεθούν στη βάση
 * γραμμές με λέξεις που το `ContractStatus` δεν αναγνωρίζει πια. Μια τέτοια
 * γραμμή δεν σκάει: το `tryFromSlug()` απαντά `null`, οι φύλακες βγαίνουν
 * σιωπηλά, και η σύμβαση κάθεται σε μια κατάσταση που καμία οθόνη δεν
 * ζωγραφίζει και καμία μετάβαση δεν βγάζει από εκεί. Δηλαδή ακριβώς το είδος
 * σφάλματος που αυτό το CRM έχει ήδη πληρώσει.
 *
 * Κοστίζει δύο UPDATE. Το να μην υπάρχει κοστίζει μια ώρα απορίας κάποιο
 * απόγευμα.
 *
 * ## Ο χάρτης, και οι τρεις θέσεις όπου χάνεται πληροφορία
 *
 * Οι περισσότερες είναι μετονομασίες. Τρεις δεν είναι, και γράφονται εδώ
 * ανοιχτά αντί να περάσουν για ισοδυναμίες:
 *
 * - **`signed` -> `finalisation`.** Η κατάσταση «Υπογράφηκε» έγινε γεγονός
 *   (`signed_at`). Μια σύμβαση με κινητή θα έπρεπε αυστηρά να πάει σε
 *   `awaiting_sim`· η μετανάστευση δεν το ξεχωρίζει, γιατί θα σήμαινε να
 *   διαβαστεί το `extra_json` κάθε γραμμής για να μαντέψει κάτι που το back
 *   office βλέπει σε ένα κλικ.
 * - **`pending` -> `registration`.** Η «Εκκρεμότητα» έγινε **εμπόδιο**, σε
 *   δικό της πίνακα που δεν υπάρχει ακόμα (commit 255). Η γραμμή κρατά το πού
 *   βρίσκεται και χάνει το τι την κρατούσε.
 * - **`resolved` -> `registration`.** Ίδιος λόγος, από την ανάποδη: ήταν το
 *   κλείσιμο του εμποδίου.
 *
 * Και οι δύο ακυρώσεις (`cancelled`, `rejected`) πάνε στην **από εμάς**: η
 * παλιά στήλη δεν κρατούσε ποιος το αποφάσισε, και το να μαντευτεί θα ήταν
 * χειρότερο από το να διορθωθεί με το χέρι εκεί που ισχύει. Καμία από τις δύο
 * δεν είναι πληρωτέα, οπότε η επιλογή δεν αγγίζει χρήματα.
 *
 * ## Γιατί και ο πίνακας των γεγονότων
 *
 * Το `events.from_status`/`to_status` είναι το ιστορικό που ρωτούν οι πύλες --
 * ο `CancellationGate` ρωτά κυριολεκτικά «έφτασε ποτέ σε `active`;». Αν το
 * ιστορικό μιλά παλιά και η στήλη νέα, οι δύο απαντήσεις για το ίδιο πράγμα
 * αποκλίνουν, και η μία από τις δύο φυλάει χρήματα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class MigrateStatusVocabulary implements Migration
{
    /**
     * παλιό slug => νέο slug. Ό,τι λείπει από εδώ μένει ως έχει: `draft`,
     * `active` και `terminated` κράτησαν και το όνομα και το νόημά τους.
     *
     * @var array<string, string>
     */
    private const MAP = [
        'new'               => 'presale',
        'processing'        => 'registration',
        'pending'           => 'registration',
        'resolved'          => 'registration',
        'pending_signature' => 'awaiting_signature',
        'signed'            => 'finalisation',
        'routed'            => 'finalisation',
        'cancelled'         => 'cancelled_by_us',
        'rejected'          => 'cancelled_by_us',
    ];

    public function id(): string
    {
        return '0027_migrate_status_vocabulary';
    }

    public function description(): string
    {
        return 'Οι καταστάσεις συμβάσεων και τα γεγονότα τους στο νέο λεξιλόγιο (10 καταστάσεις).';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $contracts = Tables::name(Tables::CONTRACTS);
        $events    = Tables::name(Tables::EVENTS);

        foreach (self::MAP as $old => $new) {
            if ($schema->hasTable($contracts)) {
                $wpdb->query(
                    $wpdb->prepare(
                        'UPDATE %i SET status = %s WHERE status = %s',
                        $contracts,
                        $new,
                        $old
                    )
                );
            }

            if (! $schema->hasTable($events)) {
                continue;
            }

            // Δύο στήλες, δύο προτάσεις: μια γραμμή γεγονότος έχει και τις δύο
            // άκρες της μετάβασης, και ένα `OR` θα ξαναέγραφε τη μία ενώ
            // κοιτούσε την άλλη.
            $wpdb->query(
                $wpdb->prepare('UPDATE %i SET from_status = %s WHERE from_status = %s', $events, $new, $old)
            );

            $wpdb->query(
                $wpdb->prepare('UPDATE %i SET to_status = %s WHERE to_status = %s', $events, $new, $old)
            );
        }
    }
}
