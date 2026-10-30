<?php

/**
 * When a contract's term ends.
 *
 * The agent types a start date and a duration; the end date follows from them
 * unless it was entered by hand. Getting this wrong moves a contract in and out
 * of the renewals list, which is how commission on a renewal gets missed.
 *
 * Pure date arithmetic, no WordPress, tested.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class ContractTerm
{
    private function __construct()
    {
    }

    /**
     * @param string $start  Y-m-d, or empty when unknown.
     * @param int    $months Term length; zero when open-ended.
     * @param string $end    An explicit end date, which always wins.
     */
    public static function endDate(string $start, int $months, string $end = ''): ?string
    {
        $end = trim($end);

        if ($end !== '') {
            return $end;
        }

        $start = trim($start);

        if ($start === '' || $months <= 0) {
            return null;
        }

        // One clock, start to finish.
        //
        // This used to read the start with strtotime() — local time — and write
        // the result with gmdate() — UTC. On a server east of Greenwich, which
        // is every Greek host, midnight local is the previous day in UTC, so
        // every end date printed on a contract was a day early. It passed for
        // a long time because the machine it was written on ran UTC.
        try {
            $from = new DateTimeImmutable($start, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        // Calendar months, not 30-day blocks: a 12-month contract signed on the
        // 3rd ends on the 3rd, whatever the month lengths in between. A 31st
        // rolls forward the way PHP rolls it — February has no 31st.
        return $from->modify('+' . $months . ' months')->format('Y-m-d');
    }

    /** (330) Η διάρκεια όταν η αίτηση δεν γράφει: 12 μήνες σε όλα τα τιμολόγια ρεύματος/αερίου. */
    public const DEFAULT_ENERGY_MONTHS = 12;

    /**
     * (330) Τι γράφεται όταν μια αίτηση ρεύματος/αερίου γίνεται «Ενεργή».
     *
     * Η υπενθύμιση ανανέωσης (`ECRM_Tasks::generate_renewal_reminders()`) και η
     * λίστα «Ανανεώσεις» διαβάζουν μόνο `end_date`, και αυτό υπολογιζόταν μόνο
     * αν ο πωλητής έγραφε έναρξη και διάρκεια στη φόρμα -- που στη φόρμα δεν
     * τα ξέρει ακόμα: η έναρξη είναι η μέρα που ενεργοποιεί ο πάροχος. Μια
     * σύμβαση χωρίς λήξη δεν εμφανιζόταν ποτέ για ανανέωση.
     *
     * Ό,τι έγραψε ο άνθρωπος μένει: λήξη γραμμένη → τίποτα· έναρξη γραμμένη →
     * κρατιέται· διάρκεια γραμμένη → κρατιέται. Λείπει έναρξη → σήμερα. Λείπει
     * διάρκεια → 12.
     *
     * @return array{start_date: string, term_months: int, end_date: string}|null null = τίποτα να γραφτεί.
     */
    public static function onActivation(?string $start, ?int $months, ?string $end, string $today): ?array
    {
        if (trim((string) $end) !== '') {
            return null;
        }

        $start  = trim((string) $start) !== '' ? substr(trim((string) $start), 0, 10) : $today;
        $months = $months !== null && $months > 0 ? $months : self::DEFAULT_ENERGY_MONTHS;
        $until  = self::endDate($start, $months);

        if ($until === null) {
            return null;
        }

        return ['start_date' => $start, 'term_months' => $months, 'end_date' => $until];
    }
}
