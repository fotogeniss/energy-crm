<?php

/**
 * Διαβάζει και γράφει τον χάρτη καταστάσεων ενός παρόχου.
 *
 * Ο χάρτης ζει ως JSON σε στήλη του ίδιου του πίνακα παρόχων και όχι σε δικό
 * του πίνακα — απόφαση ιδιοκτήτη, 27/08/2026. Ο λόγος: δεν ερωτάται ποτέ με
 * SQL. Διαβάζεται ολόκληρος μία φορά όταν ανοίγει η οθόνη εισαγωγής και
 * γράφεται ολόκληρος όταν κλείνει. Ξεχωριστός πίνακας θα έφερνε μαζί του
 * κλειδιά, join και δική του οθόνη διαχείρισης για δεδομένα που κανείς δεν
 * θέλει να δει έξω από τη ροή που τα παράγει.
 *
 * ## Γιατί ο χάρτης δένεται στον ΠΑΡΟΧΟ και όχι στον χρήστη
 *
 * Το λεξιλόγιο είναι του παρόχου. Δύο συνεργάτες που ανεβάζουν το ίδιο αρχείο
 * της ίδιας εταιρείας πρέπει να δουν την ίδια αντιστοίχιση — αλλιώς το ίδιο
 * «ΑΠΟΡΡΙΦΘΗ» θα σήμαινε άλλο πράγμα ανά χρήστη, και το ιστορικό των συμβάσεων
 * θα έλεγε δύο διαφορετικές ιστορίες για το ίδιο γεγονός.
 *
 * ## Εμβέλεια
 *
 * Δεν υπάρχει `UserScope` εδώ, και είναι σωστό: οι πάροχοι είναι **κοινός
 * κατάλογος**, όχι δεδομένα συνεργάτη. Η προστασία είναι στη διαδρομή HTTP,
 * που ζητά `IMPORT_DATA` — το ίδιο δικαίωμα με την εισαγωγή που τον χρησιμοποιεί.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Providers\Persistence;

use EnergyCRM\Persistence\Tables;
use EnergyCRM\Providers\Domain\ProviderStatusMap;

final class ProviderStatusMapRepository
{
    private string $table;

    public function __construct()
    {
        $this->table = Tables::name(Tables::PROVIDERS);
    }

    /** Ο χάρτης του παρόχου — κενός όταν δεν υπάρχει πάροχος ή δεν έχει γραφτεί ποτέ. */
    public function find(int $providerId): ProviderStatusMap
    {
        global $wpdb;

        if ($providerId <= 0) {
            return ProviderStatusMap::empty();
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $json = $wpdb->get_var(
            $wpdb->prepare('SELECT status_map FROM %i WHERE id = %d', $this->table, $providerId)
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return ProviderStatusMap::fromJson($json === null ? null : (string) $json);
    }

    /**
     * Γράφει τον χάρτη ολόκληρο.
     *
     * Αντικατάσταση και όχι συγχώνευση: η οθόνη στέλνει πάντα την πλήρη
     * κατάσταση όπως τη βλέπει ο χρήστης, οπότε μια τιμή που εκείνος έσβησε
     * πρέπει να φύγει. Συγχώνευση εδώ θα σήμαινε ότι λάθος αντιστοίχιση δεν
     * ξεγράφεται ποτέ.
     *
     * @return bool True όταν ο πάροχος υπάρχει και γράφτηκε.
     */
    public function save(int $providerId, ProviderStatusMap $map): bool
    {
        global $wpdb;

        if ($providerId <= 0) {
            return false;
        }

        $entries = $map->toArray();

        return $wpdb->update(
            $this->table,
            ['status_map' => $entries === [] ? null : (string) wp_json_encode($entries)],
            ['id' => $providerId]
        ) !== false;
    }
}
