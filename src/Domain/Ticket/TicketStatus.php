<?php

/**
 * (339) Οι τέσσερις καταστάσεις μιας αναφοράς προβλήματος.
 *
 * Mockup docs/UI-TICKETS.html (εγκρίθηκε 30/10): Νέο / Σε εξέλιξη / Λύθηκε /
 * Δεν είναι πρόβλημα. Οι δύο τελευταίες είναι «κλειστές»: εκεί σβήνεται η
 * εικόνα (GDPR) και από εκεί μετράει η διατήρηση των 12 μηνών.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Ticket;

enum TicketStatus: string
{
    case New        = 'new';
    case InProgress = 'in_progress';
    case Resolved   = 'resolved';
    case NotAnIssue = 'not_issue';

    public function label(): string
    {
        return match ($this) {
            self::New        => 'Νέο',
            self::InProgress => 'Σε εξέλιξη',
            self::Resolved   => 'Λύθηκε',
            self::NotAnIssue => 'Δεν είναι πρόβλημα',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Resolved || $this === self::NotAnIssue;
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::New->value, self::InProgress->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
