<?php

/**
 * Μια σύμβαση που απορρίφθηκε από τον πάροχο αφήνει πίσω της μια εργασία.
 *
 * ## Το κομμάτι του pipeline που καλύπτει
 *
 * Από το διάγραμμα του ιδιοκτήτη (31/08): «...Αν απορριφθεί → δημιουργεί
 * task». Τα δύο βήματα πριν από αυτό — αυτόματη υποβολή στον πάροχο,
 * αυτόματη λήψη της απάντησής του — παραμένουν εξωτερικά μπλοκαρισμένα
 * (`HANDOVER.md` §1.13, κανένας πάροχος δεν έχει συμφωνήσει API). Αυτό εδώ
 * δεν προσποιείται ότι λύνει εκείνα· υποθέτει μόνο ότι ΚΑΠΟΙΟΣ — σήμερα ένας
 * άνθρωπος στο dropdown κατάστασης, αύριο ίσως ένα webhook — έγραψε ήδη
 * `Rejected` πάνω στη σύμβαση, και αναλαμβάνει από εκεί και πέρα.
 *
 * ## Γιατί Infrastructure και όχι Domain
 *
 * Πρώτη γραφή αυτού του αρχείου ήταν σε `Domain\Contract`, δίπλα στον
 * `AutoProcess` -- λάθος, και το έπιασε ο υπάρχων έλεγχος
 * `DomainStaysFrameworkFreeTest` στο πρώτο `composer check:all` (31/08): η
 * κλάση καλεί `add_action()`/`current_time()`, πλατφόρμα, όχι καθαρή λογική.
 * Ο `ContractNotices` κάνει ΑΚΡΙΒΩΣ την ίδια δουλειά -- ακούει το ίδιο
 * γεγονός, αγγίζει WordPress και Persistence -- και ήδη ζει σωστά εδώ, στο
 * `Infrastructure`. Ο `AutoProcess` είναι ο ίδιος ήδη καταγεγραμμένος ως
 * γνωστό χρέος στο `ALLOWED` του παραπάνω test, όχι πρότυπο προς αντιγραφή.
 *
 * ## Γιατί ακούει το ίδιο γεγονός με τον AutoProcess και τον ContractNotices
 *
 * Ο `ContractLifecycle` δεν πρέπει να ξέρει ότι υπάρχουν εργασίες, όπως δεν
 * ξέρει ότι υπάρχει χρονοπρογραμματιστής (`AutoProcess`) ή καμπανάκι
 * (`ContractNotices`). Και οι τρεις συνδέονται στο ίδιο `STATUS_CHANGED` για
 * τον ίδιο λόγο: όποιος κι αν είναι ο καλών — χειροκίνητη αλλαγή, μαζική
 * ενέργεια, μελλοντικό webhook παρόχου — περνά ΠΑΝΤΑ από εδώ, άρα η εργασία
 * δημιουργείται μία φορά, με τον ίδιο τρόπο, ανεξάρτητα ποιος γύρισε τη
 * σύμβαση σε Rejected.
 *
 * ## Γιατί εργασία και όχι μόνο καμπανάκι
 *
 * Το `ContractNotices` ήδη χτυπά καμπανάκι για το Rejected (είναι στο δικό
 * του `ANNOUNCED`) — αυτό ενημερώνει. Η εργασία εδώ είναι διαφορετική: δεν
 * λέει απλώς «κάτι έγινε», λέει «κάτι πρέπει να γίνει», και μένει στη λίστα
 * «Ανοιχτές» μέχρι κάποιος να την κλείσει ρητά — ο συνεργάτης δεν μπορεί να
 * τη χάσει σαν καμπανάκι σε λίστα από δέκα.
 *
 * ## Γιατί προθεσμία «σήμερα»
 *
 * Ρητή απόφαση ιδιοκτήτη (31/08): μια απόρριψη παρόχου είναι επιχειρηματικό
 * ρίσκο, όχι κάτι που περιμένει. Προθεσμία «τώρα» σημαίνει ότι η εργασία
 * μπαίνει αμέσως και στο `ECRM_Tasks::due_count()` -- το μπατζάκι δίπλα στο
 * «Εργασίες» στο sidebar -- αντί να εμφανιστεί εκεί μόνο την επόμενη μέρα.
 *
 * ## Γιατί δεν εμπιστεύεται customer_id
 *
 * Το `ContractDetails::noticeSubject()` -- η ίδια πηγή που ήδη χρησιμοποιεί
 * το `ContractNotices` -- δεν επιστρέφει `customer_id`, μόνο τα στοιχεία
 * εμφάνισης. Η εργασία συνδέεται με τη σύμβαση μέσω `contract_id`· από εκεί
 * ο πελάτης είναι ένα κλικ μακριά. Δεν αξίζει δεύτερο ερώτημα SQL μόνο για
 * μια στήλη προαιρετική στο σχήμα του πίνακα `tasks`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\ContractDetails;
use EnergyCRM\Persistence\TaskRepository;

final class RejectionFollowUp
{
    public function __construct(
        private readonly ContractDetails $details,
        private readonly TaskRepository $tasks,
    ) {
    }

    /** Ίδιο σημείο σύνδεσης με τον AutoProcess και τον ContractNotices. */
    public function register(): void
    {
        add_action(ContractLifecycle::STATUS_CHANGED, [$this, 'onStatusChanged'], 10, 2);
    }

    public function onStatusChanged(int $contractId, string $to): void
    {
        if ($to !== ContractStatus::Rejected->value) {
            return;
        }

        $row = $this->details->noticeSubject($contractId);

        if ($row === null) {
            return;
        }

        // Χωρίς ιδιοκτήτη δεν υπάρχει σε ποιον να ανατεθεί -- και το
        // `assigned_to` του πίνακα `tasks` είναι NOT NULL, δεν δέχεται 0.
        $owner = (int) $row['partner_user_id'];

        if ($owner <= 0) {
            return;
        }

        $code = (string) ($row['code'] ?? '');
        $name = $this->customerName($row);

        $this->tasks->create([
            'contract_id' => $contractId,
            'customer_id' => null,
            'assigned_to' => $owner,
            'created_by'  => null,
            'title'       => trim('Απορρίφθηκε από πάροχο — ' . $code),
            'note'        => sprintf(
                '%s: ο πάροχος απέρριψε την αίτηση. Έλεγξε τον λόγο και ξαναστείλε ή έλα σε επαφή με τον πελάτη.',
                $name
            ),
            'due_at'   => current_time('mysql'),
            'priority' => 'high',
            'status'   => 'open',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function customerName(array $row): string
    {
        $company = (string) ($row['company_name'] ?? '');
        $person  = trim(
            (string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')
        );

        return ($company ?: $person) ?: 'Ο πελάτης';
    }
}
