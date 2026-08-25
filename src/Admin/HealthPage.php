<?php

/**
 * Energy CRM → Υγεία. Τι δεν πάει καλά, και τι έσπασε τελευταία.
 *
 * Υπάρχει για μία συγκεκριμένη στιγμή: κάτι χάλασε, ο διαχειριστής δεν είναι
 * προγραμματιστής, και πρέπει να στείλει κάτι χρήσιμο. Το κουμπί αντιγραφής
 * βγάζει κείμενο έτοιμο για επικόλληση.
 *
 * ## Ευθυγράμμιση με το κέλυφος `.ecrm`, 2026-08-25
 *
 * HANDOVER §6γ, κενό (2): μέχρι σήμερα ήταν καθαρή σελίδα wp-admin
 * (`table.widefat`, inline `color:` στα ✔/✘/!). Σύγκριση δίπλα-δίπλα κατά
 * §1.8 πρώτα (`docs/UI-HEALTH-SETTINGS-VS-KIT.html`) — ο ιδιοκτήτης
 * προτίμησε το κέλυφος.
 *
 * Η σελίδα ΜΕΝΕΙ στο wp-admin — μενού, toolbar, `manage_options`, όλα ίδια.
 * Το `.ecrm` κέλυφος ζει στο FRONTEND μέσα από shortcode
 * (`[energy_crm_app]`), όχι full-page takeover, οπότε δεν μπορεί κυριολεκτικά
 * να «γίνει» αυτή η σελίδα — παίρνει μόνο τα ίδια tokens/κλάσεις
 * (`.ecrm-card`, `.ecrm-kpis`, `.ecrm-badge--*`) μέσα στο δικό της `.wrap`.
 * Οι κλάσεις αυτές δεν είναι scoped κάτω από `.ecrm` compound selector — απλές
 * class δηλώσεις σε `ecrm-app.css`/`ecrm-form.css` — οπότε δουλεύουν παντού
 * αρκεί το `:root`/`.ecrm[data-theme]` να έχει ήδη ορίσει τα tokens πάνω από
 * αυτές, γι' αυτό το wrapper div παίρνει `class="ecrm"` + `data-theme`.
 *
 * Τα ✔/✘/! έγιναν `.ecrm-badge--active`/`--cancelled`/`--pending` — ΟΧΙ νέες
 * κλάσεις: οι τρεις αυτές έχουν ήδη τα σωστά χρώματα (πράσινο/κόκκινο/amber)
 * ΚΑΙ το δεύτερο κανάλι γλυφής (×/!) για δυσχρωματοψία, από τη δουλειά της
 * (3) — άσχετο νόημα (κατάσταση σύμβασης) αλλά ίδιο σχήμα «καλό/κακό/
 * προσοχή», οπότε η επαναχρησιμοποίηση είναι ακριβώς αυτό: ίδιο χρώμα σημαίνει
 * ίδιο πράγμα παντού στην εφαρμογή, όχι σύμπτωση ονόματος.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Admin;

use EnergyCRM\Infrastructure\ErrorLog;
use EnergyCRM\Infrastructure\HealthChecks;
use EnergyCRM\Infrastructure\ThemePreference;
use EnergyCRM\Plugin;

final class HealthPage
{
    private const PAGE = 'energy-crm-health';

    private const CLEAR = 'ecrm_clear_error_log';

    /** @var string Το hook suffix που επιστρέφει η add_submenu_page(), για το admin_enqueue_scripts. */
    private string $hook = '';

    public function __construct(
        private readonly HealthChecks $checks,
        private readonly ErrorLog $errors,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_post_' . self::CLEAR, [$this, 'clear']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueSkin']);
    }

    public function addPage(): void
    {
        $hook = add_submenu_page(
            'energy-crm',
            'Υγεία',
            'Υγεία',
            'manage_options',
            self::PAGE,
            [$this, 'render']
        );

        // add_submenu_page() γυρίζει false αν απέτυχε η καταχώρηση (π.χ. λείπει
        // το parent menu) — τότε ΔΕΝ ορίζεται hook, και το enqueueSkin() παρακάτω
        // δεν φορτώνει τίποτα πουθενά αντί να σκάσει σε άγνωστο string.
        $this->hook = $hook !== false ? $hook : '';
    }

    /**
     * Τα ίδια δύο αρχεία CSS με το frontend κέλυφος — ΟΧΙ αντίγραφο τιμών.
     * Μόνο σε αυτή τη σελίδα, με το ακριβές hook suffix που επέστρεψε η
     * add_submenu_page(): ένα `strpos($hook,'energy-crm')` θα έπιανε και τις
     * Προμήθειες/GDPR/Βάση Γνώσης/Εκκαθαρίσεις — σελίδες που δεν αλλάζουν εδώ.
     */
    public function enqueueSkin(string $hook): void
    {
        if ($this->hook === '' || $hook !== $this->hook) {
            return;
        }

        // Plugin::instance()?->url(), ΟΧΙ η καθολική σταθερά ECRM_URL:
        // ορίζεται δυναμικά (define() στο energy-crm.php) και το phpstan δεν
        // μπορεί να την εντοπίσει από μέσα από src/ — 3 ψευδή
        // constant.notFound, βρέθηκαν από composer check:all 25/08. Ίδιο
        // μοτίβο πρόσβασης με το ProviderFormController.
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

    private static function tile(string $label, int $value): string
    {
        return '<div class="ecrm-kpi"><div class="ecrm-kpi__k">' . esc_html($label) . '</div>'
            . '<div class="ecrm-kpi__v">' . $value . '</div></div>';
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
        $warn   = array_filter($rows, static fn (array $r): bool => $r['ok'] === null);
        $theme  = ThemePreference::forUser(get_current_user_id());

        echo '<div class="wrap ecrm ecrm-adminwrap" data-theme="' . esc_attr($theme) . '">';
        echo '<h1>Υγεία</h1>';

        if ($bad === []) {
            echo '<div class="notice notice-success"><p>Όλοι οι έλεγχοι πέρασαν.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p><strong>' . (int) count($bad)
                . '</strong> έλεγχοι απέτυχαν. Δες τα κόκκινα παρακάτω.</p></div>';
        }

        // Τέσσερα πλακίδια σύνοψης, ίδια δομή με τα πλακίδια του Πίνακα
        // (`.ecrm-kpis.ecrm-kpis--4` — CHANGELOG 119) αντί για μόνο το notice
        // πάνω-πάνω: το notice λέει «κάτι απέτυχε», τα πλακίδια λένε ΠΟΣΟ.
        $ok = count($rows) - count($bad) - count($warn);

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- tile()
        // escapes its own $label with esc_html() and prints $value as a plain
        // int (every caller passes count(...) or a subtraction of counts,
        // never request input) — the sniff just can't see through the call.
        echo '<div class="ecrm-kpis ecrm-kpis--4">'
            . self::tile('Απέτυχαν', count($bad))
            . self::tile('Προειδοποιήσεις', count($warn))
            . self::tile('Πέρασαν', $ok)
            . self::tile('Σφάλματα', count($errors))
            . '</div>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        echo '<div class="ecrm-card"><div class="ecrm-step">Έλεγχοι</div>';

        $group = '';

        foreach ($rows as $row) {
            if ($row['group'] !== $group) {
                $group = $row['group'];
                echo '<div class="ecrm-healthgrp">' . esc_html($group) . '</div>';
            }

            [$badgeClass, $badgeLabel] = match ($row['ok']) {
                true    => ['active', 'OK'],
                false   => ['cancelled', 'Αποτυχία'],
                default => ['pending', 'Έλεγξε'],
            };

            echo '<div class="ecrm-healthrow">';
            echo '<span class="ecrm-healthrow__lbl">' . esc_html($row['label']) . '</span>';
            echo '<span class="ecrm-healthrow__d">' . esc_html($row['detail']) . '</span>';
            echo '<span class="ecrm-badge ecrm-badge--' . esc_attr($badgeClass) . '">'
                . esc_html($badgeLabel) . '</span>';
            echo '</div>';
        }

        echo '</div>';

        echo '<div class="ecrm-card" style="margin-top:16px"><div class="ecrm-step">Τελευταία τεχνικά σφάλματα</div>';
        echo '<p>Μόνο όσα <strong>φταίμε εμείς</strong>. Ό,τι μπορεί να διορθώσει ο χρήστης '
            . '— λείπει ΑΦΜ, λείπει δικαιολογητικό, μη επιτρεπτή μετάβαση — του λέγεται '
            . 'κατευθείαν στην οθόνη του και δεν καταγράφεται εδώ: δεν είναι βλάβη.</p>';
        echo '<p>Όταν κάποιος αναφέρει <code>ECRM-XXXX</code>, βρες τον εδώ. '
            . 'Δεν καταγράφεται περιεχόμενο αιτήματος, και ό,τι μοιάζει με ΑΦΜ, ΑΔΤ, '
            . 'τηλέφωνο, email ή IBAN αντικαθίσταται πριν αποθηκευτεί.</p>';

        if ($errors === []) {
            echo '<p><em>Κανένα καταγεγραμμένο σφάλμα.</em></p>';
        } else {
            echo '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>'
                . '<th>Κωδικός</th><th>Πότε (UTC)</th><th>Τι</th><th>Πού</th><th>Διαδρομή</th><th>Φορές</th>'
                . '</tr></thead><tbody>';

            foreach ($errors as $e) {
                echo '<tr>';
                echo '<td><span class="ecrm-code">' . esc_html((string) ($e['code'] ?? '—')) . '</span></td>';
                echo '<td style="white-space:nowrap">' . esc_html((string) ($e['at'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($e['message'] ?? '')) . '</td>';
                echo '<td><span class="ecrm-code">' . esc_html((string) ($e['where'] ?? '')) . '</span></td>';
                echo '<td><span class="ecrm-code">' . esc_html((string) ($e['route'] ?? '')) . '</span></td>';
                echo '<td>' . (int) ($e['count'] ?? 1) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px">';
            wp_nonce_field(self::CLEAR);
            echo '<input type="hidden" name="action" value="' . esc_attr(self::CLEAR) . '">';
            echo '<button class="ecrm-btn ecrm-btn--ghost">Καθαρισμός λίστας</button></form>';
        }

        echo '</div>';

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
                '  %s  %s  %s  [%s]  %s  x%d',
                (string) ($e['code'] ?? '—'),
                (string) ($e['at'] ?? ''),
                (string) ($e['message'] ?? ''),
                (string) ($e['where'] ?? ''),
                (string) ($e['route'] ?? ''),
                (int) ($e['count'] ?? 1)
            );
        }

        echo '<div class="ecrm-card" style="margin-top:16px"><div class="ecrm-step">Για αποστολή</div>';
        echo '<p>Διάλεξε τα πάντα και αντίγραψε. Δεν περιέχει προσωπικά δεδομένα.</p>';
        echo '<textarea readonly rows="12" class="ecrm-textarea"'
            . ' style="width:100%;font-family:ui-monospace,Menlo,monospace;font-size:12px">';
        echo esc_textarea(implode("\n", $lines));
        echo '</textarea></div>';
    }
}
