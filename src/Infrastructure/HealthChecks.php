<?php

/**
 * Τι πρέπει να ισχύει για να δουλεύει σωστά το CRM, ελεγμένο αντί για δηλωμένο.
 *
 * Οι περισσότερες ρυθμίσεις εδώ αποτυγχάνουν σιωπηλά: ένα .htaccess που δεν το
 * διαβάζει ο nginx, ένα cron που ξεχάστηκε, ένα migration που κόλλησε. Τίποτα
 * δεν σπάει — απλώς κάτι δεν γίνεται, και φαίνεται μήνες αργότερα.
 *
 * Κάθε έλεγχος επιστρέφει ok=true (εντάξει), false (πρόβλημα) ή null (δεν
 * μπόρεσε να απαντήσει). Το null ΔΕΝ είναι εντάξει: «δεν ξέρω» και «μια χαρά»
 * είναι διαφορετικές απαντήσεις και η οθόνη τις δείχνει διαφορετικά.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\MigrationList;
use EnergyCRM\Persistence\Schema\MigrationRunner;
use EnergyCRM\Persistence\Tables;

final class HealthChecks
{
    /** Το αρχείο που δοκιμάζει αν ο φάκελος εγγράφων είναι όντως κλειστός. */
    private const PROBE = 'ecrm-probe.txt';

    /**
     * @return list<array{group: string, label: string, ok: bool|null, detail: string}>
     */
    public function all(): array
    {
        return array_merge(
            $this->encryption(),
            $this->documents(),
            $this->schema(),
            $this->scheduled(),
            $this->platform()
        );
    }

    /**
     * @return list<array{group: string, label: string, ok: bool|null, detail: string}>
     */
    private function encryption(): array
    {
        $on          = CustomerFields::isEnabled();
        $fingerprint = KeyFingerprint::default();

        $out = [
            self::row('Κρυπτογράφηση', 'Κρυπτογράφηση PII', $on, $on
                ? 'Ενεργή. Οι στήλες ΑΦΜ, ΑΔΤ και διεύθυνση γράφονται κρυπτογραφημένες.'
                : 'Κλειστή. Βάλε ECRM_ENCRYPT_PII στο wp-config.php πριν το go-live.'),
        ];

        // Τα salts. Αν λείπουν από το wp-config, το WordPress παράγει δικά του
        // και τα βάζει στη βάση — δίπλα στο κρυπτογράφημα που ανοίγουν.
        foreach (['SECURE_AUTH_KEY', 'SECURE_AUTH_SALT'] as $constant) {
            $real = defined($constant)
                && is_string(constant($constant))
                && strlen((string) constant($constant)) >= 32
                && ! str_contains((string) constant($constant), 'put your unique phrase here');

            $out[] = self::row('Κρυπτογράφηση', $constant, $real, $real
                ? 'Ορισμένο στο wp-config.php.'
                : 'ΛΕΙΠΕΙ. Το WordPress θα φτιάξει δικό του και θα το αποθηκεύσει στη βάση, '
                  . 'δίπλα στα δεδομένα που ξεκλειδώνει. Βάλε πραγματικά από api.wordpress.org.');
        }

        if ($on) {
            $matches = $fingerprint->isRecorded() ? $fingerprint->matches() : null;

            $out[] = self::row('Κρυπτογράφηση', 'Αποτύπωμα κλειδιού', $matches, match (true) {
                $matches === true  => 'Το κλειδί είναι το ίδιο που έγραψε τα δεδομένα.',
                $matches === false => 'ΑΛΛΑΞΕ. Ό,τι γράφτηκε με το παλιό δεν διαβάζεται, '
                    . 'και η πρώτη αποθήκευση θα γράψει κενό από πάνω του. Μη σώσεις τίποτα.',
                default            => 'Δεν έχει καταγραφεί ακόμη.',
            });
        }

        return $out;
    }

    /**
     * @return list<array{group: string, label: string, ok: bool|null, detail: string}>
     */
    private function documents(): array
    {
        $dir = \ECRM_Files::dir();

        $out = [
            self::row('Έγγραφα', 'Φάκελος', is_dir($dir), is_dir($dir) ? $dir : 'Δεν υπάρχει.'),
        ];

        $reachable = $this->probeIsReachable($dir);

        $out[] = self::row('Έγγραφα', 'Απευθείας πρόσβαση από το web', $reachable === null ? null : ! $reachable, match ($reachable) {
            true  => 'ΑΝΟΙΧΤΟΣ. Το .htaccess ισχύει μόνο σε Apache. Σε nginx χρειάζεται κανόνας '
                . 'στη ρύθμιση του server: location ~* /uploads/ecrm-secure/ { deny all; }',
            false => 'Κλειστός. Ο server αρνείται το αρχείο δοκιμής.',
            null  => 'Δεν απαντήθηκε — το site δεν φτάνει τον εαυτό του. Έλεγξέ το με το χέρι.',
        });

        return $out;
    }

    /**
     * Γράφει ένα αβλαβές αρχείο και ζητά το URL του.
     *
     * Η μόνη απάντηση που μετράει: αν έρθει 200, ο φάκελος σερβίρεται.
     */
    private function probeIsReachable(string $dir): ?bool
    {
        $path = trailingslashit($dir) . self::PROBE;

        if (! file_exists($path)) {
            file_put_contents($path, "ok\n"); // phpcs:ignore
        }

        $uploads = wp_upload_dir();
        $url     = trailingslashit($uploads['baseurl']) . 'ecrm-secure/' . self::PROBE;

        $response = wp_remote_get($url, ['timeout' => 5, 'sslverify' => false, 'redirection' => 0]);

        if (is_wp_error($response)) {
            return null;
        }

        return wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * @return list<array{group: string, label: string, ok: bool|null, detail: string}>
     */
    private function schema(): array
    {
        global $wpdb;

        $pending = (new MigrationRunner(MigrationList::all()))->pending();
        $missing = [];

        foreach (Tables::all() as $table) {
            $name = Tables::name($table);

            // phpcs:ignore WordPress.DB.PreparedSQL
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) {
                $missing[] = $table;
            }
        }

        return [
            self::row('Βάση', 'Πίνακες', $missing === [], $missing === []
                ? count(Tables::all()) . ' πίνακες στη θέση τους.'
                : 'Λείπουν: ' . implode(', ', $missing)),

            self::row('Βάση', 'Migrations', $pending === [], $pending === []
                ? 'Όλα εφαρμοσμένα.'
                : count($pending) . ' εκκρεμούν: ' . implode(', ', array_map(
                    static fn (Migration $m): string => $m->id(),
                    $pending
                )) . '. Αν μένουν εκκρεμή, κάποιο αποτυγχάνει — δες το log.'),
        ];
    }

    /**
     * @return list<array{group: string, label: string, ok: bool|null, detail: string}>
     */
    private function scheduled(): array
    {
        $jobs = [
            Retention::HOOK              => 'Διαγραφή παλιών δεδομένων εξαγωγής',
            DocumentProtection::HOOK     => 'Μεταφορά ανασφάλιστων εγγράφων',
            PiiBackfill::HOOK            => 'Κρυπτογράφηση παλιών γραμμών',
            \ECRM_Notifications::CRON_HOOK => 'Ημερήσιες υπενθυμίσεις',
        ];

        $out = [];

        foreach ($jobs as $hook => $label) {
            $next = wp_next_scheduled($hook);

            $out[] = self::row('Προγραμματισμένα', $label, $next !== false, $next !== false
                ? 'Επόμενο: ' . gmdate('d/m/Y H:i', (int) $next) . ' UTC'
                : 'ΔΕΝ ΕΙΝΑΙ ΠΡΟΓΡΑΜΜΑΤΙΣΜΕΝΟ. Απενεργοποίησε και ενεργοποίησε ξανά το plugin.');
        }

        if (defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON') === true) {
            $out[] = self::row('Προγραμματισμένα', 'WP-Cron', null,
                'Το DISABLE_WP_CRON είναι true. Σωστό, αν ο server τρέχει το wp-cron.php '
                . 'μόνος του. Αν όχι, τίποτα από τα παραπάνω δεν τρέχει ποτέ.');
        }

        return $out;
    }

    /**
     * @return list<array{group: string, label: string, ok: bool|null, detail: string}>
     */
    private function platform(): array
    {
        return [
            self::row('Περιβάλλον', 'libsodium', FieldCipher::isAvailable(), FieldCipher::isAvailable()
                ? 'Διαθέσιμο.'
                : 'ΛΕΙΠΕΙ. Χωρίς αυτό η κρυπτογράφηση αρνείται να γράψει, και οι αποθηκεύσεις σκάνε.'),

            self::row('Περιβάλλον', 'ZipArchive', class_exists('ZipArchive'), class_exists('ZipArchive')
                ? 'Διαθέσιμο.'
                : 'ΛΕΙΠΕΙ. Οι εξαγωγές Excel δεν δουλεύουν.'),

            self::row('Περιβάλλον', 'Έμπιστοι proxy', RequestIp::trustedProxies() !== [] ? true : null,
                RequestIp::trustedProxies() !== []
                    ? count(RequestIp::trustedProxies()) . ' δηλωμένοι.'
                    : 'Κανένας. Σωστό αν το site δεν είναι πίσω από Cloudflare ή load balancer. '
                      . 'Αν είναι, τα consent_ip και signed_ip καταγράφουν τον proxy αντί του πελάτη.'),

            self::row('Περιβάλλον', 'Έκδοση PHP', version_compare(PHP_VERSION, '8.2', '>='), PHP_VERSION),
        ];
    }

    /**
     * @return array{group: string, label: string, ok: bool|null, detail: string}
     */
    private static function row(string $group, string $label, ?bool $ok, string $detail): array
    {
        return ['group' => $group, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
    }
}
