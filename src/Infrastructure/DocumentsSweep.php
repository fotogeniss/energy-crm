<?php

/**
 * Η ανάγνωση εγγράφων (242) δεν περιμένει πια κανέναν να ανοίξει την καρτέλα.
 *
 * Το `DocumentKindReview::run()` υπάρχει ήδη και δουλεύει σωστά -- διαβάζει,
 * διορθώνει, σημειώνει. Το κενό δεν ήταν ο έλεγχος, ήταν το πότε τρέχει: μόνο
 * όταν κάποιος ανοίξει χειροκίνητα μια συγκεκριμένη αίτηση, ή πατήσει το
 * «Ελεγξε τώρα» της οθόνης «Έγγραφα» (243). Μια αίτηση από πέρσι που κανείς
 * δεν ξαναανοίγει θα κρατούσε λάθος ετικέτα για πάντα -- ακριβώς αυτό ζήτησε
 * ρητά ο ιδιοκτήτης να μην ξανασυμβεί.
 *
 * ## Γιατί ωριαίο και όχι ημερήσιο
 *
 * Δεν είναι ναι/όχι ερώτημα ρύθμισης σαν το DocumentExposureCheck -- είναι
 * backlog, ίδιο σχήμα με το DocumentQueue: όσο περισσότερες παλιές αιτήσεις
 * περιμένουν, τόσο πιο συχνά αξίζει να προχωράει η ουρά. Ωριαίο με μικρό
 * ταβάνι ανά τρέξιμο αδειάζει σταδιακά χωρίς να ανταγωνιστεί το `/extract`
 * σε ώρες αιχμής -- και όταν αδειάσει το backlog, κάθε τρέξιμο είναι απλώς
 * ένα φθηνό `SELECT DISTINCT` που δεν βρίσκει τίποτα.
 *
 * ## Γιατί δεν χρειάζεται δικό του throttle
 *
 * Το ίδιο `ExtractionGate` που προστατεύει το `/extract` προστατεύει και αυτό,
 * μέσα στο `run()` που ήδη καλεί. Ένα τρέξιμο που βρίσκει όλες τις θέσεις
 * γεμάτες απλώς δεν διορθώνει τίποτα αυτή τη φορά -- οι σημειωμένες αιτήσεις
 * (`kind_source IS NULL`) ξαναμπαίνουν στη λίστα στο επόμενο τρέξιμο, χωρίς
 * να χαθεί καμία.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Persistence\FileRepository;

final class DocumentsSweep
{
    public const HOOK = 'ecrm_documents_sweep';

    /**
     * Πόσες αιτήσεις ανά τρέξιμο -- ίδιο ταβάνι σκεπτικό με το
     * `DocumentKindReview::MAX_PER_RUN`: αρκετό για να προχωράει το backlog,
     * όχι τόσο ώστε ένα τρέξιμο cron να κρατήσει τον worker για πολύ.
     */
    private const CONTRACTS_PER_RUN = 15;

    public function __construct(
        private readonly FileRepository $files,
        private readonly DocumentKindReview $review,
    ) {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'onScheduledSweep']);

        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Cron entry point. Καμία επιστρεφόμενη τιμή -- ό,τι αξίζει να μαθευτεί
     * περνά ήδη από τα δικά του κανάλια (`doc_kind_ai` στο ιστορικό κάθε
     * αίτησης, `doc_kind_fixed` στους μετρητές), όχι από εδώ.
     */
    public function onScheduledSweep(): void
    {
        Heartbeat::mark(self::HOOK);

        if (! $this->review->enabled()) {
            return;
        }

        $ids = $this->files->contractsPendingKindReview(
            $this->review->readableMimes(),
            self::CONTRACTS_PER_RUN
        );

        foreach ($ids as $id) {
            $this->review->run($id);
        }
    }
}
