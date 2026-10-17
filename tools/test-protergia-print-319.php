<?php

/**
 * (319) Δοκιμαστική εκτύπωση των εντύπων ρεύματος Protergia: οικιακό με δικό
 * του φύλλο (Lite 2.0), γενικό οικιακό, επαγγελματικό με δικό του φύλλο
 * (Sure 12) και ένα από τα 13 νέα επαγγελματικά (γενικό φύλλο), και αυτό ΚΑΙ
 * με πελάτη περασμένο ως ιδιώτη.
 *
 * Τίποτα δεν διαβάζεται ή γράφεται στη βάση: κάθε στοιχείο είναι φτιαγμένο
 * εδώ. Βγάζει PDF στο tools/output/ για οπτικό έλεγχο:
 *
 *   - σελ.1: kVA, Χ στις φάσεις και στη μέτρηση
 *   - σελ.2: εγγύηση
 *   - σελίδα «Αποδοχή όρων»: Α (αποστολή λογαριασμού), Β1/Β2 (αλλαγή όρων),
 *     Ζ/Η (συναινέσεις), Θ (μόνο οικιακά, πάντα), Παρατηρήσεις σε δύο γραμμές
 *
 * Από το Site shell του Local, στη ρίζα του plugin:
 *
 *     php tools/test-protergia-print-319.php
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
function ecrm_protergia_row(string $program, string $customerType, bool $biz): array
{
    $company = $customerType === 'company';

    return [
        'id'                  => 999301,
        'code'                => 'TEST-PROTERGIA',
        'partner_user_id'     => 0,
        'provider_name'       => 'Protergia',
        'energy_type'         => 'power',
        'customer_type'       => $customerType,
        'category'            => $biz ? 'business' : 'home',
        'activation_type'     => 'change_provider',
        'program_code'        => $program,
        'program_name'        => $program,
        'term_months'         => 12,
        'invoice_code'        => 'Γ1',
        'created_at'          => gmdate('Y-m-d H:i:s'),
        'first_name'          => 'ΔΟΚΙΜΗ',
        'last_name'           => 'ΣΥΝΘΕΤΙΚΟ',
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
        'meter_number'        => 'Μ0000001',
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
            'previous_provider'  => 'ΔΕΗ',
            'day_indication'     => '12345',
            'guarantee'          => '0',
            'agreed_power'       => $biz ? '15' : '8',
            'supply_phases'      => $biz ? 'three' : 'single',
            'meter_reading_type' => $biz ? 'telemetry' : 'day',
            'eidiki_katigoria'   => 'ΚΟΤ Α',
            'promotion'          => 'Μηδενική εγγύηση',
            'bill_delivery'      => 'email',
            'terms_notice'       => $biz ? 'registered' : 'email',
            'group_data_consent' => $biz ? 'no' : 'yes',
            'survey_consent'     => $biz ? 'yes' : 'no',
            'payment_method'     => 'standing_order',
            'kad'                => '35.11',
            'kod_synergati'      => 'ΣΥΝ-001',
            'rep_first_name'     => 'ΕΚΠΡΟΣΩΠΟΣ',
            'rep_last_name'      => 'ΔΟΚΙΜΗ',
        ]),
    ];
}

$exit = 0;

$cases = [
    'home_lite2'              => ['protergia_oik_lite2', 'individual', false],
    'home_generic'            => ['protergia_value_standard2', 'individual', false],
    'biz_sure12'              => ['protergia_epag_sure12', 'company', true],
    'biz_new'                 => ['protergia_epag2_special', 'company', true],
    'biz_new_as_individual'   => ['protergia_epag3_sure12m_2', 'individual', true],
];

foreach ($cases as $name => [$program, $type, $biz]) {
    $row  = ecrm_protergia_row($program, $type, $biz);
    $keys = ECRM_FormFill::template_keys($row);
    echo "=== {$name} === πρότυπο: " . implode(', ', $keys) . "\n";

    $result = ECRM_FormFill::fill($row);

    if (empty($result['ok'])) {
        echo '  ΣΦΑΛΜΑ: ' . ($result['error'] ?? '?') . "\n\n";
        $exit = 1;
        continue;
    }

    $file = $outputDir . '/protergia319__' . $name . '.pdf';
    file_put_contents($file, (string) $result['bytes']);
    echo '  ' . strlen((string) $result['bytes']) . ' bytes -> tools/output/' . basename($file) . "\n\n";
}

echo $exit === 0
    ? "Βγήκαν. Άνοιξε τα PDF στο tools/output/ και δες σελ.1 (kVA/φάσεις/μέτρηση), σελ.2 (εγγύηση) και τη σελίδα «Αποδοχή όρων».\n"
    : "Κάτι δεν βγήκε — δες τις γραμμές παραπάνω.\n";

exit($exit);
