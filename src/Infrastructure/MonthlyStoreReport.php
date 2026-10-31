<?php

/**
 * (345) Την 1η του μήνα: «Οκτώβριος σε μια ματιά» σε κάθε υπεύθυνο και στον
 * διαχειριστή, με email και ειδοποίηση στο καμπανάκι.
 *
 * Τρέχει στο ίδιο καθημερινό cron με το digest και σέβεται την ίδια ρύθμιση
 * (`notify_digest`). Στέλνει μία φορά ανά μήνα: κρατά στο option τον μήνα που
 * έστειλε, οπότε αν το cron χάσει την 1η, φεύγει την επόμενη μέρα που θα
 * τρέξει. Όποιος δεν είχε καμία κίνηση τον μήνα εκείνο δεν παίρνει τίποτα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Analytics\Month;
use EnergyCRM\Domain\Analytics\MonthlySummary;
use EnergyCRM\Persistence\NotificationRepository;
use EnergyCRM\Persistence\StoreRepository;

final class MonthlyStoreReport
{
    /** Ο τελευταίος μήνας που στάλθηκε, «YYYY-MM». */
    public const SENT_OPTION = 'ecrm_monthly_report_sent';

    public const TYPE_PREFIX = 'monthly:';

    public function __construct(
        private readonly StoreRepository $store,
        private readonly StoreMonthReport $reports,
        private readonly ScopeResolver $scopes,
        private readonly NotificationRepository $notifications,
    ) {
    }

    /** @return int Σε πόσους στάλθηκε. */
    public function run(): int
    {
        if (class_exists(\ECRM_Admin::class) && ! \ECRM_Admin::get('notify_digest', '1')) {
            return 0;
        }

        $current = $this->store->calendar()['month'];

        if (Month::parse($current) === null) {
            return 0;
        }

        $month = Month::previous($current);

        if (get_option(self::SENT_OPTION) === $month) {
            return 0;
        }

        // Πριν τον βρόχο: δύο cron που τρέχουν μαζί δεν στέλνουν διπλά.
        update_option(self::SENT_OPTION, $month, false);

        $company = class_exists(\ECRM_Admin::class)
            ? (string) \ECRM_Admin::get('company_name', get_bloginfo('name'))
            : (string) get_bloginfo('name');
        $sent    = 0;

        /** @var list<\WP_User> $users */
        $users = get_users(['capability' => Capability::MANAGE_TEAM]);

        foreach ($users as $user) {
            if (! user_can($user, Capability::USE_CRM)) {
                continue;
            }

            $report = $this->reports->build($this->scopes->forUser((int) $user->ID), $month);
            $tiles  = (array) $report['tiles'];

            if ((int) $tiles['applications'] + (int) $tiles['activated'] + (int) $tiles['lost'] === 0) {
                continue;
            }

            $lines   = MonthlySummary::lines($report);
            $subject = MonthlySummary::subject($report);

            $this->notifications->add((int) $user->ID, self::TYPE_PREFIX . $month, $subject, $lines[0]);

            if (is_email($user->user_email)) {
                wp_mail(
                    $user->user_email,
                    $subject . ' - ' . $company,
                    sprintf(
                        "Καλημέρα %s,\n\n%s\n\nΌλη η αναφορά στο CRM: Αρχική → Κατάστημα → %s.\n\n%s",
                        $user->display_name,
                        implode("\n", $lines),
                        Month::name($month),
                        $company
                    )
                );
            }

            $sent++;
        }

        return $sent;
    }
}
