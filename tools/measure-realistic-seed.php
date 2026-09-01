<?php
/**
 * Ρεαλιστική ιεραρχία, όπως τη διόρθωσε ο ιδιοκτήτης στις 31/08:
 *
 *     100 καταστήματα (Συνεργάτες, ρόλος ecrm_partner)
 *        -> 10 πωλητές έκαστο (Πωλητές, ρόλος ecrm_seller)
 *           -> 1.000 πωλητές συνολικά
 *     = 1.100 λογαριασμοί συνολικά.
 *
 * Διαφορά από το measure-scale80-seed.php: εκείνο είχε 1 διευθυντή + 8
 * προϊστάμενους + 80 καταστήματα, ΧΩΡΙΣ κανέναν πωλητή -- οι συμβάσεις
 * ανήκαν στους ίδιους τους ιδιοκτήτες καταστήματος. Ο ιδιοκτήτης διόρθωσε
 * ρητά αυτό το σχήμα: στην πραγματικότητα υπάρχουν πωλητές κάτω από κάθε
 * κατάστημα, και αυτοί δημιουργούν τις συμβάσεις -- όχι το κατάστημα.
 *
 * Δεν υπάρχει ξεχωριστό επίπεδο "διευθυντή" εδώ: κάθε κατάστημα ΕΙΝΑΙ ο
 * προϊστάμενος των δικών του πωλητών (attach μέσω ecrm_parent), όπως στην
 * TeamController::routes() -- MANAGE_TEAM το έχει ο Συνεργάτης, όχι ξεχωριστός
 * ρόλος. Αυτό ταιριάζει ακριβώς με το διάγραμμα του ιδιοκτήτη.
 *
 * Οι συμβάσεις ανήκουν στους ΠΩΛΗΤΕΣ (partner_user_id = seller id), όχι στο
 * κατάστημα -- έτσι δημιουργεί συμβάσεις η πλατφόρμα στην πραγματικότητα.
 * Αυτό είναι διαφορετικό, μετρήσιμα σημαντικό σχήμα για το escalations():
 * το uplineOf() ενός πωλητή περνάει τώρα ένα επιπλέον σκαλί (seller ->
 * store) πριν φτάσει σε κάποιον με MANAGE_TEAM.
 *
 * Ο αριθμός συμβάσεων δίνεται σαν όρισμα (checkpoint) -- αυτό το script
 * ΔΕΝ μετράει τίποτα, μόνο χτίζει την ιεραρχία μία φορά και σπρώχνει τις
 * συμβάσεις μέχρι τον στόχο. Το τρέχει το measure-realistic.php.
 *
 * Marker: user_meta ecrm_synthreal = 'store' | 'seller' (ξεχωριστό από
 * ecrm_synth80 και ecrm_synthstress -- τα τρία τεστ δεν μπερδεύονται ποτέ
 * μεταξύ τους, και το καθένα καθαρίζει μόνο το δικό του marker).
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-realistic-seed.php [target]
 *
 * target: πόσες συνθετικές συμβάσεις να υπάρχουν συνολικά μετά το τρέξιμο
 * (default 4000 -- το κάτω άκρο του 4.000-10.000/μήνα που ζήτησε ο
 * ιδιοκτήτης). Μπορεί να ξανατρέξει με μεγαλύτερο target για να ανεβάσει
 * τον αριθμό -- η ιεραρχία χτίζεται μόνο την πρώτη φορά (idempotent),
 * μετά προσθέτει μόνο συμβάσεις.
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή> [target]\n";
    return;
}

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\TeamRepository;
use EnergyCRM\Persistence\Tables;

const ECRM_REAL_META    = 'ecrm_synthreal';
const ECRM_REAL_CODE    = 'ECRM-REAL-';
const ECRM_REAL_STORES  = 100;
const ECRM_REAL_SELLERS_PER_STORE = 10;

try {
    global $wpdb;

    // Το `wp eval-file <path> [<arg>...]` δίνει τα επιπλέον ορίσματα στο
    // εκτελούμενο αρχείο μέσω της μεταβλητής $args (τεκμηριωμένη συμπεριφορά
    // του eval-file command, όχι $argv).
    $target = isset($args[0]) ? max(1, (int) $args[0]) : 4000;

    $describeRequired = static function (string $table) use ($wpdb): array {
        $columns = $wpdb->get_results("DESCRIBE `{$table}`"); // phpcs:ignore
        $required = [];
        foreach ($columns as $col) {
            if ($col->Null === 'NO' && $col->Default === null && stripos($col->Extra, 'auto_increment') === false) {
                $required[$col->Field] = $col->Type;
            }
        }
        return $required;
    };

    $fillDefaults = static function (array $required, array $given) {
        foreach ($required as $field => $type) {
            if (array_key_exists($field, $given)) {
                continue;
            }
            if (str_contains($type, 'int')) {
                $given[$field] = 0;
            } elseif (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
                $given[$field] = current_time('mysql');
            } else {
                $given[$field] = '';
            }
        }
        return $given;
    };

    $team = new TeamRepository();

    $existingStores = $wpdb->get_col($wpdb->prepare(
        'SELECT user_id FROM %i WHERE meta_key = %s AND meta_value = %s',
        $wpdb->usermeta,
        ECRM_REAL_META,
        'store'
    ));

    if ($existingStores === []) {
        echo "Χτίζω την ιεραρχία: " . ECRM_REAL_STORES . " καταστήματα x "
            . ECRM_REAL_SELLERS_PER_STORE . " πωλητές...\n";

        $makeUser = static function (string $tag, string $role, string $marker) {
            $login = 'ecrm-real-' . $tag . '-' . wp_generate_password(6, false);
            $userId = wp_insert_user([
                'user_login' => $login,
                'user_pass'  => wp_generate_password(24, true, true),
                'user_email' => $login . '@example.invalid',
                'role'       => 'subscriber',
            ]);

            if (is_wp_error($userId)) {
                throw new RuntimeException('wp_insert_user: ' . $userId->get_error_message());
            }

            $user = new WP_User($userId);
            $user->set_role($role);
            update_user_meta($userId, ECRM_REAL_META, $marker);

            return $userId;
        };

        $storeIds = [];
        for ($s = 1; $s <= ECRM_REAL_STORES; $s++) {
            $storeId = $makeUser('store' . $s, Roles::PARTNER, 'store');
            $storeIds[] = $storeId;

            for ($p = 1; $p <= ECRM_REAL_SELLERS_PER_STORE; $p++) {
                $sellerId = $makeUser('seller' . $s . '-' . $p, Roles::SELLER, 'seller');
                $team->attach($sellerId, $storeId);
            }

            if ($s % 20 === 0) {
                echo "  ...{$s}/" . ECRM_REAL_STORES . " καταστήματα\n";
            }
        }

        echo "Έτοιμα " . count($storeIds) . " καταστήματα, "
            . (count($storeIds) * ECRM_REAL_SELLERS_PER_STORE) . " πωλητές.\n\n";
    } else {
        echo count($existingStores) . " καταστήματα ήδη υπάρχουν -- προσθέτω μόνο συμβάσεις.\n\n";
    }

    $sellerIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
        'SELECT user_id FROM %i WHERE meta_key = %s AND meta_value = %s',
        $wpdb->usermeta,
        ECRM_REAL_META,
        'seller'
    )));

    if ($sellerIds === []) {
        echo "ΣΦΑΛΜΑ: δεν βρέθηκαν συνθετικοί πωλητές μετά το χτίσιμο.\n";
        return;
    }

    $contracts = Tables::name(Tables::CONTRACTS);
    $files     = Tables::name(Tables::FILES);
    $leads     = Tables::name(Tables::LEADS);

    $currentCount = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE code LIKE %s',
        $contracts,
        ECRM_REAL_CODE . '%'
    ));

    $toAdd = $target - $currentCount;

    if ($toAdd <= 0) {
        echo "Ήδη {$currentCount} συνθετικές συμβάσεις -- στόχος {$target} έχει καλυφθεί.\n";
        return;
    }

    echo "Έχω {$currentCount}, προσθέτω {$toAdd} για να φτάσω τον στόχο {$target}...\n";

    $contractRequired = $describeRequired($contracts);
    $fileRequired     = $describeRequired($files);
    $leadRequired     = $describeRequired($leads);

    $escalationDays = class_exists('ECRM_Notifications') ? ECRM_Notifications::escalation_days() : 10;
    $openStatuses   = ['new', 'pending', 'processing', 'pending_signature', 'routed', 'signed'];

    $seq = $currentCount;
    $seedSellersLeads = ($currentCount === 0);
    $filesInsertErrorShown = false;

    for ($i = 1; $i <= $toAdd; $i++) {
        $seq++;
        $sellerId = $sellerIds[$seq % count($sellerIds)];
        $stale    = (random_int(1, 100) <= 15);
        $status   = $stale || random_int(1, 100) <= 70
            ? $openStatuses[array_rand($openStatuses)]
            : 'closed';

        $updatedAt = $stale
            ? gmdate('Y-m-d H:i:s', time() - (($escalationDays + random_int(1, 20)) * DAY_IN_SECONDS))
            : current_time('mysql');

        $row = $fillDefaults($contractRequired, [
            'code'             => ECRM_REAL_CODE . $seq,
            'partner_user_id'  => $sellerId,
            'customer_id'      => null,
            'status'           => $status,
            'created_at'       => $updatedAt,
            'updated_at'       => $updatedAt,
        ]);

        $wpdb->insert($contracts, $row); // phpcs:ignore
        $contractId = (int) $wpdb->insert_id;

        if ($contractId && random_int(1, 100) <= 10) {
            // doc_kind, ΟΧΙ doc_type -- πραγματικό όνομα στήλης (includes/class-ecrm-db.php).
            // expires_at προστέθηκε αργότερα από το migration AddFileExpiryColumn
            // (src/Persistence/Schema/Migrations/), γι' αυτό δεν φαίνεται στο αρχικό
            // dbDelta -- επιβεβαιώθηκε ότι υπάρχει σε αυτή την εγκατάσταση πριν
            // χρησιμοποιηθεί εδώ (FileRepository/ECRM_Notifications το διαβάζουν ήδη).
            $fileRow = $fillDefaults($fileRequired, [
                'contract_id' => $contractId,
                'doc_kind'    => 'id_card',
                'path'        => '/nonexistent/ecrm-real-' . $seq . '.pdf',
                'expires_at'  => gmdate('Y-m-d', time() - random_int(1, 60) * DAY_IN_SECONDS),
                'created_at'  => current_time('mysql'),
            ]);
            $inserted = $wpdb->insert($files, $fileRow); // phpcs:ignore

            if ($inserted === false && $filesInsertErrorShown === false) {
                echo "  ΠΡΟΣΟΧΗ: αποτυχία εισαγωγής files row -- " . $wpdb->last_error . "\n";
                $filesInsertErrorShown = true;
            }
        }

        if ($i % 500 === 0) {
            echo "  ...{$i}/{$toAdd} συμβάσεις\n";
        }
    }

    if ($seedSellersLeads) {
        echo "\nΣπέρνω leads (μία φορά, ανά πωλητή)...\n";
        $leadsInserted = 0;
        $overdue = 0;
        foreach ($sellerIds as $sellerId) {
            $n = random_int(1, 4);
            for ($l = 0; $l < $n; $l++) {
                $isOverdue = random_int(1, 100) <= 30;
                $row = $fillDefaults($leadRequired, [
                    'partner_user_id' => $sellerId,
                    'stage'           => 'contacted',
                    'callback_at'     => $isOverdue
                        ? gmdate('Y-m-d H:i:s', time() - random_int(1, 15) * DAY_IN_SECONDS)
                        : gmdate('Y-m-d H:i:s', time() + random_int(1, 15) * DAY_IN_SECONDS),
                    'created_at'      => current_time('mysql'),
                ]);
                $wpdb->insert($leads, $row); // phpcs:ignore
                $leadsInserted++;
                if ($isOverdue) {
                    $overdue++;
                }
            }
        }
        echo "Μπήκαν {$leadsInserted} leads, εκ των οποίων {$overdue} με περασμένο ραντεβού.\n";
    }

    $finalCount = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE code LIKE %s',
        $contracts,
        ECRM_REAL_CODE . '%'
    ));

    echo "\nΣύνολο τώρα: {$finalCount} συνθετικές συμβάσεις.\n";
    echo "Επόμενο: wp eval-file wp-content/plugins/energy-crm/tools/measure-realistic.php\n";
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
