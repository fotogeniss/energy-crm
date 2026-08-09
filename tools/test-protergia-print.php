<?php

/**
 * Manual smoke test for the four residential Protergia applications.
 *
 * Renders one synthetic contract per tariff through ECRM_FormFill::fill() and
 * writes the PDFs to tools/output/ for visual inspection. Nothing here touches
 * the database — every row is fabricated in this file, nothing real is read.
 * tools/output/ is git-ignored; delete it once you are done looking.
 *
 * What to check by eye, because no assertion can:
 *
 *   σελ.1  τα στοιχεία πελάτη/παροχής πέφτουν στις γραμμές τους (ίδιο layout
 *          με το παλιό protergia_he, γι' αυτό και οι ίδιες συντεταγμένες)
 *   σελ.2  ημερομηνία στο κουτί της, Χ στο οβάλ «Ηλεκτρονικά», εγγύηση στο
 *          κενό κελί δεξιά από το «ΕΓΓΥΗΣΗ (€)» — προσοχή, τα Sure και τα
 *          κυμαινόμενα έχουν αυτή τη σειρά σε διαφορετικό ύψος
 *   σελ.3  τα Χ μέσα στα τετραγωνάκια ΝΑΙ/ΟΧΙ των όρων Ζ και Η, και ο
 *          «Πόλη, ημερομηνία» στη γραμμή ΗΜΕΡΟΜΗΝΙΑ/ΤΟΠΟΣ (όχι ΠΑΡΑΤΗΡΗΣΕΙΣ)
 *
 * Run from the plugin root, in Local's Site shell (PHP 8.2.29 with sodium —
 * see docs/HANDOVER.md on why it has to be that shell):
 *
 *     php tools/test-protergia-print.php
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

use EnergyCRM\Domain\Forms\ProtergiaHomePlans;

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
 * A joined contract+customer row shaped like ContractRepository::findDetailed(),
 * carrying a value for every field the four sheets map — a blank box tells you
 * nothing about whether its coordinates are right.
 *
 * @return array<string, mixed>
 */
function ecrm_protergia_row(string $programCode, string $programName): array
{
    return [
        'id'                 => 999101,
        'code'               => 'TEST-0001',
        'partner_user_id'    => 0,
        'provider_name'      => 'Protergia',
        'energy_type'        => 'power',
        'customer_type'      => 'individual',
        'activation_type'    => 'new_connection',
        'program_code'       => $programCode,
        'program_name'       => $programName,
        'term_months'        => 12,
        'invoice_code'       => 'Γ1Ν',
        'created_at'         => gmdate('Y-m-d H:i:s'),
        'first_name'         => 'ΔΟΚΙΜΗ',
        'last_name'          => 'ΣΥΝΘΕΤΙΚΟ',
        'afm'                => '000000000',
        'doy'                => 'Α΄ ΑΘΗΝΩΝ',
        'adt'                => 'ΑΒ000000',
        'phone'              => '2100000000',
        'mobile'             => '6900000000',
        'email'              => 'test@example.invalid',
        'city'               => 'Αθήνα',
        'street'             => 'Δοκιμαστική',
        'street_no'          => '1',
        'postal_code'        => '11111',
        'region'             => 'Αττικής',
        // Οι δύο σημαίες, ρητά 0: θέλουμε να δούμε τυπωμένες τρεις
        // *διαφορετικές* διευθύνσεις. Χωρίς αυτές ισχύει ο κανόνας «απούσα
        // στήλη = ίδια με του πελάτη» (ContractAddresses::isFlaggedSame) και
        // το έντυπο βγάζει τρεις φορές τη διεύθυνση κατοικίας — σωστό, αλλά
        // δεν ελέγχει τίποτα.
        'supply_addr_same'   => 0,
        'billing_addr_same'  => 0,
        'supply_number'      => '12345678901',
        'meter_number'       => 'Μ0000001',
        'supply_street'      => 'Παροχής',
        'supply_street_no'   => '2',
        'supply_city'        => 'Πειραιάς',
        'supply_postal_code' => '18500',
        'billing_street'     => 'Αποστολής',
        'billing_street_no'  => '3',
        'billing_city'       => 'Χαλάνδρι',
        'billing_postal_code' => '15232',
        'extra_json'         => (string) json_encode([
            'activity'           => 'Ιδιώτης',
            'previous_provider'  => 'ΔΕΗ',
            'agreed_power'       => '8',
            'day_indication'     => '12345',
            'guarantee'          => '150',
            'meter_reading_type' => 'day_night',
            'kod_synergati'      => 'ΣΥΝ-001',
            'contact_first_name' => 'ΕΠΑΦΗ',
            'contact_last_name'  => 'ΔΟΚΙΜΗ',
            'contact_phone'      => '2109999999',
            'contact_mobile'     => '6999999999',
            'contact_email'      => 'contact@example.invalid',
            // Οι δύο συναινέσεις της σελ.3: μία ΝΑΙ και μία ΟΧΙ, ώστε να
            // φανεί ότι το κάθε Χ πάει στο δικό του κουτί.
            'group_data_consent' => 'yes',
            'survey_consent'     => 'no',
        ]),
    ];
}

