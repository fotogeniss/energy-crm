<?php

/**
 * Manual smoke test for the Orizon printing pipeline (ORIZON-TODO #7).
 *
 * Renders three synthetic applications through ECRM_FormFill::fill_all() and
 * writes the resulting PDFs to tools/output/ for visual inspection. Nothing
 * here touches the database — every row is fabricated in this file, nothing
 * real is read. tools/output/ is git-ignored; delete it once you are done
 * looking at the PDFs.
 *
 * Run from the plugin root, in Local's Site shell (PHP 8.2.29 with sodium —
 * see docs/HANDOVER.md on why it has to be that shell):
 *
 *     php tools/test-orizon-print.php
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

$wpLoad = dirname(__DIR__, 4) . '/wp-load.php';

if (! is_readable($wpLoad)) {
    fwrite(STDERR, "Δεν βρέθηκε το wp-load.php στο: {$wpLoad}\n");
    exit(1);
}

require $wpLoad;

if (! class_exists('ECRM_FormFill')) {
    fwrite(STDERR, "Το ECRM_FormFill δεν φορτώθηκε — είναι ενεργό το plugin;\n");
    exit(1);
}

$outputDir = __DIR__ . '/output';

if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, "Δεν μπόρεσα να δημιουργήσω το {$outputDir}\n");
    exit(1);
}

/**
 * A minimal joined contract+customer row, shaped like the SELECT that
 * ContractRepository::findDetailed() produces — only the columns
 * ECRM_FormFill::values() actually reads. Every value below is fabricated;
 * nothing here identifies a real person.
 *
 * @param array<string, mixed> $extra Overrides (program_code, extra_json, ...).
 *
 * @return array<string, mixed>
 */
function ecrm_test_row(array $extra): array
{
    $base = [
        'id'              => 999001,
        'code'            => 'TEST-0001',
        'partner_user_id' => 0,
        'provider_name'   => 'Orizon',
        'energy_type'     => 'mobile',
        'customer_type'   => 'individual',
        'activation_type' => '',
        'term_months'     => 24,
        'created_at'      => gmdate('Y-m-d H:i:s'),
        'first_name'      => 'ΔΟΚΙΜΗ',
        'last_name'       => 'ΣΥΝΘΕΤΙΚΟ',
        'afm'             => '000000000',
        'adt'             => 'ΑΒ000000',
        'phone'           => '2100000000',
        'mobile'          => '6900000000',
        'email'           => 'test@example.invalid',
        'city'            => 'Αθήνα',
        'street'          => 'Δοκιμαστική',
        'street_no'       => '1',
        'postal_code'     => '11111',
        'region'          => 'Αττικής',
        'extra_json'      => '{}',
    ];

    return array_merge($base, $extra);
}

$scenarios = [
    // 1. Φορητότητα + Συνδυαστική (mobile+mobile): σύμβαση + αίτηση
    //    φορητότητας + έντυπο Family. Πλάνο 5GB.
    'A_portability_family' => ecrm_test_row([
        'program_code' => \EnergyCRM\Domain\Forms\MobilePlans::P_5GB,
        'program_name' => \EnergyCRM\Domain\Forms\MobilePlans::label(\EnergyCRM\Domain\Forms\MobilePlans::P_5GB),
        'extra_json'   => json_encode([
            'request_type'  => 'portability',
            'mobile_offer'  => 'family',
            'mobile_msisdn' => '6912345678',
            'sim_number'    => '8930012345678901234',
        ]),
    ]),

    // 2. Φορητότητα + COMBO (mobile+ρεύμα): σύμβαση + αίτηση φορητότητας +
    //    έντυπο COMBO, με τα πεδία ρεύματος του COMBO συμπληρωμένα. Πλάνο 40GB.
    'B_portability_combo' => ecrm_test_row([
        'program_code' => \EnergyCRM\Domain\Forms\MobilePlans::P_40GB,
        'program_name' => \EnergyCRM\Domain\Forms\MobilePlans::label(\EnergyCRM\Domain\Forms\MobilePlans::P_40GB),
        'extra_json'   => json_encode([
            'request_type'         => 'portability',
            'mobile_offer'         => 'combo',
            'mobile_msisdn'        => '6987654321',
            'sim_number'           => '8930019876543210987',
            'combo_supply_number'  => 'HK1234567890',
            'combo_energy_program' => 'Δοκιμαστικό Πρόγραμμα Ρεύματος',
        ]),
    ]),

    // 3. Απλή αίτηση: μόνο η σύμβαση, χωρίς φορητότητα ή συνδυαστική. Πλάνο
    //    unlimited.
    'C_plain' => ecrm_test_row([
        'program_code' => \EnergyCRM\Domain\Forms\MobilePlans::P_UNLIMITED,
        'program_name' => \EnergyCRM\Domain\Forms\MobilePlans::label(\EnergyCRM\Domain\Forms\MobilePlans::P_UNLIMITED),
        'extra_json'   => json_encode([
            'request_type'  => 'new_number',
            'mobile_offer'  => '',
            'mobile_msisdn' => '',
            'sim_number'    => '8930011111111111111',
        ]),
    ]),
];

$expectedKeys = [
    'A_portability_family' => ['orizon_mobile', 'orizon_portability', 'orizon_family'],
    'B_portability_combo'  => ['orizon_mobile', 'orizon_portability', 'orizon_combo'],
    'C_plain'              => ['orizon_mobile'],
];

$exit = 0;

foreach ($scenarios as $name => $row) {
    echo "=== {$name} ===\n";

    $sheets  = ECRM_FormFill::fill_all($row);
    $gotKeys = array_column($sheets, 'key');

    $expected = $expectedKeys[$name];
    $match    = $gotKeys === $expected;

    echo '  Αναμένονταν: ' . implode(', ', $expected) . "\n";
    echo '  Παράχθηκαν : ' . implode(', ', $gotKeys) . ($match ? '  OK' : '  ΔΙΑΦΟΡΕΤΙΚΟ') . "\n";

    if (! $match) {
        $exit = 1;
    }

    foreach ($sheets as $sheet) {
        $key = (string) $sheet['key'];

        if (empty($sheet['ok'])) {
            echo "    [{$key}] ΣΦΑΛΜΑ: " . ($sheet['error'] ?? '?') . "\n";
            $exit = 1;
            continue;
        }

        $bytes    = (string) $sheet['bytes'];
        $filename = $outputDir . '/' . $name . '__' . $key . '.pdf';
        file_put_contents($filename, $bytes);
        echo "    [{$key}] OK, " . strlen($bytes) . ' bytes -> ' . basename($filename) . "\n";
    }

    echo "\n";
}

echo $exit === 0
    ? "Όλα τα σετ εντύπων βγήκαν όπως αναμενόταν. Άνοιξε τα PDF στο tools/output/ για οπτικό έλεγχο\n"
      . "— ιδίως ότι το «Χ» του προγράμματος στο Family/COMBO δεν πατάει πάνω στο όνομά του.\n"
    : "Κάτι δεν βγήκε όπως αναμενόταν — δες τα σφάλματα πιο πάνω.\n";

exit($exit);
