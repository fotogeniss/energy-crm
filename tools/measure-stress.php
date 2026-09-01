<?php
/**
 * Πραγματικό stress test -- «θέλω πραγματικά να δω πόσο αντέχει το σύστημα»,
 * 31/08/2026, μετά τη μέτρηση των 80 καταστημάτων (2.147 συμβάσεις).
 *
 * ## Τι διαφορετικό κάνει από το measure-scale80
 *
 * Το measure-scale80 έδωσε ΕΝΑ σημείο (~2.000 συμβάσεις, το σημερινό
 * σύνολο). Αυτό δίνει ΚΑΜΠΥΛΗ: σπρώχνει σταδιακά τον ίδιο πίνακα
 * `contracts` από 2.000 ως 20.000 και ξαναμετράει σε κάθε σκαλί, ώστε να
 * φανεί ΠΩΣ μεγαλώνει το κόστος -- γραμμικά (εντάξει σε κάθε κλίμακα) ή
 * χειρότερα (σημείο που κάποια στιγμή θα «σπάσει»).
 *
 * ## Μετάφραση «10.000 συμβάσεις τον μήνα» σε «σύνολο ανοιχτών»
 *
 * ΜΕΤΡΑ, ΜΗΝ ΤΟ ΥΠΟΘΕΣΕΙΣ -- εδώ γίνεται ΜΙΑ υπόθεση, ρητά: τα ερωτήματα
 * που μας ενδιαφέρουν (`escalations()`, `missing_docs_for()` κλπ.) σαρώνουν
 * `status IN (open_statuses)`, δηλαδή το ΣΥΝΟΛΟ ανοιχτών αιτήσεων ΤΗ ΔΕΔΟΜΕΝΗ
 * ΣΤΙΓΜΗ -- όχι τον ρυθμό εισροής τον μήνα. Αν μια αίτηση μένει ανοιχτή κατά
 * μέσο όρο ~1 μήνα πριν κλείσει, 10.000/μήνα εισροή σημαίνει ένα σταθερό
 * απόθεμα γύρω στις 10.000 ανοιχτές -- αυτό προσομοιώνεται εδώ. Αν ο μέσος
 * χρόνος είναι διαφορετικός, το πραγματικό απόθεμα θα είναι διαφορετικό, και
 * αυτό ΔΕΝ το ξέρουμε από τα δεδομένα -- η υπόθεση είναι εδώ γραμμένη ρητά
 * για να μπορεί να αμφισβητηθεί.
 *
 * ## Ιεραρχία -- ίδια με το measure-scale80 (1 διευθυντής -> 8 προϊστάμενοι
 * -> 80 καταστήματα), αλλά ΞΕΧΩΡΙΣΤΟ marker (`ecrm_synthstress`) ώστε τα δύο
 * script να μην μπερδεύονται μεταξύ τους ή με ό,τι έμεινε (δεν πρέπει να
 * μείνει τίποτα) από το προηγούμενο τρέξιμο.
 *
 * Τα leads/ληγμένα έγγραφα σπέρνονται ΜΙΑ φορά, στη βασική κλίμακα (ίδιοι
 * λόγοι/ποσοστά με το measure-scale80) -- το ραμπάρισμα αφορά ΜΟΝΟ τις
 * συμβάσεις, γιατί αυτό είναι που σαρώνει το company-wide escalations().
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-stress.php
 *
 * Μπορεί να πάρει αρκετά λεπτά στο πιο ψηλό σκαλί (20.000 συμβάσεις). Τυπώνει
 * πρόοδο ανά σκαλί ώστε να φαίνεται ότι δουλεύει, όχι κολλημένο.
 *
 * ΥΠΟΧΡΕΩΤΙΚΟ μετά:
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-stress-cleanup.php
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

const ECRM_STRESS_META = 'ecrm_synthstress';
const ECRM_STRESS_CODE = 'ECRM-STRESS-';
const ECRM_STRESS_MANAGERS = 8;
const ECRM_STRESS_STORES_PER_MANAGER = 10;
const ECRM_STRESS_CHECKPOINTS = [2000, 5000, 10000, 20000];

try {
    global $wpdb;

    $contractsTable = Tables::name(Tables::CONTRACTS);
    $leadsTable     = Tables::name(Tables::LEADS);
    $filesTable     = Tables::name(Tables::FILES);

    $describeRequired = static function (string $table) use ($wpdb): array {
        // phpcs:ignore WordPress.DB.PreparedSQL
        $columns  = $wpdb->get_results("DESCRIBE `{$table}`", ARRAY_A);
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

    $openStatuses   = ['new', 'pending', 'processing', 'pending_signature', 'awaiting_signature', 'routed', 'signed', 'resolved'];
    $closedStatuses = ['active', 'cancelled'];
    $escalationDays = class_exists('ECRM_Notifications') ? ECRM_Notifications::escalation_days() : 10;

    // --- ιεραρχία: φτιάχνεται μόνο αν δεν υπάρχει ήδη (idempotent, ώστε το
    // script να μπορεί να ξανατρέξει μετά από διακοπή χωρίς να διπλασιάσει
    // χρήστες) ------------------------------------------------------------

    $stores = $wpdb->get_col($wpdb->prepare(
        'SELECT user_id FROM %i WHERE meta_key = %s AND meta_value = %s',
        $wpdb->usermeta,
        ECRM_STRESS_META,
        'store'
    ));
    $stores = array_map('intval', $stores);

    if ($stores === []) {
        echo "Φτιάχνω ιεραρχία 1 διευθυντή -> 8 προϊστάμενοι -> 80 καταστήματα (marker: " . ECRM_STRESS_META . ")...\n";

        $makeUser = static function (string $tag, string $role) use ($wpdb): int {
            $userId = wp_insert_user([
                'user_login' => 'ecrmstress-' . $tag . '-' . wp_generate_password(6, false),
                'user_email' => 'ecrmstress-' . $tag . '-' . wp_generate_password(6, false) . '@ecrm-stress.test',
                'user_pass'  => wp_generate_password(20, true, true),
                'role'       => 'subscriber',
            ]);

            if (is_wp_error($userId)) {
                throw new \RuntimeException('wp_insert_user απέτυχε: ' . $userId->get_error_message());
            }

            $user = get_user_by('id', $userId);
            $user->set_role(Roles::PARTNER);
            update_user_meta($userId, ECRM_STRESS_META, $role);

            return (int) $userId;
        };

        $team     = new TeamRepository();
        $director = $makeUser('director', 'director');

        for ($m = 1; $m <= ECRM_STRESS_MANAGERS; $m++) {
            $managerId = $makeUser('mgr' . $m, 'manager');
            $team->attach($managerId, $director);

            for ($s = 1; $s <= ECRM_STRESS_STORES_PER_MANAGER; $s++) {
                $storeId = $makeUser('store' . $m . '-' . $s, 'store');
                $team->attach($storeId, $managerId);
                $stores[] = $storeId;
            }
        }

        echo "Έτοιμοι " . count($stores) . " συνεργάτες-καταστήματα.\n\n";

        // --- leads + ληγμένα έγγραφα, ΜΙΑ φορά, ίδιοι λόγοι με το
        // measure-scale80-seed.php -- δεν ραμπάρονται, μόνο οι συμβάσεις.
        $totalLeads = 0;
        foreach ($stores as $storeId) {
            $leadsForStore = random_int(3, 10);
            for ($l = 1; $l <= $leadsForStore; $l++) {
                $isOverdue = random_int(1, 100) <= 30;
                $row = $fillDefaults([
                    'partner_user_id' => $storeId,
                    'name'            => 'ECRM-STRESS Lead',
                    'phone'           => '69' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                    'stage'           => $isOverdue ? 'contacted' : 'new',
                    'callback_at'     => $isOverdue
                        ? gmdate('Y-m-d H:i:s', time() - random_int(1, 15) * DAY_IN_SECONDS)
                        : null,
                    'created_at'      => gmdate('Y-m-d H:i:s', time() - random_int(0, 90) * DAY_IN_SECONDS),
                ], $leadRequired, gmdate('Y-m-d') . ' 00:00:00');
                $wpdb->insert($leadsTable, $row);
                $totalLeads++;
            }
        }
        echo "Μπήκαν {$totalLeads} leads (μία φορά, δεν ξαναμπαίνουν στα επόμενα σκαλιά).\n\n";
    } else {
        echo "Βρέθηκε ήδη ιεραρχία " . count($stores) . " καταστημάτων (marker: " . ECRM_STRESS_META . ") -- συνεχίζω πάνω της.\n\n";
    }

    // --- μετρητής, ίδια συνάρτηση με το measure-scale80.php ---------------

    $measure = static function (string $label, callable $call) use ($wpdb): array {
        $queriesBefore = $wpdb->num_queries;
        $start         = hrtime(true);
        $result        = $call();
        $elapsedMs     = (hrtime(true) - $start) / 1_000_000;
        $queries       = $wpdb->num_queries - $queriesBefore;
        return [$elapsedMs, $queries, $result];
    };

    $report = [];

    // --- σκαλιά: σπρώχνουμε τις συμβάσεις ως το επόμενο checkpoint, μετά
    // μετράμε -- ίδια αναλογία αδράνειας/ληγμένων εγγράφων με πριν ---------

    $contractSeq = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE code LIKE %s',
        $contractsTable,
        ECRM_STRESS_CODE . '%'
    ));

    foreach (ECRM_STRESS_CHECKPOINTS as $target) {
        $toAdd = $target - $contractSeq;

        if ($toAdd > 0) {
            echo "Σπρώχνω {$contractSeq} -> {$target} συμβάσεις...\n";
            $now = time();

            for ($i = 1; $i <= $toAdd; $i++) {
                $contractSeq++;
                $storeId = $stores[$contractSeq % count($stores)];

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
                } else {
                    $updatedOffset = random_int(0, $createdOffset > 0 ? $createdOffset : 1);
                    $updatedAt     = gmdate('Y-m-d H:i:s', $now - $updatedOffset);
                }

                $row = $fillDefaults([
                    'partner_user_id' => $storeId,
                    'customer_id'     => null,
                    'status'          => $status,
                    'code'            => ECRM_STRESS_CODE . $contractSeq,
                    'supply_number'   => (string) (30000000000 + $contractSeq),
                    'energy_type'     => $contractSeq % 2 === 0 ? 'power' : 'gas',
                    'created_at'      => $createdAt,
                    'updated_at'      => $updatedAt,
                ], $contractRequired, $createdAt);

                $ok = $wpdb->insert($contractsTable, $row);

                if ($ok === false) {
                    echo "Σταμάτησα στη σύμβαση {$contractSeq}: " . $wpdb->last_error . "\n";
                    break 2;
                }

                $newContractId = (int) $wpdb->insert_id;

                if (random_int(1, 100) <= 10) {
                    $wpdb->insert($filesTable, [
                        'contract_id'   => $newContractId,
                        'attachment_id' => null,
                        'doc_kind'      => 'id_card',
                        'filename'      => 'stress-id.jpg',
                        'mime'          => 'image/jpeg',
                        'path'          => '/nonexistent/ecrm-stress/' . $newContractId . '.jpg',
                        'protected'     => 1,
                        'expires_at'    => gmdate('Y-m-d', $now - random_int(1, 400) * DAY_IN_SECONDS),
                    ]);
                }
            }
        }

        // --- μέτρηση σε αυτό το σκαλί -------------------------------------

        [$msEsc, $qEsc, $escRows] = $measure('escalations()', static function () {
            return class_exists('ECRM_Notifications') ? ECRM_Notifications::escalations() : [];
        });

        $totalEscalatedRows = 0;
        foreach ($escRows as $managerRows) {
            $totalEscalatedRows += count($managerRows);
        }

        $busiestStore = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT partner_user_id FROM `{$contractsTable}` " // phpcs:ignore
            . 'WHERE code LIKE %s GROUP BY partner_user_id ORDER BY COUNT(*) DESC LIMIT 1',
            ECRM_STRESS_CODE . '%'
        ));

        [$msMissing, $qMissing] = $measure('missing_docs_for()', static function () use ($busiestStore) {
            return class_exists('ECRM_Notifications') ? ECRM_Notifications::missing_docs_for([$busiestStore]) : [];
        });

        $report[] = [
            'total'      => $contractSeq,
            'esc_ms'     => $msEsc,
            'esc_q'      => $qEsc,
            'esc_rows'   => $totalEscalatedRows,
            'missing_ms' => $msMissing,
            'missing_q'  => $qMissing,
        ];

        printf(
            "  [%6d συμβάσεις] escalations(): %8.2f ms / %4d ερωτήματα (%d γραμμές)   missing_docs_for() [1 κατάστημα]: %7.2f ms / %3d ερωτήματα\n",
            $contractSeq,
            $msEsc,
            $qEsc,
            $totalEscalatedRows,
            $msMissing,
            $qMissing
        );
    }

    // --- verdict: γραμμικό ή χειρότερο; -------------------------------------

    echo "\n--- Καμπύλη ---\n";
    printf("%-12s %14s %10s %16s %10s\n", 'Συμβάσεις', 'escalations()', 'ερωτ.', 'missing_docs()', 'ερωτ.');
    foreach ($report as $row) {
        printf(
            "%-12d %12.2fms %10d %14.2fms %10d\n",
            $row['total'],
            $row['esc_ms'],
            $row['esc_q'],
            $row['missing_ms'],
            $row['missing_q']
        );
    }

    if (count($report) >= 2) {
        $first = $report[0];
        $last  = end($report);

        $scaleRatio = $last['total'] / max(1, $first['total']);
        $escMsRatio = $last['esc_ms'] / max(0.01, $first['esc_ms']);
        $escQRatio  = $last['esc_q'] / max(1, $first['esc_q']);

        echo "\nΑπό {$first['total']} σε {$last['total']} συμβάσεις (×" . round($scaleRatio, 1) . "):\n";
        echo "  escalations() χρόνος ×" . round($escMsRatio, 1) . ", ερωτήματα ×" . round($escQRatio, 1) . "\n";

        if ($escMsRatio > $scaleRatio * 1.5) {
            echo "  -> ΧΕΙΡΟΤΕΡΟ από γραμμικό -- αξίζει κοίταγμα πριν φτάσει η πραγματική βάση εδώ.\n";
        } else {
            echo "  -> Γραμμικό ή καλύτερο -- αντέχει σε αυτή την κλίμακα χωρίς αλλαγή.\n";
        }
    }

    echo "\nΘυμήσου να τρέξεις μετά:\n";
    echo "wp eval-file wp-content/plugins/energy-crm/tools/measure-stress-cleanup.php\n";
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
