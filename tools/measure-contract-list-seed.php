<?php
/**
 * Συνθετικά δεδομένα για τη μέτρηση του §2.2 (ORDER BY updated_at έναντι
 * created_at στη λίστα συμβάσεων) -- ΜΟΝΟ όταν ο πραγματικός πίνακας είναι
 * πολύ μικρός για να δείξει τίποτα (6 γραμμές, μετρήθηκε 29/08).
 *
 * HANDOVER §1 σημείο 6: ~2.000 ανοιχτές καρτέλες συνολικά είναι η
 * πραγματική κλίμακα παραγωγής. Αυτό το script φτιάχνει 2.500 γραμμές, όχι
 * λιγότερες, ώστε η μέτρηση να μην είναι αισιόδοξη για την πραγματική
 * κλίμακα.
 *
 * Ασφαλές να τρέξει και να διαγραφεί: κάθε γραμμή που φτιάχνει έχει
 * code='ECRM-SYNTH-<n>', ώστε το tools/measure-contract-list-cleanup.php να
 * τις βρίσκει με σιγουριά και να μην αγγίξει ΚΑΜΙΑ πραγματική γραμμή.
 *
 * Ανακαλύπτει τις υποχρεωτικές στήλες ΑΠΟ ΤΟ ΙΔΙΟ ΤΟ SCHEMA (DESCRIBE) αντί
 * να τις υποθέσει -- ο πίνακας contracts δεν έχει ένα σημείο αλήθειας εδώ
 * μέσα σε αυτό το repo (η δημιουργία του ζει σε παλιό activation SQL), οπότε
 * το σωστό «μέτρα, μη το υποθέσεις» είναι να ρωτηθεί η ίδια η βάση.
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-contract-list-seed.php
 *
 * Δεν αγγίζει ΚΑΜΙΑ υπάρχουσα γραμμή. Δεν γράφει προσωπικά δεδομένα --
 * customer_id μένει NULL, όλα τα ονόματα/διευθύνσεις είναι placeholder.
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

@ini_set('display_errors', '1'); // phpcs:ignore
error_reporting(E_ALL);

const ECRM_SYNTH_COUNT  = 2500;
const ECRM_SYNTH_PREFIX = 'ECRM-SYNTH-';

try {
    global $wpdb;

    $contracts = $wpdb->prefix . 'ecrm_contracts';

    $existing = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$contracts}` WHERE code LIKE %s", // phpcs:ignore
        ECRM_SYNTH_PREFIX . '%'
    ));

    if ($existing > 0) {
        echo "Βρέθηκαν ήδη {$existing} συνθετικές γραμμές -- δεν ξαναγράφω. Τρέξε πρώτα το cleanup αν θες φρέσκο σετ.\n";

        return;
    }

    // Ρωτάμε το ίδιο το schema ποιες στήλες ΔΕΝ επιτρέπουν NULL και δεν
    // έχουν default -- αυτές ΠΡΕΠΕΙ να γεμίσουν, ό,τι κι αν είναι.
    // phpcs:ignore WordPress.DB.PreparedSQL
    $columns = $wpdb->get_results("DESCRIBE `{$contracts}`", ARRAY_A);

    $mustFill = [];
    foreach ($columns as $col) {
        $noDefault = $col['Default'] === null && strpos((string) $col['Extra'], 'auto_increment') === false;
        $notNull   = strtoupper((string) $col['Null']) === 'NO';

        if ($notNull && $noDefault) {
            $mustFill[$col['Field']] = $col['Type'];
        }
    }

    echo 'Στήλες χωρίς default που πρέπει να γεμίσουν: ' . implode(', ', array_keys($mustFill)) . "\n";

    $realPartners = $wpdb->get_col(
        "SELECT DISTINCT partner_user_id FROM `{$contracts}` WHERE partner_user_id > 0 LIMIT 5" // phpcs:ignore
    );

    if ($realPartners === []) {
        $anyUser        = (int) $wpdb->get_var("SELECT MIN(ID) FROM `{$wpdb->users}`"); // phpcs:ignore
        $realPartners[] = $anyUser > 0 ? $anyUser : 1;
    }

    $statuses = ['draft', 'new', 'submitted', 'active', 'cancelled', 'terminated'];
    $now      = time();
    $inserted = 0;

    for ($i = 1; $i <= ECRM_SYNTH_COUNT; $i++) {
        // 70% πέφτουν σε ΕΝΑΝ partner -- προσομοιώνει τον χειρότερο
        // πραγματικό ρόλο, έναν πωλητή/διαχειριστή με πολλές γραμμές, αντί
        // για ομοιόμορφη κατανομή που δεν αντιπροσωπεύει τίποτα πραγματικό.
        $partnerId = ($i % 10 < 7) ? $realPartners[0] : $realPartners[$i % count($realPartners)];
        $status    = $statuses[$i % count($statuses)];

        // created_at και updated_at ΔΙΑΦΟΡΕΤΙΚΑ σκόπιμα -- αν ήταν ίδια, η
        // ταξινόμηση σε updated_at θα συμπεριφερόταν σαν να ταξινομούσε σε
        // created_at, και η μέτρηση θα έλεγε ψέματα.
        $createdOffset = random_int(0, 730) * DAY_IN_SECONDS;
        $updatedOffset = random_int(0, $createdOffset > 0 ? $createdOffset : 1);
        $createdAt     = gmdate('Y-m-d H:i:s', $now - $createdOffset);
        $updatedAt     = gmdate('Y-m-d H:i:s', $now - $updatedOffset);

        $row = [
            'partner_user_id' => $partnerId,
            'customer_id'     => null,
            'status'          => $status,
            'code'            => ECRM_SYNTH_PREFIX . $i,
            'supply_number'   => (string) (10000000000 + $i),
            'energy_type'     => $i % 2 === 0 ? 'power' : 'gas',
            'created_at'      => $createdAt,
            'updated_at'      => $updatedAt,
        ];

        // Ό,τι στήλη το schema απαιτεί και δεν είναι ήδη στο $row, γεμίζει
        // με ασφαλή γενική τιμή ανάλογα με τον τύπο της.
        foreach ($mustFill as $field => $type) {
            if (array_key_exists($field, $row)) {
                continue;
            }

            if (str_contains($type, 'int')) {
                $row[$field] = 0;
            } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
                $row[$field] = $createdAt;
            } else {
                $row[$field] = '';
            }
        }

        $ok = $wpdb->insert($contracts, $row);

        if ($ok === false) {
            echo "Σταμάτησα στη γραμμή {$i}: " . $wpdb->last_error . "\n";
            break;
        }

        $inserted++;
    }

    echo "Μπήκαν {$inserted} συνθετικές συμβάσεις (code LIKE '" . ECRM_SYNTH_PREFIX . "%').\n";
    echo "Τώρα τρέξε: wp eval-file wp-content/plugins/energy-crm/tools/measure-contract-list.php\n";
    echo "Μετά ΟΠΩΣΔΗΠΟΤΕ: wp eval-file wp-content/plugins/energy-crm/tools/measure-contract-list-cleanup.php\n";
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
