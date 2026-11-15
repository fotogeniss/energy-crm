<?php

/**
 * (394) Οι παραβιάσεις CSP που έστειλαν οι browsers, για τη σελίδα «Υγεία».
 *
 * Μετρώνται μαζί όσες είναι ίδιες (οδηγία, τι, πού, γραμμή)· κρατιούνται
 * έως `CspPolicy::MAX_KEPT`, και όταν γεμίσει φεύγει η παλαιότερη. Κανένα
 * προσωπικό στοιχείο: ούτε IP, ούτε χρήστης, ούτε query string.
 *
 * Laravel-ready: ένας πίνακας `csp_reports` με τα ίδια πεδία.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Domain\Security\CspPolicy;

final class CspReports
{
    public const OPTION = 'ecrm_csp_reports';

    /**
     * @param array{directive: string, blocked: string, page: string, line: int} $report
     */
    public function record(array $report): void
    {
        $all = $this->stored();
        $key = CspPolicy::key($report);
        $now = gmdate('Y-m-d H:i');

        if (isset($all[$key])) {
            $all[$key]['count'] = (int) $all[$key]['count'] + 1;
            $all[$key]['last']  = $now;
        } else {
            $all[$key] = $report + ['count' => 1, 'first' => $now, 'last' => $now];
        }

        uasort($all, static fn (array $a, array $b): int => strcmp((string) $b['last'], (string) $a['last']));

        update_option(self::OPTION, array_slice($all, 0, CspPolicy::MAX_KEPT, true), false);
    }

    /**
     * Πιο πρόσφατες πρώτα.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(): array
    {
        return array_values($this->stored());
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }

    /** @return array<string, array<string, mixed>> */
    private function stored(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? array_filter($stored, 'is_array') : [];
    }
}
