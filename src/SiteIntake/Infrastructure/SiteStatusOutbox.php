<?php

/**
 * (350) Οι ενημερώσεις κατάστασης προς το site: ουρά, αποστολή, επανάληψη.
 *
 * Ακούει τον κύκλο ζωής (STATUS_CHANGED), όπως ActivationTerm και
 * RejectionFollowUp, ώστε να ισχύει από κάθε πόρτα (κουμπί, μαζική αλλαγή,
 * εισαγωγή, cron). Προτεραιότητα 20: μετά το ActivationTerm (10), που γράφει
 * τη λήξη μιας σύμβασης που μόλις έγινε Ενεργή — αλλιώς θα στέλναμε χωρίς λήξη.
 *
 * Η αποστολή ΔΕΝ γίνεται μέσα στην αλλαγή κατάστασης: μπαίνει στην ουρά και
 * φεύγει από το WP-cron. Ένα site που αργεί δεν πρέπει να παγώνει τη μαζική
 * αλλαγή του back office.
 *
 * Ουρά = ένα option, μία γραμμή ανά αίτηση (`ref`). Νέα αλλαγή στην ίδια
 * αίτηση αντικαθιστά την παλιά: το site θέλει μόνο την τελευταία κατάσταση.
 *
 * Η διεύθυνση του site τη δίνει το ίδιο το site με κάθε αίτηση (`status_url`),
 * ή ορίζεται σταθερά `ECRM_SITE_STATUS_URL` στο wp-config. Μέχρι να υπάρξει,
 * οι ενημερώσεις περιμένουν στην ουρά.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\SiteIntake\Infrastructure;

use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Persistence\ContractTransitions;
use EnergyCRM\SiteIntake\Domain\SiteSignature;
use EnergyCRM\SiteIntake\Domain\SiteStatusUpdate;
use EnergyCRM\SiteIntake\Persistence\SiteLeadRepository;

final class SiteStatusOutbox
{
    public const OPTION = 'ecrm_site_status_outbox';

    public const HOOK = 'ecrm_site_status_flush';

    public function __construct(
        private readonly SiteIntakeConfig $config,
        private readonly SiteLeadRepository $leads,
        private readonly ContractTransitions $transitions,
    ) {
    }

    public function register(): void
    {
        add_action(ContractLifecycle::STATUS_CHANGED, [$this, 'onStatusChanged'], 20, 3);
        add_action(self::HOOK, [$this, 'flush']);
    }

    public function onStatusChanged(int $contractId, string $to, string $from = ''): void
    {
        if ($contractId <= 0) {
            return;
        }

        $ref = $this->leads->refForContract($contractId);
        if ($ref === null) {
            return;
        }

        $term   = $this->transitions->termOf($contractId);
        $update = SiteStatusUpdate::forSite(
            $ref,
            $to,
            $from,
            $term['start_date'] ?? null,
            $term['end_date'] ?? null,
            time()
        );

        if ($update === null) {
            return;
        }

        $queue               = $this->queue();
        $queue[$update->ref] = ['payload' => $update->toArray(), 'attempts' => 0, 'next' => 0];
        $this->save($queue);

        $this->schedule(time());
    }

    /** Από το WP-cron: ό,τι είναι η ώρα του φεύγει, ό,τι αποτύχει ξαναμπαίνει αργότερα. */
    public function flush(): void
    {
        $queue = $this->queue();
        if ($queue === []) {
            return;
        }

        $url    = $this->config->statusUrl();
        $secret = $this->config->secret();

        if ($url === '' || $secret === '') {
            // Δεν ξέρουμε ακόμα πού· η επόμενη αίτηση από το site θα το πει.
            $this->schedule(time() + HOUR_IN_SECONDS);

            return;
        }

        $now     = time();
        $handled = [];

        foreach ($queue as $ref => $item) {
            if ((int) $item['next'] > $now) {
                continue;
            }

            $handled[$ref] = $item['payload'];
            $code          = $this->post($url, $secret, (array) $item['payload']);

            if (($code >= 200 && $code < 300) || SiteStatusUpdate::isPermanent($code)) {
                unset($queue[$ref]);
                continue;
            }

            $attempts = (int) $item['attempts'] + 1;
            if ($attempts >= SiteStatusUpdate::MAX_ATTEMPTS) {
                unset($queue[$ref]);
                continue;
            }

            $queue[$ref] = [
                'payload'  => $item['payload'],
                'attempts' => $attempts,
                'next'     => $now + SiteStatusUpdate::retryDelay($attempts),
            ];
        }

        // Ξαναδιαβάζουμε πριν γράψουμε: όσο στέλναμε, μπορεί να μπήκε νέα αλλαγή
        // (άλλη αίτηση, ή νέα κατάσταση σε αίτηση που μόλις στείλαμε). Αυτή κρατιέται.
        foreach ($this->queue() as $ref => $item) {
            $isNew = ! array_key_exists($ref, $queue) && ! array_key_exists($ref, $handled);
            $changed = array_key_exists($ref, $handled) && $item['payload'] !== $handled[$ref];
            if ($isNew || $changed) {
                $queue[$ref] = $item;
            }
        }

        $this->save($queue);

        if ($queue !== []) {
            $this->schedule((int) min(array_column($queue, 'next')));
        }
    }

    /** @param array<string, mixed> $payload */
    private function post(string $url, string $secret, array $payload): int
    {
        $body = (string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    /** Ένα προγραμματισμένο τρέξιμο, το νωρίτερο που χρειάζεται. */
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

    /** @return array<string, array{payload: array<string, mixed>, attempts: int, next: int}> */
    private function queue(): array
    {
        $queue = get_option(self::OPTION, []);

        return is_array($queue) ? $queue : [];
    }

    /** @param array<string, array{payload: array<string, mixed>, attempts: int, next: int}> $queue */
    private function save(array $queue): void
    {
        update_option(self::OPTION, $queue, false);
    }
}
