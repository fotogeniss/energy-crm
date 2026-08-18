<?php

/**
 * Energy CRM → Υγεία. Τι δεν πάει καλά, και τι έσπασε τελευταία.
 *
 * Υπάρχει για μία συγκεκριμένη στιγμή: κάτι χάλασε, ο διαχειριστής δεν είναι
 * προγραμματιστής, και πρέπει να στείλει κάτι χρήσιμο. Το κουμπί αντιγραφής
 * βγάζει κείμενο έτοιμο για επικόλληση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Admin;

use EnergyCRM\Infrastructure\ErrorLog;
use EnergyCRM\Infrastructure\HealthChecks;

final class HealthPage
{
    private const PAGE = 'energy-crm-health';

    private const CLEAR = 'ecrm_clear_error_log';

    public function __construct(
        private readonly HealthChecks $checks,
        private readonly ErrorLog $errors,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_post_' . self::CLEAR, [$this, 'clear']);
    }

    public function addPage(): void
    {
        add_submenu_page(
            'energy-crm',
            'Υγεία',
            'Υγεία',
            'manage_options',
            self::PAGE,
            [$this, 'render']
        );
    }

    public function clear(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Δεν επιτρέπεται.');
        }

        check_admin_referer(self::CLEAR);
        $this->errors->clear();
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
        exit;
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Δεν επιτρέπεται.');
        }

        $rows   = $this->checks->all();
        $errors = $this->errors->recent();
        $bad    = array_filter($rows, static fn (array $r): bool => $r['ok'] === false);

        echo '<div class="wrap"><h1>Υγεία</h1>';

        if ($bad === []) {
            echo '<div class="notice notice-success"><p>Όλοι οι έλεγχοι πέρασαν.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p><strong>' . (int) count($bad)
                . '</strong> έλεγχοι απέτυχαν. Δες τα κόκκινα παρακάτω.</p></div>';
        }

        echo '<h2>Έλεγχοι</h2><table class="widefat striped"><tbody>';

        $group = '';

        foreach ($rows as $row) {
            if ($row['group'] !== $group) {
                $group = $row['group'];
                echo '<tr><th colspan="3" style="background:#f6f7f7">' . esc_html($group) . '</th></tr>';
            }

            [$mark, $colour] = match ($row['ok']) {
                true    => ['✔', '#1a7f37'],
                false   => ['✘', '#b32d2e'],
                default => ['!', '#b45309'],
            };

            echo '<tr>';
            echo '<td style="width:32px;color:' . esc_attr($colour) . ';font-weight:700">' . esc_html($mark) . '</td>';
            echo '<td style="width:220px"><strong>' . esc_html($row['label']) . '</strong></td>';
            echo '<td>' . esc_html($row['detail']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<h2 style="margin-top:28px;">Τελευταία σφάλματα</h2>';
        echo '<p>Δεν καταγράφεται περιεχόμενο αιτήματος. Ό,τι μοιάζει με ΑΦΜ, ΑΔΤ, '
            . 'τηλέφωνο, email ή IBAN αντικαθίσταται πριν αποθηκευτεί.</p>';

        if ($errors === []) {
            echo '<p><em>Κανένα καταγεγραμμένο σφάλμα.</em></p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>'
                . '<th>Πότε (UTC)</th><th>Τι</th><th>Πού</th><th>Διαδρομή</th><th>Φορές</th>'
                . '</tr></thead><tbody>';

            foreach ($errors as $e) {
                echo '<tr>';
                echo '<td style="white-space:nowrap">' . esc_html((string) ($e['at'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($e['message'] ?? '')) . '</td>';
                echo '<td><code>' . esc_html((string) ($e['where'] ?? '')) . '</code></td>';
                echo '<td><code>' . esc_html((string) ($e['route'] ?? '')) . '</code></td>';
                echo '<td>' . (int) ($e['count'] ?? 1) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px">';
            wp_nonce_field(self::CLEAR);
            echo '<input type="hidden" name="action" value="' . esc_attr(self::CLEAR) . '">';
            echo '<button class="button">Καθαρισμός λίστας</button></form>';
        }

        $this->copyBox($rows, $errors);

        echo '</div>';
    }

    /**
     * Ένα textarea με τα πάντα, για επικόλληση σε μήνυμα υποστήριξης.
     *
     * @param list<array{group: string, label: string, ok: bool|null, detail: string}> $rows
     * @param list<array<string, mixed>>                                               $errors
     */
    private function copyBox(array $rows, array $errors): void
    {
        $lines = ['Energy CRM ' . \EnergyCRM\Plugin::VERSION . ' — ' . gmdate('Y-m-d H:i') . ' UTC'];

        foreach ($rows as $row) {
            $mark    = match ($row['ok']) {
                true    => 'OK  ',
                false   => 'ΛΑΘΟΣ',
                default => '?   ',
            };
            $lines[] = $mark . ' ' . $row['group'] . ' / ' . $row['label'] . ': ' . $row['detail'];
        }

        $lines[] = '';
        $lines[] = 'Σφάλματα: ' . count($errors);

        foreach (array_slice($errors, 0, 15) as $e) {
            $lines[] = sprintf(
                '  %s  %s  [%s]  %s  x%d',
                (string) ($e['at'] ?? ''),
                (string) ($e['message'] ?? ''),
                (string) ($e['where'] ?? ''),
                (string) ($e['route'] ?? ''),
                (int) ($e['count'] ?? 1)
            );
        }

        echo '<h2 style="margin-top:28px;">Για αποστολή</h2>';
        echo '<p>Διάλεξε τα πάντα και αντίγραψε. Δεν περιέχει προσωπικά δεδομένα.</p>';
        echo '<textarea readonly rows="12" style="width:100%;font-family:ui-monospace,Menlo,monospace;font-size:12px">';
        echo esc_textarea(implode("\n", $lines));
        echo '</textarea>';
    }
}
