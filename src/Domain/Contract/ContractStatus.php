<?php

/**
 * Where a contract sits in the pipeline, and where it may go next.
 *
 * The statuses existed already, as loose strings validated only against a list
 * of known names. Anything could become anything: a cancelled application could
 * return to draft, a signed one could go back to new, an active supply could be
 * rewound past its own signature. Each of those quietly rewrites commercial
 * history, and the events log would faithfully record the nonsense.
 *
 * The graph below is deliberately generous going forward — the back office
 * genuinely moves work around — and strict about two things: terminal states
 * are terminal, and nothing returns to a stage before its signature.
 *
 * Pure domain logic: no WordPress, no database, fully unit tested.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

enum ContractStatus: string
{
    case Draft             = 'draft';
    case Submitted         = 'new';
    case PendingSignature  = 'pending_signature';
    case AwaitingSignature = 'awaiting_signature';
    case Signed            = 'signed';
    case Processing        = 'processing';
    case Pending           = 'pending';
    case Resolved          = 'resolved';
    case Routed            = 'routed';
    case Active            = 'active';
    case Cancelled         = 'cancelled';
    case Terminated        = 'terminated';

    // Rejected προστέθηκε 31/08: ξεχωριστό από το Cancelled επίτηδες -- ο
    // ιδιοκτήτης το επέλεξε ρητά ("Νέο status «Rejected» (Συνιστάται)") αντί
    // για εικασία πάνω στο μήνυμα ενός Cancelled, ώστε μια απόρριψη παρόχου
    // να μετράει ξεχωριστά σε αναφορές/dashboard και όχι σαν γενική ακύρωση.
    case Rejected           = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft             => 'Πρόχειρο',
            self::Submitted         => 'Νέα',
            self::PendingSignature  => 'Περιμένει υπογραφή',
            self::AwaitingSignature => 'Αναμονή υπογραφής πελάτη',
            self::Signed            => 'Υπογράφηκε',
            self::Processing        => 'Σε επεξεργασία',
            self::Pending           => 'Εκκρεμεί',
            self::Resolved          => 'Επιλύθηκε',
            self::Routed            => 'Στάλθηκε στον πάροχο',
            self::Active            => 'Ενεργή',
            self::Cancelled         => 'Ακυρώθηκε',
            self::Terminated        => 'Έκλεισε',
            self::Rejected          => 'Απορρίφθηκε',
        };
    }

    /** Nothing leaves these. A new application is the way forward. */
    public function isTerminal(): bool
    {
        return $this === self::Cancelled || $this === self::Terminated || $this === self::Rejected;
    }

    /** Statuses that count as a payable contract for commission purposes. */
    public function isPayable(): bool
    {
        return in_array($this, [self::Routed, self::Active, self::Resolved], true);
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [
                self::Submitted, self::PendingSignature, self::AwaitingSignature, self::Cancelled,
            ],
            self::Submitted => [
                self::PendingSignature, self::AwaitingSignature, self::Signed,
                self::Processing, self::Pending, self::Cancelled,
            ],
            self::PendingSignature => [
                self::AwaitingSignature, self::Signed, self::Cancelled,
            ],
            self::AwaitingSignature => [
                self::PendingSignature, self::Signed, self::Cancelled,
            ],
            // PendingSignature προστέθηκε 24/08: μια υπογεγραμμένη αίτηση
            // μπορεί γνήσια να χρειαστεί δεύτερη υπογραφή (λάθος που φάνηκε
            // μετά, πελάτης που θέλει να την ξανακάνει) — δεν είναι ρύθμιση
            // πίσω στον χρόνο, είναι νέος γύρος. Η μόνη πύλη που εμποδίζει
            // κάθε τυχαίο κλικ να σβήσει μια υπογραφή είναι στο
            // SignLinkController::create() (confirm_resend), όχι εδώ — αυτός
            // ο γράφος λέει μόνο ποια μετάβαση είναι νόμιμη, όχι πότε.
            self::Signed => [
                self::Processing, self::Pending, self::Resolved, self::Routed,
                self::PendingSignature, self::Cancelled,
            ],
            self::Processing => [
                self::Pending, self::Resolved, self::Routed, self::Active, self::Cancelled, self::Rejected,
            ],
            self::Pending => [
                self::Processing, self::Resolved, self::Routed, self::Cancelled, self::Rejected,
            ],
            self::Resolved => [
                self::Processing, self::Pending, self::Routed, self::Active, self::Cancelled,
            ],
            // PendingSignature και εδώ, 24/08, και είναι η ΠΙΟ συχνή από τις
            // δύο στην πράξη: ο πάροχος παίρνει την αίτηση και τη γυρίζει
            // πίσω ζητώντας νέα υπογραφή (λάθος πεδίο, λάθος κουτί, κακή
            // ποιότητα σάρωσης). Η αίτηση εκείνη τη στιγμή είναι εδώ,
            // «Στάλθηκε στον πάροχο» — όχι στο Signed, το έχει ήδη περάσει.
            // Χωρίς αυτή τη γραμμή ο συνεργάτης δεν είχε ΚΑΜΙΑ διαδρομή:
            // έπρεπε να ακυρώσει και να ξαναφτιάξει ολόκληρη την αίτηση.
            //
            // Το isPayable() περιλαμβάνει το Routed — μια αίτηση που γυρίζει
            // από εδώ σε νέα υπογραφή σταματά προσωρινά να μετρά για προμήθεια
            // μέχρι να ξαναφτάσει, που είναι το σωστό: δεν ολοκληρώθηκε ακόμη.
            // Rejected προστέθηκε 31/08 στα ίδια τρία σημεία όπου φτάνει ήδη το
            // Cancelled (Routed/Processing/Pending) -- εκεί είναι η μπάλα στον
            // πάροχο, άρα εκεί μπορεί να έρθει μια απόρριψή του. ΟΧΙ στο
            // Resolved/Active: μια αίτηση που έφτασε ή πέρασε ενεργοποίηση δεν
            // "απορρίπτεται" πια, τερματίζεται (βλ. CancellationGate).
            self::Routed => [
                self::Processing, self::Pending, self::Active,
                self::PendingSignature, self::Cancelled, self::Terminated, self::Rejected,
            ],
            self::Active => [
                self::Pending, self::Terminated,
            ],
            self::Cancelled, self::Terminated, self::Rejected => [],
        };
    }

    /** Staying put is always fine; callers treat it as a no-op. */
    public function canMoveTo(self $target): bool
    {
        return $this === $target || in_array($target, $this->allowedNext(), true);
    }

    public static function tryFromSlug(?string $slug): ?self
    {
        return $slug === null ? null : self::tryFrom($slug);
    }

    /**
     * Slug => Greek label, in pipeline order. Used for the filter tabs and the
     * status dropdowns, so those stay in step with the graph automatically.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $status) {
            $labels[$status->value] = $status->label();
        }

        return $labels;
    }
}
