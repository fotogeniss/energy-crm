<?php
/**
 * Συνθετική προσομοίωση 80 καταστημάτων (συνεργατών) -- η κλίμακα που ρωτήθηκε
 * ρητά, 31/08/2026: «μπορούμε να κάνουμε προσομοίωση 80 καταστημάτων;».
 *
 * ## Γιατί τώρα, γιατί αυτή η κλίμακα
 *
 * Τα (197)-(201) πρόσθεσαν ΤΕΣΣΕΡΙΣ νέες company-wide/cross-contract
 * αναγνώσεις που δεν υπήρχαν πριν: `ECRM_Notifications::escalations()`,
 * `missing_docs_for()`, `expired_docs_for()`, `overdue_leads_for()`. Το ίδιο
 * το `escalations()` λέει ρητά στο δικό του docblock: «Γνωστό όριο... ένα
 * `NetworkRepository::uplineOf()` ανά αδρανή σύμβαση... αποδεκτό στη
 * σημερινή κλίμακα, πρώτο σημείο να μετρηθεί αν μεγαλώσει η βάση
 * συνεργατών». Αυτό το ζευγάρι seed/measure είναι ακριβώς αυτή η μέτρηση,
 * όχι εικασία.
 *
 * HANDOVER §1.6: ~2.000 ανοιχτές καρτέλες είναι η σημερινή πραγματική
 * κλίμακα παραγωγής. 80 καταστήματα × ~25 συμβάσεις έκαστο ≈ 2.000 --
 * δηλαδή αυτό το σενάριο ΔΕΝ είναι «τι θα γινόταν αν»· είναι η σημερινή
 * συνολική κλίμακα, απλωμένη σε 80 ξεχωριστούς ιδιοκτήτες αντί για όσους
 * υπάρχουν σήμερα -- ακριβώς η μορφή φορτίου που χτυπάει το company-wide
 * `escalations()` (ΕΝΑ πέρασμα σε ΟΛΗ την εταιρεία, όχι ανά συνεργάτη).
 *
 * ## Ιεραρχία
 *
 * 1 διευθυντής → 8 προϊστάμενοι (MANAGE_TEAM μέσω Roles::PARTNER) → 10
 * καταστήματα έκαστος = 80. Τριών επιπέδων επίτηδες, ίδιο σχήμα με το
 * `ContractNotificationsTest` (director → manager → owner) -- το
 * `uplineOf()` πρέπει να περπατήσει πραγματικό βάθος, όχι μόνο πλάτος.
 *
 * ## Ασφάλεια -- HANDOVER §1.7: «το site πρέπει να είναι πεντακάθαρο όταν
 * ανέβει live»
 *
 * ΚΑΘΕ γραμμή που φτιάχνει αυτό το script είναι εντοπίσιμη:
 *   - Οι 89 χρήστες (1+8+80) παίρνουν user_meta `ecrm_synth80=1` -- το ΜΟΝΟ
 *     κλειδί που κοιτάζει το cleanup, όχι το login/email (που είναι απλώς
 *     για ανθρώπινη αναγνωσιμότητα σε `wp user list`).
 *   - Κάθε σύμβαση έχει code='ECRM-SYNTH80-<n>', ίδιο μοτίβο με το
 *     tools/measure-contract-list-seed.php.
 *   - `customer_id` μένει NULL παντού -- καμία γραμμή δεν κρατά προσωπικό
 *     δεδομένο πελάτη (ΑΦΜ, όνομα, διεύθυνση). Το ίδιο loyalty με το
 *     tools/measure-contract-list-seed.php.
 *   - Τα λίγα file rows (για expired_docs_for()) δείχνουν σε path που ΔΕΝ
 *     υπάρχει στον δίσκο -- δεν γράφεται κανένα πραγματικό byte, και το
 *     tools/diagnose-orphan-documents.php ελέγχει την ΑΝΤΙΘΕΤΗ κατεύθυνση
 *     (δίσκος→βάση), οπότε αυτές οι γραμμές δεν το ενεργοποιούν.
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-scale80-seed.php
 *
 * ΥΠΟΧΡΕΩΤΙΚΟ μετά τη μέτρηση (tools/measure-scale80.php):
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-scale80-cleanup.php
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

@ini_set('display_errors', '1'); // phpcs:ignore
error_reporting(E_ALL);

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\TeamRepository;

const ECRM_SYNTH80_META    = 'ecrm_synth80';
const ECRM_SYNTH80_CODE    = 'ECRM-SYNTH80-';
const ECRM_SYNTH80_MANAGERS = 8;
const ECRM_SYNTH80_STORES_PER_MANAGER = 10;

try {
    global $wpdb;

    $existing = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE meta_key = %s',
        $wpdb->usermeta,
        ECRM_SYNTH80_META
    ));

    if ($existing > 0) {
        echo "Βρέθηκαν ήδη {$existing} συνθετικοί χρήστες -- δεν ξαναγράφω.\n";
        echo "Τρέξε πρώτα: wp eval-file wp-content/plugins/energy-crm/tools/measure-scale80-cleanup.php\n";

        return;
    }

    $contractsTable = Tables::name(Tables::CONTRACTS);
    $leadsTable     = Tables::name(Tables::LEADS);
    $filesTable     = Tables::name(Tables::FILES);

    // Ίδιο τέχνασμα με το measure-contract-list-seed.php: ρωτάμε το ίδιο το
    // schema ποιες στήλες δεν επιτρέπουν NULL και δεν έχουν default, αντί
    // να τις υποθέσουμε -- ο πίνακας contracts δεν έχει ένα σημείο αλήθειας
    // μέσα σε αυτό το repo.
    $describeRequired = static function (string $table) use ($wpdb): array {
        // phpcs:ignore WordPress.DB.PreparedSQL
        $columns = $wpdb->get_results("DESCRIBE `{$table}`", ARRAY_A);
        $mustFill = [];
        foreach ($columns as $col) {
            $noDefault = $col['Default'] === null && strpos((string) $col['Extra'], 'auto_increment') === false;
            $notNull   = strtoupper((string) $col['Null']) === 'NO';
            if ($notNull && $noDefault) {
                $mustFill[$col['Field']] = $col['Type'];
            }
        }
        return $mustFill;
    };

    $fillDefaults = static function (array $row, array $mustFill, string $fallbackDate): array {
        foreach ($mustFill as $field => $type) {
            if (array_key_exists($field, $row)) {
                continue;
            }
            if (str_contains($type, 'int')) {
                $row[$field] = 0;
            } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
                $row[$field] = $fallbackDate;
            } else {
                $row[$field] = '';
            }
        }
        return $row;
    };

    $contractRequired = $describeRequired($contractsTable);
    $leadRequired      = $describeRequired($leadsTable);

    // --- ιεραρχία: 1 διευθυντής, 8 προϊστάμενοι, 80 καταστήματα ------------

    $makeUser = static function (string $tag) use ($wpdb): int {
        $userId = wp_insert_user([
            'user_login' => 'ecrmsynth80-' . $tag . '-' . wp_generate_password(6, false),
            'user_email' => 'ecrmsynth80-' . $tag . '-' . wp_generate_password(6, false) . '@ecrm-synth80.test',
            'user_pass'  => wp_generate_password(20, true, true),
            'role'       => 'subscriber',
        ]);

        if (is_wp_error($userId)) {
            throw new \RuntimeException('wp_insert_user απέτυχε: ' . $userId->get_error_message());
        }

        $user = get_user_by('id', $userId);
        $user->set_role(Roles::PARTNER);

        // Το ΜΟΝΟ κλειδί που κοιτάζει το cleanup -- ό,τι κι αν αλλάξει το
        // login/email format αργότερα, αυτό μένει το σημείο αλήθειας.
        update_user_meta($userId, ECRM_SYNTH80_META, '1');

        return (int) $userId;
    };

    echo "Φτιάχνω ιεραρχία 1 διευθυντή -> 8 προϊστάμενοι -> 80 καταστήματα...\n";

    $director = $makeUser('director');

    $team    = new TeamRepository();
    $stores  = [];
    $managers = [];

    for ($m = 1; $m <= ECRM_SYNTH80_MANAGERS; $m++) {
        $managerId = $makeUser('mgr' . $m);
        $team->attach($managerId, $director);
        $managers[] = $managerId;

        for ($s = 1; $s <= ECRM_SYNTH80_STORES_PER_MANAGER; $s++) {
            $storeId = $makeUser('store' . $m . '-' . $s);
            $team->attach($storeId, $managerId);
            $stores[] = $storeId;
        }
    }

    $storeCount = count($stores);
    echo "Έτοιμοι {$storeCount} συνεργάτες-καταστήματα κάτω από " . count($managers) . " προϊσταμένους.\n\n";

    // --- συμβάσεις + leads ανά κατάστημα ------------------------------------

    $openStatuses   = ['new', 'pending', 'processing', 'pending_signature', 'awaiting_signature', 'routed', 'signed', 'resolved'];
    $closedStatuses = ['active', 'cancelled'];
    $now            = time();
    $today          = gmdate('Y-m-d');

    $escalationDays = class_exists('ECRM_Notifications') ? ECRM_Notifications::escalation_days() : 10;

    $contractSeq   = 0;
    $totalContracts = 0;
    $totalStale     = 0;
    $totalExpiredDocs = 0;
    $totalLeads     = 0;
    $totalOverdueLeads = 0;

    foreach ($stores as $storeId) {
        $contractsForStore = random_int(15, 35);

        for ($c = 1; $c <= $contractsForStore; $c++) {
            $contractSeq++;

            // 15% του καταστήματος πέφτει σε αδράνεια πέρα από το
            // escalation_days() -- αυτό είναι το φορτίο που μετράει το
            // company-wide escalations().
            $isStale = random_int(1, 100) <= 15;
            $status  = $isStale
                ? $openStatuses[array_rand($openStatuses)]
                : (random_int(1, 100) <= 70
                    ? $openStatuses[array_rand($openStatuses)]
                    : $closedStatuses[array_rand($closedStatuses)]);

            $createdOffset = random_int(0, 365) * DAY_IN_SECONDS;
            $createdAt     = gmdate('Y-m-d H:i:s', $now - $createdOffset);

            if ($isStale && in_array($status, $openStatuses, true)) {
                $staleDays = $escalationDays + random_int(1, 20);
                $updatedAt = gmdate('Y-m-d H:i:s', $now - $staleDays * DAY_IN_SECONDS);
                $totalStale++;
            } else {
                $updatedOffset = random_int(0, $createdOffset > 0 ? $createdOffset : 1);
                $updatedAt     = gmdate('Y-m-d H:i:s', $now - $updatedOffset);
            }

            $row = $fillDefaults([
                'partner_user_id' => $storeId,
                'customer_id'     => null,
                'status'          => $status,
                'code'            => ECRM_SYNTH80_CODE . $contractSeq,
                'supply_number'   => (string) (20000000000 + $contractSeq),
                'energy_type'     => $contractSeq % 2 === 0 ? 'power' : 'gas',
                'created_at'      => $createdAt,
                'updated_at'      => $updatedAt,
            ], $contractRequired, $createdAt);

            $ok = $wpdb->insert($contractsTable, $row);

            if ($ok === false) {
                echo "Σταμάτησα στη σύμβαση {$contractSeq}: " . $wpdb->last_error . "\n";
                break 2;
            }

            $totalContracts++;
            $newContractId = (int) $wpdb->insert_id;

            // ~10% των συμβάσεων παίρνει ΛΗΓΜΕΝΗ ταυτότητα -- τροφοδοτεί το
            // expired_docs_for(). Το path ΔΕΝ υπάρχει στον δίσκο σκόπιμα
            // (βλ. docblock πιο πάνω) -- η μέτρηση θέλει τη γραμμή της
            // βάσης, όχι πραγματικά bytes.
            if (random_int(1, 100) <= 10) {
                $wpdb->insert($filesTable, [
                    'contract_id'   => $newContractId,
                    'attachment_id' => null,
                    'doc_kind'      => 'id_card',
                    'filename'      => 'synth80-id.jpg',
                    'mime'          => 'image/jpeg',
                    'path'          => '/nonexistent/ecrm-synth80/' . $newContractId . '.jpg',
                    'protected'     => 1,
                    'expires_at'    => gmdate('Y-m-d', $now - random_int(1, 400) * DAY_IN_SECONDS),
                ]);
                $totalExpiredDocs++;
            }
        }

        // --- leads, μερικά με περασμένο ραντεβού -------------------------
        $leadsForStore = random_int(3, 10);
        for ($l = 1; $l <= $leadsForStore; $l++) {
            $isOverdue = random_int(1, 100) <= 30;

            $row = $fillDefaults([
                'partner_user_id' => $storeId,
                'name'            => 'ECRM-SYNTH80 Lead',
                'phone'           => '69' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                'stage'           => $isOverdue ? 'contacted' : 'new',
                'callback_at'     => $isOverdue
                    ? gmdate('Y-m-d H:i:s', $now - random_int(1, 15) * DAY_IN_SECONDS)
                    : null,
                'created_at'      => gmdate('Y-m-d H:i:s', $now - random_int(0, 90) * DAY_IN_SECONDS),
            ], $leadRequired, $today . ' 00:00:00');

            $wpdb->insert($leadsTable, $row);
            $totalLeads++;
            if ($isOverdue) {
                $totalOverdueLeads++;
            }
        }
    }

    echo "Μπήκαν {$totalContracts} συμβάσεις (code LIKE '" . ECRM_SYNTH80_CODE . "%'), εκ των οποίων {$totalStale} αδρανείς πέρα από escalation_days()={$escalationDays}.\n";
    echo "Μπήκαν {$totalExpiredDocs} ληγμένα έγγραφα ταυτότητας.\n";
    echo "Μπήκαν {$totalLeads} leads, εκ των οποίων {$totalOverdueLeads} με περασμένο ραντεβού.\n\n";

    echo "Τώρα τρέξε: wp eval-file wp-content/plugins/energy-crm/tools/measure-scale80.php\n";
    echo "Μετά ΟΠΩΣΔΗΠΟΤΕ: wp eval-file wp-content/plugins/energy-crm/tools/measure-scale80-cleanup.php\n";
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
