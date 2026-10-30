<?php

/**
 * (336) Η ημερήσια εργασία που λέει στους διαχειριστές ποιες τιμές παλιώνουν.
 *
 * Κανόνας στο Domain\Pricing\StalePrices· εδώ μόνο cron, ποιοι είναι
 * διαχειριστές, και πότε στάλθηκε τελευταία (option). Τρέχει στο ίδιο
 * ημερήσιο hook με το digest (`ECRM_Notifications::CRON_HOOK`).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use ECRM_Notifications;
use EnergyCRM\Domain\Pricing\StalePrices;
use EnergyCRM\Persistence\NotificationRepository;
use EnergyCRM\Persistence\ProviderRepository;

final class StalePriceNotice
{
    public const TYPE = 'stale_prices';

    private const OPTION = 'ecrm_stale_price_notice';

    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly NotificationRepository $notifications,
    ) {
    }

    public function register(): void
    {
        add_action(ECRM_Notifications::CRON_HOOK, [$this, 'run']);
    }

    public function run(): void
    {
        $today = current_time('Y-m-d');
        $names = StalePrices::names($this->providers->activePrograms(), $today);
        $last  = get_option(self::OPTION, []);
        $last  = is_array($last) ? $last : [];

        if (
            ! StalePrices::shouldNotify(
                isset($last['date']) ? (string) $last['date'] : null,
                (int) ($last['count'] ?? 0),
                count($names),
                $today
            )
        ) {
            if ($names === []) {
                delete_option(self::OPTION);
            }

            return;
        }

        $message = StalePrices::message($names);

        foreach (get_users(['capability' => 'manage_options', 'fields' => 'ID']) as $userId) {
            $this->notifications->add((int) $userId, self::TYPE, $message['title'], $message['body']);
        }

        update_option(self::OPTION, ['date' => $today, 'count' => count($names)], false);
    }
}
