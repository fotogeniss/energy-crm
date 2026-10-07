<?php

/**
 * Οι καταστάσεις μιας σύμβασης, και ποια μετάβαση επιτρέπεται από πού.
 *
 * ## Γιατί ξαναγράφτηκε ολόκληρο (07/09/2026)
 *
 * Οι προηγούμενες 13 καταστάσεις ήταν σωρός, όχι μοντέλο: είχαν μέσα δύο
 * ονόματα για την ίδια αναμονή υπογραφής (`pending_signature`,
 * `awaiting_signature`), μια `pending` που δεν έλεγε πού βρίσκεται η αίτηση
 * αλλά τι την κρατάει, μια `resolved` που ήταν το κλείσιμο αυτού του
 * εμποδίου, και μια `signed` που ήταν γεγονός βαφτισμένο στάδιο. Το
 * λεξιλόγιο δεν είχε προκύψει από τη δουλειά· είχε μαζευτεί.
 *
 * Το νέο βγήκε από τον υπεύθυνο του δικτύου και καταγράφεται ολόκληρο στο
 * `docs/STATUS-MODEL.md` -- εκεί είναι ο πίνακας μεταβάσεων, οι κανόνες
 * Κ1-Κ8 και οι λόγοι ακύρωσης. Εδώ ζει μόνο ό,τι μπορεί να επιβληθεί από
 * τον τύπο.
 *
 * ## Δύο λίστες από 05/10/2026
 *
 * Ο ιδιοκτήτης έδωσε νέες καταστάσεις γραμμένες στο χέρι: μία λίστα για
 * ρεύμα (και αέριο, χωρίς τα ΘΑΛΗΣ) και μία για κινητή Orizon. Εδώ ζουν
 * όλες σε έναν τύπο, και το `appliesTo()` λέει ποια ανήκει πού. Ποια λίστα
 * ισχύει για μια αίτηση το λέει το `StatusTrack::of()`.
 *
 * Τα «εμπόδια» (οφειλές, εκκρεμότητα) που σχεδιάστηκαν στις 07/09 δεν
 * χτίστηκαν ποτέ. Η Εκκρεμότητα και η Οφειλή είναι πλέον καταστάσεις, όπως
 * στο χαρτί.
 *
 * Οι τρεις παλιές τερματικές (Διακοπή, Ακυρώθηκε από εμάς, από πελάτη)
 * έγιναν μία: «Ακυρώθηκε». Αν η αίτηση είχε γίνει ποτέ Ενεργός, η προμήθεια
 * που πληρώθηκε μένει (βλ. `CancellationGate::keepsCommission()`).
 *
 * Ανάμεσα στις ενδιάμεσες καταστάσεις η αίτηση πάει ελεύθερα, επιλογή του
 * ιδιοκτήτη: το back office ξέρει τι έγινε, ο γράφος όχι.
 *
 * ## Πληρωτέα είναι ΜΟΝΟ η ΕΝΕΡΓΟΣ
 *
 * Η οριστικοποίηση δεν πληρώνει, ό,τι κι αν φαίνεται «ολοκληρωμένο» σε
 * αυτήν. Ήταν ρητή διόρθωση του ιδιοκτήτη μέσα στη σχεδίαση, και είναι ο
 * λόγος που η `PaperworkGate` (χαρτιά + υπογραφή) και το δεύτερο επίπεδο
 * δικαιώματος κοιτούν αυτή τη μία κατάσταση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

enum ContractStatus: string
{
    /** Δεν υποβλήθηκε ακόμα. Δεν φτάνει στο back office, δεν μετράει πουθενά. */
    case Draft = 'draft';

    case Presale = 'presale';

    case Registration = 'registration';

    case AwaitingSignature = 'awaiting_signature';

    /** Ρεύμα/αέριο: ο πελάτης υπέγραψε, πάει για οριστικοποίηση. */
    case ToFinalisation = 'to_finalisation';

    /** Κινητή: ο πελάτης υπέγραψε. */
    case SignatureComplete = 'signature_complete';

    /** Κινητή: courier με SIM. Το slug κρατήθηκε από το παλιό μοντέλο. */
    case AwaitingSim = 'awaiting_sim';

    case SimDelivered = 'sim_delivered';

    case Finalisation = 'finalisation';

    /** Μόνο ρεύμα. */
    case ThalisConfirmed = 'thalis_confirmed';

    /** Μόνο ρεύμα. */
    case ThalisRejected = 'thalis_rejected';

    case PendingIssue = 'pending_issue';

    /** Ρεύμα/αέριο. */
    case Recheck = 'recheck';

    /** Κινητή. */
    case Debt = 'debt';

    /** ΠΛΗΡΩΤΕΑ. Εδώ και μόνο εδώ κλειδώνει προμήθεια. */
    case Active = 'active';

    /** Κινητή. */
    case ForReview = 'for_review';

    /** Κινητή: απόρριψη φορητότητας. */
    case MnpReject = 'mnp_reject';

    /**
     * Τέλος, για κάθε λόγο. Αντικαθιστά τις παλιές Διακοπή, Ακυρώθηκε από
     * εμάς και Ακυρώθηκε από πελάτη.
     */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft             => 'Πρόχειρο',
            self::Presale           => 'Presale',
            self::Registration      => 'Καταχώρηση',
            self::AwaitingSignature => 'Αναμονή υπογραφής',
            self::ToFinalisation    => 'Προς οριστικοποίηση',
            self::SignatureComplete => 'Ολοκλήρωση υπογραφής',
            self::AwaitingSim       => 'Αναμονή παράδοσης SIM',
            self::SimDelivered      => 'Παράδοση SIM',
            self::Finalisation      => 'Οριστικοποίηση',
            self::ThalisConfirmed   => 'Επιβεβαίωση ΘΑΛΗΣ',
            self::ThalisRejected    => 'Απόρριψη ΘΑΛΗΣ',
            self::PendingIssue      => 'Εκκρεμότητα',
            self::Recheck           => 'Επανέλεγχος',
            self::Debt              => 'Οφειλή',
            self::Active            => 'Ενεργός',
            self::ForReview         => 'For review',
            self::MnpReject         => 'MP reject',
            self::Cancelled         => 'Ακυρώθηκε',
        };
    }

    /**
     * Ανήκει η κατάσταση στη λίστα αυτού του είδους αίτησης;
     *
     * @param string $track `StatusTrack::POWER`, `GAS` ή `MOBILE`.
     */
    public function appliesTo(string $track): bool
    {
        return match ($this) {
            self::ToFinalisation, self::Recheck => $track !== StatusTrack::MOBILE,
            self::ThalisConfirmed, self::ThalisRejected => $track === StatusTrack::POWER,
            self::SignatureComplete, self::AwaitingSim, self::SimDelivered,
            self::Debt, self::ForReview, self::MnpReject => $track === StatusTrack::MOBILE,
            default => true,
        };
    }

    /**
     * Οι καταστάσεις μιας λίστας, με τη σειρά του χαρτιού.
     *
     * @return list<self>
     */
    public static function forTrack(string $track): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $s): bool => $s->appliesTo($track)
        ));
    }

    /** Τέλος διαδρομής: καμία μετάβαση δεν βγαίνει από εδώ. */
    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }

    /** Πληρωτέα είναι μόνο η Ενεργός. */
    public function isPayable(): bool
    {
        return $this === self::Active;
    }

    public function isCancellation(): bool
    {
        return $this === self::Cancelled;
    }

    /** Ούτε πρόχειρο, ούτε Ενεργός, ούτε τέλος: οι καταστάσεις της δουλειάς. */
    public function isIntermediate(): bool
    {
        return ! in_array($this, [self::Draft, self::Active, self::Cancelled], true);
    }

    /**
     * Τα slugs των ενδιάμεσων, για ερωτήματα SQL και λίστες «σε εξέλιξη».
     *
     * @return list<string>
     */
    public static function intermediateValues(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            if ($case->isIntermediate()) {
                $out[] = $case->value;
            }
        }

        return $out;
    }

    /**
     * Έξοδος από την πληρωτέα κατάσταση, προς οποιαδήποτε κατεύθυνση.
     *
     * Φυλάει το δεύτερο επίπεδο δικαιώματος (`ecrm_exit_active`): η αλλαγή
     * μιας Ενεργού κοστίζει χρήματα, οπότε δεν την κάνει όποιος να 'ναι. Και
     * η ακύρωση μιας Ενεργού (η παλιά Διακοπή) μετράει ως έξοδος.
     */
    public static function exitsActive(self $from, self $to): bool
    {
        return $from === self::Active && $to !== self::Active;
    }

    /**
     * Πού μπορεί να πάει από εδώ, χωρίς να ξέρουμε το είδος της αίτησης.
     *
     * - Από το πρόχειρο: μόνο υποβολή ή ακύρωση.
     * - Από κάθε ενδιάμεση: σε κάθε άλλη ενδιάμεση, στην Ενεργό, ή ακύρωση.
     * - Από την Ενεργό: πίσω σε ενδιάμεση για διόρθωση, ή ακύρωση. Και τα δύο
     *   θέλουν το `ecrm_exit_active`.
     * - Από την ακύρωση: πουθενά.
     *
     * Κανένα βήμα πίσω στο πρόχειρο. Ποιες ενδιάμεσες ανήκουν σε ποιο είδος
     * το φιλτράρει το `allowedNextFor()`.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        if ($this === self::Draft) {
            return [self::Presale, self::Cancelled];
        }

        if ($this === self::Cancelled) {
            return [];
        }

        $out = [];

        foreach (self::cases() as $case) {
            if ($case === $this || $case === self::Draft) {
                continue;
            }

            $out[] = $case;
        }

        return $out;
    }

    /**
     * Ίδιο με το `allowedNext()`, μόνο με τις καταστάσεις της λίστας του.
     *
     * @return list<self>
     */
    public function allowedNextFor(string $track): array
    {
        return array_values(array_filter(
            $this->allowedNext(),
            static fn (self $s): bool => $s->appliesTo($track)
        ));
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /** Το slug σε enum, ή null όταν δεν αντιστοιχεί σε τίποτα. */
    public static function tryFromSlug(?string $slug): ?self
    {
        return $slug === null ? null : self::tryFrom($slug);
    }

    /**
     * slug => ελληνική ετικέτα, για φίλτρα και dropdowns.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
