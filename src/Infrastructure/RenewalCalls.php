<?php

/**
 * (344) Η καθημερινή «Κλήση ανανέωσης»: εργασία στον πωλητή έναν μήνα
 * (ρυθμίσιμο) πριν λήξει μια σύμβαση, με τον πελάτη, το τρέχον πρόγραμμα και
 * τη φθηνότερη πρόταση -- ο ίδιος υπολογισμός με τις «Λήξεις».
 *
 * Αντικαθιστά το σώμα της ECRM_Tasks::generate_renewal_reminders(), που
 * τρέχει πάντα από το ίδιο καθημερινό cron.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\RenewalCall;
use EnergyCRM\Persistence\RenewalCallRepository;

final class RenewalCalls
{
    public function __construct(
        private readonly RenewalCallRepository $calls,
        private readonly RenewalOffers $offers,
    ) {
    }

    /** @return int Πόσες εργασίες φτιάχτηκαν. */
    public function run(int $days): int
    {
        $made = 0;

        foreach ($this->calls->due($days) as $row) {
            $owner = (int) $row['partner_user_id'];

            // Τα προγράμματα που βλέπει ο ίδιος ο πωλητής: δεν του προτείνουμε
            // πάροχο που δεν πουλά.
            [$row] = $this->offers->attach([$row], UserScope::forSelf($owner));

            $customer = (string) ($row['company_name'] ?? '')
                ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $endDate  = (string) $row['end_date'];
            $offer    = is_array($row['offer'] ?? null) ? $row['offer'] : null;

            $this->calls->create(
                (int) $row['id'],
                ! empty($row['customer_id']) ? (int) $row['customer_id'] : null,
                $owner,
                $endDate,
                RenewalCall::title($customer, (string) $row['code'], $endDate),
                RenewalCall::note((string) ($row['program_name'] ?? ''), $offer),
                RenewalCall::priority((int) $row['days_left'])
            );
            $made++;
        }

        return $made;
    }
}
