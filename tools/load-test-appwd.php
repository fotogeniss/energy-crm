<?php
/**
 * Φτιάχνει Application Passwords για μερικούς συνθετικούς λογαριασμούς, ώστε
 * το tools/load-test-concurrency.js να μπορεί να κάνει πραγματικά,
 * αυθεντικοποιημένα HTTP requests -- όχι μέσω cookie/nonce (αυτά είναι
 * φτιαγμένα για browser session, όχι για ένα script που ανοίγει 50
 * ταυτόχυνες συνδέσεις), αλλά μέσω Basic Auth, που ο πυρήνας του WordPress
 * καταλαβαίνει από μόνος του (wp_authenticate_application_password) χωρίς
 * καμία αλλαγή στο plugin.
 *
 * ΠΡΟΫΠΟΘΕΤΕΙ ήδη σπαρμένη ιεραρχία: τρέξε πρώτα
 * measure-realistic-seed.php. Παίρνει το πρώτο κατάστημα και τους 10
 * πωλητές του (11 λογαριασμοί) -- αρκετό για να χτυπήσει και τα δύο
 * endpoints (/dashboard ως πωλητής, /team/escalations ως κατάστημα/
 * "προϊστάμενος") υπό πίεση, χωρίς να φτιάχνει 1.100 application passwords
 * που κανείς δεν θα χρησιμοποιήσει.
 *
 * Το αρχείο διαπιστευτηρίων ΔΕΝ μπαίνει ποτέ σε git (θα προστεθεί στο
 * .gitignore αν δεν είναι ήδη) και διαγράφεται από το
 * measure-realistic-cleanup.php.
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/load-test-appwd.php
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

if (! class_exists('WP_Application_Passwords')) {
    echo "ΣΦΑΛΜΑ: Application Passwords δεν είναι διαθέσιμα σε αυτή την εγκατάσταση ";
    echo "(WordPress < 5.6, ή απενεργοποιημένα μέσω wp_is_application_passwords_available filter).\n";
    return;
}

try {
    global $wpdb;

    $storeId = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT user_id FROM %i WHERE meta_key = %s AND meta_value = %s ORDER BY user_id ASC LIMIT 1',
        $wpdb->usermeta,
        'ecrm_synthreal',
        'store'
    ));

    if (! $storeId) {
        echo "Δεν βρέθηκε ιεραρχία -- τρέξε πρώτα:\n";
        echo "wp eval-file wp-content/plugins/energy-crm/tools/measure-realistic-seed.php [target]\n";
        return;
    }

    $sellerIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
        'SELECT user_id FROM %i WHERE meta_key = %s AND meta_value = %d',
        $wpdb->usermeta,
        'ecrm_parent',
        $storeId
    )));

    $accountIds = array_merge([$storeId], $sellerIds);

    $credentials = [];
    foreach ($accountIds as $userId) {
        $user = get_user_by('id', $userId);
        if (! $user) {
            continue;
        }

        $created = WP_Application_Passwords::create_new_application_password($userId, [
            'name' => 'ecrm-load-test-' . wp_generate_password(4, false),
        ]);

        if (is_wp_error($created)) {
            echo "  ΣΦΑΛΜΑ για user {$userId}: " . $created->get_error_message() . "\n";
            continue;
        }

        [$plaintextPassword] = $created;

        $credentials[] = [
            'user_id'  => $userId,
            'login'    => $user->user_login,
            'password' => $plaintextPassword,
            'role'     => (string) get_user_meta($userId, 'ecrm_synthreal', true),
        ];
    }

    $out = __DIR__ . '/.load-test-credentials.json';
    file_put_contents($out, wp_json_encode([
        'site_url'    => home_url('/'),
        'rest_url'    => rest_url('ecrm/v1/'),
        'store_id'    => $storeId,
        'credentials' => $credentials,
    ], JSON_PRETTY_PRINT));

    echo "Έτοιμα " . count($credentials) . " Application Passwords (1 κατάστημα + " . count($sellerIds) . " πωλητές).\n";
    echo "Γράφτηκαν στο: tools/.load-test-credentials.json (ΔΕΝ μπαίνει σε git)\n\n";
    echo "site_url: " . home_url('/') . "\n";
    echo "rest_url: " . rest_url('ecrm/v1/') . "\n\n";
    echo "Επόμενο (από cmd, ΟΧΙ wp eval-file -- αυτό είναι Node.js):\n";
    echo "  node tools/load-test-concurrency.js\n";

    $gitignore = dirname(__DIR__) . '/.gitignore';
    $marker    = 'tools/.load-test-credentials.json';
    if (file_exists($gitignore) && ! str_contains((string) file_get_contents($gitignore), $marker)) {
        file_put_contents($gitignore, "\n{$marker}\n", FILE_APPEND);
        echo "\nΠροστέθηκε στο .gitignore: {$marker}\n";
    }
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
