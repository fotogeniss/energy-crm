<?php

/**
 * Τα γεγονότα του CRM προς το Gateway: ουρά, αποστολή, επανάληψη χωρίς όριο.
 *
 * Ίδιο σχήμα με το `SiteStatusOutbox`, με δύο διαφορές που έχουν λόγο:
 *
 *  - Δεν παραιτείται ποτέ (δες `GatewayEvent`): ένα χαμένο «μη καλέσεις» είναι
 *    παράνομη κλήση. Αν το Gateway δεν είναι ρυθμισμένο ή δεν απαντά, το
 *    γεγονός περιμένει, και η Υγεία του CRM μπορεί να δείξει πόσα περιμένουν.
 *  - Η ουρά κρατά αριθμούς τηλεφώνου, άρα φυλάσσεται κρυπτογραφημένη (FieldCipher)
 *    όπου υπάρχει. Ένα γεγονός «διαγράφηκε ο πελάτης» δεν πρέπει να αφήνει τον
 *    αριθμό του ανοιχτό στον πίνακα options.
 *
 * Δύο τρόποι να χαθεί ένα γεγονός, και πώς κλείνουν:
 *
 *  - Δύο αιτήματα γράφουν την ουρά μαζί (διάβασε-άλλαξε-γράψε στο ίδιο option):
 *    το ένα σβήνει το άλλο. Κάθε αλλαγή γίνεται κάτω από `GET_LOCK` της MySQL, με
 *    φρέσκια ανάγνωση (όχι από cache), και η αποστολή γίνεται ΕΚΤΟΣ lock, ώστε ένα
 *    αργό Gateway να μην κρατά τους πωλητές να περιμένουν.
 *  - Η ουρά δεν αποκρυπτογραφείται (άλλαξε το κλειδί): αντί να θεωρηθεί άδεια και
 *    να γραφτεί από πάνω, η τιμή φυλάσσεται ως έχει σε `OPTION_UNREADABLE`, για
 *    ανάκτηση με το παλιό κλειδί.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\GatewayIntake\Infrastructure;

use EnergyCRM\GatewayIntake\Domain\GatewayEvent;
use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Persistence\PersonalDataEraser;
use EnergyCRM\SiteIntake\Domain\SiteSignature;

final class GatewayEventOutbox
{
    public const OPTION = 'ecrm_gateway_event_outbox';

    public const HOOK = 'ecrm_gateway_event_flush';

    /** Ό,τι δεν διαβάστηκε (λάθος κλειδί), φυλαγμένο ως έχει. Δεν το σβήνει ποτέ ο κώδικας. */
    public const OPTION_UNREADABLE = 'ecrm_gateway_event_outbox_unreadable';

    private const LOCK = 'ecrm_gateway_event_outbox';

    public function __construct(
        private readonly GatewayIntakeConfig $config,
        private readonly FieldCipher $cipher,
    ) {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'flush']);
        add_action(PersonalDataEraser::LEAD_PHONES_ERASED, [$this, 'onLeadPhonesErased']);
    }

    /** Από τον PersonalDataEraser, με τα τηλέφωνα που μόλις σβήστηκαν από τους υποψηφίους. */
    public function onLeadPhonesErased(mixed $phones): void
    {
        foreach (is_array($phones) ? $phones : [] as $phone) {
            $this->enqueue(GatewayEvent::ERASED, is_scalar($phone) ? (string) $phone : '');
        }
    }

    /** Επιστρέφει false αν ο αριθμός δεν στέκει ώστε να φτάσει στο Gateway. */
    public function enqueue(string $type, string $rawPhone): bool
    {
        $event = GatewayEvent::forPhone($type, $rawPhone, time());
        if ($event === null) {
            return false;
        }

        $this->locked(function () use ($event): void {
            $queue                = $this->queue();
            $queue[$event->key()] = ['event' => $event->toArray(), 'attempts' => 0, 'next' => 0];
            $this->save($queue);
        });

        $this->schedule(time());

        return true;
    }

    /** Πόσα περιμένουν. Για την Υγεία του CRM: αν μεγαλώνει, κάτι δεν δουλεύει. */
    public function pending(): int
    {
        return count($this->queue());
    }

    public function flush(): void
    {
        $url    = $this->config->eventsUrl();
        $secret = $this->config->secret();
        $now    = time();

        // 1. Κάτω από lock: τι είναι ώριμο να σταλεί.
        $due = $this->locked(function () use ($now): array {
            $out = [];
            foreach ($this->queue() as $key => $item) {
                if ((int) $item['next'] <= $now) {
                    $out[$key] = (array) $item['event'];
                }
            }

            return $out;
        });

        if ($due === []) {
            $this->rescheduleFromQueue();

            return;
        }

        if ($url === '' || $secret === '') {
            $this->schedule($now + HOUR_IN_SECONDS);

            return;
        }

        // 2. Χωρίς lock: η αποστολή.
        $codes = [];
        foreach ($due as $key => $event) {
            $codes[$key] = $this->post($url, $secret, $event);
        }

        // 3. Κάτω από lock, με φρέσκια ουρά: ό,τι μπήκε στο μεταξύ μένει ανέγγιχτο.
        $this->locked(function () use ($due, $codes, $now): void {
            $queue = $this->queue();

            foreach ($codes as $key => $code) {
                if (! isset($queue[$key])) {
                    continue;
                }
                if (GatewayEvent::delivered($code) || GatewayEvent::isPermanent($code)) {
                    unset($queue[$key]);
                    continue;
                }
                // Ξαναμπήκε στο μεταξύ (νέο enqueue): κρατά τη δική του μέτρηση.
                if ($queue[$key]['event'] !== $due[$key] || (int) $queue[$key]['next'] > $now) {
                    continue;
                }
                $attempts      = (int) $queue[$key]['attempts'] + 1;
                $queue[$key]['attempts'] = $attempts;
                $queue[$key]['next']     = $now + GatewayEvent::retryDelay($attempts);
            }

            $this->save($queue);
        });

        $this->rescheduleFromQueue();
    }

    private function rescheduleFromQueue(): void
    {
        $queue = $this->queue();
        if ($queue !== []) {
            $this->schedule((int) min(array_column($queue, 'next')));
        }
    }

    /**
     * Κρατά το lock της ουράς όσο τρέχει το $fn. Αν δεν το πάρει σε 10″, προχωρά
     * χωρίς αυτό: καλύτερα ένα σπάνιο διπλό γράψιμο παρά ένα «μη καλέσεις» που δεν γράφτηκε.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function locked(callable $fn): mixed
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK, όχι ανάγνωση πίνακα (όπως στο ExtractionGate).
        $taken = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', self::LOCK)) === 1;

        try {
            return $fn();
        } finally {
            if ($taken) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK));
            }
        }
    }

    /** @param array<string, mixed> $event */
    private function post(string $url, string $secret, array $event): int
    {
        $body = (string) wp_json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $time = time();

        $response = wp_remote_post($url, [
            'timeout' => 8,
            'headers' => [
                'Content-Type'     => 'application/json',
                'Accept'           => 'application/json',
                'X-ECRM-Timestamp' => (string) $time,
                'X-ECRM-Signature' => SiteSignature::sign($secret, $time, $body),
            ],
            'body'    => $body,
        ]);

        return is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    }

    private function schedule(int $at): void
    {
        $existing = wp_next_scheduled(self::HOOK);

        if ($existing !== false && $existing <= $at) {
            return;
        }

        if ($existing !== false) {
            wp_unschedule_event($existing, self::HOOK);
        }

        wp_schedule_single_event(max(time(), $at), self::HOOK);
    }

    /** @return array<string, array{event: array<string, mixed>, attempts: int, next: int}> */
    private function queue(): array
    {
        wp_cache_delete(self::OPTION, 'options'); // φρέσκια τιμή, όχι από object cache
        $stored = (string) get_option(self::OPTION, '');
        if ($stored === '') {
            return [];
        }

        $json    = FieldCipher::isEncrypted($stored) ? $this->cipher->decrypt($stored) : $stored;
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            self::keepUnreadable($stored);

            return [];
        }

        return $decoded;
    }

    /** @param array<string, array{event: array<string, mixed>, attempts: int, next: int}> $queue */
    private function save(array $queue): void
    {
        $json = (string) wp_json_encode($queue);

        if ($queue === []) {
            $value = '';
        } else {
            $value = FieldCipher::isAvailable() ? $this->cipher->encrypt($json) : $json;
        }

        update_option(self::OPTION, $value, false);
    }

    /** Η ουρά δεν διαβάζεται: φυλάγεται ως έχει (μία φορά ανά τιμή) και γράφεται στο log. */
    private static function keepUnreadable(string $stored): void
    {
        $kept = get_option(self::OPTION_UNREADABLE, []);
        $kept = is_array($kept) ? $kept : [];

        if (! in_array($stored, $kept, true)) {
            $kept[] = $stored;
            update_option(self::OPTION_UNREADABLE, $kept, false);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- κρίσιμο: εκκρεμή «μη καλέσεις» που δεν διαβάζονται.
            error_log('energy-crm: η ουρά προς το Gateway δεν διαβάζεται· φυλάχτηκε στο ' . self::OPTION_UNREADABLE);
        }
    }
}
