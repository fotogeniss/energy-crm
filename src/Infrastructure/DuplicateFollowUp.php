<?php

/**
 * Ο ζωντανός έλεγχος διπλοεγγραφής δεν έτρεξε -- κάποιος πρέπει να δει.
 *
 * ## Το κενό που καλύπτει (Επίπεδο ΣΤ, docs/OFFLINE-MODEL.md §2ΣΤ)
 *
 * Στην οριστικοποίηση (`save('presale', ...)`, ecrm-form.js) ο client καλεί
 * `GET /customers/check` και δείχνει `window.confirm()` αν βρεθεί ήδη
 * καταχωρημένο ΑΦΜ ή αριθμός παροχής -- ο συνεργάτης βλέπει τι υπάρχει και
 * αποφασίζει ρητά αν θα προχωρήσει. Αυτό το confirm() είναι το ΜΟΝΟ εμπόδιο:
 * αν η κλήση αποτύχει (`.catch()`), η φόρμα προχωρά κατευθείαν σε
 * `doSave()` χωρίς καμία προειδοποίηση -- και αποτυγχάνει σε ΔΥΟ σαφείς
 * περιπτώσεις: όσο κρατά η ουρά εκτός σύνδεσης (Επίπεδο Δ, καθόλου δίκτυο),
 * αλλά και online, σε ένα σκέτο timeout ή 5xx του server εκείνη τη στιγμή.
 * Και στις δύο, η αίτηση καταλήγει σε πραγματική διπλοεγγραφή χωρίς κανείς
 * να το έμαθε ποτέ.
 *
 * ## Γιατί «μετά τη δημιουργία», όχι «μετά τον συγχρονισμό της ουράς»
 *
 * Το §2ΣΤ το περιγράφει σαν «μετά το sync» επειδή αυτό ήταν το κίνητρο, αλλά
 * ο server δεν έχει -- και δεν χρειάζεται -- τρόπο να ξέρει αν ένα
 * συγκεκριμένο `POST /contracts` ήρθε από ζωντανό πάτημα ή από
 * `ecrm-queue.js::flush()`: και τα δύο περνούν από το ΙΔΙΟ route, με το ΙΔΙΟ
 * σχήμα. Ο κίνδυνος («η προειδοποίηση δεν φάνηκε ποτέ») είναι ο ίδιος και
 * στις δύο περιπτώσεις αποτυχίας παραπάνω, άρα το δίχτυ ασφαλείας τρέχει σε
 * ΚΑΘΕ δημιουργία -- ίδιο σκεπτικό με το `RejectionFollowUp`: «όποιος κι αν
 * είναι ο καλών, περνά ΠΑΝΤΑ από εδώ».
 *
 * ## Γιατί μόνο `presale`, όχι κάθε πρόχειρο
 *
 * Ο ζωντανός έλεγχος στο `save()` τρέχει ΜΟΝΟ όταν `status === 'presale'` --
 * ένα πρόχειρο δεν ελέγχεται για διπλοεγγραφή ούτε online, εσκεμμένα (ο
 * συνεργάτης μπορεί ακόμα να αλλάξει στοιχεία). Το δίχτυ ασφαλείας ακολουθεί
 * ΑΚΡΙΒΩΣ το ίδιο όριο -- δεν είναι αυστηρότερο από το πρωτότυπο που
 * αντικαθιστά.
 *
 * ## Γιατί δεν αγνοεί το ενδεχόμενο «ο συνεργάτης ήδη είπε ναι»
 *
 * Δεν υπάρχει σήμα να ξεχωρίσει «ο ζωντανός έλεγχος έτρεξε, βρήκε ταίριασμα,
 * και ο συνεργάτης πάτησε ΟΚ στο confirm()» από «ο έλεγχος δεν έτρεξε ποτέ».
 * Θα μπορούσε να προστεθεί ρητό flag στο payload (όπως το `confirm_duplicate`
 * του `CustomersController::updateFull()`) ώστε η εργασία να μην
 * δημιουργείται όταν ο συνεργάτης το είδε ήδη -- ρητά ΕΚΤΟΣ αυτού του commit:
 * θα άλλαζε το σχήμα του `POST /contracts` για ένα όφελος (λιγότερος
 * θόρυβος σε task list) μικρότερο από το κόστος του κινδύνου να μείνει ΠΟΤΕ
 * αόρατη μια πραγματική διπλοεγγραφή λόγω λάθους στο flag. Το χειρότερο
 * σήμερα είναι μία επιπλέον εργασία που κλείνει σε ένα κλικ -- αποδεκτό.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\ContractDetails;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\TaskRepository;

final class DuplicateFollowUp
{
    public function __construct(
        private readonly ContractDetails $details,
        private readonly CustomerRepository $customers,
        private readonly ScopeResolver $scopes,
        private readonly TaskRepository $tasks,
    ) {
    }

    /** Ίδιο σημείο σύνδεσης με το RejectionFollowUp -- ο κύκλος ζωής δεν πρέπει να ξέρει ότι υπάρχει αυτός ο έλεγχος. */
    public function register(): void
    {
        add_action(ContractLifecycle::CREATED, [$this, 'onCreated'], 10, 2);
    }

    public function onCreated(int $contractId, string $status): void
    {
        if ($status !== ContractStatus::Presale->value) {
            return;
        }

        $row = $this->details->noticeSubject($contractId);

        if ($row === null) {
            return;
        }

        // Χωρίς ιδιοκτήτη δεν υπάρχει σε ποιον να ανατεθεί -- ίδιος έλεγχος
        // και ίδιος λόγος με το RejectionFollowUp (assigned_to NOT NULL).
        $owner = (int) $row['partner_user_id'];

        if ($owner <= 0) {
            return;
        }

        $afm    = (string) ($row['afm'] ?? '');
        $supply = (string) ($row['supply_number'] ?? '');

        if ($afm === '' && $supply === '') {
            return;
        }

        // Ο owner εδώ δεν είναι ο τρέχων χρήστης του αιτήματος -- αυτό τρέχει
        // από hook, όχι από HTTP request. forUser() χτίζει το ΙΔΙΟ scope που
        // θα είχε χτίσει forCurrentUser() αν ο ιδιοκτήτης της σύμβασης
        // έκανε ο ίδιος αυτό το αίτημα -- ίδια εμβέλεια με το ζωντανό
        // /customers/check, ούτε στενότερη ούτε ευρύτερη.
        $scope   = $this->scopes->forUser($owner);
        $matches = array_values(array_filter(
            $this->customers->duplicatesOf($scope, $afm, $supply),
            static fn (array $m): bool => (int) $m['contract_id'] !== $contractId
        ));

        if ($matches === []) {
            return;
        }

        $code = (string) ($row['code'] ?? '');
        $name = $this->customerName($row);

        $this->tasks->create([
            'contract_id' => $contractId,
            'customer_id' => null,
            'assigned_to' => $owner,
            'created_by'  => null,
            'title'       => trim('Πιθανή διπλοεγγραφή — ' . $code),
            'note'        => sprintf(
                '%s: ο αυτόματος έλεγχος βρήκε %s με το ίδιο ΑΦΜ ή παροχή. Ο ζωντανός έλεγχος '
                    . 'δεν πρόλαβε να τρέξει πριν την αποθήκευση (εκτός σύνδεσης, ή σφάλμα δικτύου '
                    . 'εκείνη τη στιγμή) -- έλεγξε αν πρόκειται όντως για τον ίδιο πελάτη.',
                $name,
                count($matches) === 1
                    ? ('την σύμβαση ' . ($matches[0]['code'] ?? ''))
                    : (count($matches) . ' άλλες συμβάσεις')
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
