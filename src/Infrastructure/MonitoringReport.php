<?php

/**
 * Τα νούμερα πίσω από τη σελίδα «Παρακολούθηση».
 *
 * Η Υγεία απαντά «τι δεν πάει καλά αυτή τη στιγμή». Εδώ απαντιέται η άλλη
 * ερώτηση, που καμία δομή του plugin δεν μπορούσε να απαντήσει μέχρι την
 * (237): «σε σχέση με πριν;». Μια πράσινη Υγεία μπορεί να κρύβει εργασία που
 * αστοχεί μία στις τρεις φορές ή σφάλματα που τριπλασιάστηκαν από τότε που
 * άλλαξε κάτι -- και τα δύο φαίνονται μόνο σε σειρά ημερών.
 *
 * Χωρισμένη από τη σελίδα για τον ίδιο λόγο που το HealthChecks είναι χωριστό
 * από το HealthPage: τα νούμερα ελέγχονται χωρίς να τυπωθεί HTML.
 *
 * ## Δύο μισά, δύο πηγές
 *
 * Το πάνω μισό (σύστημα) διαβάζει τον πίνακα `ecrm_metrics` -- ιστορικό που
 * δεν υπάρχει αλλού. Το κάτω μισό (πωλήσεις) διαβάζει ζωντανά τις συμβάσεις,
 * που έχουν ήδη `created_at`: καμία δεύτερη αποθήκευση για δεδομένα που
 * υπάρχουν.
 *
 * ## Η σκοπιά
 *
 * Η σελίδα είναι `manage_options`, άρα το scope που φτάνει εδώ είναι πάντα
 * διαχειριστή -- δηλαδή ΟΛΟ το site. Αυτή ακριβώς είναι η διαφορά από την
 * οθόνη «Στατιστικά», που δείχνει «δικά μου / της ομάδας μου». Ιδια
 * repositories, άλλη σκοπιά· κανένα διπλό ερώτημα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use ECRM_Notifications;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Analytics\Funnel;
use EnergyCRM\Persistence\AnalyticsRepository;
use EnergyCRM\Persistence\MetricsRepository;

final class MonitoringReport
{
    /** Οι περίοδοι που μπορεί να ζητήσει η σελίδα. Κλειστό σύνολο. */
    public const RANGES = [7, 30, 90];

    public const DEFAULT_RANGE = 30;

    public function __construct(
        private readonly MetricsRepository $metrics,
        private readonly AnalyticsRepository $analytics,
        private readonly ScopeResolver $scopes,
    ) {
    }

    /**
     * @return array{
     *     days: int, today: string, axis: list<string>,
     *     errors: array<string, mixed>, cron: array<string, mixed>,
     *     backup: array<string, mixed>, sales: array<string, mixed>
     * }
     */
    public function all(int $days): array
    {
        $days  = in_array($days, self::RANGES, true) ? $days : self::DEFAULT_RANGE;
        $today = $this->metrics->today();
        $axis  = self::axis($today, $days);

        return [
            'days'   => $days,
            'today'  => $today,
            'axis'   => $axis,
            'errors' => $this->errors($axis, $days),
            'cron'   => $this->cron($axis, $days),
            'backup' => $this->backup($axis, $days),
            'sales'  => $this->sales($days),
        ];
    }

    /**
     * Οι ημέρες του άξονα, παλαιότερη πρώτη.
     *
     * Χτίζεται από τη σημερινή ημερομηνία ΤΗΣ ΒΑΣΗΣ και όχι της PHP: οι
     * αποθηκευμένες ημέρες γράφτηκαν με `CURDATE()`, και άξονας με άλλο ρολόι
     * θα κόλλαγε τα δεδομένα σε λάθος στήλη κατά μία μέρα.
     *
     * @return list<string>
     */
    private static function axis(string $today, int $days): array
    {
        $anchor = strtotime($today . ' 12:00:00');

        if ($anchor === false) {
            return [];
        }

        $axis = [];

        for ($back = $days - 1; $back >= 0; $back--) {
            $axis[] = gmdate('Y-m-d', $anchor - ($back * DAY_IN_SECONDS));
        }

        return $axis;
    }

    /**
     * @param  list<string> $axis
     * @return array<string, mixed>
     */
    private function errors(array $axis, int $days): array
    {
        // Διπλάσιο παράθυρο: η προηγούμενη περίοδος είναι το μόνο μέτρο
        // σύγκρισης που έχει νόημα. «14 σφάλματα» δεν λέει τίποτα· «14 από 23»
        // λέει ότι κάτι διορθώθηκε.
        $series   = self::byDay($this->metrics->series([Metrics::ERRORS], $days * 2));
        $current  = self::fill($axis, $series);
        $previous = 0;

        foreach ($series as $day => $count) {
            if (! in_array($day, $axis, true)) {
                $previous += $count;
            }
        }

        return [
            'series'   => $current,
            'total'    => array_sum($current),
            'previous' => $previous,
            'quiet'    => self::quietDays($current),
        ];
    }

    /**
     * @param  list<string> $axis
     * @return array<string, mixed>
     */
    private function cron(array $axis, int $days): array
    {
        $labels = [
            Retention::HOOK                  => 'Διαγραφή παλιών δεδομένων εξαγωγής',
            DocumentProtection::HOOK         => 'Μεταφορά ανασφάλιστων εγγράφων',
            PiiBackfill::HOOK                => 'Κρυπτογράφηση παλιών γραμμών',
            ECRM_Notifications::CRON_HOOK    => 'Ημερήσιες υπενθυμίσεις',
        ];

        $names  = array_map([Metrics::class, 'cron'], array_keys($labels));
        $rows   = $this->metrics->series($names, $days);
        $known  = self::firstDay($rows);
        $jobs   = [];
        $ran    = 0;
        $missed = 0;

        foreach ($labels as $hook => $label) {
            $counts = self::fill($axis, self::byDay($rows, Metrics::cron($hook)));
            $cells  = [];

            foreach ($counts as $day => $count) {
                // Πριν από την πρώτη καταγεγραμμένη ημέρα δεν ξέρουμε -- και
                // «δεν ξέρω» δεν πρέπει να βάφεται κόκκινο: θα έδειχνε βλάβη
                // εκεί που απλώς δεν υπήρχε ακόμη μετρητής.
                $state = $known !== null && $day >= $known ? $count > 0 : null;

                $cells[] = ['day' => $day, 'runs' => $count, 'ok' => $state];

                if ($state === true) {
                    $ran++;
                } elseif ($state === false) {
                    $missed++;
                }
            }

            $jobs[] = ['label' => $label, 'cells' => $cells];
        }

        $total = $ran + $missed;

        return [
            'jobs'   => $jobs,
            'ran'    => $ran,
            'missed' => $missed,
            'rate'   => $total > 0 ? round(100 * $ran / $total, 1) : null,
        ];
    }

    /**
     * @param  list<string> $axis
     * @return array<string, mixed>
     */
    private function backup(array $axis, int $days): array
    {
        $counts  = self::fill($axis, self::byDay($this->metrics->series([Metrics::BACKUP], $days)));
        $covered = 0;
        $gap     = 0;
        $worst   = 0;

        foreach ($counts as $count) {
            if ($count > 0) {
                $covered++;
                $gap = 0;
                continue;
            }

            $gap++;
            $worst = max($worst, $gap);
        }

        return [
            'days'    => $counts,
            'covered' => $covered,
            'total'   => count($counts),
            'worst'   => $worst,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sales(int $days): array
    {
        $scope  = $this->scopes->forCurrentUser();
        $funnel = Funnel::from($this->analytics->statusCountsSince($scope, $days));

        return [
            'daily'     => $this->analytics->dailyCreated($scope, $days),
            'total'     => $funnel['total'],
            'won'       => $funnel['won'],
            'conv_rate' => $funnel['conv_rate'],
            'canc_rate' => $funnel['canc_rate'],
            'avg_days'  => $this->analytics->averageDaysToActivation($scope, $days),
            'providers' => $this->analytics->topProviders($scope, 6, $days),
            'idle'      => $this->analytics->partnersIdle($scope, $days),
        ];
    }

    /**
     * Σειρές του repository σε «ημέρα => αριθμός», προαιρετικά για έναν μετρητή.
     *
     * @param  list<array{day: string, metric: string, value: int}> $rows
     * @return array<string, int>
     */
    private static function byDay(array $rows, ?string $metric = null): array
    {
        $out = [];

        foreach ($rows as $row) {
            if ($metric !== null && $row['metric'] !== $metric) {
                continue;
            }

            $out[$row['day']] = ($out[$row['day']] ?? 0) + $row['value'];
        }

        return $out;
    }

    /**
     * Κάθε ημέρα του άξονα, με μηδέν όπου δεν υπάρχει γραμμή.
     *
     * Το repository επιστρέφει μόνο ό,τι γράφτηκε· η απόφαση τι σημαίνει η
     * απουσία (μηδέν σφάλματα ή καμία εκτέλεση) ανήκει εδώ, γιατί είναι
     * αντίθετη ανά μετρητή.
     *
     * @param  list<string>        $axis
     * @param  array<string, int>  $values
     * @return array<string, int>
     */
    private static function fill(array $axis, array $values): array
    {
        $out = [];

        foreach ($axis as $day) {
            $out[$day] = $values[$day] ?? 0;
        }

        return $out;
    }

    /**
     * Η πρώτη ημέρα με οποιαδήποτε καταγραφή -- το σύνορο ανάμεσα σε «δεν
     * έτρεξε» και «δεν μετρούσαμε ακόμη».
     *
     * @param list<array{day: string, metric: string, value: int}> $rows
     */
    private static function firstDay(array $rows): ?string
    {
        $days = array_column($rows, 'day');

        return $days === [] ? null : (string) min($days);
    }

    /**
     * Πόσες συνεχόμενες ημέρες μέχρι σήμερα δεν κατέγραψαν τίποτα.
     *
     * @param array<string, int> $series
     */
    private static function quietDays(array $series): int
    {
        $quiet = 0;

        foreach (array_reverse($series) as $count) {
            if ($count > 0) {
                break;
            }

            $quiet++;
        }

        return $quiet;
    }
}
