<?php

/**
 * (327) Η κλήση στην ΑΑΔΕ (RgWsPublic2). Το αίτημα και η ανάγνωση της
 * απάντησης είναι του `Domain\Customer\AadeRegistry`· εδώ μόνο το δίκτυο,
 * οι κωδικοί και ο μετρητής του μήνα.
 *
 * Κωδικοί: οι «ειδικοί κωδικοί» της ΑΑΔΕ (όχι TAXISnet), στο wp-config ως
 * `ECRM_AADE_USERNAME` / `ECRM_AADE_PASSWORD` -- ή κρυπτογραφημένοι στη βάση
 * μέσω SecretStore, όπως το κλειδί του Claude. Προαιρετικά
 * `ECRM_AADE_CALLED_BY`: ΑΦΜ για λογαριασμό του οποίου γίνεται η αναζήτηση,
 * όταν οι κωδικοί είναι ανθρώπου με εξουσιοδότηση από την εταιρεία.
 *
 * Μετρητής: η ΑΑΔΕ έχει μηνιαίο όριο αναζητήσεων ανά ΑΦΜ. Κρατάμε μόνο ΠΟΣΕΣ
 * έγιναν τον μήνα (για τη σελίδα «Υγεία») -- ποτέ ποιο ΑΦΜ ή τι απάντησε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Domain\Customer\AadeRegistry;
use WP_Error;

final class AadeClient
{
    private const COUNTER = 'ecrm_aade_calls';

    public function __construct(private readonly SecretStore $secrets)
    {
    }

    public function configured(): bool
    {
        return $this->secrets->get('aade_username') !== '' && $this->secrets->get('aade_password') !== '';
    }

    public function isPinned(): bool
    {
        return $this->secrets->isPinned('aade_username') && $this->secrets->isPinned('aade_password');
    }

    /**
     * @return array<string, mixed> ό,τι δίνει το AadeRegistry::parse(), ή
     *                              ['ok' => false, 'transport' => true, 'error' => …]
     *                              όταν δεν φτάσαμε καν στην ΑΑΔΕ
     */
    public function lookup(string $afm): array
    {
        $body = AadeRegistry::envelope(
            $this->secrets->get('aade_username'),
            $this->secrets->get('aade_password'),
            $afm,
            \ECRM_Validate::digits($this->secrets->get('aade_called_by')),
            wp_date('Y-m-d')
        );

        $response = wp_remote_post(AadeRegistry::ENDPOINT, [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/soap+xml; charset=UTF-8'],
            'body'    => $body,
        ]);

        $this->count();

        if ($response instanceof WP_Error) {
            return ['ok' => false, 'transport' => true, 'error' => 'Αποτυχία σύνδεσης στην ΑΑΔΕ.'];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $result = AadeRegistry::parse((string) wp_remote_retrieve_body($response));

        // 5xx χωρίς αναγνώσιμο σφάλμα: η υπηρεσία είναι κάτω, όχι το ΑΦΜ λάθος.
        if (! $result['ok'] && $result['error_code'] === '' && $status >= 500) {
            return ['ok' => false, 'transport' => true, 'error' => 'Η ΑΑΔΕ δεν απάντησε (HTTP ' . $status . ').'];
        }

        return $result;
    }

    public function callsThisMonth(): int
    {
        $c = get_option(self::COUNTER, []);

        return is_array($c) && ($c['month'] ?? '') === wp_date('Y-m') ? (int) ($c['n'] ?? 0) : 0;
    }

    private function count(): void
    {
        update_option(self::COUNTER, ['month' => wp_date('Y-m'), 'n' => $this->callsThisMonth() + 1], false);
    }
}
