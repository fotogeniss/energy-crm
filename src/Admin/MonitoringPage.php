<?php

/**
 * Energy CRM → Παρακολούθηση. Τι αλλάζει με τον χρόνο.
 *
 * Αδελφή σελίδα της Υγείας, με σκόπιμα διαφορετική ερώτηση: η Υγεία λέει «τι
 * δεν πάει καλά αυτή τη στιγμή», εδώ φαίνεται «πάει καλύτερα ή χειρότερα από
 * την περασμένη εβδομάδα». Μια πράσινη Υγεία μπορεί να κρύβει εργασία που
 * αστοχεί μία στις τρεις φορές -- το `wp_next_scheduled()` δείχνει πάντα
 * χθεσινή ώρα και ο έλεγχος μένει πράσινος.
 *
 * ## Γιατί wp-admin και όχι μέσα στο κέλυφος
 *
 * Το πάνω μισό είναι εσωτερικά του συστήματος (σφάλματα, cron, αντίγραφα) και
 * δεν έχει λόγο να φαίνεται σε συνεργάτη· το κάτω μισό είναι ΟΛΟ το site,
 * δηλαδή ούτως ή άλλως όψη ιδιοκτήτη. `manage_options`, ίδιο κέλυφος `.ecrm`
 * με την Υγεία, ίδιος λόγος (CHANGELOG (137)).
 *
 * ## Καμία διπλή υλοποίηση με τα «Στατιστικά»
 *
 * Τα ίδια repositories, άλλη σκοπιά: εκείνη η οθόνη φιλτράρει «δικά μου / της
 * ομάδας μου», εδώ ο διαχειριστής βλέπει το σύνολο. Το `MonitoringReport`
 * κρατά όλη την αριθμητική, ώστε εδώ να μένει μόνο η εκτύπωση -- ίδιος
 * χωρισμός με HealthChecks/HealthPage.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Admin;

use EnergyCRM\Infrastructure\MonitoringReport;
use EnergyCRM\Infrastructure\ThemePreference;
use EnergyCRM\Plugin;

final class MonitoringPage
{
    private const PAGE = 'energy-crm-monitoring';

    /** @var string Το hook suffix της add_submenu_page(), για το admin_enqueue_scripts. */
    private string $hook = '';

    public function __construct(private readonly MonitoringReport $report)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueSkin']);
    }

    public function addPage(): void
    {
        $hook = add_submenu_page(
            'energy-crm',
            'Παρακολούθηση',
            'Παρακολούθηση',
            'manage_options',
            self::PAGE,
            [$this, 'render']
        );

        $this->hook = $hook !== false ? $hook : '';
    }

    /** Τα ίδια τρία CSS με την Υγεία, μόνο σε αυτή τη σελίδα. */
    public function enqueueSkin(string $hook): void
    {
        if ($this->hook === '' || $hook !== $this->hook) {
            return;
        }

        $url = Plugin::instance()?->url() ?? '';

        wp_enqueue_style(
            'ecrm-form',
            $url . 'public/assets/ecrm-form.css',
            [],
            \ECRM_Shortcodes::asset_version('ecrm-form.css')
        );
        wp_enqueue_style(
            'ecrm-app',
            $url . 'public/assets/ecrm-app.css',
            ['ecrm-form'],
            \ECRM_Shortcodes::asset_version('ecrm-app.css')
        );
        wp_enqueue_style(
            'ecrm-admin-skin',
            $url . 'public/assets/ecrm-admin-skin.css',
            ['ecrm-app'],
            \ECRM_Shortcodes::asset_version('ecrm-admin-skin.css')
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Δεν επιτρέπεται.');
        }

        $data  = $this->report->all($this->requestedRange());
        $theme = ThemePreference::forUser(get_current_user_id());

        echo '<div class="wrap ecrm ecrm-adminwrap" data-theme="' . esc_attr($theme) . '">';
        echo '<h1>Παρακολούθηση</h1>';

        $this->rangeLinks((int) $data['days']);

        echo '<div class="ecrm-secline">Σύστημα</div>';
        $this->systemTiles($data);
        $this->errorsCard($data);
        $this->cronCard($data);
        $this->backupCard($data);

        echo '<div class="ecrm-secline">Πωλήσεις — όλο το site</div>';
        $this->salesTiles($data);
        $this->salesTrendCard($data);
        $this->providersCard($data);
        $this->idleCard($data);

        echo '</div>';
    }

    /**
     * Η περίοδος έρχεται από το URL: σύνδεσμος, όχι φόρμα.
     *
     * Δεν αλλάζει τίποτα στον διακομιστή -- είναι φίλτρο ανάγνωσης, όπως το
     * `?post_status=` των λιστών του WordPress -- οπότε δεν χρειάζεται nonce.
     * Η τιμή ελέγχεται απέναντι σε κλειστό σύνολο και ό,τι δεν ανήκει σε αυτό
     * γίνεται η προεπιλογή.
     */
    private function requestedRange(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- φίλτρο ανάγνωσης, καμία μεταβολή κατάστασης.
        $asked = isset($_GET['range']) ? absint(wp_unslash($_GET['range'])) : 0;

        return in_array($asked, MonitoringReport::RANGES, true) ? $asked : MonitoringReport::DEFAULT_RANGE;
    }

    private function rangeLinks(int $current): void
    {
        echo '<p>';

        foreach (MonitoringReport::RANGES as $days) {
            $url = admin_url('admin.php?page=' . self::PAGE . '&range=' . $days);

            echo '<a class="button' . ($days === $current ? ' button-primary' : '')
                . '" style="margin-right:6px" href="' . esc_url($url) . '">'
                . (int) $days . ' ημέρες</a>';
        }

        echo '</p>';
    }

    /** @param array<string, mixed> $data */
    private function systemTiles(array $data): void
    {
        /** @var array<string, mixed> $errors */
        $errors = $data['errors'];
        /** @var array<string, mixed> $cron */
        $cron = $data['cron'];
        /** @var array<string, mixed> $backup */
        $backup = $data['backup'];

        $total    = (int) $errors['total'];
        $previous = (int) $errors['previous'];
        $rate     = $cron['rate'];
        $missed   = (int) $cron['missed'];

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- η tile()
        // περνά ΚΑΘΕ κομμάτι της από esc_html()· ο sniff δεν βλέπει μέσα από την
        // κλήση. Ιδιο μοτίβο με τη HealthPage.
        echo '<div class="ecrm-kpis ecrm-kpis--4">';
        echo self::tile(
            'Σφάλματα',
            (string) $total,
            $previous === 0
                ? 'Καμία προηγούμενη περίοδος για σύγκριση.'
                : sprintf('Προηγούμενη περίοδος: %d.', $previous),
            $total > $previous && $previous > 0
        );
        echo self::tile(
            'Επιτυχία εργασιών',
            $rate === null ? '—' : self::number((float) $rate) . '%',
            $rate === null
                ? 'Δεν έχει καταγραφεί ακόμη εκτέλεση.'
                : sprintf('%d αστοχίες σε %d ημέρες-εργασίας.', $missed, (int) $cron['ran'] + $missed),
            $missed > 0
        );
        echo self::tile(
            'Κάλυψη αντιγράφων',
            sprintf('%d/%d', (int) $backup['covered'], (int) $backup['total']),
            (int) $backup['worst'] === 0
                ? 'Καμία ημέρα χωρίς αντίγραφο.'
                : sprintf('Μεγαλύτερο κενό: %d ημέρες.', (int) $backup['worst']),
            (int) $backup['worst'] > 1
        );
        echo self::tile(
            'Ησυχες ημέρες',
            (string) (int) $errors['quiet'],
            'Συνεχόμενες ημέρες χωρίς κανένα σφάλμα.',
            false
        );
        echo '</div>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /** @param array<string, mixed> $data */
    private function errorsCard(array $data): void
    {
        /** @var array<string, int> $series */
        $series = $data['errors']['series'];

        echo '<div class="ecrm-card" style="margin-top:16px">';
        echo '<div class="ecrm-step">Σφάλματα ανά ημέρα</div>';
        echo '<p>Κάθε καταγραφή του ErrorLog, και οι επαναλήψεις ήδη γνωστού κωδικού: '
            . 'μια βλάβη που χτυπά χίλιες φορές σε μία μέρα δεν πρέπει να μοιάζει με μία.</p>';
        self::trend($series, true);
        self::axisNote($series);
        echo '</div>';
    }

    /** @param array<string, mixed> $data */
    private function cronCard(array $data): void
    {
        /** @var list<array{label: string, cells: list<array{day: string, runs: int, ok: bool|null}>}> $jobs */
        $jobs = $data['cron']['jobs'];

        echo '<div class="ecrm-card" style="margin-top:16px">';
        echo '<div class="ecrm-step">Αξιοπιστία προγραμματισμένων εργασιών</div>';
        echo '<p>Μία στήλη ανά ημέρα. Το WP-Cron ενεργοποιείται από επισκέψεις: '
            . 'σε ήσυχο site μια εργασία μπορεί να μένει «προγραμματισμένη» για μέρες, '
            . 'με την Υγεία πράσινη — εδώ φαίνεται.</p>';
        echo '<div class="ecrm-hm">';

        foreach ($jobs as $job) {
            echo '<div class="ecrm-hm__lbl">' . esc_html($job['label']) . '</div>';
            echo '<div class="ecrm-hm__row">';

            foreach ($job['cells'] as $cell) {
                $class = match ($cell['ok']) {
                    true    => '',
                    false   => ' is-miss',
                    default => ' is-none',
                };

                $title = $cell['ok'] === null
                    ? $cell['day'] . ' — χωρίς δεδομένα'
                    : sprintf('%s — %d εκτελέσεις', $cell['day'], $cell['runs']);

                echo '<span class="ecrm-hm__c' . esc_attr($class) . '" title="'
                    . esc_attr($title) . '"></span>';
            }

            echo '</div>';
        }

        echo '</div>';
        echo '<div class="ecrm-legend">'
            . '<span><i class="is-ok"></i>Ετρεξε</span>'
            . '<span><i class="is-miss"></i>Δεν έτρεξε</span>'
            . '<span><i class="is-none"></i>Χωρίς δεδομένα (πριν αρχίσει η καταγραφή)</span>'
            . '</div>';
        echo '</div>';
    }

    /** @param array<string, mixed> $data */
    private function backupCard(array $data): void
    {
        /** @var array<string, int> $days */
        $days = $data['backup']['days'];

        echo '<div class="ecrm-card" style="margin-top:16px">';
        echo '<div class="ecrm-step">Κάλυψη αντιγράφων ασφαλείας</div>';
        echo '<p>Το «τελευταίο αντίγραφο» της Υγείας δεν μπορεί να δείξει κενό: '
            . 'τρεις μέρες χωρίς και μετά ένα καθαρό δείχνουν ίδια με τριάντα συνεχόμενες.</p>';
        echo '<div class="ecrm-strip">';

        foreach ($days as $day => $count) {
            echo '<span class="ecrm-strip__d' . ($count > 0 ? ' has' : '') . '" title="'
                . esc_attr($day . ($count > 0 ? ' — αντίγραφο' : ' — κανένα αντίγραφο')) . '"></span>';
        }

        echo '</div>';
        self::axisNote($days);
        echo '</div>';
    }

    /** @param array<string, mixed> $data */
    private function salesTiles(array $data): void
    {
        /** @var array<string, mixed> $sales */
        $sales = $data['sales'];
        $avg   = $sales['avg_days'];

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- η tile()
        // περνά ΚΑΘΕ κομμάτι της από esc_html()· ο sniff δεν βλέπει μέσα από την
        // κλήση. Ιδιο μοτίβο με τη HealthPage.
        echo '<div class="ecrm-kpis ecrm-kpis--4">';
        echo self::tile('Νέες αιτήσεις', (string) (int) $sales['total'], 'Δημιουργήθηκαν μέσα στην περίοδο.', false);
        echo self::tile(
            'Conversion',
            self::number((float) $sales['conv_rate']) . '%',
            sprintf('%d απέδωσαν προμήθεια.', (int) $sales['won']),
            false
        );
        echo self::tile(
            'Ακυρώσεις',
            self::number((float) $sales['canc_rate']) . '%',
            'Ακυρωμένες ή λυμένες, στην ίδια περίοδο.',
            (float) $sales['canc_rate'] > 15.0
        );
        echo self::tile(
            'Μέσος χρόνος ενεργοποίησης',
            $avg === null ? '—' : self::number((float) $avg) . ' ημ.',
            $avg === null ? 'Καμία ενεργοποίηση στην περίοδο.' : 'Από δημιουργία έως «Ενεργή».',
            false
        );
        echo '</div>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /** @param array<string, mixed> $data */
    private function salesTrendCard(array $data): void
    {
        /** @var list<string> $axis */
        $axis = $data['axis'];
        /** @var array<string, int> $daily */
        $daily = $data['sales']['daily'];

        $series = [];

        foreach ($axis as $day) {
            $series[$day] = (int) ($daily[$day] ?? 0);
        }

        echo '<div class="ecrm-card" style="margin-top:16px">';
        echo '<div class="ecrm-step">Νέες αιτήσεις ανά ημέρα</div>';
        echo '<p>Ολοι οι συνεργάτες μαζί. Η οθόνη «Στατιστικά» δείχνει το ίδιο '
            . 'φιλτραρισμένο στον καθένα, οπότε αυτή η γραμμή δεν φαίνεται πουθενά αλλού.</p>';
        self::trend($series, false);
        self::axisNote($series);
        echo '</div>';
    }

    /** @param array<string, mixed> $data */
    private function providersCard(array $data): void
    {
        /** @var list<array<string, mixed>> $providers */
        $providers = $data['sales']['providers'];

        echo '<div class="ecrm-card" style="margin-top:16px">';
        echo '<div class="ecrm-step">Ανά πάροχο</div>';

        if ($providers === []) {
            echo '<div class="ecrm-empty">Καμία αίτηση στην περίοδο.</div></div>';

            return;
        }

        $max = 1;

        foreach ($providers as $row) {
            $max = max($max, (int) $row['c']);
        }

        echo '<div class="ecrm-barlist">';

        foreach ($providers as $row) {
            $count = (int) $row['c'];
            $name  = (string) ($row['name'] ?? '');

            echo '<div class="ecrm-barrow">';
            echo '<div class="ecrm-barrow__lbl">' . esc_html($name === '' ? '—' : $name) . '</div>';
            echo '<div class="ecrm-barrow__track"><div class="ecrm-barrow__fill is-prov" style="width:'
                . (int) round(100 * $count / $max) . '%"></div></div>';
            echo '<div class="ecrm-barrow__val">' . (int) $count . '</div>';
            echo '</div>';
        }

        echo '</div></div>';
    }

    /** @param array<string, mixed> $data */
    private function idleCard(array $data): void
    {
        /** @var list<array{partner_user_id: int, last: string}> $idle */
        $idle = $data['sales']['idle'];

        echo '<div class="ecrm-card" style="margin-top:16px">';
        echo '<div class="ecrm-step">Συνεργάτες χωρίς κίνηση</div>';
        echo '<p>Καμία αίτηση μέσα στην περίοδο, ενώ έχουν καταχωρίσει στο παρελθόν. '
            . 'Οποιος δεν έχει ποτέ ούτε μία δεν εμφανίζεται εδώ — αυτό είναι ερώτηση '
            . 'ένταξης, όχι πτώσης.</p>';

        if ($idle === []) {
            echo '<div class="ecrm-empty">Ολοι όσοι έχουν ιστορικό κινήθηκαν στην περίοδο.</div></div>';

            return;
        }

        echo '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>'
            . '<th>Συνεργάτης</th><th>Τελευταία αίτηση</th></tr></thead><tbody>';

        foreach ($idle as $row) {
            $user = get_userdata($row['partner_user_id']);
            $name = $user ? $user->display_name : '#' . $row['partner_user_id'];

            echo '<tr><td><strong>' . esc_html($name) . '</strong></td>';
            echo '<td style="white-space:nowrap">' . esc_html(substr($row['last'], 0, 10)) . '</td></tr>';
        }

        echo '</tbody></table></div></div>';
    }

    /**
     * Μπάρες τάσης. Ετικέτα κάθε πέμπτη στήλη: σε 90 ημέρες οι ετικέτες
     * γίνονται μουτζούρα και δεν διαβάζεται καμία.
     *
     * @param array<string, int> $series
     */
    private static function trend(array $series, bool $isError): void
    {
        $max   = max(1, $series === [] ? 1 : (int) max($series));
        $index = 0;

        echo '<div class="ecrm-trend">';

        foreach ($series as $day => $count) {
            $height = max(3, (int) round(100 * $count / $max));
            $label  = $index % 5 === 0 ? substr($day, 8, 2) : '';

            echo '<div class="ecrm-trend__col" title="' . esc_attr($day . ': ' . $count) . '">';
            echo '<div class="ecrm-trend__bar' . ($isError ? ' is-err' : '')
                . '" style="height:' . (int) $height . '%"></div>';
            echo '<div class="ecrm-trend__lbl">' . esc_html($label) . '</div>';
            echo '</div>';

            $index++;
        }

        echo '</div>';
    }

    /** @param array<string, int> $series */
    private static function axisNote(array $series): void
    {
        if ($series === []) {
            return;
        }

        $days = array_keys($series);

        echo '<div class="ecrm-axisnote"><span>' . esc_html((string) reset($days)) . '</span>'
            . '<span>' . esc_html((string) end($days)) . '</span></div>';
    }

    /** Ενα πλακίδιο, με την υποσημείωση που εξηγεί τον αριθμό. */
    private static function tile(string $label, string $value, string $foot, bool $warn): string
    {
        return '<div class="ecrm-kpi">'
            . '<div class="ecrm-kpi__k">' . esc_html($label) . '</div>'
            . '<div class="ecrm-kpi__v">' . esc_html($value) . '</div>'
            . '<div class="ecrm-kpi__f' . ($warn ? ' is-warn' : '') . '">' . esc_html($foot) . '</div>'
            . '</div>';
    }

    /** Ελληνική υποδιαστολή, όπως παντού αλλού στην εφαρμογή. */
    private static function number(float $value): string
    {
        return number_format_i18n($value, 1);
    }
}
