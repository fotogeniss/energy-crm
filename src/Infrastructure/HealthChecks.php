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
            $this->secrets(),
            $this->encryption(),
            $this->documents(),
            $this->schema(),
            $this->commissions(),
            $this->scheduled(),
            $this->platform()
        );
    }

    /**
     * Πού ζουν τα διαπιστευτήρια, και πόσο εκτεθειμένο είναι το αρχείο που τα κρατά.
     *
     * @return list<array{group: string, label: string, ok: bool|null, detail: string}>
     */
    private function secrets(): array
    {
        $pinned = \EnergyCRM\Services::secrets()->isPinned('claude_api_key');
        $set    = \EnergyCRM\Services::secrets()->get('claude_api_key') !== '';

        $out = [
            self::row('Μυστικά', 'Κλειδί Claude', $set ? $pinned : null, match (true) {
                ! $set  => 'Δεν έχει οριστεί. Η ανάγνωση λογαριασμών και η βάση γνώσης δεν δουλεύουν.',
                $pinned => 'Καρφωμένο εκτός βάσης (σταθερά ή μεταβλητή περιβάλλοντος). Σωστό.',
                default => 'Αποθηκευμένο κρυπτογραφημένο στη ΒΑΣΗ. Δουλεύει, αλλά ένα dump μαζί '
                    . 'με τα salts του wp-config το ανοίγει. Προτίμησε σταθερά στο wp-config.php.',
            }),
        ];

        // Το wp-config μπορεί να ζει ένα επίπεδο πάνω από τον web root· το
        // WordPress το ψάχνει και εκεί. Τότε δεν το σερβίρει ποτέ ο server.
        $inRoot = file_exists(ABSPATH . 'wp-config.php');
        $above  = file_exists(dirname(rtrim(ABSPATH, '/\\')) . '/wp-config.php');

        $out[] = self::row('Μυστικά', 'Θέση wp-config.php', $above ? true : null, $above
            ? 'Πάνω από τον web root. Ο server δεν μπορεί να το σερβίρει.'
            : ($inRoot
                ? 'Μέσα στον web root. Δουλεύει και είναι το συνηθισμένο, αλλά αν κάποια '
                  . 'στιγμή σπάσει η PHP, το αρχείο σερβίρεται ως κείμενο με όλα μέσα. '
                  . 'Μετακίνησέ το ένα επίπεδο πάνω αν το επιτρέπει ο host.'
                : 'Δεν βρέθηκε στα συνήθη σημεία.'));

        $config = $inRoot ? ABSPATH . 'wp-config.php' : dirname(rtrim(ABSPATH, '/\\')) . '/wp-config.php';

        if (file_exists($config)) {
            // Σε Windows το fileperms() επιστρέφει σταθερά 0666: δεν υπάρχουν
            // δικαιώματα POSIX να διαβαστούν. Φύλακας που φωνάζει σε κάθε
            // μηχάνημα ανάπτυξης παύει να διαβάζεται.
            if (PHP_OS_FAMILY === 'Windows') {
                $out[] = self::row(
                    'Μυστικά',
                    'Δικαιώματα wp-config.php',
                    null,
                    'Δεν ελέγχεται σε Windows. Στον server της παραγωγής θέλει 0640 ή 0600.'
                );
            } else {
                $perms = fileperms($config);
                $loose = $perms !== false && ($perms & 0o044) !== 0;

                $out[] = self::row('Μυστικά', 'Δικαιώματα wp-config.php', ! $loose, $loose
                    ? sprintf('%04o — το διαβάζουν και άλλοι χρήστες του server. Βάλε 0640 ή 0600.', $perms & 0o777)
                    : sprintf('%04o', $perms === false ? 0 : $perms & 0o777));
            }
        }

        return $out;
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

        $out[] = self::row(
            'Έγγραφα',
            'Απευθείας πρόσβαση από το web',
            $reachable === null ? null : ! $reachable,
            match ($reachable) {
                true  => 'ΑΝΟΙΧΤΟΣ. Το .htaccess ισχύει μόνο σε Apache. Σε nginx βάλε στη ρύθμιση '
                    . 'του server: location ^~ /wp-content/uploads/ecrm-secure/ { deny all; return 404; } '
                    . '— το ^~ είναι απαραίτητο, αλλιώς ο κανόνας για τις εικόνες πιάνει πρώτος '
                    . 'ένα doc_XXXX.jpg και το σερβίρει.',
                false => 'Κλειστός. Ο server αρνείται το αρχείο δοκιμής.',
                null  => 'Δεν απαντήθηκε — το site δεν φτάνει τον εαυτό του. Έλεγξέ το με το χέρι.',
            }
        );

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

            // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off admin health-check probe, not a hot path.
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
     * Υπάρχει έστω ένας κανόνας που να δίνει ποσό;
     *
     * Ο πιο αθόρυβος τρόπος να αποτύχει αυτό το σύστημα. Η
     * `ECRM_Commissions::amount_for()` περνά κάθε σύμβαση από τον `RuleMatch`
     * πάνω σε όσους κανόνες είναι `active = 1`. Με άδειο πίνακα κανένας δεν
     * ταιριάζει και η απάντηση είναι **0** — για κάθε σύμβαση, κάθε συνεργάτη,
     * κάθε εκκαθάριση.
     *
     * Και δεν φαίνεται πουθενά ως βλάβη. Η οθόνη Προμήθειες δείχνει 0,00 € σε
     * κάθε γραμμή, η κατάταξη της ομάδας είναι όλο μηδενικά, η εκκαθάριση
     * δημιουργείται κανονικά με `amount = 0` και η βεβαίωση τυπώνεται. Όλα
     * δουλεύουν· απλώς δεν πληρώνεται κανείς.
     *
     * Ο έλεγχος γράφτηκε επειδή στις 18/08/2026 ο πίνακας ήταν **όντως άδειος**
     * και οι δεκαεπτά υπόλοιποι έλεγχοι αυτής της οθόνης ήταν πράσινοι.
     *
     * @return list<array{group: string, label: string, ok: bool|null, detail: string}>
     */
    private function commissions(): array
    {
        global $wpdb;

        $table = Tables::name(Tables::COMMISSION_RULES);

        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off admin health-check probe, not a hot path.
        $active = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE active = 1', $table));

        return [
            self::row('Προμήθειες', 'Ενεργοί κανόνες', $active > 0, $active > 0
                ? $active . ' ενεργοί. Οι υπολογισμοί έχουν πάνω σε τι να πέσουν.'
                : 'ΚΑΝΕΝΑΣ. Κάθε προμήθεια υπολογίζεται 0 € — σε κάθε οθόνη, σε κάθε '
                  . 'εκκαθάριση, σε κάθε βεβαίωση. Τίποτα δεν σπάει και κανείς δεν '
                  . 'πληρώνεται. Πρόσθεσε κανόνες στη σελίδα «Προμήθειες».'),
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
            $out[] = self::row(
                'Προγραμματισμένα',
                'WP-Cron',
                null,
                'Το DISABLE_WP_CRON είναι true. Σωστό, αν ο server τρέχει το wp-cron.php '
                . 'μόνος του. Αν όχι, τίποτα από τα παραπάνω δεν τρέχει ποτέ.'
            );
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

            self::row(
                'Περιβάλλον',
                'Έμπιστοι proxy',
                RequestIp::trustedProxies() !== [] ? true : null,
                RequestIp::trustedProxies() !== []
                    ? count(RequestIp::trustedProxies()) . ' δηλωμένοι.'
                    : 'Κανένας. Σωστό αν το site δεν είναι πίσω από Cloudflare ή load balancer. '
                . 'Αν είναι, τα consent_ip και signed_ip καταγράφουν τον proxy αντί του πελάτη.'
            ),

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
