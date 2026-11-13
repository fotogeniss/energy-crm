<?php

/**
 * (380) Δοκιμαστική εκτύπωση των εντύπων ΔΕΗ: οικιακό σταθερό (Enter),
 * επαγγελματικό πάνω από 25 kVA (αίτηση μεγάλου πελάτη), οικιακό και
 * επαγγελματικό δυναμικό (με Προσυμβατική), και Γ1Ν χωρίς υπογραφή στους
 * Ειδικούς Όρους.
 *
 * Τίποτα δεν διαβάζεται ή γράφεται στη βάση: κάθε στοιχείο είναι φτιαγμένο
 * εδώ. Βγάζει PDF στο tools/output/ για οπτικό έλεγχο της αίτησης (σελ. 1)
 * και της σειράς των φύλλων.
 *
 * Από το Site shell του Local, στη ρίζα του plugin:
 *
 *     php tools/test-dei-print.php
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

/** @return array<string, mixed> */
function ecrm_dei_row(string $program, string $customerType, string $kva): array
{
    $company = $customerType === 'company';

    return [
        'id'                  => 999380,
        'code'                => 'TEST-DEI',
        'partner_user_id'     => 0,
        'provider_name'       => 'ΔΕΗ',
        'energy_type'         => 'power',
        'customer_type'       => $customerType,
        'category'            => $company ? 'business' : 'home',
        'activation_type'     => 'change_provider',
        'program_code'        => $program,
        'program_name'        => $program,
        'term_months'         => 12,
        'invoice_code'        => 'Γ1Ν',
        'provider_ref'        => 'ΔΕΗ-0000-0001',
        'created_at'          => gmdate('Y-m-d H:i:s'),
        'first_name'          => 'ΔΟΚΙΜΗ',
        'last_name'           => 'ΣΥΝΘΕΤΙΚΟ',
        'father_name'         => 'ΠΑΤΡΩΝΥΜΟ',
        'company_name'        => $company ? 'ΔΟΚΙΜΑΣΤΙΚΗ ΑΕ' : '',
        'afm'                 => '000000000',
        'doy'                 => 'Α΄ ΑΘΗΝΩΝ',
        'adt'                 => 'ΑΒ000000',
        'phone'               => '2100000000',
        'mobile'              => '6900000000',
        'email'               => 'test@example.invalid',
        'city'                => 'Αθήνα',
        'street'              => 'Δοκιμαστική',
        'street_no'           => '1',
        'postal_code'         => '11111',
        'region'              => 'Αττικής',
        'supply_addr_same'    => 0,
        'billing_addr_same'   => 0,
        'supply_number'       => '12345678901',
        'supply_street'       => 'Παροχής',
        'supply_street_no'    => '2',
        'supply_city'         => 'Πειραιάς',
        'supply_postal_code'  => '18500',
        'billing_street'      => 'Αποστολής',
        'billing_street_no'   => '3',
        'billing_city'        => 'Χαλάνδρι',
        'billing_postal_code' => '15232',
        'billing_region'      => 'Αττικής',
        'extra_json'          => (string) json_encode([
            'agreed_power'   => $kva,
            'kod_synergati'  => 'ΣΥΝ-001',
            'rep_first_name' => 'ΕΚΠΡΟΣΩΠΟΣ',
            'rep_last_name'  => 'ΔΟΚΙΜΗ',
        ]),
    ];
}

$exit = 0;

$cases = [
    'home_enter'       => ['dei_myhome_enter', 'individual', '8'],
    'biz_enter_large'  => ['dei_mybusiness_enter', 'company', '35'],
    'home_dynamic'     => ['dei_myhome_dynamic', 'individual', '12'],
    'biz_dynamic'      => ['dei_mybusiness_dynamic', 'company', '50'],
    'home_g1'          => ['dei_g1', 'individual', ''],
];

foreach ($cases as $name => [$program, $type, $kva]) {
    $row  = ecrm_dei_row($program, $type, $kva);
    $keys = ECRM_FormFill::template_keys($row);
    echo "=== {$name} === φύλλα: " . implode(', ', $keys) . "\n";

    $result = ECRM_FormFill::fill($row);

    if (empty($result['ok'])) {
        echo '  ΣΦΑΛΜΑ: ' . ($result['error'] ?? '?') . "\n\n";
        $exit = 1;
        continue;
    }

    $file = $outputDir . '/dei380__' . $name . '.pdf';
    file_put_contents($file, (string) $result['bytes']);
    echo '  ' . strlen((string) $result['bytes']) . ' bytes -> tools/output/' . basename($file) . "\n\n";
}

echo $exit === 0
    ? "Βγήκαν. Άνοιξε τα PDF στο tools/output/ και δες την αίτηση (σελ. 1) και τις υπογραφές στους Ειδικούς Όρους.\n"
    : "Κάτι δεν βγήκε — δες τις γραμμές παραπάνω.\n";

exit($exit);
