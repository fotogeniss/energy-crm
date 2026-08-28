<?php

/**
 * Μετράει τι βάζει συνήθως ένας πωλητής: πάροχο, είδος, πρόγραμμα.
 *
 * ## Εμβέλεια — γιατί δεν υπάρχει `UserScope` εδώ
 *
 * Δεν λείπει: **δεν χωράει**. Το ερώτημα δέχεται έναν και μόνο `partner_user_id`
 * και είναι πάντα ο ίδιος ο συνδεδεμένος χρήστης (`UserScope::actorId()`). Η
 * συνήθεια είναι **δική του**· η συνήθεια της ομάδας δεν θα ήταν χρήσιμη
 * πρόταση και θα άνοιγε παράθυρο σε δεδομένα άλλων. Ένα `UserScope` εδώ θα
 * επέτρεπε ακριβώς αυτό που δεν πρέπει να γίνει ποτέ.
 *
 * ## Τι μετριέται
 *
 * Οι τελευταίες `UsualChoice::SAMPLE` συμβάσεις του πωλητή, ομαδοποιημένες
 * κατά (πάροχος, είδος, πρόγραμμα). Το άθροισμα και το μέγιστο βγαίνουν σε
 * PHP: οι ομάδες είναι το πολύ όσες και οι συμβάσεις του δείγματος, δηλαδή
 * είκοσι. Ένα `SUM(...) OVER ()` θα ήταν μία γραμμή λιγότερη εδώ και μια
 * απαίτηση MySQL 8 στην παραγωγή — δυσανάλογο τίμημα για είκοσι γραμμές.
 *
 * **Τα προσχέδια δεν μετρούν.** Ένα `draft` είναι αίτηση που δεν κατατέθηκε
 * ποτέ — μπορεί να άνοιξε κατά λάθος, με τον πρώτο πάροχο της λίστας. Η
 * συνήθεια φαίνεται σε ό,τι όντως κατέθεσε.
 *
 * Το `program_id` επιτρέπεται να είναι `NULL`: πωλητής που δεν συμπληρώνει
 * πρόγραμμα έχει ακόμα συνήθεια ως προς τον πάροχο, και η οθόνη δείχνει τότε
 * μόνο τον πάροχο. Το να απαιτηθεί πρόγραμμα θα έκρυβε την πρόταση από
 * ανθρώπους που θα την ήθελαν.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Providers\Persistence;

use EnergyCRM\Persistence\Tables;
use EnergyCRM\Providers\Domain\UsualChoice;

final class UsualChoiceRepository
{
    private string $table;

    public function __construct()
    {
        $this->table = Tables::name(Tables::CONTRACTS);
    }

    /** Η συνήθεια του συγκεκριμένου πωλητή — `none()` όταν δεν υπάρχει αρκετή. */
    public function forPartner(int $partnerUserId): UsualChoice
    {
        global $wpdb;

        if ($partnerUserId <= 0) {
            return UsualChoice::none();
        }

        /* Το `LIMIT` μπαίνει ΜΕΣΑ στο υποερώτημα, όχι στο εξωτερικό: θέλουμε
         * «οι τελευταίες 20 συμβάσεις, ομαδοποιημένες», όχι «οι πολυπληθέστερες
         * ομάδες όλης της ιστορίας του». Το δεύτερο θα κρατούσε ζωντανό για
         * πάντα έναν πάροχο που ο πωλητής σταμάτησε να δουλεύει πριν από χρόνο. */
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT recent.provider_id, recent.energy_type, recent.program_id, COUNT(*) AS times
                 FROM (
                     SELECT provider_id, energy_type, program_id FROM %i
                     WHERE partner_user_id = %d AND provider_id IS NOT NULL AND status <> %s
                     ORDER BY created_at DESC, id DESC
                     LIMIT %d
                 ) AS recent
                 GROUP BY recent.provider_id, recent.energy_type, recent.program_id
                 ORDER BY times DESC',
                $this->table,
                $partnerUserId,
                'draft',
                UsualChoice::SAMPLE
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        if (! is_array($rows) || $rows === []) {
            return UsualChoice::none();
        }

        $sampled = 0;

        foreach ($rows as $row) {
            $sampled += (int) $row['times'];
        }

        /* Πρώτη γραμμή = η πολυπληθέστερη ομάδα, από το `ORDER BY times DESC`. */
        $top = $rows[0];

        return UsualChoice::from(
            (int) $top['provider_id'],
            (string) $top['energy_type'],
            $top['program_id'] === null ? null : (int) $top['program_id'],
            (int) $top['times'],
            $sampled
        );
    }
}
