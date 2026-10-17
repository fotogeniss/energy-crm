<?php

/**
 * Energy CRM → Site σύγκρισης: η σύνδεση με το site τιμών ρεύματος.
 *
 * Τρία πράγματα, όλα του admin:
 *  - ποιος χρήστης παίρνει τις αιτήσεις (σε αυτόν ανήκουν οι υποψήφιοι)
 *  - το κοινό μυστικό: φτιάχνεται εδώ, φαίνεται ΜΙΑ φορά, μπαίνει στο .env
 *    του site. Αν χαθεί, φτιάχνεται νέο και το παλιό παύει αμέσως.
 *  - η διεύθυνση που καλεί το site
 *
 * Το μυστικό που μόλις φτιάχτηκε περνά στη σελίδα με transient ανά χρήστη,
 * 5 λεπτά, και σβήνεται με την πρώτη εμφάνιση. Ποτέ σε URL (θα έμενε σε
 * ιστορικό browser και σε access logs).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\SiteIntake\Admin;

use EnergyCRM\Access\Capability;
use EnergyCRM\Http\Router;
use EnergyCRM\Infrastructure\ThemePreference;
use EnergyCRM\SiteIntake\Infrastructure\SiteIntakeConfig;

final class SiteIntakePage
{
    private const PAGE = 'energy-crm-site-intake';

    private const SAVE = 'ecrm_site_intake_save';

    private const ROTATE = 'ecrm_site_intake_rotate';

    public function __construct(private readonly SiteIntakeConfig $config)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_post_' . self::SAVE, [$this, 'save']);
        add_action('admin_post_' . self::ROTATE, [$this, 'rotate']);
    }

    public function addPage(): void
    {
        add_submenu_page(
            'energy-crm',
            'Site σύγκρισης',
            'Site σύγκρισης',
            'manage_options',
            self::PAGE,
            [$this, 'render']
        );
    }

    public function save(): void
    {
        $this->guard(self::SAVE);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- ελέγχθηκε στο guard().
        $owner = isset($_POST['owner']) ? absint(wp_unslash($_POST['owner'])) : 0;
        $this->config->setOwner($owner);

        $this->back('saved');
    }

    public function rotate(): void
    {
        $this->guard(self::ROTATE);

        if ($this->config->isPinned()) {
            $this->back('pinned');
        }

        set_transient($this->onceKey(), $this->config->rotate(), 300);

        $this->back('rotated');
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Δεν επιτρέπεται.');
        }

        $fresh = (string) get_transient($this->onceKey());
        if ($fresh !== '') {
            delete_transient($this->onceKey());
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- μόνο μήνυμα επιβεβαίωσης.
        $done  = isset($_GET['done']) ? sanitize_key(wp_unslash($_GET['done'])) : '';
        $theme = ThemePreference::forUser(get_current_user_id());
        $users = get_users(['capability' => Capability::CREATE_CONTRACT, 'orderby' => 'display_name']);
        $owner = $this->config->ownerId();
        $url   = rest_url(Router::NAMESPACE . '/site-intake');

        echo '<div class="wrap ecrm ecrm-adminwrap" data-theme="' . esc_attr($theme) . '">';
        echo '<h1>Site σύγκρισης τιμών</h1>';
        echo '<p>Οι αιτήσεις που κάνουν οι πελάτες στο site μπαίνουν εδώ ως <strong>Υποψήφιοι</strong>'
            . ' («Από το site»), με τα έγγραφά τους. Η «Μετατροπή» γεμίζει πελάτη και σύμβαση με'
            . ' ΑΦΜ, διεύθυνση και αριθμό παροχής.</p>';

        if ($done === 'saved') {
            echo '<div class="notice notice-success"><p>Αποθηκεύτηκε.</p></div>';
        } elseif ($done === 'pinned') {
            echo '<div class="notice notice-warning"><p>Το μυστικό ορίζεται στο wp-config'
                . ' (ECRM_SITE_INTAKE_SECRET) και δεν αλλάζει από εδώ.</p></div>';
        }

        if ($fresh !== '') {
            echo '<div class="notice notice-warning"><p><strong>Νέο μυστικό — φαίνεται μόνο τώρα.</strong>'
                . ' Βάλ’ το στο <code>.env</code> του site ως <code>CRM_INTAKE_SECRET</code>.'
                . ' Το παλιό δεν ισχύει πια.</p>'
                . '<p><input type="text" readonly class="large-text code" onclick="this.select()" value="'
                . esc_attr($fresh) . '"></p></div>';
        }

        // 1. Ποιος παίρνει τις αιτήσεις.
        echo '<h2>Ποιος παίρνει τις αιτήσεις</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE) . '">';
        wp_nonce_field(self::SAVE);
        echo '<select name="owner"><option value="0">— κανένας (οι αιτήσεις δεν γίνονται δεκτές) —</option>';
        foreach ($users as $user) {
            echo '<option value="' . (int) $user->ID . '"' . selected($owner, (int) $user->ID, false) . '>'
                . esc_html($user->display_name . ' (' . $user->user_email . ')') . '</option>';
        }
        echo '</select> ';
        submit_button('Αποθήκευση', 'primary', 'submit', false);
        echo '</form>';
        $problem = $this->config->ownerProblem();
        if ($owner > 0 && $problem !== '') {
            echo '<p class="description">' . esc_html($problem) . ' Οι αιτήσεις απορρίπτονται μέχρι να διορθωθεί.</p>';
        }

        // 2. Το μυστικό.
        echo '<h2>Κοινό μυστικό</h2>';
        echo '<p>' . ($this->config->hasSecret()
            ? 'Υπάρχει: <code>' . esc_html($this->config->maskedSecret()) . '</code>'
            : 'Δεν υπάρχει ακόμα — η σύνδεση είναι κλειστή.') . '</p>';
        if (! $this->config->isPinned()) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ROTATE) . '">';
            wp_nonce_field(self::ROTATE);
            submit_button(
                $this->config->hasSecret() ? 'Νέο μυστικό (το παλιό παύει)' : 'Δημιουργία μυστικού',
                'secondary',
                'submit',
                false
            );
            echo '</form>';
        }

        // 3. Η διεύθυνση.
        echo '<h2>Διεύθυνση για το .env του site</h2>';
        echo '<p><code>CRM_INTAKE_URL=' . esc_html($url) . '</code></p>';

        echo '</div>';
    }

    private function guard(string $action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Δεν επιτρέπεται.');
        }

        check_admin_referer($action);
    }

    /** @return never */
    private function back(string $done): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&done=' . $done));
        exit;
    }

    private function onceKey(): string
    {
        return 'ecrm_site_secret_' . get_current_user_id();
    }
}
