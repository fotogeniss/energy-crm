<?php

/**
 * (422) Ο ένας κανόνας σύγκρισης για το τηλέφωνο (K11).
 *
 * Το `phone_hash` ήταν hash της τιμής όπως γράφτηκε: «697 123 4567»,
 * «6971234567» και «+30 6971234567» έδιναν τρία διαφορετικά hash, και η
 * αναζήτηση με κρυπτογραφημένο τηλέφωνο έβρισκε μόνο αν γράφατε τον αριθμό
 * ακριβώς όπως είχε καταχωρηθεί.
 *
 * Μόνο για τη σύγκριση. Η τιμή που αποθηκεύεται και τυπώνεται μένει όπως τη
 * γράφει ο συνεργάτης (K7). Η αποστολή SMS/Viber έχει δικό της κανόνα με
 * κωδικό χώρας (`ECRM_Messaging::normalize_phone()`): άλλη δουλειά.
 *
 * Χωρίς WordPress, tested.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Customer;

final class PhoneNumber
{
    /** Ό,τι γράφεται ανάμεσα στα ψηφία ενός τηλεφώνου. */
    private const NOISE = [' ', '-', '.', '(', ')', '/'];

    private function __construct()
    {
    }

    /**
     * Τα ψηφία του αριθμού, χωρίς τον κωδικό της Ελλάδας.
     *
     * «0030…» και «+30…» με 10 ψηφία μετά γίνονται τα 10 ψηφία. Ξένος αριθμός
     * μένει με τον κωδικό του. Χωρίς ψηφία: κενό.
     */
    public static function key(string $typed): string
    {
        $digits = (string) preg_replace('/\D+/', '', $typed);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '30')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    /**
     * Η ίδια πράξη πάνω σε στήλη απλού κειμένου (κινητό, lead), για
     * `… LIKE %s` με το `like()`: χωρίς κενά, παύλες, τελείες, παρενθέσεις.
     * Το «+30» μένει στη στήλη· το `key()` το βγάζει από τον όρο, οπότε το
     * LIKE ταιριάζει ούτως ή άλλως.
     */
    public static function sql(string $column): string
    {
        $expr = 'COALESCE(' . $column . ", '')";

        foreach (self::NOISE as $char) {
            $expr = "REPLACE({$expr}, '{$char}', '')";
        }

        return $expr;
    }

    /**
     * Ο όρος για το `sql()`, χωρίς τα `%` και χωρίς escape (το κάνει ο
     * καλών με `esc_like()`).
     *
     * Μόνο όρος που μοιάζει με τηλέφωνο (ψηφία, «+» και τα διαχωριστικά)
     * γίνεται ψηφία. Ένα «ΕΝ-12» μένει ως έχει: αλλιώς θα έψαχνε «12» σε κάθε
     * κινητό και θα έφερνε τις μισές αιτήσεις.
     */
    public static function likeTerm(string $typed): string
    {
        if (preg_match('#^[0-9+ .()/-]+$#', $typed) !== 1) {
            return $typed;
        }

        $key = self::key($typed);

        return $key !== '' ? $key : $typed;
    }
}