/** Page count from raw PDF bytes — no library, just counting page objects. */
function ecrm_protergia_page_count(string $bytes): int
{
    return substr_count($bytes, '/Type /Page') - substr_count($bytes, '/Type /Pages');
}

$exit = 0;

foreach (ProtergiaHomePlans::all() as $code => $plan) {
    echo "=== {$code} ===\n";

    $row = ecrm_protergia_row($code, $plan['label']);
    $got = ECRM_FormFill::template_keys($row);

    // Ένα τιμολόγιο, ένα φύλλο: αν βγει άλλο κλειδί, ο πελάτης υπογράφει
    // έντυπο που ονομάζει τιμολόγιο διαφορετικό από αυτό που αγόρασε.
    $match = $got === [$code];
    echo '  Πρότυπο: ' . implode(', ', $got) . ($match ? '  OK' : "  ΔΙΑΦΟΡΕΤΙΚΟ (αναμενόταν {$code})") . "\n";

    if (! $match) {
        $exit = 1;
    }

    $result = ECRM_FormFill::fill($row);

    if (empty($result['ok'])) {
        echo '  ΣΦΑΛΜΑ: ' . ($result['error'] ?? '?') . "\n\n";
        $exit = 1;
        continue;
    }

    $bytes = (string) $result['bytes'];
    $pages = ecrm_protergia_page_count($bytes);
    $file  = $outputDir . '/protergia__' . $code . '.pdf';
    file_put_contents($file, $bytes);

    // Και τα τέσσερα έντυπα είναι εξασέλιδα: 3 σελίδες αίτησης + 3 όρων.
    $pagesMatch = $pages === 6;
    echo "  {$pages} σελίδες" . ($pagesMatch ? '  OK' : '  ΔΙΑΦΟΡΕΤΙΚΟ (αναμένονταν 6)')
        . ', ' . strlen($bytes) . ' bytes -> ' . basename($file) . "\n\n";

    if (! $pagesMatch) {
        $exit = 1;
    }
}

// Μια σύμβαση σε πρόγραμμα εκτός των τεσσάρων (παλιά, ή φτιαγμένο από τον
// χρήστη) πρέπει να πέφτει στο παλιό ενιαίο έντυπο, όχι σε κάποιο τυχαίο.
$legacy = ECRM_FormFill::template_keys(ecrm_protergia_row('', 'Σταθερό Οικιακό'));
$legacyOk = $legacy === ['protergia_he'];
echo '=== fallback ===' . "\n";
echo '  Χωρίς code: ' . implode(', ', $legacy) . ($legacyOk ? '  OK' : '  ΔΙΑΦΟΡΕΤΙΚΟ') . "\n\n";

if (! $legacyOk) {
    $exit = 1;
}

echo $exit === 0
    ? "Και τα τέσσερα έντυπα βγήκαν. Άνοιξε τα PDF στο tools/output/ για οπτικό έλεγχο\n"
      . "— ιδίως τα Χ των όρων Ζ/Η στη σελ.3 και την εγγύηση στη σελ.2.\n"
    : "Κάτι δεν βγήκε όπως αναμενόταν — δες τις γραμμές παραπάνω.\n";

exit($exit);
