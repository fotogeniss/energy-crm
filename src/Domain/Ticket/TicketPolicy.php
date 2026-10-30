<?php

/**
 * (339) Ποιος βλέπει, ποιος απαντά, ποιος αλλάζει κατάσταση.
 *
 * Πίνακας «Ποιος τι κάνει» του docs/UI-TICKETS.html:
 * - διαχειριστής: όλες, και μόνο αυτός αλλάζει κατάσταση·
 * - υπεύθυνος: τις δικές του και της ομάδας του·
 * - πωλητής, back office: μόνο τις δικές τους (το back office βλέπει όλες τις
 *   αιτήσεις, όχι όλες τις αναφορές -- οι αναφορές είναι άλλων ανθρώπων).
 * Όποιος βλέπει μια αναφορά, απαντά σε αυτήν.
 * Ο αναφέρων ξανανοίγει μια «Λύθηκε» μέσα σε 14 μέρες.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Ticket;

final class TicketPolicy
{
    public const REOPEN_DAYS = 14;

    public const RETENTION_MONTHS = 12;

    private function __construct()
    {
    }

    /**
     * Ποιανών οι αναφορές φαίνονται· null = όλων.
     *
     * @param list<int> $team Οι χρήστες της ομάδας του (για υπεύθυνο), αλλιώς [].
     * @return list<int>|null
     */
    public static function reporters(int $viewer, bool $isAdmin, bool $managesTeam, array $team): ?array
    {
        if ($isAdmin) {
            return null;
        }

        if (! $managesTeam) {
            return [$viewer];
        }

        return array_values(array_unique(array_merge([$viewer], array_map('intval', $team))));
    }

    /**
     * @param list<int>|null $reporters
     */
    public static function canSee(?array $reporters, int $reporter): bool
    {
        return $reporters === null || in_array($reporter, $reporters, true);
    }

    /**
     * @param int|null $daysClosed Μέρες από το κλείσιμο (μετρημένες στη βάση), null αν δεν έκλεισε.
     */
    public static function canReopen(TicketStatus $status, bool $isReporter, ?int $daysClosed): bool
    {
        return $isReporter
            && $status === TicketStatus::Resolved
            && $daysClosed !== null
            && $daysClosed <= self::REOPEN_DAYS;
    }
}
