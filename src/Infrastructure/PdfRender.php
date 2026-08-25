<?php

/**
 * Run a PDF builder safely, and hand back only the PDF bytes.
 *
 * Ο έλεγχος «τι κάνει το wp-admin»/«τι κάνει το REST» δεν αφορά ΚΑΘΟΛΟΥ αυτή
 * την κλάση — τη γράφει το ίδιο πρόβλημα και στα δύο: οι βιβλιοθήκες PDF
 * γράφουν στο stdout καθώς δουλεύουν, και μια αδέσποτη notice μπροστά από το
 * `%PDF-` χαλάει το αρχείο. Ήταν ιδιωτική μέθοδος του
 * `ContractDocumentsController` (μόνο σύμβαση) πριν το build queue 11
 * χρειαστεί ΤΟ ΙΔΙΟ πράγμα για τη βεβαίωση εκκαθάρισης — δεύτερος καλών είναι
 * ο ορισμός του «βγες από τον controller».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use Throwable;

final class PdfRender
{
    private function __construct()
    {
    }

    /**
     * @param callable(): string $build
     */
    public static function bytes(callable $build): ?string
    {
        TimeLimit::atLeast(60);
        $reporting = error_reporting(0);

        // Buffering opens outside the try and closes in finally, so it is
        // balanced on every path. Guarding with ob_get_level() would only be
        // papering over a start and an end that could get out of step.
        ob_start();

        try {
            $bytes = $build();
        } catch (Throwable) {
            return null;
        } finally {
            ob_end_clean();
            error_reporting($reporting);
        }

        $start = strpos($bytes, '%PDF-');

        if ($start === false) {
            return null;
        }

        // substr from zero returns the string unchanged, so no branch is needed.
        return substr($bytes, $start);
    }
}
