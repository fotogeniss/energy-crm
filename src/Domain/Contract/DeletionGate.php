<?php

/**
 * Μια σύμβαση που υπογράφηκε ποτέ δεν διαγράφεται.
 *
 * ## Το ρίσκο που έγραφε το build queue 15
 *
 * `ContractRepository::deleteMany()`/`delete()` σβήνουν χωρίς κανέναν έλεγχο
 * κατάστασης — απλώς `DELETE FROM contracts WHERE id IN (...) {scope}`. Το
 * foreign key παρασέρνει μαζί ΚΑΙ τις γραμμές του `files`, δηλαδή και τον
 * φάκελο υπογραφής: ταυτότητα, λογαριασμό, το ίδιο το σαρωμένο έντυπο.
 * `DELETE_CONTRACT` το έχουν πλέον και οι δύο ρόλοι (δες CHANGELOG 127/128) —
 * ο καθένας σβήνει τα δικά του, σωστά scoped — αλλά «scoped σωστά» δεν
 * σημαίνει «ασφαλές να σβηστεί»: ένας συνεργάτης θα μπορούσε να σβήσει μια
 * δική του σύμβαση που υπογράφηκε ήδη, χάνοντας οριστικά το μόνο αντίγραφο
 * της υπογραφής του πελάτη.
 *
 * ## Γιατί το τρέχον status δεν αρκεί
 *
 * Αντίθετα με το `CancellationGate` (που έχει μια φτηνή συντόμευση όταν το
 * τρέχον status είναι ήδη `Active`), εδώ ΔΕΝ υπάρχει τέτοια συντόμευση: το
 * `ContractStatus::allowedNext()` επιτρέπει `Submitted → Processing` και
 * `Submitted → Pending` απευθείας, προσπερνώντας το `Signed` εντελώς. Άρα μια
 * σύμβαση που είναι σήμερα `Processing` μπορεί να μην υπογράφηκε ΠΟΤΕ, ενώ μια
 * που είναι σήμερα `Cancelled` μπορεί κάλλιστα να υπογράφηκε πρώτα
 * (`Signed → Cancelled` επιτρέπεται στον γράφο). Το τρέχον status δεν λέει
 * τίποτα αξιόπιστο εδώ — μόνο το ιστορικό το ξέρει, ίδιο μάθημα με το
 * `CancellationGate`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

use EnergyCRM\Persistence\EventRepository;

final class DeletionGate
{
    public const WAS_SIGNED = 'Η σύμβαση υπογράφηκε, οπότε δεν διαγράφεται. '
        . 'Αν χρειάζεται να σταματήσει, χρησιμοποίησε «Ακύρωση» ή «Έκλεισε».';

    public function __construct(private readonly EventRepository $events)
    {
    }

    /**
     * Άρνηση της διαγραφής, ή null για να προχωρήσει.
     */
    public function refusalOnDelete(int $contractId): ?string
    {
        return $this->events->hasReached($contractId, ContractStatus::Signed->value)
            ? self::WAS_SIGNED
            : null;
    }
}
