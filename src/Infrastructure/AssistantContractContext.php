<?php

/**
 * Οι αιτήσεις του χρήστη, ως κείμενο για το prompt του AlfrAId.
 *
 * Ο ιδιοκτήτης ζήτησε (05/10/2026) ο AlfrAId να διαβάζει τις αιτήσεις, με
 * τρεις όρους:
 *
 * - **Χωρίς προσωπικά στοιχεία.** Το ερώτημα δεν διαβάζει πελάτες
 *   (`ContractQueries::forAssistant()`) και το κείμενο το γράφει μόνο το
 *   `ContractBrief`, με λίστα επιτρεπτών πεδίων.
 * - **Όσες βλέπει ο χρήστης.** Ίδιο scope με τη λίστα συμβάσεων: ο πωλητής
 *   τις δικές του, ο υπεύθυνος και της ομάδας του.
 * - **Με κωδικό ή «οι δικές μου».** Αν η ερώτηση γράφει κωδικό, διαβάζονται
 *   αυτές οι αιτήσεις. Πάντα μπαίνουν και τα σύνολα ανά κατάσταση και οι
 *   ανοιχτές που κάθονται περισσότερο.
 *
 * Το ιστορικό δεν περιέχει το κείμενο των γεγονότων (`message`): εκεί
 * γράφεται π.χ. η IP του πελάτη που υπέγραψε. Μόνο από → προς και ημερομηνία.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use ECRM_Docs;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Assistant\ContractBrief;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Domain\Contract\StatusTrack;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\EventRepository;

final class AssistantContractContext
{
    /** Πόσες ανοιχτές αιτήσεις μπαίνουν όταν η ερώτηση δεν γράφει κωδικό. */
    private const OPEN_LIMIT = 15;

    /** Πόσες αλλαγές κατάστασης ανά αίτηση. */
    private const HISTORY_LIMIT = 5;

    private const KINDS = [
        StatusTrack::POWER  => 'Ρεύμα',
        StatusTrack::GAS    => 'Αέριο',
        StatusTrack::MOBILE => 'Κινητή',
    ];

    public function __construct(
        private readonly ContractQueries $queries,
        private readonly EventRepository $events,
    ) {
    }

    public function forMessage(UserScope $scope, string $message): string
    {
        $codes = ContractBrief::codesIn($message);
        $out   = [];

        if ($codes !== []) {
            $wanted = $this->withPaddedVariants($codes);
            $rows   = $this->queries->forAssistant($scope, $wanted, ContractBrief::MAX_CODES * 2);
            $found = array_map(static fn (array $r): string => (string) $r['code'], $rows);
            $out[] = 'Αιτήσεις που ρώτησε ο χρήστης:';

            foreach ($rows as $row) {
                $out[] = ContractBrief::line($this->brief($row, true));
            }

            foreach ($codes as $code) {
                if (! $this->matchesAny($code, $found)) {
                    $out[] = '- ' . $code . ': δεν υπάρχει στις αιτήσεις που βλέπει ο χρήστης.';
                }
            }

            $out[] = '';
        }

        $counts = $this->queries->countsByStatus($scope);
        $labels = ContractStatus::labels();
        $totals = [];

        foreach ($counts as $slug => $n) {
            $totals[] = ($labels[$slug] ?? $slug) . ': ' . $n;
        }

        $out[] = 'Σύνολα ανά κατάσταση: ' . ($totals === [] ? 'καμία αίτηση' : implode(', ', $totals)) . '.';

        $open = $this->queries->forAssistant($scope, [], self::OPEN_LIMIT);

        if ($open !== []) {
            $out[] = 'Ανοιχτές αιτήσεις, όσο περισσότερο ακίνητη τόσο πιο πάνω (το πολύ ' . self::OPEN_LIMIT . '):';

            foreach ($open as $row) {
                $out[] = ContractBrief::line($this->brief($row, false));
            }
        }

        return implode("\n", $out);
    }

    /**
     * Ο κωδικός έχει τουλάχιστον τέσσερα ψηφία (`ContractCode`): το
     * «ORIZON-12» είναι αποθηκευμένο ως «ORIZON-0012», και το «ORIZON-0012»
     * μπορεί να γραφτεί και «ORIZON-12».
     *
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private function withPaddedVariants(array $codes): array
    {
        $out = [];

        foreach ($codes as $code) {
            [$prefix, $number] = explode('-', $code, 2);

            $out[] = $code;
            $out[] = $prefix . '-' . str_pad(ltrim($number, '0'), 4, '0', STR_PAD_LEFT);
        }

        return array_values(array_unique($out));
    }

    /** @param list<string> $found */
    private function matchesAny(string $code, array $found): bool
    {
        [$prefix, $number] = explode('-', $code, 2);

        foreach ($found as $f) {
            [$fp, $fn] = array_pad(explode('-', $f, 2), 2, '');

            if ($fp === $prefix && ltrim($fn, '0') === ltrim($number, '0')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function brief(array $row, bool $detailed): array
    {
        $status = ContractStatus::tryFromSlug((string) $row['status']);
        $track  = StatusTrack::of($row);

        $brief = [
            'code'      => (string) $row['code'],
            'kind'      => self::KINDS[$track],
            'provider'  => (string) ($row['provider_name'] ?? ''),
            'program'   => (string) ($row['program_name'] ?? ''),
            'status'    => $status !== null ? $status->label() : (string) $row['status'],
            'idle_days' => $row['idle_days'],
            'signed'    => (bool) (int) $row['signed'],
        ];

        if (! $detailed) {
            return $brief;
        }

        $created = strtotime((string) $row['created_at']);

        $brief['created'] = $created !== false ? gmdate('d/m/Y', $created) : '';
        $brief['missing'] = class_exists(ECRM_Docs::class)
            ? ECRM_Docs::missing_labels(
                (int) $row['id'],
                (string) $row['activation_type'],
                (string) $row['energy_type']
            )
            : [];
        $brief['history'] = $this->history((int) $row['id']);

        return $brief;
    }

    /** @return list<string> */
    private function history(int $contractId): array
    {
        $labels = ContractStatus::labels();
        $out    = [];

        foreach ($this->events->forContract($contractId) as $event) {
            if (($event['type'] ?? '') !== 'status_change') {
                continue;
            }

            $when  = strtotime((string) $event['created_at']);
            $from  = (string) ($event['from_status'] ?? '');
            $to    = (string) ($event['to_status'] ?? '');
            $out[] = ($when !== false ? gmdate('d/m', $when) . ' ' : '')
                . ($labels[$from] ?? ($from !== '' ? $from : '—')) . ' → ' . ($labels[$to] ?? $to);

            if (count($out) >= self::HISTORY_LIMIT) {
                break;
            }
        }

        return array_reverse($out);
    }
}
