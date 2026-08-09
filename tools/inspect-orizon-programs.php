<?php

/**
 * Diagnostic: why doesn't the ΠΡΟΓΡΑΜΜΑ dropdown show the 4 real Orizon
 * plans? Dumps which migrations have actually run against this database, and
 * the current state of the `programs` table for any mobile-selling provider.
 *
 * Read-only. Run from the plugin root, Local's Site shell:
 *
 *     php tools/inspect-orizon-programs.php
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

use EnergyCRM\Persistence\Schema\MigrationList;
use EnergyCRM\Persistence\Schema\MigrationRunner;
use EnergyCRM\Persistence\Tables;

$wpLoad = dirname(__DIR__, 4) . '/wp-load.php';

if (! is_readable($wpLoad)) {
    fwrite(STDERR, "Δεν βρέθηκε το wp-load.php στο: {$wpLoad}\n");
    exit(1);
}

require $wpLoad;

global $wpdb;

echo "=== Migrations: τι έχει καταγραφεί ως εφαρμοσμένο ===\n";
$applied = get_option(MigrationRunner::OPTION, []);
$applied = is_array($applied) ? $applied : [];
foreach (MigrationList::all() as $m) {
    $status = in_array($m->id(), $applied, true) ? 'OK     ' : 'ΕΚΚΡΕΜΕΙ';
    echo "  [{$status}] {$m->id()}\n";
}

$providersTable = Tables::name(Tables::PROVIDERS);
$programsTable  = Tables::name(Tables::PROGRAMS);

echo "\n=== programs.code υπάρχει σαν στήλη; ===\n";
// phpcs-equivalent care not needed here: read-only diagnostic, not shipped code.
$hasCol = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'code'",
    $programsTable
));
echo '  ' . ($hasCol ? 'ΝΑΙ' : 'ΟΧΙ — το migration 0012 δεν έχει τρέξει ακόμη') . "\n";

echo "\n=== providers με 'mobile' στο energy_types ===\n";
$rows = $wpdb->get_results(
    "SELECT id, slug, name, energy_types, active FROM {$providersTable} WHERE energy_types LIKE '%mobile%'",
    ARRAY_A
);

if (! $rows) {
    echo "  (καμία γραμμή — κανένας provider δεν έχει 'mobile' στο energy_types)\n";
}

foreach ((array) $rows as $r) {
    echo "\n  provider id={$r['id']} slug={$r['slug']} name={$r['name']} energy_types={$r['energy_types']} active={$r['active']}\n";

    $progs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, name, " . ($hasCol ? 'code' : 'NULL AS code') . ", energy_type, active, sort_order
             FROM {$programsTable} WHERE provider_id = %d ORDER BY sort_order",
            (int) $r['id']
        ),
        ARRAY_A
    );

    if (! $progs) {
        echo "    (κανένα πρόγραμμα καταχωρημένο για αυτόν τον provider)\n";
    }

    foreach ((array) $progs as $p) {
        $code = $p['code'] ?? null;
        echo "    program id={$p['id']} name=\"{$p['name']}\" code=" . ($code === null ? 'NULL' : $code)
            . " energy_type={$p['energy_type']} active={$p['active']}\n";
    }
}
