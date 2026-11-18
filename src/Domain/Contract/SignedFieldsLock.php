<?php

/**
 * (415) Ό,τι υπέγραψε ο πελάτης δεν αλλάζει από πωλητή ή συνεργάτη.
 *
 * Απόφαση ιδιοκτήτη 17/11 (έλεγχος, εύρημα 4): μετά την υπογραφή του πελάτη
 * (`signed_at`) κλειδώνουν ο πάροχος, το πρόγραμμα, το είδος ενέργειας, ο
 * πελάτης της αίτησης και το ΑΦΜ του. Πριν, αλλαγή προγράμματος σε Ενεργή
 * άλλαζε και την προμήθεια (υπολογίζεται ζωντανά ως την εκκαθάριση), και τα
 * στοιχεία δεν ταίριαζαν πια με το υπογεγραμμένο PDF.
 *
 * Ο διαχειριστής (`manage_options`) αλλάζει κανονικά· η αλλαγή μένει στο
 * ιστορικό όπως πάντα (`ECRM_Audit`).
 *
 * Μόνο ΑΛΛΑΓΗ κλειδώνει: η φόρμα ξαναστέλνει όλα τα πεδία σε κάθε
 * αποθήκευση, και η ίδια τιμή δεν είναι αλλαγή. Κενό που γεμίζει (π.χ. ΑΦΜ που
 * έλειπε) δεν είναι αλλαγή υπογεγραμμένου στοιχείου.
 *
 * Laravel-ready: καθαρή κλάση, χωρίς WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class SignedFieldsLock
{
    /** Στήλη σύμβασης => όνομα στο μήνυμα. */
    public const CONTRACT_FIELDS = [
        'provider_id' => 'ο πάροχος',
        'program_id'  => 'το πρόγραμμα',
        'energy_type' => 'το είδος ενέργειας',
        'customer_id' => 'ο πελάτης',
    ];

    public const MESSAGE = 'Η αίτηση έχει υπογραφεί από τον πελάτη, οπότε %s δεν αλλάζει πια. '
        . 'Αν χρειάζεται αλλαγή, μίλα με τον διαχειριστή.';

    public const AFM_MESSAGE = 'Ο πελάτης έχει υπογεγραμμένη αίτηση, οπότε το ΑΦΜ του δεν αλλάζει πια. '
        . 'Αν χρειάζεται αλλαγή, μίλα με τον διαχειριστή.';

    private function __construct()
    {
    }

    /** @param array<string, mixed> $contract */
    public static function isSigned(array $contract): bool
    {
        return trim((string) ($contract['signed_at'] ?? '')) !== '';
    }

    /**
     * Η πρώτη κλειδωμένη αλλαγή, ή null.
     *
     * @param array<string, mixed> $existing Η σύμβαση όπως είναι τώρα.
     * @param array<string, mixed> $patch    Ό,τι θα γραφτεί (μόνο όσα στάλθηκαν).
     * @return array{field: string, error: string}|null
     */
    public static function refusal(array $existing, array $patch, string $afmBefore, ?string $afmAfter): ?array
    {
        if (! self::isSigned($existing)) {
            return null;
        }

        foreach (self::CONTRACT_FIELDS as $column => $label) {
            if (! array_key_exists($column, $patch)) {
                continue;
            }

            if (self::changed($existing[$column] ?? null, $patch[$column])) {
                return ['field' => $column, 'error' => sprintf(self::MESSAGE, $label)];
            }
        }

        if (self::afmChanged($afmBefore, $afmAfter)) {
            return ['field' => 'afm', 'error' => sprintf(self::MESSAGE, 'το ΑΦΜ')];
        }

        return null;
    }

    /**
     * ΑΦΜ που υπήρχε και αλλάζει σε άλλο (το κενό που γεμίζει δεν μετρά).
     * Και τα δύο έρχονται ήδη κανονικοποιημένα (`ECRM_Validate::digits()`).
     */
    public static function afmChanged(string $before, ?string $after): bool
    {
        $before = trim($before);

        return $after !== null && $before !== '' && trim($after) !== $before;
    }

    private static function changed(mixed $before, mixed $after): bool
    {
        $a = self::normal($before);
        $b = self::normal($after);

        // Κενό που γεμίζει δεν είναι αλλαγή υπογεγραμμένου στοιχείου.
        return $a !== '' && $a !== $b;
    }

    private static function normal(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = trim((string) $value);

        return $text === '0' ? '' : $text;
    }
}
