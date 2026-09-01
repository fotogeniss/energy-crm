<?php
/**
 * Τι ακριβώς περιορίζει την ταυτοχρονία -- δεν το υποθέτουμε, το διαβάζουμε.
 *
 * Το (204) μέτρησε throughput κολλημένο στα ~15 req/s (/dashboard) και
 * ~5-6 req/s (/team/escalations) ανεξάρτητα από ταυτοχρονία, ΜΕΤΑ την
 * αύξηση pm.max_children (2->16) και innodb_buffer_pool_size (32M->1024M)
 * -- καμία από τις δύο δεν το άλλαξε. Αυτό εδώ διαβάζει τις υπόλοιπες
 * ύποπτες ρυθμίσεις απευθείας από το τρέχον PHP/MySQL process αντί να
 * μαντεύουμε από αρχεία .hbs (που είναι απλά templates, όχι απαραίτητα
 * αυτό που τρέχει τώρα).
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/diagnose-concurrency-limits.php
 *
 * Δεν γράφει τίποτα στη βάση.
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

echo "--- PHP ---\n";
printf("%-28s %s\n", 'Xdebug φορτωμένο:', extension_loaded('xdebug') ? 'ΝΑΙ' : 'όχι');
if (extension_loaded('xdebug') && function_exists('xdebug_info')) {
    printf("%-28s %s\n", 'xdebug.mode:', ini_get('xdebug.mode') ?: '(άγνωστο)');
}
printf("%-28s %s\n", 'OPcache ενεργό:', (function_exists('opcache_get_status') && opcache_get_status(false)) ? 'ΝΑΙ' : 'όχι/όχι διαθέσιμο');
printf("%-28s %s\n", 'memory_limit:', ini_get('memory_limit'));
printf("%-28s %s\n", 'max_execution_time:', ini_get('max_execution_time'));
printf("%-28s %s\n", 'NUMBER_OF_PROCESSORS (env):', getenv('NUMBER_OF_PROCESSORS') ?: '(δεν εκτέθηκε στο PHP process)');
printf("%-28s %s\n", 'PHP SAPI:', PHP_SAPI);
printf("%-28s %s\n", 'PHP version:', PHP_VERSION);

echo "\n--- MySQL (τρέχουσες τιμές, όχι το .hbs template) ---\n";
global $wpdb;
$vars = ['max_connections', 'innodb_buffer_pool_size', 'innodb_buffer_pool_instances',
    'thread_cache_size', 'innodb_thread_concurrency', 'table_open_cache', 'version'];
foreach ($vars as $var) {
    $row = $wpdb->get_row($wpdb->prepare('SHOW VARIABLES LIKE %s', $var));
    printf("%-28s %s\n", $var . ':', $row->Value ?? '(δεν βρέθηκε)');
}

$status = $wpdb->get_row("SHOW STATUS LIKE 'Threads_connected'");
printf("%-28s %s\n", 'Threads_connected τώρα:', $status->Value ?? '?');
$maxUsed = $wpdb->get_row("SHOW STATUS LIKE 'Max_used_connections'");
printf("%-28s %s\n", 'Max_used_connections ever:', $maxUsed->Value ?? '?');

echo "\n--- WordPress object cache ---\n";
printf("%-28s %s\n", 'Persistent object cache:', wp_using_ext_object_cache() ? 'ΝΑΙ' : 'όχι (default: DB-backed, ανά-request)');
