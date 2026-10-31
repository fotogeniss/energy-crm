<?php

/**
 * (342) Γιατί απορρίφθηκε ή ακυρώθηκε μια αίτηση -- από λίστα, όχι ελεύθερο
 * κείμενο. docs/UI-REJECT-REASONS.html (εγκρίθηκε 31/10).
 *
 * Το STATUS-MODEL το ζητούσε από την αρχή (Κ5: «κάθε ακύρωση απαιτεί λόγο από
 * τη λίστα του §7») και δεν υλοποιήθηκε: ο λόγος γραφόταν, όταν γραφόταν, ως
 * σημείωση, και δεν μετριόταν πουθενά. Χωρίς κωδικό, «τι μας κοστίζει πιο
 * συχνά» δεν απαντιέται.
 *
 * - Απόρριψη (ΘΑΛΗΣ, MP reject): τι βρήκε ο πάροχος.
 * - Ακύρωση: οι πέντε του §7, συν «απορρίφθηκε από πάροχο» και «άλλο».
 * Το «άλλο» θέλει σημείωση, αλλιώς δεν λέει τίποτα.
 *
 * Οι κωδικοί αποθηκεύονται (events.reason)· οι ετικέτες αλλάζουν ελεύθερα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class CloseReason
{
    public const OTHER = 'other';

    /** Χωρίς λόγο: παλιές αιτήσεις, μαζικές από Excel. Μόνο για αναφορές. */
    public const NONE = '';

    private const REJECT = [
        'documents'    => 'Ελλιπή ή δυσανάγνωστα δικαιολογητικά',
        'data_mismatch' => 'Στοιχεία δεν ταιριάζουν (ΑΦΜ, ΑΔΤ, όνομα)',
        'wrong_number' => 'Λάθος αριθμός παροχής / τηλεφώνου',
        'debt'         => 'Οφειλή σε προηγούμενο πάροχο',
        'supply_locked' => 'Παροχή δεσμευμένη ή σε διακοπή',
        'signature'    => 'Λείπει ή λάθος υπογραφή',
        'acceptance'   => 'Πολιτική αποδοχής παρόχου',
        self::OTHER    => 'Άλλο',
    ];

    private const CANCEL = [
        'contract_lock'  => 'Δέσμευση συμβολαίου',
        'retention'      => 'Τον κράτησε ο πάροχος (retention)',
        'acceptance'     => 'Πολιτική αποδοχής',
        'changed_mind'   => 'Το μετάνιωσε',
        'incomplete'     => 'Δεν ολοκληρώθηκαν τα δικαιολογητικά / δεν ανταποκρίθηκε',
        'provider_reject' => 'Απορρίφθηκε από πάροχο',
        self::OTHER      => 'Άλλο',
    ];

    private function __construct()
    {
    }

    /**
     * Οι λόγοι για μια κατάσταση, ή [] όταν δεν χρειάζεται λόγος.
     *
     * @return array<string, string> κωδικός => ετικέτα
     */
    public static function for(string $status): array
    {
        return match ($status) {
            ContractStatus::ThalisRejected->value, ContractStatus::MnpReject->value => self::REJECT,
            ContractStatus::Cancelled->value => self::CANCEL,
            default => [],
        };
    }

    /**
     * Θέλει λόγο αυτή η μετάβαση;
     *
     * Όχι όταν πετιέται ένα πρόχειρο («Ακυρώθηκε» από «Πρόχειρο»): δεν
     * στάλθηκε ποτέ, άρα δεν χάθηκε -- και δεν πρέπει να μετρά στο «Γιατί
     * χάνουμε αιτήσεις».
     */
    public static function required(string $status, string $from = ''): bool
    {
        return $from !== ContractStatus::Draft->value && self::for($status) !== [];
    }

    /**
     * Ελέγχει την επιλογή· null όταν είναι εντάξει, αλλιώς το μήνυμα.
     */
    public static function problem(string $status, string $reason, string $note, string $from = ''): ?string
    {
        if (! self::required($status, $from)) {
            return null;
        }

        $list = self::for($status);

        if (! isset($list[$reason])) {
            return 'Διάλεξε λόγο.';
        }

        if ($reason === self::OTHER && trim($note) === '') {
            return 'Γράψε τι έγινε στο «Άλλο».';
        }

        return null;
    }

    /** Η ετικέτα ενός κωδικού, σε όποια λίστα κι αν ανήκει. */
    public static function label(string $reason): string
    {
        if ($reason === self::NONE) {
            return 'Χωρίς λόγο';
        }

        return self::REJECT[$reason] ?? self::CANCEL[$reason] ?? $reason;
    }

    /**
     * Όλες οι λίστες, για τη σελίδα (window.ECRM).
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public static function all(): array
    {
        $out = [];

        foreach ([ContractStatus::ThalisRejected, ContractStatus::MnpReject, ContractStatus::Cancelled] as $status) {
            $out[$status->value] = array_map(
                static fn (string $code, string $label): array => [$code, $label],
                array_keys(self::for($status->value)),
                array_values(self::for($status->value))
            );
        }

        return $out;
    }
}
