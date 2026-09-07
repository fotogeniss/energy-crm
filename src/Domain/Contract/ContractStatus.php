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
 * ## Κατάσταση ≠ εμπόδιο
 *
 * Η κεντρική διάκριση, και ο λόγος που από τα 11 ονόματα της αρχικής λίστας
 * εδώ υπάρχουν τα 8 (συν το πρόχειρο και τη διακοπή):
 *
 * - **Κατάσταση** = πού βρίσκεται η αίτηση στη γραμμή. Μία τιμή, εδώ.
 * - **Εμπόδιο** = τι την κρατάει αυτή τη στιγμή. Μηδέν έως τρία ταυτόχρονα,
 *   σε δικό τους πίνακα (`contract_blockers`).
 *
 * Μια σύμβαση μπορεί να είναι «Οριστικοποίηση **με** οφειλές **και**
 * εκκρεμότητα». Με μία στήλη αυτό δεν λέγεται -- ή χάνεις πού βρίσκεται, ή
 * χάνεις τι την κρατάει. Οι παλιές `pending`/`resolved` ήταν ακριβώς αυτή η
 * σύγχυση, κωδικοποιημένη.
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

    /** Υποβλήθηκε, λείπουν δικαιολογητικά. */
    case Presale = 'presale';

    /** Έχουμε όλα τα χαρτιά· καταχωρείται από το back office. */
    case Registration = 'registration';

    /** Στάλθηκε στον πελάτη, περιμένουμε την υπογραφή του. */
    case AwaitingSignature = 'awaiting_signature';

    /** Courier με SIM. Μόνο για αιτήσεις με κινητή -- αλλιώς παρακάμπτεται. */
    case AwaitingSim = 'awaiting_sim';

    /** Ολοκληρώθηκε από πλευράς μας· περιμένει τον πάροχο. */
    case Finalisation = 'finalisation';

    /** ΠΛΗΡΩΤΕΑ. Εδώ και μόνο εδώ κλειδώνει προμήθεια. */
    case Active = 'active';

    /**
     * Ήταν ενεργή και σταμάτησε.
     *
     * Δεν είναι ακύρωση: η προμήθεια που πληρώθηκε μένει πληρωμένη, γιατί η
     * σύμβαση όντως δούλεψε. Κρατάει το παλιό slug επίτηδες -- σημαίνει ήδη
     * ακριβώς αυτό, και μια μετονομασία θα μεγάλωνε το diff χωρίς κέρδος.
     */
    case Terminated = 'terminated';

    /** Ακυρώθηκε με δική μας ευθύνη/απόφαση (πολιτική αποδοχής, δικαιολογητικά, δέσμευση). */
    case CancelledByUs = 'cancelled_by_us';

    /** Ακυρώθηκε επειδή το γύρισε ο πελάτης (μετάνιωσε, retention). */
    case CancelledByCustomer = 'cancelled_by_customer';

    public function label(): string
    {
        return match ($this) {
            self::Draft               => 'Πρόχειρο',
            self::Presale             => 'Presale',
            self::Registration        => 'Καταχώρηση',
            self::AwaitingSignature   => 'Αναμονή υπογραφής',
            self::AwaitingSim         => 'Αναμονή παράδοσης SIM',
            self::Finalisation        => 'Οριστικοποίηση',
            self::Active              => 'Ενεργός',
            self::Terminated          => 'Διακοπή',
            self::CancelledByUs       => 'Ακυρώθηκε από εμάς',
            self::CancelledByCustomer => 'Ακυρώθηκε από πελάτη',
        };
    }

    /** Τέλος διαδρομής: καμία μετάβαση δεν βγαίνει από εδώ. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Terminated, self::CancelledByUs, self::CancelledByCustomer => true,
            default => false,
        };
    }

    /**
     * Μία, και μόνο μία.
     *
     * Ήταν τρεις (`routed`, `active`, `resolved`) και αυτό ήταν λάθος: η
     * `routed` σήμαινε «φύγαμε προς τον πάροχο» και η `resolved` «λύθηκε το
     * εμπόδιο» -- καμία από τις δύο δεν σημαίνει ότι η σύμβαση δούλεψε.
     */
    public function isPayable(): bool
    {
        return $this === self::Active;
    }

    /** Οι δύο ακυρώσεις μαζί -- και οι δύο απαιτούν λόγο. */
    public function isCancellation(): bool
    {
        return $this === self::CancelledByUs || $this === self::CancelledByCustomer;
    }

    /**
     * Έξοδος από την πληρωτέα κατάσταση, προς οποιαδήποτε κατεύθυνση.
     *
     * Ζει εδώ και όχι σαν `if` στον controller επειδή είναι ο ορισμός που
     * φυλάει το δεύτερο επίπεδο δικαιώματος (`ecrm_exit_active`): *«το να
     * αλλάξει από ενεργός δεν μπορεί να γίνει έτσι απλά από έναν πωλητή --
     * πρέπει να γίνει από υπεύθυνο, ώστε να αποφύγουμε αυτό το οικονομικό
     * πρόβλημα»*. Ένας ορισμός, ένα σημείο να αλλάξει, και τα tests τον
     * ρωτούν κατευθείαν.
     *
     * Και η ΔΙΑΚΟΠΗ μετράει ως έξοδος. Είναι νόμιμο τέλος ζωής, αλλά κλείνει
     * μια σύμβαση που έχει ήδη πληρώσει προμήθεια -- δεν το κάνει όποιος να
     * 'ναι.
     */
    public static function exitsActive(self $from, self $to): bool
    {
        return $from === self::Active && $to !== self::Active;
    }

    /**
     * Πού μπορεί να πάει από εδώ.
     *
     * Ο γράφος έχει τρία είδη ακμών, και αξίζει να ξεχωρίζουν διαβάζοντας:
     *
     * 1. **Εμπρός** -- η κανονική ροή, ένα βήμα τη φορά.
     * 2. **Πίσω** -- διόρθωση του back office (Κ7). Υπάρχουν επειδή η
     *    πραγματικότητα δεν προχωράει πάντα προς τα εμπρός: βρέθηκε ότι
     *    λείπει χαρτί μετά την καταχώρηση, ήρθε λάθος SIM, στάλθηκε λάθος
     *    σύνδεσμος. Χωρίς αυτές ο μόνος τρόπος διόρθωσης θα ήταν απευθείας
     *    UPDATE στη βάση, που δεν αφήνει ίχνος.
     * 3. **Ακύρωση** -- από κάθε μη τερματική κατάσταση, στα δύο είδη.
     *
     * Δύο απουσίες που είναι σκόπιμες:
     *
     * - **Καμία επιστροφή στο `draft`.** Το πρόχειρο είναι πριν την υποβολή·
     *   μια αίτηση που υποβλήθηκε δεν ξαναγίνεται ανυπόβλητη.
     * - **Καμία ακύρωση από την `active`.** Σύμβαση που δούλεψε δεν
     *   «ακυρώνεται» -- διακόπτεται. Ο `CancellationGate` το επιβάλλει ήδη
     *   και για το ιστορικό (σύμβαση που ΥΠΗΡΞΕ ενεργή), κάτι που ο γράφος
     *   από μόνος του δεν μπορεί να ξέρει· εδώ κλείνει απλώς η μπροστινή
     *   πόρτα.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        $cancellations = [self::CancelledByUs, self::CancelledByCustomer];

        return match ($this) {
            self::Draft => [self::Presale, ...$cancellations],

            self::Presale => [self::Registration, ...$cancellations],

            // Πίσω στο presale: το back office άνοιξε τον φάκελο και βρήκε
            // ότι κάτι λείπει ή δεν ισχύει.
            self::Registration => [self::AwaitingSignature, self::Presale, ...$cancellations],

            // Δύο μπροστινοί δρόμοι, και ποιος ισχύει το λέει η ίδια η
            // αίτηση: με κινητή περνάει από το SIM, χωρίς κινητή πάει
            // κατευθείαν στην οριστικοποίηση (Κ2). Ο γράφος δεν ξέρει τι
            // περιέχει η σύμβαση -- επιτρέπει και τα δύο, και ο κώδικας που
            // προωθεί αυτόματα διαλέγει.
            self::AwaitingSignature => [
                self::AwaitingSim,
                self::Finalisation,
                self::Registration,
                ...$cancellations,
            ],

            self::AwaitingSim => [self::Finalisation, self::AwaitingSignature, ...$cancellations],

            self::Finalisation => [
                self::Active,
                self::AwaitingSim,
                self::AwaitingSignature,
                ...$cancellations,
            ],

            // Μπροστά η διακοπή, πίσω η οριστικοποίηση για διόρθωση. Και τα
            // δύο περνούν από το `ecrm_exit_active` -- βλ. exitsActive().
            self::Active => [self::Terminated, self::Finalisation],

            self::Terminated, self::CancelledByUs, self::CancelledByCustomer => [],
        };
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
