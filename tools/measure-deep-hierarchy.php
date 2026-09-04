<?php
/**
 * Κλείνει ένα ανοιχτό εύρημα: «22 εξαντλήσεις μνήμης 256 MB, όλες στο
 * src/Access/NetworkPath.php, 4-5 Αυγούστου -- δεν έχει ξαναφανεί σε δέκα
 * μέρες, αλλά δεν έχει δοκιμαστεί με ιεραρχία πολλών επιπέδων» (HANDOVER.md,
 * γραμμένο ρητά ως «test με βαθύ δίκτυο πριν πάει ζωντανά»). Οι εξαντλήσεις
 * ήταν ΠΡΙΝ την αναδιάρθρωση σε materialized path (Βήμα 3) -- αυτό εδώ
 * δοκιμάζει τη ΣΗΜΕΡΙΝΗ υλοποίηση, όχι εκείνη που έσπαγε.
 *
 * Διάβασμα του κώδικα δείχνει ΓΙΑΤΙ θα έπρεπε να είναι ασφαλές τώρα:
 * NetworkRepository::computePath() είναι φραγμένος βρόχος (MAX_DEPTH=50, όχι
 * αναδρομή), και NetworkRepository::uplineOf()/pathFor() διαβάζουν ΜΙΑ ήδη
 * αποθηκευμένη τιμή -- καμία κλήση ανά πρόγονο. Το ΜΟΝΟ σημείο με κόστος
 * ανάλογο του βάθους είναι το computePath() μέσα στο rebuild(), που καλείται
 * ΚΑΘΕ φορά που αλλάζει ένα ecrm_parent (NetworkSync). Αυτό το script το
 * μετρά, αντί να το υποθέτει.
 *
 * Χτίζει ΜΙΑ αλυσίδα 50 λογαριασμών (49 ζεύγη γονιού-παιδιού, ελάχιστο κάτω
 * από το MAX_DEPTH=50) -- επίτηδες γραμμική, όχι πλατιά: το πλάτος (πολλοί
 * πωλητές ανά κατάστημα) ήδη μετρήθηκε στο measure-realistic.php. Εδώ το
 * ζητούμενο είναι ΜΟΝΟ το βάθος.
 *
 * Marker: user_meta ecrm_synthdeep = '1' (ξεχωριστό από όλα τα άλλα synth
 * markers -- δεν μπερδεύεται, δεν καθαρίζει τα άλλα).
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-deep-hierarchy.php
 *
 * Καθάρισμα:
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-deep-hierarchy-cleanup.php
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Persistence\TeamRepository;

const ECRM_DEEP_META  = 'ecrm_synthdeep';
const ECRM_DEEP_CHAIN = 49; // ζεύγη γονιού-παιδιού· 50 λογαριασμοί συνολικά, κάτω από MAX_DEPTH=50.

try {
    global $wpdb;

    $measure = static function (string $label, callable $call) use ($wpdb): array {
        $queriesBefore = $wpdb->num_queries;
        $memBefore     = memory_get_usage();
        $start         = hrtime(true);

        $result = $call();

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $queries   = $wpdb->num_queries - $queriesBefore;
        $memKb     = (memory_get_usage() - $memBefore) / 1024;

        printf("%-32s %8.2f ms   %5d ερωτήματα   %8.1f KB\n", $label, $elapsedMs, $queries, $memKb);

        return [$elapsedMs, $queries, $result];
    };

    $existing = $wpdb->get_col($wpdb->prepare(
        'SELECT user_id FROM %i WHERE meta_key = %s',
        $wpdb->usermeta,
        ECRM_DEEP_META
    ));

    if ($existing !== []) {
        echo count($existing) . " λογαριασμοί αλυσίδας ήδη υπάρχουν -- προχωρώ κατευθείαν στη μέτρηση.\n\n";
        $chainIds = array_map('intval', $existing);
        // Η σειρά χρειάζεται να είναι root-πρώτα για τη γραμμή "χτίσιμο" παρακάτω,
        // αλλά αφού δεν ξαναχτίζουμε εδώ, αρκεί το τελευταίο (φύλλο) για τη μέτρηση.
        sort($chainIds);
    } else {
        echo 'Χτίζω αλυσίδα ' . (ECRM_DEEP_CHAIN + 1) . " λογαριασμών (βάθος {$ECRM_DEEP_CHAIN})...\n\n";

        $makeUser = static function (int $depth): int {
            $login  = 'ecrm-deep-' . $depth . '-' . wp_generate_password(6, false);
            $userId = wp_insert_user([
                'user_login' => $login,
                'user_pass'  => wp_generate_password(24, true, true),
                'user_email' => $login . '@example.invalid',
                'role'       => 'subscriber',
            ]);

            if (is_wp_error($userId)) {
                throw new RuntimeException('wp_insert_user: ' . $userId->get_error_message());
            }

            (new WP_User($userId))->set_role(Roles::PARTNER);
            update_user_meta($userId, ECRM_DEEP_META, '1');

            return $userId;
        };

        $chainIds   = [$makeUser(0)];
        $team       = new TeamRepository();
        $lastMs     = 0.0;
        $lastQueries = 0;

        for ($depth = 1; $depth <= ECRM_DEEP_CHAIN; $depth++) {
            $childId = $makeUser($depth);

            // Το attach() γράφει το ecrm_parent meta, που ενεργοποιεί ΑΜΕΣΩΣ το
            // NetworkSync -> NetworkRepository::rebuild() -- ίδιο μονοπάτι με ένα
            // πραγματικό reorg μέσα από την οθόνη Ομάδα. Μετράμε ΤΟ ΤΕΛΕΥΤΑΙΟ
            // βήμα ξεχωριστά παρακάτω (το χειρότερο σενάριο, βάθος 49).
            [$lastMs, $lastQueries] = $measure(
                "attach() βάθος {$depth}",
                static function () use ($team, $childId, $chainIds) {
                    $team->attach($childId, end($chainIds));
                    return null;
                }
            );

            $chainIds[] = $childId;

            if ($depth % 10 === 0) {
                echo "  ...{$depth}/" . ECRM_DEEP_CHAIN . "\n";
            }
        }

        echo "\nΈτοιμη αλυσίδα " . count($chainIds) . " λογαριασμών.\n\n";
    }

    $rootId = $chainIds[0];
    $leafId = end($chainIds);

    echo "=== Μετρήσεις στο ήδη χτισμένο, ολοκληρωμένο δίκτυο ===\n\n";

    // 1) Ξαναχτίζει το path του φύλλου από την αρχή -- το ίδιο computePath()
    //    που τρέχει σε ΚΑΘΕ αλλαγή ecrm_parent, στο χειρότερο βάθος.
    $network = new NetworkRepository();
    $measure('rebuild() στο φύλλο (βάθος 49)', static function () use ($network, $leafId) {
        return $network->rebuild($leafId);
    });

    // 2) Ανάγνωση: πρέπει να είναι 1 ερώτημα ΑΝΕΞΑΡΤΗΤΑ από το βάθος --
    //    διαβάζει το ήδη αποθηκευμένο path, δεν ξαναπερπατά τίποτα.
    $measure('uplineOf() στο φύλλο', static function () use ($network, $leafId) {
        return $network->uplineOf($leafId);
    });

    // 3) subtreeIds() από τη ρίζα -- ΕΝΑ prefix query, ανεξάρτητα από βάθος.
    $measure('subtreeIds() από τη ρίζα', static function () use ($network, $rootId) {
        return $network->subtreeIds($rootId);
    });

    // 4) escalations() company-wide -- το πιο ακριβό read που εξαρτάται
    //    έμμεσα από το network, ίδιο ερώτημα με το measure-scale80.php.
    $measure('escalations() (όλη η εταιρεία)', static function () {
        return class_exists('ECRM_Notifications') ? ECRM_Notifications::escalations() : [];
    });

    $upline = $network->uplineOf($leafId);

    echo "\nΈλεγχος ορθότητας: uplineOf(φύλλο) έχει " . count($upline) . " πρόγονο(υς)"
        . ' (αναμενόμενο ' . ECRM_DEEP_CHAIN . ").\n";
    echo (count($upline) === ECRM_DEEP_CHAIN ? "  [ΟΚ]\n" : "  [ΠΡΟΣΟΧΗ] Δεν ταιριάζει -- δες το MAX_DEPTH guard.\n");

    echo "\nΚορυφή μνήμης PHP σε όλο το script: "
        . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB "
        . "(memory_limit: " . ini_get('memory_limit') . ").\n";

    echo "\nΕπόμενο: wp eval-file wp-content/plugins/energy-crm/tools/measure-deep-hierarchy-cleanup.php\n";
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
