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

        $from = strtotime($start);

        if ($from === false) {
            return null;
        }

        // Calendar months, not 30-day blocks: a 12-month contract signed on the
        // 3rd ends on the 3rd, whatever the month lengths in between.
        $to = strtotime('+' . $months . ' months', $from);

        return $to === false ? null : gmdate('Y-m-d', $to);
    }
}
