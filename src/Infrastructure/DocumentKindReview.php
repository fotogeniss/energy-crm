<?php

/**
 * Διαβάζει τα έγγραφα μιας αίτησης και διορθώνει την ετικέτα τους όταν είναι
 * σίγουρη ότι λέει άλλο πράγμα από αυτό που έγραψε ο συνεργάτης.
 *
 * ## Γιατί υπάρχει
 *
 * Ο συνεργάτης ανέβαζε ταυτότητα και λογαριασμό μαζί, το widget έβαζε την ίδια
 * ετικέτα και στα δύο, και το `ECRM_Docs::checklist()` -- που κοιτά μόνο αν
 * υπάρχει `doc_kind` -- έλεγε «λείπουν δικαιολογητικά» για χαρτί που ήταν ήδη
 * στον φάκελο. Το ανέβασμα ανά αρχείο λύνει την αιτία. Αυτό εδώ πιάνει το
 * ανθρώπινο λάθος που θα ξανασυμβεί ούτως ή άλλως: το ζητούμενο του ιδιοκτήτη
 * ήταν ρητά να μη χρειάζεται να το προσέξει κανείς για να διορθωθεί.
 *
 * ## Γιατί ΜΕΤΑ το ανέβασμα, όχι πριν
 *
 * Πριν, θα σήμαινε ότι τα bytes ταξιδεύουν δύο φορές (μια για ανάγνωση, μια
 * για αποθήκευση) και ότι ο συνεργάτης περιμένει την απάντηση του μοντέλου
 * πριν καν μπει το αρχείο. Μετά, τα έγγραφα είναι ήδη σωσμένα και διαβάζονται
 * από τον δίσκο: αν η ανάγνωση αργήσει, αποτύχει ή δεν γίνει καθόλου, το μόνο
 * που χάνεται είναι η διόρθωση -- ποτέ το ίδιο το ανέβασμα.
 *
 * ## Γιατί δεν ξαναδιαβάζει
 *
 * Κάθε αρχείο σημειώνεται μόλις κριθεί (`kind_source`), ακόμα κι όταν η
 * ανάγνωση συμφώνησε. Χωρίς αυτό, κάθε άνοιγμα της καρτέλας θα ξεκινούσε την
 * ίδια ανάγνωση των ίδιων αρχείων και θα πλήρωνε ξανά -- για πάντα. Το ίδιο
 * σκεπτικό που κράτησε τον εξαγωγέα έξω από τη δεύτερη κλήση στο (171).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use ECRM_Docs;
use ECRM_Extractor;
use EnergyCRM\Domain\Document\KindVerdict;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\FileRepository;

final class DocumentKindReview
{
    /**
     * Τι μπορεί να διαβαστεί.
     *
     * Ιδια λίστα με τον εξαγωγέα: ο,τι δεν είναι εικόνα ή PDF δεν φτάνει καν
     * στο μοντέλο ως μπλοκ περιεχομένου.
     *
     * @var list<string>
     */
    private const READABLE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /**
     * Πόσα αρχεία διαβάζονται σε μία κλήση.
     *
     * Το ανέβασμα δέχεται ήδη μέχρι δέκα· ίδιο ταβάνι εδώ, ώστε ένα batch που
     * πέρασε τον έλεγχο του ανεβάσματος να μη μένει μισοδιαβασμένο.
     */
    private const MAX_PER_RUN = 10;

    /**
     * Ο διακόπτης στις Ρυθμίσεις.
     *
     * Γραμμένο ολόκληρο, όχι μέσω `ECRM_PREFIX`: το `src/` δεν εξαρτάται από
     * defines του plugin πουθενά αλλού (`Retention`, `SecretStore`), και δεν
     * αρχίζει από εδώ -- ο κανόνας υπάρχει για να μπορεί αυτός ο κώδικας να
     * σηκωθεί χωρίς το WordPress bootstrap (§1.12).
     */
    private const OPTION = 'ecrm_doc_kind_ai';

    public function __construct(
        private readonly FileRepository $files,
        private readonly ExtractionGate $gate,
        private readonly EventRepository $events,
    ) {
    }

    /**
     * Ειναι ανοιχτή η αυτόματη αναγνώριση;
     *
     * Ανοιχτή εξ ορισμού. Ο διακόπτης υπάρχει ώστε να μπορεί να κλείσει χωρίς
     * αλλαγή κώδικα -- π.χ. αν το κόστος ανά αίτηση σταματήσει να βγαίνει.
     */
    public function enabled(): bool
    {
        return '1' === (string) get_option(self::OPTION, '1');
    }

    /**
     * Ποιοι τύποι αρχείου διαβάζονται -- έξω από αυτή την κλάση για το
     * `DocumentsSweep` (243), που χρειάζεται την ίδια λίστα για να ρωτήσει
     * τη βάση ΠΟΙΕΣ αιτήσεις αξίζει να διαβάσει, χωρίς να αντιγράψει τη
     * σταθερά σε δεύτερο σημείο.
     *
     * @return list<string>
     */
    public function readableMimes(): array
    {
        return self::READABLE_MIMES;
    }

    /**
     * Διάβασε ό,τι δεν έχει κριθεί σε αυτή την αίτηση.
     *
     * Η εμβέλεια ΔΕΝ ελέγχεται εδώ -- ο καλών έχει ήδη επιβεβαιώσει ότι ο
     * χρήστης βλέπει τη σύμβαση, όπως ακριβώς και στις μεθόδους ανάγνωσης του
     * `FileRepository`.
     *
     * @return array{checked: int, fixed: list<array{id: int, from: string, to: string}>, busy: bool}
     */
    public function run(int $contractId): array
    {
        $nothing = ['checked' => 0, 'fixed' => [], 'busy' => false];

        if ($contractId <= 0 || ! $this->enabled() || ECRM_Extractor::api_key() === '') {
            return $nothing;
        }

        $pending = array_slice(
            $this->files->pendingKindReview($contractId, self::READABLE_MIMES),
            0,
            self::MAX_PER_RUN
        );

        if ($pending === []) {
            return $nothing;
        }

        // Κάθε ανάγνωση κρατά μια θέση όσο τρέχει. Αν δεν υπάρχει ελεύθερη, η
        // απάντηση είναι «απασχολημένο» και όχι σφάλμα: τα αρχεία μένουν
        // ασημείωτα και θα ξαναδοκιμαστούν στο επόμενο άνοιγμα της καρτέλας.
        if (! $this->gate->enter()) {
            return ['checked' => 0, 'fixed' => [], 'busy' => true];
        }

        try {
            $result = ECRM_Extractor::classify(array_map(
                static fn (array $row): array => [
                    'path' => $row['path'],
                    'mime' => $row['mime'],
                ],
                $pending
            ));
        } finally {
            // Σε finally ώστε μια ανάγνωση που πετάει να μην κρατήσει τη θέση
            // μέχρι να κλείσει η σύνδεση.
            $this->gate->leave();
        }

        if (($result['ok'] ?? false) !== true) {
            // Καμία σήμανση: μια αποτυχία δικτύου δεν είναι κρίση για το
            // έγγραφο, και το αρχείο πρέπει να ξαναδοκιμαστεί.
            return $nothing;
        }

        /** @var array<int, array{kind: ?string, confidence: ?string}> $readings */
        $readings = $result['kinds'] ?? [];

        return $this->applyAll($contractId, $pending, $readings);
    }

    /**
     * Γράψε τα αποτελέσματα -- και σημείωσε ΚΑΘΕ αρχείο που διαβάστηκε.
     *
     * @param list<array{id: int, path: string, mime: string, doc_kind: string}> $pending
     * @param array<int, array{kind: ?string, confidence: ?string}>              $readings
     *
     * @return array{checked: int, fixed: list<array{id: int, from: string, to: string}>, busy: bool}
     */
    private function applyAll(int $contractId, array $pending, array $readings): array
    {
        $fixed   = [];
        $checked = 0;

        foreach ($pending as $index => $row) {
            $reading = $readings[$index] ?? null;

            if (! is_array($reading)) {
                // Το μοντέλο δεν απάντησε γι' αυτό το αρχείο. Ασημείωτο, ώστε
                // να ξαναδοκιμαστεί -- σιωπή δεν είναι κρίση.
                continue;
            }

            $declared   = (string) $row['doc_kind'];
            $correction = KindVerdict::correction(
                $declared,
                $reading['kind'] ?? null,
                $reading['confidence'] ?? null
            );

            $this->files->markKindReviewed(
                (int) $row['id'],
                $contractId,
                KindVerdict::sourceAfterReview($correction),
                $correction,
                $declared
            );

            ++$checked;

            if ($correction === null) {
                continue;
            }

            $fixed[] = ['id' => (int) $row['id'], 'from' => $declared, 'to' => $correction];

            $this->record($contractId, $declared, $correction);
            Metrics::bump(Metrics::DOC_KIND_FIXED);
        }

        return ['checked' => $checked, 'fixed' => $fixed, 'busy' => false];
    }

    /**
     * Γράψε την αλλαγή στο ιστορικό της αίτησης.
     *
     * Με `user_id` μηδέν, που είναι η αλήθεια: δεν την έκανε χρήστης. Χωρίς
     * αυτή την εγγραφή, μια ετικέτα θα άλλαζε μόνη της και κανείς δεν θα
     * μπορούσε να πει ποιος την άλλαξε ή πότε -- σε φάκελο με σαρωμένες
     * ταυτότητες αυτό δεν είναι λεπτομέρεια.
     *
     * Το όνομα του αρχείου ΔΕΝ μπαίνει: οι συνεργάτες τα ονομάζουν
     * «ΠΑΠΑΔΟΠΟΥΛΟΣ_ΤΑΥΤΟΤΗΤΑ.jpg» και το ιστορικό δεν είναι μέρος για ονόματα
     * πελατών -- ίδιος κανόνας με το ημερολόγιο σφαλμάτων του
     * `DocumentsController`.
     */
    private function record(int $contractId, string $from, string $to): void
    {
        $this->events->record($contractId, 0, 'doc_kind_ai', [
            'message' => sprintf(
                'Αυτόματη αναγνώριση εγγράφου — είδος: %s → %s',
                ECRM_Docs::label($from),
                ECRM_Docs::label($to)
            ),
        ]);
    }
}
