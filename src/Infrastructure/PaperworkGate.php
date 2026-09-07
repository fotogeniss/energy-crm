<?php

/**
 * Χαρτιά και υπογραφή, πριν η σύμβαση μπει σε κατάσταση που σημαίνει λεφτά.
 *
 * ## Το κενό που κλείνει (εύρημα 07/09/2026)
 *
 * Ο έλεγχος δικαιολογητικών υπήρχε ήδη -- `ECRM_Docs::gate_statuses()` -- αλλά
 * επιβαλλόταν σε **δύο** σημεία, και τα δύο controllers:
 * `ContractStatusController:179` και `ContractsBulkController:154`. Ο
 * `ContractSaveController` γράφει κι αυτός `status` (η φόρμα το στέλνει σαν
 * κανονικό πεδίο) και **δεν** τον καλούσε. Ούτε η εισαγωγή Excel, που περνά
 * απευθείας από τον `ContractLifecycle` (εύρημα ελέγχου #7, 18/08).
 *
 * Το αποτέλεσμα ήταν εκτεθειμένο στα λεφτά: ο ρόλος **Πωλητής** έχει
 * `CHANGE_STATUS` (`Roles.php:109`), ο γράφος επιτρέπει
 * `new -> processing -> active`, και το `isPayable()` περιλαμβάνει το `active`.
 * Τρεις αποθηκεύσεις της φόρμας πάνω στη δική του σύμβαση, μηδέν
 * δικαιολογητικά, και η προμήθεια κλείδωνε. Το σενάριο δεν το φαντάστηκε
 * κανείς -- το περιέγραψε ο υπεύθυνος του δικτύου πριν βρεθεί στον κώδικα.
 *
 * Η θεραπεία είναι αυτή που λέει ήδη το σχόλιο του `DraftExitGate`: *«a rule
 * enforced in five places out of six is not a rule»*. Η πύλη μετακομίζει στο
 * `ContractLifecycle::moveTo()`, το ένα σημείο απ' όπου περνούν όλες οι
 * διαδρομές -- φόρμα, οθόνη κατάστασης, μαζική ενέργεια, εισαγωγή, cron, και
 * κάθε μελλοντικό webhook παρόχου.
 *
 * ## Η υπογραφή, που έλειπε εντελώς
 *
 * Δεύτερο, ξεχωριστό κενό της ίδιας μέρας: **κανένας κανόνας δεν απαιτούσε
 * υπογραφή** για να γίνει μια σύμβαση πληρωτέα. Ο έλεγχος εγγράφων κοιτά
 * παρουσία αρχείων, όχι αν υπέγραψε ποτέ ο πελάτης. Δηλαδή ακόμη και από τη
 * «σωστή» οθόνη, ανεβάζοντας τα δύο απαιτούμενα χαρτιά, μια αίτηση περνούσε σε
 * `active` χωρίς ο πελάτης να έχει δει ποτέ σύνδεσμο υπογραφής.
 *
 * Το μοτίβο της λύσης υπήρχε ήδη δύο γραμμές πιο πάνω, στον ίδιο controller:
 * το `Signed` απαγορεύεται να μπει χειροκίνητα χωρίς `signed_at` (24/08). Η
 * ίδια σκέψη, απλώς δεν είχε επεκταθεί στο `active`.
 *
 * ## Μία λίστα, όχι δύο
 *
 * Χαρτιά και υπογραφή φυλάνε **τις ίδιες** καταστάσεις -- αυτές του
 * `ECRM_Docs::gate_statuses()`. Δεν προστίθεται δεύτερη ρυθμιζόμενη λίστα:
 * είναι μία έννοια («πλήρης, υπογεγραμμένος φάκελος»), άρα ένα φίλτρο, και δεν
 * μπορούν οι δύο να ξεσυγχρονιστούν αργότερα.
 *
 * Σειρά μηνυμάτων: πρώτα τα χαρτιά, μετά η υπογραφή -- ώστε μια σύμβαση που
 * της λείπουν και τα δύο να δίνει **το ίδιο μήνυμα που έδινε πάντα**, και να
 * μην αλλάξει η συμπεριφορά που ήδη ξέρουν οι χρήστες.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use ECRM_Docs;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Domain\Contract\StatusEntryGate;
use EnergyCRM\Persistence\ContractTransitions;

final class PaperworkGate implements StatusEntryGate
{
    /** Το ίδιο μήνυμα από κάθε πόρτα, γιατί είναι το ίδιο γεγονός. */
    public const MISSING_DOCS = 'Λείπουν δικαιολογητικά: ';

    public const EXPIRED_DOCS = 'Έχει λήξει: ';

    public const NOT_SIGNED = 'Η αίτηση δεν έχει υπογραφή πελάτη, οπότε δεν προχωράει σε αυτή την κατάσταση. '
        . 'Η υπογραφή μπαίνει μόνο από τον σύνδεσμο παρακολούθησης που πατά ο ίδιος ο πελάτης.';

    public function __construct(private readonly ContractTransitions $contracts)
    {
    }

    /**
     * Η ερώτηση στη βάση γίνεται **μόνο** όταν ο στόχος είναι φυλασσόμενη
     * κατάσταση. Κάθε άλλη μετάβαση φεύγει από την πρώτη γραμμή χωρίς κόστος:
     * ο κανόνας δεν έχει λόγο να επιβαρύνει διαδρομές που δεν αφορά.
     */
    public function refusalOnEntry(ContractStatus $target, int $contractId): ?string
    {
        if (! in_array($target->value, ECRM_Docs::gate_statuses(), true)) {
            return null;
        }

        $row = $this->contracts->paperworkFieldsOf($contractId);

        // Ανύπαρκτη σύμβαση δεν κρίνεται εδώ. Η ίδια η εγγραφή θα αποτύχει
        // παρακάτω· μια άρνηση εδώ θα έλεγε λάθος αιτία.
        if ($row === null) {
            return null;
        }

        $missing = ECRM_Docs::missing_labels($contractId, $row['activation_type'], $row['energy_type']);

        if ($missing !== []) {
            return self::MISSING_DOCS . implode(', ', $missing);
        }

        // Παρόν δεν σημαίνει έγκυρο: μια ληγμένη ταυτότητα περνά το
        // missing_labels() παραπάνω, αφού το είδος υπάρχει -- απλώς έχει λήξει
        // η ημερομηνία που είναι τυπωμένη πάνω της.
        $expired = ECRM_Docs::expired_docs($contractId);

        if ($expired !== []) {
            $labels = array_map(static fn (array $e): string => (string) $e['label'], $expired);

            return self::EXPIRED_DOCS . implode(', ', $labels);
        }

        return trim($row['signed_at']) === '' ? self::NOT_SIGNED : null;
    }
}
