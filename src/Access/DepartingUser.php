<?php

/**
 * Όταν σβήνεται χρήστης από το WordPress, η δουλειά του δεν σβήνεται μαζί.
 *
 * Το CRM έχει δικό του «Αφαίρεση» που τα κάνει όλα σωστά — παραδίδει συμβάσεις
 * και ομάδα στον από πάνω και κλείνει τον λογαριασμό. Ο διαχειριστής όμως έχει
 * μπροστά του και τον προφανή δρόμο: **Χρήστες → Διαγραφή**. Το WordPress εκεί
 * ρωτά τι να κάνει με τα **άρθρα** του και δεν ξέρει τίποτα για τους πίνακες
 * μας.
 *
 * Πριν από αυτή την κλάση, το αποτέλεσμα ήταν:
 *
 * - συμβάσεις με `partner_user_id` που δεν αντιστοιχεί σε χρήστη — στις λίστες
 *   εμφανίζονταν με «—» και στην εξαγωγή με κενό όνομα,
 * - τα παιδιά του στο δίκτυο με `ecrm_parent` προς φάντασμα, δηλαδή **ολόκληρο
 *   το υποδέντρο κομμένο** από το δέντρο της εταιρείας,
 * - leads που κανείς δεν θα ξανατηλεφωνήσει και ανοιχτές εργασίες ανατεθειμένες
 *   σε κανέναν.
 *
 * Έλεγχος λειτουργίας 18/08/2026, εύρημα 5.
 *
 * ## Ποιος τα παίρνει
 *
 * Ο από πάνω του, όπως και στο «Αφαίρεση» — η δουλειά ανεβαίνει το δέντρο που
 * ανεβαίνει και η προμήθεια. Αν δεν έχει από πάνω, τα παίρνει **ο διαχειριστής
 * που κάνει τη διαγραφή**: απόφαση του ιδιοκτήτη 19/08/2026. Η εναλλακτική
 * ήταν να μπλοκάρεται η διαγραφή, αλλά το `delete_user` είναι action και όχι
 * filter — άρνηση σημαίνει `wp_die()` στη μέση μιας οθόνης διαχείρισης, που
 * είναι χειρότερη εμπειρία από το να κρατήσει η εταιρεία το χαρτοφυλάκιο.
 *
 * ## Τι ΔΕΝ μετακινείται, και γιατί
 *
 * Οι **εκκαθαρίσεις** και το **ιστορικό**. Είναι αρχείο, όχι δουλειά: η γραμμή
 * της παρτίδας λέει «σε αυτόν τον άνθρωπο πληρώθηκαν τόσα, τότε», και το
 * γεγονός λέει «αυτός άλλαξε αυτή την κατάσταση, τότε». Μεταφέροντάς τα θα
 * ξαναγράφαμε ιστορία για να βολέψουμε ένα ξένο κλειδί. Το ίδιο και οι
 * **ολοκληρωμένες** εργασίες — μόνο οι ανοιχτές αλλάζουν χέρια.
 *
 * ## Γιατί `delete_user` και όχι `deleted_user`
 *
 * Το πρώτο τρέχει **πριν** φύγει ο χρήστης, οπότε το `ecrm_parent` του υπάρχει
 * ακόμη και μπορεί να διαβαστεί. Στο δεύτερο ο διάδοχος θα ήταν άγνωστος.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\LeadRepository;
use EnergyCRM\Persistence\TaskRepository;
use EnergyCRM\Persistence\TeamRepository;

final class DepartingUser
{
    public function __construct(
        private readonly TeamRepository $team,
        private readonly ContractRepository $contracts,
        private readonly LeadRepository $leads,
        private readonly TaskRepository $tasks,
    ) {
    }

    public function register(): void
    {
        add_action('delete_user', [$this, 'handOverEverything']);
    }

    /**
     * Παραδίδει ό,τι κρατούσε ο χρήστης, πριν πάψει να υπάρχει.
     *
     * Σιωπηλά όταν δεν υπάρχει διάδοχος — χωρίς προϊστάμενο και χωρίς
     * συνδεδεμένο διαχειριστή (WP-CLI, cron) δεν υπάρχει σωστός παραλήπτης, και
     * μια αυθαίρετη επιλογή θα ήταν χειρότερη από το να μείνουν τα δεδομένα
     * όπως ήταν. Η διαγραφή προχωρά έτσι κι αλλιώς· δεν είναι δική μας απόφαση.
     */
    public function handOverEverything(int $userId): void
    {
        $successor = $this->successorOf($userId);

        if ($successor <= 0) {
            return;
        }

        // Ο διάδοχος είναι διαχειριστής ή προϊστάμενος και η εξουσία έχει ήδη
        // κριθεί από το WordPress, που δεν αφήνει κανέναν χωρίς `delete_users`
        // να φτάσει ως εδώ. Το scope εδώ δεν αποφασίζει ποιος επιτρέπεται —
        // δίνει στα repositories τη μορφή που περιμένουν.
        $scope = UserScope::forAdministrator($successor);

        $this->contracts->handOver($userId, $successor, $scope);
        $this->leads->handOver($userId, $successor, $scope);
        $this->tasks->handOverOpen($userId, $successor);

        // Τελευταία η ομάδα: μόλις αλλάξει το `ecrm_parent` των παιδιών, ο
        // χρήστης παύει να είναι προϊστάμενός τους, και ό,τι τον αφορά πρέπει
        // να έχει ήδη φύγει από πάνω του.
        $this->team->reparentChildren($userId, $successor);
    }

    /**
     * Ο από πάνω του, αλλιώς ο διαχειριστής που διαγράφει.
     *
     * Ο έλεγχος ότι ο προϊστάμενος υπάρχει ακόμη δεν είναι υπερβολή: δύο
     * διαγραφές στη σειρά, πρώτα ο προϊστάμενος και μετά το μέλος, θα έδιναν
     * αλλιώς τη δουλειά σε δεύτερο φάντασμα.
     */
    private function successorOf(int $userId): int
    {
        $parent = (int) get_user_meta($userId, TeamRepository::PARENT_META, true);

        if ($parent > 0 && $parent !== $userId && get_userdata($parent) !== false) {
            return $parent;
        }

        $actor = get_current_user_id();

        return $actor !== $userId ? $actor : 0;
    }
}
