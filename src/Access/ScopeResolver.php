<?php

/**
 * Builds a UserScope for an actor.
 *
 * Behind an interface deliberately: swapping the tree walk for a materialized
 * path changed only the implementation, and no caller had to move. The next
 * change — caching, or a dedicated hierarchy table — can happen the same way.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

interface ScopeResolver
{
    /**
     * @throws NotAuthenticated When there is no usable actor.
     */
    public function forUser(int $userId): UserScope;

    /**
     * Scope for whoever is driving the current request.
     *
     * @throws NotAuthenticated When nobody is logged in.
     */
    public function forCurrentUser(): UserScope;

    /**
     * Οι συγκεκριμένες ταυτότητες συνεργατών που καλύπτει ένα scope.
     *
     * ## Γιατί δεν φτάνει το UserScope::userIds()
     *
     * Για διαχειριστή το `userIds()` επιστρέφει **μόνο τον ίδιο** — δες
     * `UserScope::forAdministrator()`, που κρατά `[$actorId]` και σημαδεύει
     * `isAdministrator`. Είναι σωστό ως «ποιος είναι ο δράστης» και θανάσιμο ως
     * «τι βλέπει», γιατί διαβάζεται σαν το δεύτερο.
     *
     * Το `ScopeClause` δεν πέφτει στην παγίδα επειδή για διαχειριστή δεν εκπέμπει
     * καθόλου συνθήκη. Κάθε αποθετήριο ελέγχει `isAdministrator()` πρώτα. Τρεις
     * controllers δεν το έκαναν, και ο διαχειριστής έπαιρνε **άδεια** εξαγωγή
     * και **κενές** ειδοποιήσεις — αποτυχία που δεν φωνάζει, γιατί λιγότερα
     * δεδομένα δεν μοιάζουν με σφάλμα.
     *
     * Εδώ η απάντηση είναι πάντα «τι βλέπει», και δεν υπάρχει τρόπος να τη
     * διαβάσει κανείς αλλιώς.
     *
     * ## Το όριο, γραμμένο αντί να ανακαλυφθεί
     *
     * Για διαχειριστή επιστρέφει **κάθε** χρήστη του site. Οι καλούντες το
     * μετατρέπουν σε `IN (%d,…)`, οπότε σε εγκατάσταση με χιλιάδες χρήστες αυτό
     * γίνεται τεράστιο ερώτημα. Είναι το ίδιο όριο που έχει ήδη το
     * `ECRM_DB::visible_user_ids()` από τότε που γράφτηκε, όχι καινούργιο. Η
     * σωστή λύση είναι να ταξιδεύει η έννοια «χωρίς περιορισμό» αντί για τη
     * λίστα — αλλά αυτό αλλάζει υπογραφές στο legacy και δεν μπαίνει στο ίδιο
     * commit με διόρθωση bug.
     *
     * @return list<int>
     */
    public function visibleUserIds(UserScope $scope): array;
}
