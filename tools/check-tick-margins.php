<?php

/**
 * Renders page 1 of the mobile contract, Family and COMBO templates once per
 * plan, with only that plan's checkbox ticked — a fast way to see whether the "X" collides
 * with the plan's printed name (ORIZON-TODO #7, last bullet: "το περιθώριο
 * είναι κάτω από 2 χιλιοστά και είναι το μόνο σημείο που δεν εγγυώμαι χωρίς
 * τυπωμένο δείγμα").
 *
 * No database, no wp-load: this only needs the bundled tFPDF and the form
 * assets (background JPEG + coordinate JSON), so it runs anywhere PHP does
 * and takes a fraction of a second.
 *
 *     php tools/check-tick-margins.php
 *
 * Writes to tools/output/ (git-ignored) — delete when done looking.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/lib/tfpdf/tfpdf.php';

// Same value as ECRM_FormFill::BASELINE — kept in sync by hand since this
// script deliberately does not load the plugin.
const CHECK_BASELINE = 2.5;

$formsDir = dirname(__DIR__) . '/assets/forms/';
$outDir   = __DIR__ . '/output';

if (! is_dir($outDir) && ! mkdir($outDir, 0755, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "Δεν μπόρεσα να δημιουργήσω το {$outDir}\n");
    exit(1);
}

$plans = ['programma_5gb', 'programma_10gb_5gb', 'programma_40gb', 'programma_unlimited'];

foreach (['orizon_mobile', 'orizon_family', 'orizon_combo'] as $key) {
    $mapFile = $formsDir . $key . '.json';
    $map     = json_decode((string) file_get_contents($mapFile), true);

    if (! is_array($map)) {
        fwrite(STDERR, "Άκυρος ή απών χάρτης: {$mapFile}\n");
        continue;
    }

    foreach ($plans as $plan) {
        $pos = $map['fields'][$plan] ?? null;

        if (! is_array($pos) || (int) ($pos['page'] ?? 1) !== 1) {
            echo "{$key}: {$plan} δεν βρέθηκε στη σελίδα 1 — παραλείπεται\n";
            continue;
        }

        $w = (float) ($map['page_w'] ?? 210);
        $h = (float) ($map['page_h'] ?? 297);

        $pdf = new tFPDF('P', 'mm', [$w, $h]);
        $pdf->fontpath = dirname(__DIR__) . '/includes/lib/tfpdf/font/';
        $pdf->SetAutoPageBreak(false);
        $pdf->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
        $pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.ttf', true);
        $pdf->AddPage('P', [$w, $h]);
        $pdf->Image($formsDir . $key . '-1.jpg', 0, 0, $w, $h);
        $pdf->SetTextColor(0, 0, 150);
        // Mirrors ECRM_FormFill::render()'s check-mark branch exactly (same
        // size/bold opt-in, same default) so this preview matches production.
        $style = ! empty($pos['bold']) ? 'B' : '';
        $pdf->SetFont('DejaVu', $style, (float) ($pos['size'] ?? 10));
        $pdf->Text((float) $pos['x'], (float) $pos['y'] + CHECK_BASELINE, 'X');

        $out = $outDir . '/tick__' . $key . '__' . $plan . '.pdf';
        file_put_contents($out, $pdf->Output('', 'S'));
        echo "{$out}\n";
    }
}
