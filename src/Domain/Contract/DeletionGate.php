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
 * ## Γιατί ρωτάει το `signed_at` και όχι την κατάσταση (07/09/2026)
 *
 * Ρωτούσε το ιστορικό: «έφτασε ποτέ σε `signed`;». Με το νέο μοντέλο η
 * κατάσταση `signed` **δεν υπάρχει** -- η υπογραφή είναι γεγονός, και το
 * γεγονός γράφεται στη στήλη `signed_at`. Δεν αντικαταστάθηκε με «έφτασε ποτέ
 * σε ΟΡΙΣΤΙΚΟΠΟΙΗΣΗ» επίτηδες: αυτό θα ήταν έμμεσο, και η ερώτηση που θέλουμε
 * να κάνουμε είναι κυριολεκτικά «υπάρχει υπογραφή πελάτη;».
 *
 * Μία διαφορά συμπεριφοράς, συνειδητή: όταν ο `SignLinkController` ξανανοίγει
 * μια σύμβαση για νέα υπογραφή, μηδενίζει το `signed_at` -- και τότε η
 * σύμβαση γίνεται ξανά διαγράψιμη. Σωστό: εκείνη τη στιγμή δεν υπάρχει
 * υπογραφή πελάτη να προστατευτεί.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

use EnergyCRM\Persistence\ContractTransitions;
use EnergyCRM\Persistence\EventRepository;

final class DeletionGate
{
    public const WAS_SIGNED = 'Η σύμβαση υπογράφηκε, οπότε δεν διαγράφεται. '
        . 'Αν χρειάζεται να σταματήσει, χρησιμοποίησε «Ακύρωση» ή «Έκλεισε».';

    /** (417) */
    public const WAS_ACTIVE = 'Η σύμβαση υπήρξε Ενεργή, οπότε δεν διαγράφεται. '
        . 'Αν χρειάζεται να σταματήσει, χρησιμοποίησε «Ακύρωση».';

    public function __construct(
        private readonly ContractTransitions $contracts,
        private readonly ?EventRepository $events = null,
    ) {
    }

    /**
     * (417) Και όταν υπήρξε ποτέ Ενεργή, ακόμη κι αν το `signed_at` σβήστηκε
     * από νέα αποστολή υπογραφής: αλλιώς «ξαναστείλε» + «διαγραφή» έσβηνε
     * αίτηση που πληρώθηκε (έλεγχος 18/11, εύρημα 1).
     */
    public function refusalOnDelete(int $contractId): ?string
    {
        $row = $this->contracts->paperworkFieldsOf($contractId);

        // Σύμβαση που δεν υπάρχει δεν κρίνεται εδώ -- η ίδια η διαγραφή δεν θα
        // βρει τίποτα να σβήσει.
        if ($row === null) {
            return null;
        }

        if (trim($row['signed_at']) !== '') {
            return self::WAS_SIGNED;
        }

        return $this->events !== null && $this->events->hasReached($contractId, ContractStatus::Active->value)
            ? self::WAS_ACTIVE
            : null;
    }
}
