<?php

/**
 * (317) Δοκιμαστική εκτύπωση των δύο εντύπων Volton (ρεύμα, αέριο), μετά τη
 * διόρθωση των χαρτών volton_fa.json / volton_he.json.
 *
 * Τίποτα δεν διαβάζεται ή γράφεται στη βάση: κάθε στοιχείο είναι φτιαγμένο
 * εδώ. Βγάζει PDF στο tools/output/ (git-ignored) για οπτικό έλεγχο:
 *
 *   - κάθε τιμή στη γραμμή της ετικέτας της (ΑΦΜ δίπλα στο «Α.Φ.Μ.» κ.λπ.)
 *   - το Χ «ΙΔΙΩΤΗΣ» / «ΕΤΑΙΡΕΙΑ» μέσα στο τετραγωνάκι
 *   - αέριο: διάρκεια 12 μήνες με Χ μέσα στο κουτί, διευθύνσεις εγκατάστασης
 *     και αποστολής στις δικές τους γραμμές
 *
 * Από το Site shell του Local, στη ρίζα του plugin:
 *
 *     php tools/test-volton-print.php
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
function ecrm_volton_row(string $energy, string $customerType): array
{
    $company = $customerType === 'company';

    return [
        'id'                  => 999201,
        'code'                => 'TEST-VOLTON',
        'partner_user_id'     => 0,
        'provider_name'       => 'VOLTON',
        'energy_type'         => $energy,
        'customer_type'       => $customerType,
        'category'            => $company ? 'business' : 'home',
        'activation_type'     => 'change_provider',
        'program_code'        => '',
        'program_name'        => $energy === 'gas' ? 'Volton Gas Δοκιμή' : 'Volton Blue Flat',
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
        // Τρεις ΔΙΑΦΟΡΕΤΙΚΕΣ διευθύνσεις, για να φανεί ότι η καθεμία πάει
        // στη δική της γραμμή (κατοικία / εγκατάσταση / αποστολή).
        'supply_addr_same'    => 0,
        'billing_addr_same'   => 0,
        'supply_number'       => $energy === 'gas' ? '0123456789012345' : '12345678901',
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
            'guarantee'          => '150',
            'kad'                => '35.11',
            'kod_synergati'      => 'ΣΥΝ-001',
            'rep_first_name'     => 'ΕΚΠΡΟΣΩΠΟΣ',
            'rep_last_name'      => 'ΔΟΚΙΜΗ',
            'contact_first_name' => 'ΕΠΑΦΗ',
            'contact_last_name'  => 'ΔΟΚΙΜΗ',
            'contact_afm'        => '111111111',
            'contact_adt'        => 'ΓΔ111111',
            'contact_phone'      => '2109999999',
            'contact_mobile'     => '6999999999',
            'contact_email'      => 'contact@example.invalid',
            'capacity_role'      => 'owner',
        ]),
    ];
}

$exit = 0;

foreach ([['power', 'individual'], ['gas', 'individual'], ['power', 'company'], ['gas', 'company']] as [$energy, $type]) {
    $row  = ecrm_volton_row($energy, $type);
    $keys = ECRM_FormFill::template_keys($row);
    echo "=== {$energy} / {$type} === πρότυπο: " . implode(', ', $keys) . "\n";

    $result = ECRM_FormFill::fill($row);

    if (empty($result['ok'])) {
        echo '  ΣΦΑΛΜΑ: ' . ($result['error'] ?? '?') . "\n\n";
        $exit = 1;
        continue;
    }

    $file = $outputDir . '/volton__' . $energy . '__' . $type . '.pdf';
    file_put_contents($file, (string) $result['bytes']);
    echo '  ' . strlen((string) $result['bytes']) . ' bytes -> tools/output/' . basename($file) . "\n\n";
}

echo $exit === 0
    ? "Βγήκαν. Άνοιξε τα PDF στο tools/output/ και δες αν κάθε τιμή είναι στη γραμμή της.\n"
    : "Κάτι δεν βγήκε — δες τις γραμμές παραπάνω.\n";

exit($exit);
