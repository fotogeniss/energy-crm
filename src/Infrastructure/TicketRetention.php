<?php

/**
 * (339) Οι κλειστές αναφορές σβήνονται 12 μήνες μετά το κλείσιμο.
 *
 * Στο ίδιο ημερήσιο hook με το digest (`ECRM_Notifications::CRON_HOOK`), όπως
 * το StalePriceNotice. Η εικόνα έχει ήδη σβηστεί στο κλείσιμο· αν για
 * οποιονδήποτε λόγο έμεινε, σβήνεται κι αυτή εδώ.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use ECRM_Notifications;
use EnergyCRM\Domain\Ticket\TicketPolicy;
use EnergyCRM\Persistence\TicketRepository;

final class TicketRetention
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketAttachments $attachments,
    ) {
    }

    public function register(): void
    {
        add_action(ECRM_Notifications::CRON_HOOK, [$this, 'run']);
    }

    public function run(): void
    {
        foreach ($this->tickets->purgeClosedOlderThan(TicketPolicy::RETENTION_MONTHS) as $path) {
            $this->attachments->delete($path);
        }
    }
}
